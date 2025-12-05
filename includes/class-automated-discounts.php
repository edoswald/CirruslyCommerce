<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cirrusly_Commerce_Automated_Discounts {

    const SESSION_KEY_PREFIX = 'cc_google_ad_';
    const TOKEN_PARAM = 'pv2';

    public function __construct() {
        // 1. Settings (Register in existing GMC settings group)
        add_filter( 'cirrusly_commerce_scan_settings_ui', array( $this, 'render_settings_field' ) );
        
        // 2. Listener (Capture URL Token)
        add_action( 'template_redirect', array( $this, 'capture_google_token' ) );

        // 3. Price Overrides (Frontend Display)
        add_filter( 'woocommerce_get_price_html', array( $this, 'override_price_display' ), 20, 2 );
        add_filter( 'woocommerce_product_get_price', array( $this, 'override_price_value' ), 20, 2 );
        add_filter( 'woocommerce_product_variation_get_price', array( $this, 'override_price_value' ), 20, 2 );

        // 4. Cart Overrides (The actual price they pay)
        add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_discount_to_cart' ), 20, 1 );
        
        // 5. Cache Busting (Ensure users see the dynamic price)
        add_action( 'send_headers', array( $this, 'prevent_caching_if_active' ) );
    }

    /**
     * Render the checkbox in the existing GMC Health Check > Settings area.
     */
    public function render_settings_field() {
        $scan_cfg = get_option( 'cirrusly_scan_config', array() );
        $enabled  = ! empty( $scan_cfg['enable_automated_discounts'] );
        ?>
        <br>
        <label>
            <input type="checkbox" name="cirrusly_scan_config[enable_automated_discounts]" value="yes" <?php checked( $enabled ); ?>> 
            <strong><?php esc_html_e( 'Enable Google Automated Discounts', 'cirrusly-commerce' ); ?></strong>
            <p class="description" style="margin-left:25px; margin-top:2px;">
                Allows Google to dynamically lower prices for specific customers via Shopping Ads. 
                <br>Requires <code>Cost of Goods</code> and <code>Google Min Price</code> to be set on products.
            </p>
        </label>
        <?php
    }

    /**
     * Listener: Checks for 'pv2' in URL, verifies JWT, and stores discount in session.
     */
    public function capture_google_token() {
        if ( is_admin() || ! isset( $_GET[ self::TOKEN_PARAM ] ) ) return;

        // Check if feature enabled
        // Use default array() to avoid array-offset warnings if option is missing/false
        $cfg = get_option('cirrusly_scan_config', array());
        if ( empty($cfg['enable_automated_discounts']) || $cfg['enable_automated_discounts'] !== 'yes' ) return;

        // Unwrap raw request with wp_unslash before sanitizing
        $token = sanitize_text_field( wp_unslash( $_GET[ self::TOKEN_PARAM ] ) );
        
        if ( $payload = $this->verify_jwt( $token ) ) {
            $this->store_discount_session( $payload );
        }
    }

    /**
     * Verifies the Google JWT.
     * Uses the Google Client Library (already in your composer.json) to verify signature.
     */
    private function verify_jwt( $token ) {
        // Ensure Google Client is available
        if ( ! class_exists( 'Google\Client' ) ) return false;

        try {
            $client = new Google\Client();
            // Verify ID Token (Google signs these tokens)
            // Note: If Google changes signing method for Automated Discounts specifically, 
            // you may need to use $client->verifySignedJwt() with specific certs, 
            // but verifyIdToken is the standard entry point for Google-signed JWTs.
            $payload = $client->verifyIdToken( $token );
            
            if ( ! $payload ) return false;

            // Validate Merchant ID if present in payload (claim 'm')
            $my_merchant_id = get_option( 'cirrusly_gmc_merchant_id' );
           if ( isset( $payload['m'] ) && (string) $payload['m'] !== (string) $my_merchant_id ) {
                return false; 
            }

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
        $discount = $this->get_active_discount( $product->get_id() );
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
        $discount = $this->get_active_discount( $product->get_id() );
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
            $discount = $this->get_active_discount( $variation_id ? $variation_id : $product_id );

            if ( $discount ) {
                $cart_item['data']->set_price( $discount['price'] );
            }
        }
    }

    /**
     * Helper: Retrieve active discount from session if valid.
     */
    private function get_active_discount( $product_id ) {
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
        if ( isset( $_GET[ self::TOKEN_PARAM ] ) ) {
            nocache_headers();
        }
    }
}