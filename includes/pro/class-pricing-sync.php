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
        $queue = get_option( self::QUEUE_OPTION, array() );
        if ( ! is_array( $queue ) ) {
            $queue = array();
        }
        
        // Check existence (handling both old scalar IDs and new array format)
        $exists = false;
        foreach ( $queue as $item ) {
            $id = is_array( $item ) && isset( $item['id'] ) ? (int) $item['id'] : (int) $item;
            if ( $id === (int) $product_id ) {
                $exists = true;
                break;
            }
        }
        
        if ( ! $exists ) {
            // Push structured entry
            $queue[] = array(
                'id'       => (int) $product_id,
                'attempts' => 0,
            );
            update_option( self::QUEUE_OPTION, $queue, false );
        }

        // Schedule the runner if it isn't already scheduled
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            // Run 30 seconds from now to allow for more bulk edits to pile up
            wp_schedule_single_event( time() + 30, self::CRON_HOOK );
        }
    }

    /**
     * Background Worker: Fetch queue and send Batch Request to Google via Worker API.
     * Handles retries and queue normalization.
     */
    public function process_batch_queue() {
        $queue = get_option( self::QUEUE_OPTION, array() );
        if ( empty( $queue ) || ! is_array( $queue ) ) return;

         if ( ! function_exists( 'wc_get_product' ) || ! function_exists( 'WC' ) || ! WC() ) {
            $this->log_global_sync_failure( 'WooCommerce is not fully initialized.' );
            return;
        }

        // 1. Check for API Client
        if ( ! class_exists( 'Cirrusly_Commerce_Google_API_Client' ) ) {
            $this->log_global_sync_failure( 'GMC API Client class not loaded.' );
            return;
        }
        
        // 2. Build Batch Entries & Normalize Queue
        // Process max 500 items at a time
        $chunk = array_splice( $queue, 0, 500 ); 
        
        // Map to track attempt counts for items in this batch
        $processing_items = array();
        $batch_entries    = array();

        // Base country is store-level; compute once per run.
        $base_country = apply_filters(
            'cirrusly_gmc_target_country',
            WC()->countries->get_base_country()
        );

        foreach ( $chunk as $q_item ) {
            // Normalize scalar IDs to array structure
            if ( ! is_array( $q_item ) ) {
                $q_item = array( 'id' => (int) $q_item, 'attempts' => 0 );
            }
            
            $product = wc_get_product( $q_item['id'] );
            if ( ! $product ) continue; 

            // Build Raw Data Array for Worker
            $offer_id = $product->get_sku() ?: $product->get_id();
            $language = apply_filters( 'cirrusly_gmc_content_language', get_bloginfo( 'language' ) );
            $country  = $base_country;
            $price    = wc_format_decimal( $product->get_price() );

            if ( ! is_numeric( $price ) || $price < 0 ) {
                continue;
            }

            // Store for retry logic
            $processing_items[ $q_item['id'] ] = $q_item;

            
            $batch_entries[] = array(
                'batchId'      => $q_item['id'], // Use Product ID as Batch ID for tracking
                'offerId'      => (string) $offer_id,
                'language'     => substr( $language, 0, 2 ),
                'country'      => $country,
                'availability' => $product->is_in_stock() ? 'in stock' : 'out of stock',
                'price'        => $price,
                'currency'     => get_woocommerce_currency()
            );
        }

        $requeue_items = array();

        // 3. Send Batch Request via Proxy
        if ( ! empty( $batch_entries ) ) {
            
            $response = Cirrusly_Commerce_Google_API_Client::request( 'batch_sync', array( 'entries' => $batch_entries ) );

            if ( is_wp_error( $response ) ) {
                $this->log_global_sync_failure( 'API Error: ' . $response->get_error_message() );
                // On total API failure, retry entire chunk
                foreach ( $processing_items as $item ) {
                    $item['attempts']++;
                    if ( $item['attempts'] < self::MAX_RETRIES ) {
                        $requeue_items[] = $item;
                    }
                }
            } else {
                // Process Worker Results
                $results = isset( $response['results'] ) ? $response['results'] : array();
                $has_errors = false;

                foreach ( $results as $batch_id => $res ) {
                    if ( isset( $res['status'] ) && $res['status'] === 'error' ) {
                        $has_errors = true;
                        // Retry specific item
                        if ( isset( $processing_items[ $batch_id ] ) ) {
                            $item = $processing_items[ $batch_id ];
                            $item['attempts']++;
                            if ( $item['attempts'] < self::MAX_RETRIES ) {
                                $requeue_items[] = $item;
                            }
                        }
                    }
                }

                if ( ! $has_errors && empty( $requeue_items ) ) {
                    $this->log_global_sync_success();
                } elseif ( ! empty( $requeue_items ) ) {
                    $this->log_global_sync_failure( count( $requeue_items ) . ' items failed and will be retried.' );
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
            if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
                wp_schedule_single_event( time() + 5, self::CRON_HOOK );
            }
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
            $msg = sprintf(
                '<strong>%s</strong> %s <code>%s</code>',
                esc_html__( 'Cirrusly Commerce Warning:', 'cirrusly-commerce' ),
                esc_html__( 'Batch sync failed. Last Error:', 'cirrusly-commerce' ),
                esc_html( $error_data['message'] )
            );
            echo '<div class="notice notice-error is-dismissible"><p>' . wp_kses_post( $msg ) . '</p></div>';
        }
    }
}