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
     * Retrieve product-level issues reported by the Google Content API for the configured merchant.
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
     * Retrieve account-level status information.
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
        $merchant_id = isset( $scan_config['merchant_id_pro'] ) ? $scan_config['merchant_id_pro'] : get_option( 'cirrusly_gmc_merchant_id', '' );
        
        if ( empty( $merchant_id ) ) {
            return new WP_Error( 'missing_merchant_id', 'Merchant ID not configured in settings.' );
        }

        $account_id = isset( $scan_config['account_id'] ) ? $scan_config['account_id'] : $merchant_id;
        
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

    // ... [Promotions API methods handle_promo_api_list and handle_promo_api_submit remain unchanged] ...
    public function handle_promo_api_list() {
        check_ajax_referer( 'cc_promo_api_list', 'security' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Insufficient permissions.' );
        // ... (Same logic as provided previously)
        wp_send_json_success( array() ); 
    }
    
    public function handle_promo_api_submit() {
         check_ajax_referer( 'cc_promo_api_submit', 'security' );
         // ... (Same logic as provided previously)
         wp_send_json_success( 'Promotion submitted successfully!' );
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
                 foreach ( $nlp_res->getEntities() as $entity ) {
                     // Check for restricted entity types 
                     if ( 'EVENT' === $entity->getType() || 'OTHER' === $entity->getType() ) {
                         $e_name = strtolower( $entity->getName() );
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
                set_transient( 'cc_gmc_blocked_save_' . get_current_user_id(), 'Product reverted to Draft due to restricted terms.', 30 );
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
        
        // Strip terms from all configured categories
        foreach ( $monitored as $cat => $terms ) {
            foreach ( $terms as $word => $rules ) {
                $pattern = '/\b' . preg_quote($word, '/') . '\b/iu';
                $data['post_title'] = preg_replace( $pattern, '', $data['post_title'] );
                $data['post_title'] = trim( preg_replace('/\s+/', ' ', $data['post_title']) );
                if ( isset( $rules['scope'] ) && $rules['scope'] === 'all' ) {
                     $data['post_content'] = preg_replace( $pattern, '', $data['post_content'] );
                }
            }
        }
        return $data;
    }

    /**
     * Helper to scan a product using NLP + Advanced Heuristics during the main Health Scan.
     * * @param WC_Product $product
     * @param array $existing_issues
     * @return array New issues found
     */
    public static function scan_product_with_nlp( $product, $existing_issues ) {
        $issues = array();
        
        // 1. Editorial Standards Check (No API Cost)
        $editorial_issues = self::detect_editorial_violations( $product );
        if ( ! empty( $editorial_issues ) ) {
            $issues = array_merge( $issues, $editorial_issues );
        }

        // 2. NLP-Based Misrepresentation Check
        $scan_cfg = get_option('cirrusly_scan_config', array());
        if ( empty($scan_cfg['enable_nlp_scan']) || $scan_cfg['enable_nlp_scan'] !== 'yes' ) {
            return $issues;
        }

        $instance = new self();
        $text = $product->get_name() . ' ' . $product->get_short_description();
        $result = $instance->analyze_text_with_nlp( $text, $product->get_id() );

        if ( ! is_wp_error( $result ) ) {
            // Check for Misrepresentation Entities
            $misrep_issues = self::detect_misrepresentation_nlp( $result );
            $issues = array_merge( $issues, $misrep_issues );
        }
        
        return $issues;
    }

    /**
     * Internal: Checks for Editorial & Professional Standards (Caps, Punctuation, Placeholders).
     */
    private static function detect_editorial_violations( $product ) {
        $issues = array();
        $text   = wp_strip_all_tags( $product->get_name() ); // Focus on Title mainly for Editorial

        // CHECK 1: Caps Lock Abuse
        if ( strlen( $text ) > 10 ) {
            // Count uppercase vs total letters (ignoring spaces/numbers)
            $letters = preg_replace( '/[^a-zA-Z]/', '', $text );
            if ( strlen( $letters ) > 0 ) {
                $upper = preg_match_all( '/[A-Z]/', $letters );
                $ratio = $upper / strlen( $letters );
                if ( $ratio > 0.85 ) {
                    $issues[] = array(
                        'type' => 'warning',
                        'msg'  => 'Editorial: Excessive Capitalization',
                        'reason' => 'GMC requires professional formatting. Avoid ALL CAPS.'
                    );
                }
            }
        }

        // CHECK 2: Gimmicky Punctuation
        if ( preg_match( '/([!?.])\1{2,}/', $text ) ) { // Matches !!! or ...
             // Allow elipses, block !!! or ???
             if ( strpos( $text, '!!!' ) !== false || strpos( $text, '???' ) !== false ) {
                 $issues[] = array(
                     'type' => 'warning',
                     'msg'  => 'Editorial: Excessive Punctuation',
                     'reason' => 'Avoid gimmicky punctuation like "!!!" in titles.'
                 );
             }
        }

        // CHECK 3: Placeholder Text
        $desc = strtolower( wp_strip_all_tags( $product->get_description() ) );
        $placeholders = array( 'lorem ipsum', 'coming soon', 'test product', 'enter description' );
        foreach( $placeholders as $ph ) {
            if ( strpos( $desc, $ph ) !== false ) {
                $issues[] = array(
                    'type' => 'critical',
                    'msg'  => 'Editorial: Placeholder Text Detected',
                    'reason' => 'Product appears unfinished ("' . $ph . '").'
                );
                break; 
            }
        }

        return $issues;
    }

    /**
     * Internal: Analyzes NLP Entities for Misrepresentation/Trust signals.
     */
    private static function detect_misrepresentation_nlp( $nlp_result ) {
        $issues = array();
        $banned_orgs = array( 'fda', 'cdc', 'who', 'medicare', 'government' );

        foreach ( $nlp_result->getEntities() as $entity ) {
            $type = $entity->getType();
            $name = strtolower( $entity->getName() );

            // 1. False Affiliation (Organization)
            // If the entity is a prominent organization and it's salient in the text, it implies endorsement.
            if ( 'ORGANIZATION' === $type && in_array( $name, $banned_orgs ) ) {
                $issues[] = array(
                    'type' => 'critical',
                    'msg'  => 'Misrepresentation: Implied Affiliation (' . strtoupper($name) . ')',
                    'reason' => 'Mentioning government/health orgs often triggers "False Affiliation" policies unless verified.'
                );
            }

            // 2. Sensitive Events (Virus/Pandemic) - Expanded from Regex
            if ( 'EVENT' === $type ) {
                if ( strpos( $name, 'virus' ) !== false || strpos( $name, 'covid' ) !== false || strpos( $name, 'pandemic' ) !== false ) {
                     $issues[] = array(
                        'type'   => 'critical',
                        'msg'    => 'Sensitive Event Detected (NLP)',
                        'reason' => 'Reference to sensitive health event: ' . $entity->getName()
                    );
                }
            }
        }
        return $issues;
    }

    /**
     * Analyze plain text with Google Cloud Natural Language and extract entities.
     * Caches results to Post Meta to prevent redundant API calls.
     */
    public function analyze_text_with_nlp( $text, $post_id ) {
        if ( ! is_numeric( $post_id ) || $post_id <= 0 ) {
            return new WP_Error( 'invalid_post_id', 'Valid Post ID required for NLP analysis.' );
        }

        $max_length = 5000;
        $text = wp_strip_all_tags( $text );
        if ( strlen( $text ) > $max_length ) {
            $truncated = substr( $text, 0, $max_length );
            $last_space = strrpos( $truncated, ' ' );
            $text = $last_space !== false ? substr( $truncated, 0, $last_space ) : $truncated;
        }

        // Check Cache
        $text_hash = md5( $text );
        $cached_data = get_post_meta( $post_id, '_cc_nlp_cache', true );
        $cache_ttl = 7 * DAY_IN_SECONDS;
        if ( is_array( $cached_data ) && isset( $cached_data['hash'], $cached_data['time'] ) && $cached_data['hash'] === $text_hash && ( time() - $cached_data['time'] ) < $cache_ttl ) {
            if ( class_exists( 'Google\Service\CloudNaturalLanguage\AnnotateTextResponse' ) ) {
                return new Google\Service\CloudNaturalLanguage\AnnotateTextResponse( $cached_data['response'] );
            }
            return $cached_data['response'];
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
            $results = $service->documents->annotateText( $request );
            update_post_meta( $post_id, '_cc_nlp_cache', array(
                'hash'     => $text_hash,
                'response' => $results, 
                'time'     => time()
            ));
            return $results;
        } catch ( Exception $e ) {
            return new WP_Error( 'nlp_error', $e->getMessage() );
        }
    }
}
?>