<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Cirrusly_Commerce_Pricing_Sync {

    const QUEUE_OPTION = 'cirrusly_gmc_sync_queue';
    const CRON_HOOK    = 'cirrusly_gmc_process_queue';

    /**
     * Initialize hooks.
     */
    public function __construct() {
        // Queue the product instead of syncing immediately
        add_action( 'cirrusly_commerce_gmc_sync', array( $this, 'add_to_queue' ), 10, 1 );
        
        // The background worker hook
        add_action( self::CRON_HOOK, array( $this, 'process_batch_queue' ) );
        
        // Admin notices
        add_action( 'admin_notices', array( $this, 'render_sync_error_notice' ) );
    }

    /**
     * Add a product ID to the sync queue and schedule the worker if not running.
     *
     * @param int $product_id
     */
    public function add_to_queue( $product_id ) {
    global $wpdb;
    $wpdb->query( 'START TRANSACTION' );
    
    $queue = get_option( self::QUEUE_OPTION, array() );
    
    // Avoid duplicates in the queue (use strict comparison)
    if ( ! in_array( $product_id, $queue, true ) ) {
        $queue[] = $product_id;
        update_option( self::QUEUE_OPTION, $queue, true );
    }
    
    $wpdb->query( 'COMMIT' );

        // Schedule the runner if it isn't already scheduled
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            // Run 30 seconds from now to allow for more bulk edits to pile up
            wp_schedule_single_event( time() + 30, self::CRON_HOOK );
        }
    }

    /**
     * Background Worker: Fetch queue and send Batch Request to Google.
     */
    public function process_batch_queue() {
        $queue = get_option( self::QUEUE_OPTION, array() );
        if ( empty( $queue ) || ! is_array( $queue ) ) return;

        // 1. Setup Client
        if ( ! class_exists( 'Cirrusly_Commerce_Google_API_Client' ) ) return;
        
        $client = Cirrusly_Commerce_Google_API_Client::get_client();
        if ( is_wp_error( $client ) ) {
            $this->log_global_sync_failure( 'GMC Client Error: ' . $client->get_error_message() );
            return;
        }

        $scan_config = get_option( 'cirrusly_scan_config' );
        $merchant_id = isset( $scan_config['merchant_id_pro'] ) ? $scan_config['merchant_id_pro'] : get_option( 'cirrusly_gmc_merchant_id', '' );
        
        if ( empty( $merchant_id ) ) {
            $this->log_global_sync_failure( 'Missing Merchant ID configuration.' );
            return;
        }
        $service = new Google\Service\ShoppingContent( $client );
        $batch_entries = array();
        
        // 2. Build Batch Entries
        // Process max 500 items at a time to stay safe (Google limit is higher but 500 is safe PHP timeout-wise)
        $chunk = array_splice( $queue, 0, 500 ); 

        foreach ( $chunk as $product_id ) {
            $product = wc_get_product( $product_id );
            if ( ! $product ) continue;

            $entry = new Google\Service\ShoppingContent\ProductsCustomBatchRequestEntry();
            $entry->setBatchId( $product_id ); // Use Product ID as Batch ID for tracking
            $entry->setMerchantId( $merchant_id );
            $entry->setMethod( 'insert' ); // 'insert' acts as 'update' in Content API

            // Build Product Object (Same logic as before)
            $offer_id = $product->get_sku() ?: $product->get_id();
            $language = apply_filters( 'cirrusly_gmc_content_language', get_bloginfo( 'language' ) );
            $country  = apply_filters( 'cirrusly_gmc_target_country', WC()->countries->get_base_country() );
            
            $gmc_product = new Google\Service\ShoppingContent\Product();
            $gmc_product->setOfferId( (string) $offer_id );
            $gmc_product->setContentLanguage( substr( $language, 0, 2 ) );
            $gmc_product->setTargetCountry( $country );
            $gmc_product->setChannel( 'online' );
            $gmc_product->setAvailability( $product->is_in_stock() ? 'in stock' : 'out of stock' );

            $price_obj = new Google\Service\ShoppingContent\Price();
            $price_obj->setValue( $product->get_price() );
            $price_obj->setCurrency( get_woocommerce_currency() );
            $gmc_product->setPrice( $price_obj );

            $entry->setProduct( $gmc_product );
            $batch_entries[] = $entry;
        }

        // 3. Send Batch Request
        if ( ! empty( $batch_entries ) ) {
            try {
                $batch_req = new Google\Service\ShoppingContent\ProductsCustomBatchRequest();
                $batch_req->setEntries( $batch_entries );
                
                $response = $service->products->custombatch( $batch_req );
                
                // Optional: Check $response->getEntries() for individual errors if you want detailed logging
                if ( $response ) {
                    $this->log_global_sync_success();
                }

            } catch ( Exception $e ) {
                $this->log_global_sync_failure( 'Batch API Exception: ' . $e->getMessage() );
                // If failed, maybe don't remove from queue? For now, we assume we remove them to prevent loops.
            }
        }

        // 4. Update Queue (Save remaining items if any)
        if ( ! empty( $queue ) ) {
            update_option( self::QUEUE_OPTION, $queue, false );
            // Schedule next run immediately to finish remaining items
            wp_schedule_single_event( time() + 5, self::CRON_HOOK );
        } else {
            delete_option( self::QUEUE_OPTION );
        }
    }

    private function log_global_sync_failure( $message ) {
        update_option( 'cirrusly_gmc_global_sync_error', array( 'time' => time(), 'message' => $message ), false );
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
            $msg = sprintf( '<strong>Cirrusly Commerce Warning:</strong> Batch sync failed. Last Error: <code>%s</code>', esc_html( $error_data['message'] ) );
            echo '<div class="notice notice-error is-dismissible"><p>' . wp_kses_post( $msg ) . '</p></div>';
        }
    }
}