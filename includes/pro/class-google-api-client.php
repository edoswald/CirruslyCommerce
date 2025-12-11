<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cirrusly_Commerce_Google_API_Client {

    /**
     * URL to your external Cloud Worker (Phase 1).
     * @var string
     */
    const API_ENDPOINT = 'https://api.cirruslyweather.com/index.php';

    /**
     * The Shared Secret Key for authentication with your Cloud Worker.
     * Match this with the key in your CloudPanel index.php.
     * @var string
     */
    const API_SECRET = 's3Y4Cezi1dKUqrAld7gcOJ2JQHU5';

    /**
     * Replaces the local library call with a remote API request.
     * This function is called by the daily cron event 'cirrusly_gmc_daily_scan'.
     */
    public static function execute_scheduled_scan() {
        // 1. Retrieve Credentials & Config
        $json_key    = get_option( 'cirrusly_service_account_json' );
        $scan_config = get_option( 'cirrusly_scan_config', array() );
        $merchant_id = isset( $scan_config['merchant_id_pro'] ) ? $scan_config['merchant_id_pro'] : '';

        // Basic validation
        if ( empty( $json_key ) || empty( $merchant_id ) ) {
            return;
        }

        // 2. Prepare the Payload
        // We attempt to decrypt the key locally before sending it to the cloud.
        $json_raw = Cirrusly_Commerce_Security::decrypt_data( $json_key );
        
        // Fallback for legacy plaintext (backward compatibility)
        if ( ! $json_raw ) {
            $test_json = json_decode( $json_key, true );
            if ( isset( $test_json['private_key'] ) ) {
                $json_raw = $json_key; 
            } else {
                // Cannot proceed if we can't get the raw JSON key
                return;
            }
        }

        $body = array(
            'service_account_json' => $json_raw,
            'merchant_id'          => $merchant_id,
            'site_url'             => get_site_url(), // Helpful for logging on your server
        );

        // 3. Send Request to Your Cloud API
        $response = wp_remote_post( self::API_ENDPOINT, array(
            'body'    => json_encode( $body ),
            'headers' => array( 
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . self::API_SECRET
            ),
            'timeout' => 45, // Extended timeout to allow Google API processing
        ) );

        // 4. Handle Errors
        if ( is_wp_error( $response ) ) {
            error_log( 'Cirrusly Cloud Scan Failed (WP Error): ' . $response->get_error_message() );
            return;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );

        if ( $response_code !== 200 ) {
            error_log( 'Cirrusly Cloud Scan Error [' . $response_code . ']: ' . $response_body );
            return;
        }

        $data = json_decode( $response_body, true );

        // 5. Process Results
        // We expect the API to return: ['success' => true, 'results' => [...]]
        $results = isset( $data['results'] ) ? $data['results'] : array();

        // Save data for the Dashboard Widget
        $scan_data = array( 'timestamp' => time(), 'results' => $results );
        update_option( 'woo_gmc_scan_data', $scan_data, false );

        // 6. Send Email Report
        self::send_email_report( $results, $scan_config );
    }

    /**
     * Sends the HTML email report if issues are found.
     * * @param array $results The array of issues returned by the API.
     * @param array $scan_config The plugin's scan configuration options.
     */
    private static function send_email_report( $results, $scan_config ) {
        $should_send = ( !empty($scan_config['enable_email_report']) && $scan_config['enable_email_report'] === 'yes' );

        if ( ! $should_send || empty( $results ) ) {
            return;
        }

        $to = !empty($scan_config['email_recipient']) ? $scan_config['email_recipient'] : get_option('admin_email');
        $subject = 'Action Required: ' . count($results) . ' Issues Found in GMC Health Scan';
        
        // Build the HTML Message
        $message  = '<h2>Cirrusly Commerce Daily Health Report</h2>';
        $message .= '<p>The daily scan detected <strong>' . count($results) . ' products</strong> with potential Google Merchant Center issues.</p>';
        $message .= '<table border="1" cellpadding="10" cellspacing="0" style="border-collapse:collapse; width:100%; border-color:#eee;">';
        $message .= '<tr style="background:#f9f9f9;"><th>Product</th><th>Severity</th><th>Issue</th><th>Action</th></tr>';
        
        foreach ( $results as $row ) {
            // Note: The API returns 'product_id', which corresponds to the WP Post ID
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

        // Send the email
        $headers = Cirrusly_Commerce_Core::get_email_from_header();
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        
        wp_mail( $to, $subject, $message, $headers );
    }
}