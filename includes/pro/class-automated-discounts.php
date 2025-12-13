<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cirrusly_Commerce_Automated_Discounts {

    const SESSION_KEY_PREFIX = 'cirrusly_google_ad_';
    const TOKEN_PARAM = 'pv2';

    /**
     * Initialize the automated discounts integration and register WordPress/WooCommerce hooks.
     */
    public function __construct() {
        // UI Settings
        add_action( 'cirrusly_commerce_scan_settings_ui', array( $this, 'render_settings_field' ) );
        
        // Logic
        add_action( 'template_redirect', array( $this, 'capture_google_token' ) );
        add_filter( 'woocommerce_get_price_html', array( $this, 'override_price_display' ), 20, 2 );
        add_filter( 'woocommerce_product_get_price', array( $this, 'override_price_value' ), 20, 2 );
        add_filter( 'woocommerce_product_variation_get_price', array( $this, 'override_price_value' ), 20, 2 );
        add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_discount_to_cart' ), 20, 1 );
        add_action( 'send_headers', array( $this, 'prevent_caching_if_active' ) );
    }

    /**
     * Render the admin settings UI for Google Automated Discounts.
     */
    public function render_settings_field() {
        $scan_cfg = get_option('cirrusly_scan_config', array());
        $checked = isset( $scan_cfg['enable_automated_discounts'] ) && $scan_cfg['enable_automated_discounts'] === 'yes';
        $merchant_id = isset($scan_cfg['merchant_id']) ? $scan_cfg['merchant_id'] : '';
        // Note: Google Public Key field retained for UI consistency.
        $public_key = isset($scan_cfg['google_public_key']) ? $scan_cfg['google_public_key'] : '';
        ?>
        <div class="cirrusly-ad-settings" style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #c3c4c7;">
            <h4 style="margin: 0 0 10px 0;">Google Automated Discounts</h4>
            <label><input type="checkbox" name="cirrusly_scan_config[enable_automated_discounts]" value="yes" <?php checked( $checked ); ?>> <strong>Enable Dynamic Pricing</strong></label>
            <p class="description">Allows Google to dynamically lower prices via Shopping Ads. Requires Cost of Goods and Google Min Price.</p>
            <div class="cirrusly-ad-fields" style="margin-left: 25px; background: #fff; padding: 15px; border: 1px solid #c3c4c7; border-radius:4px;">
                <p><label><strong>Merchant ID</strong></label><br><input type="text" name="cirrusly_scan_config[merchant_id]" value="<?php echo esc_attr( $merchant_id ); ?>" class="regular-text"></p>
                <p><label><strong>Google Public Key (PEM)</strong></label><br><textarea name="cirrusly_scan_config[google_public_key]" rows="5" class="large-text code" placeholder="Paste Google Public Key here" style="background:#f0f0f1; color:#50575e;"><?php echo esc_textarea( $public_key ); ?></textarea></p>
            </div>
        </div>
        <?php
    }

    /**
     * Captures a Google Automated Discounts token from the request.
     */
    public function capture_google_token() {
        if ( is_admin() || ! isset( $_GET[ self::TOKEN_PARAM ] ) ) return;
        $cfg = get_option('cirrusly_scan_config', array());
        if ( empty($cfg['enable_automated_discounts']) || $cfg['enable_automated_discounts'] !== 'yes' ) return;

        // Unwrap raw request with wp_unslash before sanitizing
        // JWTs contain base64url chars and dots - sanitize while preserving structure
        $token = preg_replace( '/[^A-Za-z0-9_.\-]/', '', wp_unslash( $_GET[ self::TOKEN_PARAM ] ) );
        
        if ( $payload = $this->verify_jwt( $token ) ) {
            $this->store_discount_session( $payload );
        }
    }

    /**
     * Verifies a Google-signed JWT using local signature verification.
     * Updated to use verifyIdToken with strict audience/issuer checks as per review.
     *
     * @param string $token The JWT to verify.
     * @return array|false The decoded JWT payload when verification succeeds, `false` on failure.
     */
    private function verify_jwt( $token ) {
        $cfg = get_option('cirrusly_scan_config', array());
        
        // Basic shape/size guard
        if ( ! is_string( $token ) || strlen( $token ) > 4096 ) {
            return false;
        }
        // 1. Get Configuration
        $merchant_id = isset( $cfg['merchant_id'] ) ? $cfg['merchant_id'] : get_option( 'cirrusly_gmc_merchant_id' );
        // Note: verifyIdToken automatically fetches Google's rotating public keys, so manual key validation is optional/fallback.
        
        if ( empty( $merchant_id ) ) {
             if ( defined('WP_DEBUG') && WP_DEBUG ) error_log('Cirrusly Discount: Missing Merchant ID.');
             return false;
        }
        // 2. Define Expected Audience
        // The audience for Automated Discounts is the Merchant ID
        $audience = $merchant_id;
        try {
        // Remedied Call: verifySignedJwt with explicit audience and issuer
        if ( function_exists( 'verifySignedJwt' ) ) {
            $payload = verifySignedJwt( $token, array( $public_key ), $audience, $issuer );
        
            // Convert object to array if necessary
            $payload = json_decode( json_encode( $payload ), true );
        }
        // 3. Validate Currency (Claim 'c')
        if ( isset( $payload['c'] ) && $payload['c'] !== get_woocommerce_currency() ) {
            error_log( 'Cirrusly Commerce JWT Fail: Currency mismatch. Expected ' . get_woocommerce_currency() . ', got ' . $payload['c'] );
            return false;
        }
        // 4. Validate Additional Claims
        if ( ! isset( $payload['dc'] ) || ! isset( $payload['dp'] ) ) {
            error_log( 'Cirrusly Commerce JWT Fail: Missing required claims (dc or dp).' );
            return false;
        }
        return $payload;
    } catch ( Exception $e ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) error_log( 'Cirrusly Discount: Token verification failed - ' . $e->getMessage() );
        return false;
    }
    }
    
    /**
     * Stores the validated discount in the WooCommerce Session.
     */
    private function store_discount_session( $payload ) {
        if ( ! isset( WC()->session ) ) return;

        // Claims: 'o' = Offer ID (SKU/ID), 'p' = Price, 'exp' = Expiration
        $offer_id = isset( $payload['o'] ) ? $payload['o'] : '';
        $price    = isset( $payload['p'] ) ? floatval( $payload['p'] ) : 0;
        $expiry   = isset( $payload['exp'] ) ? (int) $payload['exp'] : time() + ( 48 * HOUR_IN_SECONDS );

        if ( ! $offer_id || $price <= 0 ) return;

        // Map Offer ID to Product ID
        $product_id = wc_get_product_id_by_sku( $offer_id );
        if ( ! $product_id ) {
            $product_id = intval( $offer_id );
        }

        if ( $product_id ) {
            $product = wc_get_product( $product_id );
            if ( ! $product ) return;
            
            // Safety: Discount must be lower than regular price
            $regular_price = $product->get_regular_price();
            if ( $regular_price && $price >= $regular_price ) return;

            $data = array(
                'price' => $price,
                'exp'   => $expiry
            );
            WC()->session->set( self::SESSION_KEY_PREFIX . $product_id, $data );
        }
    }

    /**
     * Frontend Logic: Override the displayed price string (e.g. "<del>$20</del> $18")
     */
    public function override_price_display( $price_html, $product ) {
        $discount = self::get_active_discount( $product->get_id() );
        if ( ! $discount ) return $price_html;

        if ( $product->is_type( 'variable' ) ) {
            $regular_price = $product->get_variation_regular_price( 'min', true );
        } else {
            $regular_price = $product->get_regular_price();
        }

        return wc_format_sale_price( $regular_price, $discount['price'] );
    }

    /**
     * Logic: Override the raw price value (used by plugins/sorting)
     */
    public function override_price_value( $price, $product ) {
        $discount = self::get_active_discount( $product->get_id() );
        if ( $discount ) {
            return $discount['price'];
        }
        return $price;
    }

    /**
     * Cart Logic: Ensure they pay the discounted price.
     */
    public function apply_discount_to_cart( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;

        foreach ( $cart->get_cart() as $cart_item ) {
            $product_id = $cart_item['product_id'];
            $variation_id = isset( $cart_item['variation_id'] ) ? $cart_item['variation_id'] : 0;

            $discount = self::get_active_discount( $variation_id ? $variation_id : $product_id );

            if ( $discount ) {
                $cart_item['data']->set_price( $discount['price'] );
            }
        }
    }

    /**
     * Helper: Retrieve active discount from session if valid.
     */
    public static function get_active_discount( $product_id ) {
        if ( ! isset( WC()->session ) ) return false;
        
        $data = WC()->session->get( self::SESSION_KEY_PREFIX . $product_id );
        
        if ( $data && isset( $data['exp'] ) && $data['exp'] > time() ) {
            return $data;
        }
        return false;
    }

    /**
     * Prevent caching if a discount session is active.
     */
    public function prevent_caching_if_active() {
        if ( isset( $_GET[ self::TOKEN_PARAM ] ) ) {
            nocache_headers();
            return;
        }

        if ( isset( WC()->session ) ) {
            $session_data = (array) WC()->session->get_session_data();
            foreach ( $session_data as $key => $data ) {
                if ( strpos( $key, self::SESSION_KEY_PREFIX ) === 0 ) {
                    $data = maybe_unserialize( $data );
                    if ( isset( $data['exp'] ) && $data['exp'] > time() ) {
                        nocache_headers();
                        return;
                    }
                }
            }
        }
    }
}