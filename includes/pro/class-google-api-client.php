<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cirrusly_Commerce_Google_API_Client {

    /**
     * Create and return a configured Google API client.
     * * @return Google\Client|WP_Error
     */
    public static function get_client() {
        // 1. Retrieve & Decrypt Key
        $json_key = get_option( 'cirrusly_service_account_json' );
        
        if ( empty( $json_key ) ) {
            return new WP_Error( 'missing_creds', 'Missing Service Account JSON.' );
        }

        // Safety: Check Composer library
        if ( ! class_exists( 'Google\Client' ) ) {
            return new WP_Error( 'missing_lib', 'Google Library not loaded. Run composer install.' );
        }

        try {
            $client = new Google\Client();
            $client->setApplicationName( 'Cirrusly Commerce' );

            // Use Security class for decryption
            $json_raw = Cirrusly_Commerce_Security::decrypt_data( $json_key );
            
            // Fallback for legacy plaintext (backward compatibility)
            if ( ! $json_raw ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( 'Cirrusly Commerce: Decryption returned false, trying raw key as fallback.' );
                }
                $test_json = json_decode( $json_key, true );
                if ( json_last_error() === JSON_ERROR_NONE && isset( $test_json['private_key'] ) ) {
                    $json_raw = $json_key; 
                } else {
                    return new WP_Error( 'decrypt_failed', 'Could not decrypt Service Account credentials.' );
                }
            }

            $auth_config = json_decode( $json_raw, true );
            if ( ! is_array( $auth_config ) ) {
                return new WP_Error( 'invalid_json', 'Service Account JSON is malformed.' );
            }

            $client->setAuthConfig( $auth_config );
            $client->setScopes([
                'https://www.googleapis.com/auth/content',
                'https://www.googleapis.com/auth/cloud-language' 
            ]);
            
            return $client;

        } catch ( Exception $e ) {
            return new WP_Error( 'auth_failed', 'Auth Error: ' . $e->getMessage() );
        }
    }

    /**
     * Perform the scheduled Google Merchant Center health scan and email results.
     */
    public static function execute_scheduled_scan() {
        if ( ! class_exists( 'Cirrusly_Commerce_GMC' ) ) return;

        $scanner = new Cirrusly_Commerce_GMC();
        // Uses the scanner logic (which will now call API logic if Pro)
        $results = $scanner->run_gmc_scan_logic();
        
        $scan_data = array( 'timestamp' => time(), 'results' => $results );
        update_option( 'woo_gmc_scan_data', $scan_data, false );
        
        $scan_cfg = get_option( 'cirrusly_scan_config', array() );
        
        $should_send = ( !empty($scan_cfg['enable_email_report']) && $scan_cfg['enable_email_report'] === 'yes' );

        if ( $should_send && ! empty( $results ) ) {
            $to = !empty($scan_cfg['email_recipient']) ? $scan_cfg['email_recipient'] : get_option('admin_email');
            $subject = 'Action Required: ' . count($results) . ' Issues Found in GMC Health Scan';
            
            $message  = '<h2>Cirrusly Commerce Daily Health Report</h2>';
            $message .= '<p>The daily scan detected <strong>' . count($results) . ' products</strong> with potential Google Merchant Center issues.</p>';
            $message .= '<table border="1" cellpadding="10" cellspacing="0" style="border-collapse:collapse; width:100%; border-color:#eee;">';
            $message .= '<tr style="background:#f9f9f9;"><th>Product</th><th>Severity</th><th>Issue</th><th>Action</th></tr>';
            
            foreach ( $results as $row ) {
                $product = wc_get_product( $row['product_id'] );
                if ( ! $product ) continue;
                
                $issues_html = '';
                foreach ( $row['issues'] as $issue ) {
                    $color = ($issue['type'] === 'critical') ? '#d63638' : '#dba617';
                    $issues_html .= '<div style="color:'. $color .'; margin-bottom:4px;"><strong>' . ucfirst($issue['type']) . ':</strong> ' . esc_html($issue['msg']) . '</div>';
                }

                $edit_url = admin_url( 'post.php?post=' . $row['product_id'] . '&action=edit' );
                
                $message .= '<tr>';
                $message .= '<td><a href="' . $edit_url . '">' . esc_html( $product->get_name() ) . '</a></td>';
                $message .= '<td>' . $issues_html . '</td>';
                $message .= '<td>See severity details above</td>';
                $message .= '<td><a href="' . $edit_url . '" style="text-decoration:none; background:#2271b1; color:#fff; padding:5px 10px; border-radius:3px;">Fix Now</a></td>';
                $message .= '</tr>';
            }
            
            $message .= '</table>';
            $message .= '<p style="margin-top:20px;">You can view the full report in your <a href="' . admin_url('admin.php?page=cirrusly-gmc') . '">GMC Hub Dashboard</a>.</p>';

            // Ensure Core class has this method (See step 2 below)
            $headers = Cirrusly_Commerce_Core::get_email_from_header();
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
            wp_mail( $to, $subject, $message, $headers );
        }
    }
}