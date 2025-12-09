<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Cirrusly_Commerce_Pricing_Sync {

    const QUEUE_OPTION = 'cirrusly_gmc_sync_queue';
    const CRON_HOOK    = 'cirrusly_gmc_process_queue';
    const MAX_RETRIES  = 3;

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
     * Use structured array format for retry tracking.
     *
     * @param int $product_id
     */
    public function add_to_queue( $product_id ) {
        global $wpdb;
        $wpdb->query( 'START TRANSACTION' );
        
        $queue = get_option( self::QUEUE_OPTION, array() );
        
        // Check existence (handling both old scalar IDs and new array format)
        $exists = false;
        foreach ( $queue as $item ) {
            $id = is_array( $item ) ? $item['id'] : $item;
            if ( $id == $product_id ) {
                $exists = true;
                break;
            }
        }
        
        if ( ! $exists ) {
            // Push structured entry
            $queue[] = array( 'id' => (int) $product_id, 'attempts' => 0 );
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
     * Handles retries and queue normalization.
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
        
        // 2. Build Batch Entries & Normalize Queue
        // Process max 500 items at a time
        $chunk = array_splice( $queue, 0, 500 ); 
        
        // Map to track attempt counts for items in this batch
        $processing_items = array();

        foreach ( $chunk as $q_item ) {
            // Normalize scalar IDs to array structure
            if ( ! is_array( $q_item ) ) {
                $q_item = array( 'id' => (int) $q_item, 'attempts' => 0 );
            }
            
            // Store for retry logic
            $processing_items[ $q_item['id'] ] = $q_item;

            $product = wc_get_product( $q_item['id'] );
            if ( ! $product ) continue;

            $entry = new Google\Service\ShoppingContent\ProductsCustomBatchRequestEntry();
            $entry->setBatchId( $q_item['id'] ); // Use Product ID as Batch ID for tracking
            $entry->setMerchantId( $merchant_id );
            $entry->setMethod( 'insert' );

            // Build Product Object
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

        $requeue_items = array();

        // 3. Send Batch Request
        if ( ! empty( $batch_entries ) ) {
            try {
                $batch_req = new Google\Service\ShoppingContent\ProductsCustomBatchRequest();
                $batch_req->setEntries( $batch_entries );
                
                $response = $service->products->custombatch( $batch_req );
                
                foreach ( $response->getEntries() as $entry ) {
                    $errors = $entry->getErrors();
                    if ( $errors && ! empty( $errors->getErrors() ) ) {
                        $bid = $entry->getBatchId();
                        
                        // Handle Retry
                        if ( isset( $processing_items[ $bid ] ) ) {
                            $item = $processing_items[ $bid ];
                            $item['attempts']++;
                            
                            if ( $item['attempts'] < self::MAX_RETRIES ) {
                                $requeue_items[] = $item;
                            } else {
                                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                                    error_log( 'Cirrusly GMC Sync: Dropping product ' . $bid . ' after ' . self::MAX_RETRIES . ' failures.' );
                                }
                            }
                        }
                    }
                }
                
                if ( empty( $requeue_items ) ) {
                    $this->log_global_sync_success();
                } else {
                    $this->log_global_sync_failure( count( $requeue_items ) . ' products failed and will be retried.' );
                }

            } catch ( Exception $e ) {
                $this->log_global_sync_failure( 'Batch API Exception: ' . $e->getMessage() );
                
                // Requeue the entire chunk on API failure
                foreach ( $processing_items as $item ) {
                    $item['attempts']++;
                    if ( $item['attempts'] < self::MAX_RETRIES ) {
                        $requeue_items[] = $item;
                    }
                }
            }
        }

        // 4. Update Queue (Merge retries back into queue)
        if ( ! empty( $requeue_items ) ) {
            $queue = array_merge( $queue, $requeue_items );
        }

        if ( ! empty( $queue ) ) {
            update_option( self::QUEUE_OPTION, $queue, false );
            // Schedule next run immediately if items remain
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