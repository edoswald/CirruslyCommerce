<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cirrusly_Commerce_GMC_Pro {

    /**
     * Initialize Pro features: AJAX endpoints and Save hooks.
     */
    public function __construct() {
        // AJAX Endpoints for Promotions (API)
        add_action( 'wp_ajax_cirrusly_list_promos_gmc', array( $this, 'handle_promo_api_list' ) );
        add_action( 'wp_ajax_cirrusly_submit_promo_to_gmc', array( $this, 'handle_promo_api_submit' ) );

        // Automation Hooks
        add_action( 'save_post_product', array( $this, 'check_compliance_on_save' ), 10, 3 );
        add_filter( 'wp_insert_post_data', array( $this, 'handle_auto_strip_on_save' ), 10, 2 );
    }

    /**
     * Retrieve product-level issues reported by the Google Content API for the configured merchant.
     * Uses service worker API instead of direct Google SDK calls.
     */
    public static function fetch_google_real_statuses() {
        // Relies on the centralized API Client
        if ( ! class_exists( 'Cirrusly_Commerce_Google_API_Client' ) ) {
            return array();
        }
        
        // Call service worker to scan GMC issues
        $result = Cirrusly_Commerce_Google_API_Client::request( 'gmc_scan', array() );
        if ( is_wp_error( $result ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'Cirrusly Commerce: Failed to fetch product statuses - ' . $result->get_error_message() );
            }
            return array();
        }

        $google_issues = array();
        $products = isset( $result['products'] ) ? $result['products'] : array();

        if ( ! empty( $products ) ) {
            foreach ( $products as $product ) {
                // Get WooCommerce product ID
                $wc_id = isset( $product['wcProductId'] ) ? $product['wcProductId'] : '';
                
                if ( ! is_numeric( $wc_id ) ) {
                    continue;
                }

                // Check for Item Level Issues
                $issues = isset( $product['issues'] ) ? $product['issues'] : array();
                if ( ! empty( $issues ) ) {
                    // Ensure the array for this product ID exists
                    if ( ! isset( $google_issues[ $wc_id ] ) ) {
                        $google_issues[ $wc_id ] = array();
                    }

                    foreach ( $issues as $issue ) {
                        $msg = '[Google API] ' . (isset( $issue['description'] ) ? $issue['description'] : '');
                        
                        // Prevent duplicates
                        $already_exists = false;
                        foreach ( $google_issues[ $wc_id ] as $existing_issue ) {
                            if ( $existing_issue['msg'] === $msg ) {
                                $already_exists = true;
                                break;
                            }
                        }

                        if ( ! $already_exists ) {
                            $google_issues[ $wc_id ][] = array(
                                'msg'    => $msg,
                                'reason' => isset( $issue['detail'] ) ? $issue['detail'] : '',
                                'type'   => (isset( $issue['severity'] ) && 'critical' === strtolower( $issue['severity'] )) ? 'critical' : 'warning'
                            );
                        }
                    }
                }
            }
        }

        return $google_issues;
    }

    /**
     * Retrieve account-level status information (policy issues and suspensions) from the Google Content API.
     * Uses service worker API instead of direct Google SDK calls.
     */
    public static function fetch_google_account_issues() {
        if ( ! class_exists( 'Cirrusly_Commerce_Google_API_Client' ) ) {
            return new WP_Error( 'missing_client_class', 'API Client class missing.' );
        }
        
        // Call service worker to fetch account status (correct action name)
        $result = Cirrusly_Commerce_Google_API_Client::request( 'fetch_account_status', array() );
        if ( is_wp_error( $result ) ) {
            return $result; 
        }

        // Return the account status object from service worker
        return $result;
    }

    /**
     * List promotions from Google Merchant Center and emit a JSON AJAX response.
     */
    public function handle_promo_api_list() {
        check_ajax_referer( 'cirrusly_promo_api_list', '_nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Insufficient permissions.' );
        }
        
        // Double check Pro status (though this class shouldn't load otherwise)
        if ( ! Cirrusly_Commerce_Core::cirrusly_is_pro() ) {
            wp_send_json_error( 'Pro version required.' );
        }
        
        // CACHE CHECK
        $force = isset($_POST['force_refresh']) && $_POST['force_refresh'] == '1';
        $cache = get_transient( 'cirrusly_gmc_promos_cache' );
        
        if ( ! $force && false !== $cache ) {
            wp_send_json_success( $cache );
            return;
        }

        if ( ! class_exists( 'Cirrusly_Commerce_Google_API_Client' ) ) {
            wp_send_json_error( 'Google API Client missing.' );
        }

        // Call service worker to list promotions (correct action name)
        $result = Cirrusly_Commerce_Google_API_Client::request( 'promo_list', array() );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        $list = isset( $result['promotions'] ) ? $result['promotions'] : array();

        try {
            $output = array();
            if ( ! empty( $list ) ) {
                foreach ( $list as $p ) {
                    // Parse Date Range from service worker response
                    $range_str = '';
                    $end_timestamp = 0;
                    
                    $period = isset( $p['dates'] ) ? $p['dates'] : null;
                    if ( $period ) {
                        $start_iso = isset( $period['start'] ) ? $period['start'] : null;
                        $end_iso   = isset( $period['end'] ) ? $period['end'] : null;
                        
                        if ( $start_iso && $end_iso ) {
                            $start = substr( $start_iso, 0, 10 );
                            $end   = substr( $end_iso, 0, 10 );
                            $range_str = $start . '/' . $end;
                            $end_timestamp = strtotime( $end_iso );
                        }
                    }
                    
                    // Status Logic
                    $status = isset( $p['status'] ) ? $p['status'] : 'unknown';

                    $output[] = array(
                        'id'    => isset( $p['id'] ) ? $p['id'] : '',
                        'title' => isset( $p['title'] ) ? $p['title'] : '',
                        'dates' => $range_str,
                        'app'   => isset( $p['applicability'] ) ? $p['applicability'] : '',
                        'type'  => isset( $p['type'] ) ? $p['type'] : '',
                        'code'  => isset( $p['code'] ) ? $p['code'] : '',
                        'status'=> $status
                    );
                }
            }
            
            set_transient( 'cirrusly_gmc_promos_cache', $output, 1 * HOUR_IN_SECONDS );
            wp_send_json_success( $output );

        } catch ( Exception $e ) {
            wp_send_json_error( 'Error processing promotions: ' . $e->getMessage() );
        }
    }

    /**
     * Handle an AJAX request to create and submit a Promotion to the Google Shopping Content API.
     */
    public function handle_promo_api_submit() {
        check_ajax_referer( 'cirrusly_promo_api_submit', '_nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Insufficient permissions.' );
        }
        
        if ( ! Cirrusly_Commerce_Core::cirrusly_is_pro() ) {
            wp_send_json_error( 'Pro version required for API access.' );
        }

        if ( ! class_exists( 'Cirrusly_Commerce_Google_API_Client' ) ) {
            wp_send_json_error( 'Google API Client missing.' );
        }

        $scan_config = get_option( 'cirrusly_scan_config' );

        // Extract and Sanitize POST data using custom prefix
        $raw_data = isset( $_POST['cirrusly_promo_data'] ) ? wp_unslash( $_POST['cirrusly_promo_data'] ) : array();
        
        // Sanitize the whole array at once since we expect text fields
        $data  = is_array( $raw_data ) ? array_map( 'sanitize_text_field', $raw_data ) : array();

        $id    = isset( $data['id'] ) ? $data['id'] : '';
        $title = isset( $data['title'] ) ? $data['title'] : '';

        if ( '' === $id || '' === $title ) {
            wp_send_json_error( 'Promotion ID and Title are required.' );
        }

        try {
            // Build promotion data for service worker using correct field names
            $promo_payload = array(
                'id'            => $id,
                'title'         => $title,
                'content_lang'  => isset( $scan_config['content_language'] ) ? $scan_config['content_language'] : substr( get_locale(), 0, 2 ),
                'target_country' => isset( $scan_config['target_country'] ) ? $scan_config['target_country'] : WC()->countries->get_base_country(),
                'applicability' => isset( $data['app'] ) ? $data['app'] : 'ALL_PRODUCTS',
                'offer_type'    => isset( $data['type'] ) ? $data['type'] : 'NO_CODE',
            );

            // 1. Parse Dates (Format: YYYY-MM-DD/YYYY-MM-DD)
            $dates_raw = isset( $data['dates'] ) ? $data['dates'] : '';
            if ( ! empty( $dates_raw ) && strpos( $dates_raw, '/' ) !== false ) {
                list( $start_str, $end_str ) = explode( '/', $dates_raw );
                
                if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_str ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_str ) ) {
                  wp_send_json_error( 'Invalid date format. Expected YYYY-MM-DD/YYYY-MM-DD.' );
                }
                
                // Calculate UTC times
                $tz_string = get_option( 'timezone_string' );
                if ( ! $tz_string ) {
                    $offset  = get_option( 'gmt_offset' );
                    $hours   = (int) $offset;
                    $minutes = abs( ( $offset - $hours ) * 60 );
                    $tz_string = sprintf( '%+03d:%02d', $hours, $minutes );
                }

                try {
                    $site_tz = new DateTimeZone( $tz_string );
                } catch ( Exception $e ) {
                    $site_tz = new DateTimeZone( 'UTC' );
                }
                $utc_tz = new DateTimeZone( 'UTC' );

                $dt_start = new DateTime( $start_str . ' 00:00:00', $site_tz );
                $dt_end   = new DateTime( $end_str . ' 23:59:59', $site_tz );

                $dt_start->setTimezone( $utc_tz );
                $dt_end->setTimezone( $utc_tz );

                $promo_payload['dates'] = array(
                    'start' => $dt_start->format( 'Y-m-d\TH:i:s\Z' ),
                    'end'   => $dt_end->format( 'Y-m-d\TH:i:s\Z' ),
                );
            }

            // 2. Generic Code
            if ( 'GENERIC_CODE' === $promo_payload['offer_type'] ) {
                if ( empty( $data['code'] ) ) {
                    wp_send_json_error( 'Redemption code is required for GENERIC_CODE promotions.' );
                }
                $promo_payload['generic_code'] = $data['code'];
            }

            // Send to service worker (correct action name)
            $result = Cirrusly_Commerce_Google_API_Client::request( 'submit_promotion', $promo_payload );
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( 'Service Error: ' . $result->get_error_message() );
            }

            // Clear Cache
            delete_transient( 'cirrusly_gmc_promos_cache' );

            wp_send_json_success( 'Promotion submitted successfully!' );
        } catch ( Exception $e ) {
            wp_send_json_error( 'Error: ' . $e->getMessage() );
        }
    }

    /**
     * Prevents publishing of products that contain monitored medical terms marked as "Critical".
     * Uses NLP verification if configured.
     */
    public function check_compliance_on_save( $post_id, $post, $update ) {
        if ( ! Cirrusly_Commerce_Core::cirrusly_is_pro() ) {
            return;
        }

        unset( $update );

        $scan_cfg = get_option('cirrusly_scan_config', array());
        if ( empty($scan_cfg['block_on_critical']) || $scan_cfg['block_on_critical'] !== 'yes' ) return;

        if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
        if ( $post->post_type !== 'product' ) return;

        // Uses static method from the main GMC class for term definitions
        $monitored = Cirrusly_Commerce_GMC::get_monitored_terms();
        $violation_found = false;

        $title_clean = wp_strip_all_tags( $post->post_title );
        $desc_clean  = wp_strip_all_tags( $post->post_content );

        // Scan all categories (Medical + Misrepresentation)
        foreach( $monitored as $cat => $terms ) {
            foreach( $terms as $word => $rules ) {
                 if ( ! isset( $rules['severity'] ) || 'Critical' !== $rules['severity'] ) {
                     continue;
                 }

                 $pattern = '/\b' . preg_quote( $word, '/' ) . '\b/iu';
                 
                 $check_title = preg_match( $pattern, $title_clean );
                 $check_desc  = ( isset( $rules['scope'] ) && 'all' === $rules['scope'] ) ? preg_match( $pattern, $desc_clean ) : false;

                 if ( $check_title || $check_desc ) {
                     $violation_found = true;
                     break 2; // Break both loops
                 }
            }
        }

        // --- NLP INTEGRATION (Blocker) ---
        if ( ! $violation_found && isset( $scan_cfg['enable_nlp_guard'] ) && 'yes' === $scan_cfg['enable_nlp_guard'] ) {
             $nlp_res = $this->analyze_text_with_nlp( $title_clean . ' ' . substr($desc_clean, 0, 500), $post_id );
             if ( ! is_wp_error( $nlp_res ) ) {
                 $entities = isset( $nlp_res['entities'] ) ? $nlp_res['entities'] : array();
                 foreach ( $entities as $entity ) {
                     // Check for restricted entity types 
                     $entity_type = isset( $entity['type'] ) ? $entity['type'] : '';
                     if ( 'EVENT' === $entity_type || 'OTHER' === $entity_type ) {
                         $e_name = strtolower( isset( $entity['name'] ) ? $entity['name'] : '' );
                         if ( strpos( $e_name, 'virus' ) !== false || strpos( $e_name, 'covid' ) !== false ) {
                             $violation_found = true;
                             break;
                         }
                     }
                 }
             }
        }

        $original_status = get_post_field( 'post_status', $post_id, 'raw' );
        if ( $violation_found && in_array( $original_status, array( 'publish', 'pending', 'future' ) ) ) {
            remove_action( 'save_post_product', array( $this, 'check_compliance_on_save' ), 10 );
            wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
            if ( $original_status !== 'draft' ) {
                set_transient( 'cirrusly_gmc_blocked_save_' . get_current_user_id(), 'Product reverted to Draft due to restricted terms.', 30 );
            }
            add_action( 'save_post_product', array( $this, 'check_compliance_on_save' ), 10, 3 );
        }
    }

    /**
     * Remove configured banned medical terms from a product's title and content during save.
     */
    public function handle_auto_strip_on_save( $data, $postarr ) {
        $scan_cfg = get_option('cirrusly_scan_config', array());
        if ( empty($scan_cfg['auto_strip_banned']) || $scan_cfg['auto_strip_banned'] !== 'yes' ) return $data;
        if ( $data['post_type'] !== 'product' ) return $data;

        $monitored = Cirrusly_Commerce_GMC::get_monitored_terms();
        $banned_words = array();

        foreach( $monitored as $cat => $terms ) {
            foreach( $terms as $word => $rules ) {
                if ( isset( $rules['severity'] ) && 'Critical' === $rules['severity'] ) {
                    $banned_words[] = $word;
                }
            }
        }

        if ( empty( $banned_words ) ) return $data;

        foreach ( $banned_words as $word ) {
            $pattern = '/\b' . preg_quote( $word, '/' ) . '\b/ui';
            $data['post_title'] = preg_replace( $pattern, '', $data['post_title'] );
            $data['post_content'] = preg_replace( $pattern, '', $data['post_content'] );
        }

        return $data;
    }

    /**
     * Analyze text with Google Cloud Natural Language API via service worker.
     */
    private function analyze_text_with_nlp( $text, $post_id ) {
        if ( ! class_exists( 'Cirrusly_Commerce_Google_API_Client' ) ) {
            return new WP_Error( 'client_missing', 'Google API Client not available.' );
        }

        // Call service worker for NLP analysis
        $result = Cirrusly_Commerce_Google_API_Client::request( 'nlp_analyze', array( 'text' => $text ) );
        return $result;
    }
}
