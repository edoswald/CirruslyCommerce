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
        add_action( 'wp_ajax_cc_list_promos_gmc', array( $this, 'handle_promo_api_list' ) );
        add_action( 'wp_ajax_cc_submit_promo_to_gmc', array( $this, 'handle_promo_api_submit' ) );

        // Automation Hooks
        add_action( 'save_post_product', array( $this, 'check_compliance_on_save' ), 10, 3 );
        add_filter( 'wp_insert_post_data', array( $this, 'handle_auto_strip_on_save' ), 10, 2 );
    }

    /**
     * Fetch actual product statuses from Google Content API.
     * * @return array Associative array of Product ID => Array of issues.
     */
    public static function fetch_google_real_statuses() {
        // Relies on the centralized API Client
        if ( ! class_exists( 'Cirrusly_Commerce_Google_API_Client' ) ) {
            return array();
        }
        
        $client = Cirrusly_Commerce_Google_API_Client::get_client();
        if ( is_wp_error( $client ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'Cirrusly Commerce: Failed to get Google client - ' . $client->get_error_message() );
            }
            return array();
        }

        // 2. Get Merchant ID
        $scan_config = get_option( 'cirrusly_scan_config', array() );
        $merchant_id = isset( $scan_config['merchant_id_pro'] ) ? $scan_config['merchant_id_pro'] : get_option( 'cirrusly_gmc_merchant_id', '' );
        
        if ( empty( $merchant_id ) ) {
            return array();
        }

        $service = new Google\Service\ShoppingContent( $client );
        $google_issues = array();

        try {
            // Fetch statuses in batch (page size 100 is standard max)
            $params = array( 'maxResults' => 100 );
            $pageToken = null;

            do {
                if ( $pageToken ) {
                    $params['pageToken'] = $pageToken;
                }

                $statuses = $service->productstatuses->listProductstatuses( $merchant_id, $params );

                foreach ( $statuses->getResources() as $status ) {
                    // Google ID format is usually "online:en:US:123" -> We need "123"
                    $parts = explode( ':', $status->getProductId() );
                    $wc_id = end( $parts );
                    
                    // Validate this is a numeric ID that could be a WC product
                    if ( ! is_numeric( $wc_id ) ) {
                        continue;
                    } 

                    // Check for Item Level Issues (The "Why" it is disapproved)
                    $issues = $status->getItemLevelIssues();
                    if ( ! empty( $issues ) ) {
                        // Ensure the array for this product ID exists
                        if ( ! isset( $google_issues[ $wc_id ] ) ) {
                            $google_issues[ $wc_id ] = array();
                        }

                        foreach ( $issues as $issue ) {
                            $msg = '[Google API] ' . $issue->getDescription();
                            
                            // Prevent duplicates (e.g., same error for multiple target countries)
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
                                    'reason' => $issue->getDetail(),
                                    'type'   => ($issue->getServability() === 'disapproved') ? 'critical' : 'warning'
                                );
                            }
                        }
                    }
                }

                // Check for next page
                $pageToken = $statuses->getNextPageToken();

            } while ( null !== $pageToken );

        } catch ( Exception $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'Cirrusly Commerce: Google API error in fetch_google_real_statuses - ' . $e->getMessage() );
            }
        }

        return $google_issues;
    }

    /**
     * Fetch account-level issues (Policy/Suspensions) from Google Content API.
     * * @return Google_Service_ShoppingContent_AccountStatus|WP_Error
     */
    public static function fetch_google_account_issues() {
        if ( ! class_exists( 'Cirrusly_Commerce_Google_API_Client' ) ) {
            return new WP_Error( 'missing_client_class', 'API Client class missing.' );
        }
        
        $client = Cirrusly_Commerce_Google_API_Client::get_client();
        if ( is_wp_error( $client ) ) {
            return $client; 
        }

        $scan_config = get_option( 'cirrusly_scan_config' );
        
        // 1. Get Merchant ID (Aggregator/Auth Scope)
        $merchant_id = isset( $scan_config['merchant_id_pro'] ) ? $scan_config['merchant_id_pro'] : get_option( 'cirrusly_gmc_merchant_id', '' );
        if ( empty( $merchant_id ) ) {
            return new WP_Error( 'missing_merchant_id', 'Merchant ID not configured in settings.' );
        }

        // 2. Get Account ID (The specific account to query)
        $account_id = isset( $scan_config['account_id'] ) ? $scan_config['account_id'] : '';
        
        // Fallback for single accounts: use merchant_id if account_id is not explicitly set
        if ( empty( $account_id ) ) {
            $account_id = $merchant_id; 
        }

        if ( empty( $account_id ) ) {
            return new WP_Error( 'missing_account_id', 'Target Account ID not configured.' );
        }

        $service = new Google\Service\ShoppingContent( $client );
        
        try {
            return $service->accountstatuses->get( $merchant_id, $account_id );
        } catch ( Exception $e ) {
            return new WP_Error( 'api_error', 'Google API Error: ' . $e->getMessage() );
        }
    }

    /**
     * AJAX Handler to list promotions from Google Merchant Center.
     */
    public function handle_promo_api_list() {
        check_ajax_referer( 'cc_promo_api_list', 'security' );

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

        $client = Cirrusly_Commerce_Google_API_Client::get_client();
        if ( is_wp_error( $client ) ) {
            wp_send_json_error( $client->get_error_message() );
        }

        // Get Merchant ID from Settings
        $scan_config = get_option( 'cirrusly_scan_config' );
        $merchant_id = isset( $scan_config['merchant_id_pro'] ) ? $scan_config['merchant_id_pro'] : '';
        
        // Fallback for legacy installs
        if ( empty( $merchant_id ) ) {
            $merchant_id = get_option( 'cirrusly_gmc_merchant_id', '' );
        }

        if ( empty( $merchant_id ) ) {
            wp_send_json_error( 'Merchant ID missing.' );
        }

        $service = new Google\Service\ShoppingContent( $client );

        try {
            $resp = $service->promotions->listPromotions( $merchant_id, array('pageSize' => 50) );
            $list = $resp->getPromotions();
            
            $output = array();
            if ( ! empty( $list ) ) {
                foreach ( $list as $p ) {
                    // Parse Date Range
                    $range_str = '';
                    $end_timestamp = 0;
                    
                    $period = $p->getPromotionEffectiveTimePeriod();
                    if ( $period ) {
                        $start_iso = $period->getStartTime(); 
                        $end_iso   = $period->getEndTime();
                        
                        if ( $start_iso && $end_iso ) {
                            $start = substr( $start_iso, 0, 10 );
                            $end   = substr( $end_iso, 0, 10 );
                            $range_str = $start . '/' . $end;
                            $end_timestamp = strtotime( $end_iso );
                        }
                    }
                    
                    // Status Logic
                    $status = 'unknown';
                    $pStats = $p->getPromotionStatus(); 
                                         
                    if ( $pStats && method_exists( $pStats, 'getDestinationStatuses' ) ) {
                        $d_statuses = $pStats->getDestinationStatuses();
                        $is_rejected = false;
                        $is_expired  = false;
                        $is_live     = false;
                        $is_pending  = false;
                        
                        $found_statuses = array();

                        if ( ! empty( $d_statuses ) ) {
                            foreach ( $d_statuses as $ds ) {
                                $s = strtoupper( $ds->getStatus() );
                                $found_statuses[] = $s;

                                if ( 'REJECTED' === $s || 'DISAPPROVED' === $s ) $is_rejected = true;
                                if ( 'EXPIRED' === $s ) $is_expired = true;
                                if ( 'LIVE' === $s || 'APPROVED' === $s || 'ACTIVE' === $s ) $is_live = true;
                                if ( 'PENDING' === $s || 'IN_REVIEW' === $s || 'READY_FOR_REVIEW' === $s ) $is_pending = true;
                            }

                            if ( $is_rejected ) {
                                $status = 'rejected';
                            } elseif ( $is_pending ) {
                                $status = 'pending';
                            } elseif ( $is_live ) {
                                $status = 'active';
                            } elseif ( $is_expired ) {
                                $status = 'expired';
                            } else {
                                if ( !empty($found_statuses) ) {
                                    $status = strtolower($found_statuses[0]);
                                }
                            }
                        }
                    }

                    if ( 'unknown' === $status ) {
                        if ( $end_timestamp > 0 && $end_timestamp < time() ) {
                            $status = 'expired';
                        } else {
                            $status = 'pending';
                        }
                    }

                    $output[] = array(
                        'id'    => $p->getPromotionId(),
                        'title' => $p->getLongTitle(),
                        'dates' => $range_str,
                        'app'   => $p->getProductApplicability(),
                        'type'  => $p->getOfferType(),
                        'code'  => $p->getGenericRedemptionCode(),
                        'status'=> $status
                    );
                }
            }
            
            set_transient( 'cirrusly_gmc_promos_cache', $output, 1 * HOUR_IN_SECONDS );
            wp_send_json_success( $output );

        } catch ( Exception $e ) {
            wp_send_json_error( 'Google API Error: ' . $e->getMessage() );
        }
    }

    /**
     * Handles an AJAX request to submit a Promotion to the Google Shopping Content API.
     */
    public function handle_promo_api_submit() {
        check_ajax_referer( 'cc_promo_api_submit', 'security' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Insufficient permissions.' );
        }
        
        if ( ! Cirrusly_Commerce_Core::cirrusly_is_pro() ) {
            wp_send_json_error( 'Pro version required for API access.' );
        }

        if ( ! class_exists( 'Cirrusly_Commerce_Google_API_Client' ) ) {
            wp_send_json_error( 'Google API Client missing.' );
        }

        $client = Cirrusly_Commerce_Google_API_Client::get_client();
        if ( is_wp_error( $client ) ) {
            wp_send_json_error( $client->get_error_message() );
        }

        $scan_config = get_option( 'cirrusly_scan_config' );
        $merchant_id = isset( $scan_config['merchant_id_pro'] ) ? $scan_config['merchant_id_pro'] : '';
        
        if ( empty( $merchant_id ) ) {
            $merchant_id = get_option( 'cirrusly_gmc_merchant_id', '' );
        }

        if ( empty( $merchant_id ) ) {
            wp_send_json_error( 'Merchant ID not configured.' );
        }

        // Extract POST data
        $data = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : array();
        $data = is_array( $data ) ? $data : array();
        $id    = isset( $data['id'] ) ? sanitize_text_field( $data['id'] ) : '';
        $title = isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '';

        if ( '' === $id || '' === $title ) {
            wp_send_json_error( 'Promotion ID and Title are required.' );
        }
        
        $service = new Google\Service\ShoppingContent( $client );

        try {
            $promo = new Google\Service\ShoppingContent\Promotion();
            $promo->setPromotionId( $id );
            $promo->setLongTitle( $title );
            $content_lang = isset( $scan_config['content_language'] ) ? $scan_config['content_language'] : substr( get_locale(), 0, 2 );
            $target_country = isset( $scan_config['target_country'] ) ? $scan_config['target_country'] : WC()->countries->get_base_country();
            $promo->setContentLanguage( $content_lang );
            $promo->setTargetCountry( $target_country );
            $promo->setRedemptionChannel( array( 'ONLINE' ) );

            // 1. Parse Dates (Format: YYYY-MM-DD/YYYY-MM-DD)
            $dates_raw = isset( $data['dates'] ) ? sanitize_text_field( $data['dates'] ) : '';
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

                $period = new Google\Service\ShoppingContent\TimePeriod();
                $period->setStartTime( $dt_start->format( 'Y-m-d\TH:i:s\Z' ) );
                $period->setEndTime( $dt_end->format( 'Y-m-d\TH:i:s\Z' ) );
                $promo->setPromotionEffectiveTimePeriod( $period );
            }

            // 2. Product Applicability
            $app_val = isset( $data['app'] ) ? sanitize_text_field( $data['app'] ) : 'ALL_PRODUCTS';
            $promo->setProductApplicability( $app_val );

            // 3. Offer Type & Generic Code
            $type_val = isset( $data['type'] ) ? sanitize_text_field( $data['type'] ) : 'NO_CODE';
            $promo->setOfferType( $type_val );
            
            if ( 'GENERIC_CODE' === $type_val && ! empty( $data['code'] ) ) {
                $promo->setGenericRedemptionCode( sanitize_text_field( $data['code'] ) );
            }

            // Send to Google
            $service->promotions->create( $merchant_id, $promo );

            // Clear Cache
            delete_transient( 'cirrusly_gmc_promos_cache' );

            wp_send_json_success( 'Promotion submitted successfully!' );
        } catch ( Exception $e ) {
            wp_send_json_error( 'Google Error: ' . $e->getMessage() );
        }
    }

    /**
     * Prevent publishing of products that contain critical (medical) terms.
     */
    public function check_compliance_on_save( $post_id, $post, $update ) {
        // Double check Pro
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

        foreach( $monitored['medical'] as $word => $rules ) {
             if ( ! isset( $rules['severity'] ) || 'Critical' !== $rules['severity'] ) {
                 continue;
             }

             $pattern = '/\b' . preg_quote( $word, '/' ) . '\b/i';
             
             $content_to_scan = $post->post_title;
             if ( isset( $rules['scope'] ) && 'all' === $rules['scope'] ) {
                 $content_to_scan .= ' ' . wp_strip_all_tags( $post->post_content );
             }

             if ( preg_match( $pattern, $content_to_scan ) ) {
                 $violation_found = true;
                 break; 
             }
        }

        if ( $violation_found && in_array( $post->post_status, array( 'publish', 'pending', 'future' ) ) ) {
            
            // Remove hook to prevent infinite loop
            remove_action( 'save_post_product', array( $this, 'check_compliance_on_save' ), 10 );
            
            // Force status to draft
            wp_update_post( array(
                'ID'          => $post_id,
                'post_status' => 'draft'
            ) );

            set_transient( 'cc_gmc_blocked_save_' . get_current_user_id(), 'Product reverted to Draft due to restricted medical terms.', 30 );

            // Re-hook
            add_action( 'save_post_product', array( $this, 'check_compliance_on_save' ), 10, 3 );
        }
    }

    /**
     * Removes configured banned medical terms from a product's title and content on save.
     */
    public function handle_auto_strip_on_save( $data, $postarr ) {
        $scan_cfg = get_option('cirrusly_scan_config', array());
        if ( empty($scan_cfg['auto_strip_banned']) || $scan_cfg['auto_strip_banned'] !== 'yes' ) return $data;
        
        if ( $data['post_type'] !== 'product' ) return $data;

        $monitored = Cirrusly_Commerce_GMC::get_monitored_terms();
        
        foreach ( $monitored['medical'] as $word => $rules ) {
            $pattern = '/\b' . preg_quote($word, '/') . '\b/i';
            
            // Strip from Title
            if ( preg_match( $pattern, $data['post_title'] ) ) {
                $data['post_title'] = preg_replace( $pattern, '', $data['post_title'] );
                $data['post_title'] = trim( preg_replace('/\s+/', ' ', $data['post_title']) );
            }
            
            // Strip from Content
            if ( isset( $rules['scope'] ) && $rules['scope'] === 'all' && preg_match( $pattern, $data['post_content'] ) ) {
                $data['post_content'] = preg_replace( $pattern, '', $data['post_content'] );
            }
        }
        
        return $data;
    }

    /**
     * Analyze text using Google Cloud NLP API.
     * * @param string $text The content to analyze.
     * @return Google\Service\CloudNaturalLanguage\AnnotateTextResponse|WP_Error Analysis results or error.
     */
    public function analyze_text_with_nlp( $text ) {
        // Truncate to avoid API limits
        $max_length = 5000;
        if ( strlen( $text ) > $max_length ) {
            $truncated = substr( $text, 0, $max_length );
            $last_space = strrpos( $truncated, ' ' );
            $text = $last_space !== false ? substr( $truncated, 0, $last_space ) : $truncated;
        }

        if ( ! class_exists( 'Cirrusly_Commerce_Google_API_Client' ) ) {
            return new WP_Error( 'missing_client', 'Google API Client not loaded.' );
        }

        $client = Cirrusly_Commerce_Google_API_Client::get_client();
        if ( is_wp_error( $client ) ) {
            return $client;
        }

        $service = new Google\Service\CloudNaturalLanguage( $client );
        
        $document = new Google\Service\CloudNaturalLanguage\Document();
        $document->setType( 'PLAIN_TEXT' );
        $document->setContent( $text );

        $features = new Google\Service\CloudNaturalLanguage\AnnotateTextRequestFeatures();
        $features->setExtractEntities( true );

        $request = new Google\Service\CloudNaturalLanguage\AnnotateTextRequest();
        $request->setDocument( $document );
        $request->setFeatures( $features );

        try {
            return $service->documents->annotateText( $request );
        } catch ( Exception $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'Cirrusly Commerce NLP Error: ' . $e->getMessage() );
            }
            return new WP_Error( 'nlp_error', $e->getMessage() );
        }
    }
}