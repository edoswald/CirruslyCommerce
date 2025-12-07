<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Cirrusly_Commerce_Google_API_Client {

    public static function get_access_token() {
        $stored_data = get_option( 'cirrusly_service_account_json' );
        if ( ! $stored_data ) return new WP_Error( 'no_creds', 'Service Account JSON not uploaded.' );

        // 1. Try to decrypt
        $json_raw = Cirrusly_Commerce_Security::decrypt_data( $stored_data );

        // 2. Fallback: Check if it's legacy plaintext (backward compatibility)
        if ( ! $json_raw ) {
            // Attempt to decode as plain JSON to see if it's old unencrypted data
            $test_json = json_decode( $stored_data, true );
            if ( json_last_error() === JSON_ERROR_NONE && isset( $test_json['private_key'] ) ) {
                $json_raw = $stored_data; // It was plaintext, use as is
            } else {
                // If it's not valid JSON and decryption failed, it's unusable
                return new WP_Error( 'decrypt_failed', 'Could not decrypt Service Account credentials. Please re-upload the JSON file.' );
            }
        }

        $creds = json_decode( $json_raw, true );
        if ( empty($creds['client_email']) || empty($creds['private_key']) ) {
            return new WP_Error( 'invalid_creds', 'Invalid Service Account JSON.' );
        }

        $header = json_encode(array('alg' => 'RS256', 'typ' => 'JWT'));
        $now = time();
        $claim = json_encode(array(
            'iss' => $creds['client_email'],
            'scope' => 'https://www.googleapis.com/auth/content',
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => $now + 3600,
            'iat' => $now
        ));

        $base64Url = function($data) {
            return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
        };

        $payload = $base64Url($header) . "." . $base64Url($claim);
        $signature = '';
        
        if ( ! openssl_sign($payload, $signature, $creds['private_key'], 'SHA256') ) {
            return new WP_Error( 'signing_failed', 'Could not sign JWT. Check private key.' );
        }

        $jwt = $payload . "." . $base64Url($signature);

        $response = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
            'timeout' => 15,
            'body' => array(
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt
            )
        ));

        if ( is_wp_error( $response ) ) return $response;

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $body['access_token'] ) ) {
            return $body['access_token'];
        }

        return new WP_Error( 'auth_failed', 'Google Auth Failed: ' . wp_remote_retrieve_body($response) );
    }

    public static function execute_scheduled_scan() {
        // This ensures the email reporting and heavy scanning 
        // logic is isolated to Pro.
    /**
     * Perform the scheduled Google Merchant Center health scan and persist the results.
     *
     * Runs the GMC scan, updates the `woo_gmc_scan_data` option with a timestamped result set, and — when the cirrusly scan configuration has `enable_email_report` set to `"yes"` and the scan returned issues — sends an HTML summary email to the configured `email_recipient` (or the site admin email when none is configured).
     */
    public function execute_scheduled_scan() {
        $scanner = new Cirrusly_Commerce_GMC();
        $results = $scanner->run_gmc_scan_logic();
        $scan_data = array( 'timestamp' => time(), 'results' => $results );
        update_option( 'woo_gmc_scan_data', $scan_data, false );
        
        $scan_cfg = get_option( 'cirrusly_scan_config', array() );
        
        $should_send = ( !empty($scan_cfg['enable_email_report']) && $scan_cfg['enable_email_report'] === 'yes' );

        if ( $should_send && ! empty( $results ) ) {
            $to = !empty($scan_cfg['email_recipient']) ? $scan_cfg['email_recipient'] : get_option('admin_email');
            $subject = 'Action Required: ' . count($results) . ' Issues Found in GMC Health Scan';
            
            // Build HTML Message
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

                $edit_url = esc_url( admin_url( 'post.php?post=' . $row['product_id'] . '&action=edit' ) );
                
                $message .= '<tr>';
                // FIX: Escape the product name to prevent XSS in email
                $message .= '<td><a href="' . esc_url( $edit_url ) . '">' . esc_html( $product->get_name() ) . '</a></td>';
                $message .= '<td>' . $issues_html . '</td>';
                $message .= '<td>See severity details above</td>';
                $message .= '<td><a href="' . $edit_url . '" style="text-decoration:none; background:#2271b1; color:#fff; padding:5px 10px; border-radius:3px;">Fix Now</a></td>';
                $message .= '</tr>';
            }
            
            $message .= '</table>';
            $message .= '<p style="margin-top:20px;">You can view the full report in your <a href="' . admin_url('admin.php?page=cirrusly-gmc') . '">GMC Hub Dashboard</a>.</p>';

            // Set HTML Content Type using headers parameter instead
            // Set HTML Content Type and From header for email authentication
            $headers = Cirrusly_Commerce_Core::get_email_from_header();
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
            wp_mail( $to, $subject, $message, $headers );
        }
    }
    }
}
?>