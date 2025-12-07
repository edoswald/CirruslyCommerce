<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cirrusly_Commerce_Automated_Discounts {

    const SESSION_KEY_PREFIX = 'cc_google_ad_';
    const TOKEN_PARAM = 'pv2';

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

    public function render_settings_field() {
        // (Copy render_settings_field HTML from original)
        $scan_cfg = get_option('cirrusly_scan_config', array());
        $checked = isset( $scan_cfg['enable_automated_discounts'] ) && $scan_cfg['enable_automated_discounts'] === 'yes';
        $merchant_id = isset($scan_cfg['merchant_id']) ? esc_attr($scan_cfg['merchant_id']) : '';
        $public_key = isset($scan_cfg['google_public_key']) ? esc_textarea($scan_cfg['google_public_key']) : '';
        ?>
        <div class="cirrusly-ad-settings" style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #c3c4c7;">
            <h4 style="margin: 0 0 10px 0;">Google Automated Discounts</h4>
            <label><input type="checkbox" name="cirrusly_scan_config[enable_automated_discounts]" value="yes" <?php checked( $checked ); ?>> <strong>Enable Dynamic Pricing</strong></label>
            <p class="description">Allows Google to dynamically lower prices via Shopping Ads. Requires Cost of Goods and Google Min Price.</p>
            <div class="cirrusly-ad-fields" style="margin-left: 25px; background: #fff; padding: 15px; border: 1px solid #c3c4c7; border-radius:4px;">
                <p><label><strong>Merchant ID</strong></label><br><input type="text" name="cirrusly_scan_config[merchant_id]" value="<?php echo $merchant_id; ?>" class="regular-text"></p>
                <p><label><strong>Google Public Key (PEM)</strong></label><br><textarea name="cirrusly_scan_config[google_public_key]" rows="5" class="large-text code"><?php echo $public_key; ?></textarea></p>
            </div>
        </div>
        <?php
    }

    public function capture_google_token() {
        if ( is_admin() || ! isset( $_GET[ self::TOKEN_PARAM ] ) ) return;
        $cfg = get_option('cirrusly_scan_config', array());
        if ( empty($cfg['enable_automated_discounts']) || $cfg['enable_automated_discounts'] !== 'yes' ) return;

        $token = sanitize_text_field( wp_unslash( $_GET[ self::TOKEN_PARAM ] ) );
        if ( $payload = $this->verify_jwt( $token ) ) {
            $this->store_discount_session( $payload );
        }
    }

    private function verify_jwt( $token ) {
        if ( ! class_exists( 'Google\Client' ) ) return false;
        $cfg = get_option('cirrusly_scan_config', array());
        $public_key = isset( $cfg['google_public_key'] ) ? $cfg['google_public_key'] : '';
        
        if ( empty( $public_key ) ) {
            error_log( 'Cirrusly Commerce: Missing Google Public Key.' );
            return false;
        }

        try {
            $client = new Google\Client();
            $payload = $client->verifySignedJwt( $token, array( $public_key ) );
            
            if ( ! $payload ) return false;
            if ( ! isset( $payload['exp'] ) || (int) $payload['exp'] <= time() ) return false;

            $stored_merchant_id = isset( $cfg['merchant_id'] ) ? $cfg['merchant_id'] : get_option( 'cirrusly_gmc_merchant_id' );
            if ( ! isset( $payload['m'] ) || (string) $payload['m'] !== (string) $stored_merchant_id ) return false; 
            if ( isset( $payload['c'] ) && $payload['c'] !== get_woocommerce_currency() ) return false;

            return $payload;
        } catch ( Exception $e ) {
            error_log( 'Cirrusly Commerce JWT Error: ' . $e->getMessage() );
            return false;
        }
    }

    /**
     * Stores the validated discount in the WooCommerce Session.
     * Google req: 48 hours for Cart, 30 mins for view. We default to 48h to be safe.
     */
    private function store_discount_session( $payload ) {
        if ( ! isset( WC()->session ) ) return;

        // Claims: 'o' = Offer ID (SKU/ID), 'p' = Price, 'exp' = Expiration
        $offer_id = isset( $payload['o'] ) ? $payload['o'] : '';
        $price    = isset( $payload['p'] ) ? floatval( $payload['p'] ) : 0;
        $expiry   = isset( $payload['exp'] ) ? (int) $payload['exp'] : time() + ( 48 * HOUR_IN_SECONDS );

        if ( ! $offer_id || $price <= 0 ) return;

        // We need to map Offer ID (which might be SKU) to Product ID
        $product_id = wc_get_product_id_by_sku( $offer_id );
        if ( ! $product_id ) {
            // If SKU lookup failed, assume Offer ID is the Post ID
            $product_id = intval( $offer_id );
        }

        if ( $product_id ) {
            // Verify product exists
            $product = wc_get_product( $product_id );
            if ( ! $product ) return;
            
            // Optional: Verify discount is actually lower than regular price
            $regular_price = $product->get_regular_price();
            if ( $regular_price && $price >= $regular_price ) return;

            $data = array(
                'price' => $price,
                'exp'   => $expiry
            );
            // Store: cc_google_ad_123 (where 123 is product ID)
            WC()->session->set( self::SESSION_KEY_PREFIX . $product_id, $data );
        }
    }

    /**
     * Frontend Logic: Override the displayed price string (e.g. "<del>$20</del> $18")
     */
    public function override_price_display( $price_html, $product ) {
        // UPDATED: Use static call
        $discount = self::get_active_discount( $product->get_id() );
        if ( ! $discount ) return $price_html;

        // Handle Variable Products: get_regular_price() returns a range string, which breaks wc_format_sale_price
        if ( $product->is_type( 'variable' ) ) {
            $regular_price = $product->get_variation_regular_price( 'min', true );
        } else {
            $regular_price = $product->get_regular_price();
        }

        // If we have a discount, show it as a Sale Price
        return wc_format_sale_price( $regular_price, $discount['price'] );
    }

    /**
     * Logic: Override the raw price value (used by plugins/sorting)
     */
    public function override_price_value( $price, $product ) {
        // UPDATED: Use static call
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

        // Iterate through cart and apply discount if session exists
        foreach ( $cart->get_cart() as $cart_item ) {
            $product_id = $cart_item['product_id'];
            $variation_id = $cart_item['variation_id']; // Handle variations if applicable

            // specific discount for variation? or parent?
            // UPDATED: Use static call
            $discount = self::get_active_discount( $variation_id ? $variation_id : $product_id );

            if ( $discount ) {
                $cart_item['data']->set_price( $discount['price'] );
            }
        }
    }

    /**
     * Helper: Retrieve active discount from session if valid.
     * UPDATED: Changed from private to public static for Block access.
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
     * Prevent caching if a discount session is active to ensure users see their unique price.
     */
    public function prevent_caching_if_active() {
        // Prevent caching if token in URL or active discount in session
        if ( isset( $_GET[ self::TOKEN_PARAM ] ) ) {
            nocache_headers();
            return;
        }

        // Also check if any discount session is active
        if ( isset( WC()->session ) ) {
            $session_data = (array) WC()->session->get_session_data();
            foreach ( $session_data as $key => $data ) {
                if ( strpos( $key, self::SESSION_KEY_PREFIX ) === 0 ) {
                    if ( isset( $data['exp'] ) && $data['exp'] > time() ) {
                    nocache_headers();
                    return;
                    }
                }
            }
        }
    }
}