<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Cirrusly_Commerce_Pricing_Sync {

    public function __construct() {
        add_action( 'cirrusly_commerce_gmc_sync', array( $this, 'handle_gmc_sync_event' ), 10, 1 );
        add_action( 'admin_notices', array( $this, 'render_sync_error_notice' ) );
    }

    public function handle_gmc_sync_event( $product_id ) {
        $this->_gmc_api_worker( $product_id );
    }

    private function _gmc_api_worker( $product_id ) {
        // Dependency Check: Use the new Pro API Client
        if ( ! class_exists( 'Cirrusly_Commerce_Google_API_Client' ) ) {
            $this->log_global_sync_failure( 'Google API Client class not found.' );
            return;
        }

        $client = Cirrusly_Commerce_Google_API_Client::get_client();
        if ( is_wp_error( $client ) ) {
            $this->log_global_sync_failure( 'GMC Client Error: ' . $client->get_error_message() );
            return;
        }
        
        $product = wc_get_product( $product_id );
        if ( ! $product ) return;

        // Uses Settings from Pro config
        $scan_config = get_option( 'cirrusly_scan_config' );
        $merchant_id = isset( $scan_config['merchant_id_pro'] ) ? $scan_config['merchant_id_pro'] : get_option( 'cirrusly_gmc_merchant_id', '' );
        
        if ( empty( $merchant_id ) ) return; 

        $service = new Google\Service\ShoppingContent( $client );

        // Fallback for SKU/ID
        $offer_id = $product->get_sku() ?: $product->get_id();
        
        try {
            // PATCH request (Inventory Update)
            $gmc_product = new Google\Service\ShoppingContent\Product();
            
            $gmc_product->setOfferId( (string) $offer_id );
            // Defaulting to en/US/online if not set elsewhere. Ideally should match feed settings.
            $language = apply_filters( 'cirrusly_gmc_content_language', get_bloginfo( 'language' ) );
            $country = apply_filters( 'cirrusly_gmc_target_country', WC()->countries->get_base_country() );
            $gmc_product->setContentLanguage( substr( $language, 0, 2 ) ); 
            $gmc_product->setTargetCountry( $country );
            $gmc_product->setChannel( 'online' );
            
            $gmc_product->setAvailability( $product->is_in_stock() ? 'in stock' : 'out of stock' );

            $price_obj = new Google\Service\ShoppingContent\Price();
            $price_obj->setValue( $product->get_price() );
            $price_obj->setCurrency( get_woocommerce_currency() );
            $gmc_product->setPrice( $price_obj );
            
            $product_rest_id = sprintf( 'online:%s:%s:%s', substr( $language, 0, 2 ), $country, $offer_id );

            $service->products->update( $merchant_id, $product_rest_id, $gmc_product );
            
            $this->log_global_sync_success();
            
        } catch ( Exception $e ) {
            $this->log_global_sync_failure( 'API Exception: ' . $e->getMessage() );
        }
    }

    private function log_global_sync_failure( $message ) {
        update_option( 'cirrusly_gmc_global_sync_error', array(
            'time'    => time(),
            'message' => $message,
        ), false );
        delete_transient( 'cirrusly_gmc_sync_notice_dismissed' );
    }

    private function log_global_sync_success() {
        delete_option( 'cirrusly_gmc_global_sync_error' );
    }

    public function render_sync_error_notice() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( get_transient( 'cirrusly_gmc_sync_notice_dismissed' ) ) return;

        $error_data = get_option( 'cirrusly_gmc_global_sync_error' );

        if ( ! empty( $error_data ) && is_array( $error_data ) ) {
            $time_diff = human_time_diff( $error_data['time'], current_time( 'timestamp' ) );
            $message = sprintf( 
                '<strong>Cirrusly Commerce Warning:</strong> Google Merchant Center sync failed %s ago. <a href="%s">Review GMC Hub</a>. Last Error: <code>%s</code>',
                esc_html( $time_diff ),
                esc_url( admin_url( 'admin.php?page=cirrusly-gmc' ) ),
                esc_html( $error_data['message'] )
            );
            echo '<div class="notice notice-error is-dismissible"><p>' . wp_kses_post( $message ) . '</p></div>';
        }
    }
}