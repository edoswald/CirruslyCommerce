<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cirrusly_Commerce_Google_API_Client {

    const API_ENDPOINT = 'https://api.cirruslyweather.com/index.php';
    const API_SECRET   = 's3Y4Cezi1dKUqrAld7gcOJ2JQHU5'; // Match your server!

    /**
     * GENERIC REQUEST METHOD (The Magic Key)
     * call this like: self::request('nlp_analyze', ['text' => '...'])
     */
    public static function request( $action, $payload = array() ) {
        // 1. Get Creds
        $json_key    = get_option( 'cirrusly_service_account_json' );
        $scan_config = get_option( 'cirrusly_scan_config', array() );
        $merchant_id = isset( $scan_config['merchant_id_pro'] ) ? $scan_config['merchant_id_pro'] : '';

        if ( empty( $json_key ) ) return new WP_Error( 'missing_creds', 'Service Account JSON missing' );

        // 2. Decrypt
        $json_raw = Cirrusly_Commerce_Security::decrypt_data( $json_key );
        if ( ! $json_raw ) {
            $test = json_decode( $json_key, true );
            $json_raw = ( isset($test['private_key']) ) ? $json_key : false;
        }
        if ( ! $json_raw ) return new WP_Error( 'decrypt_fail', 'Could not decrypt keys' );

        // 3. Build Body
        $body = array(
            'action'               => $action,
            'service_account_json' => $json_raw,
            'merchant_id'          => $merchant_id,
            'payload'              => $payload
        );

        // 4. Send
        $response = wp_remote_post( self::API_ENDPOINT, array(
            'body'    => json_encode( $body ),
            'headers' => array( 
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . self::API_SECRET
            ),
            'timeout' => 45
        ) );

        if ( is_wp_error( $response ) ) return $response;
        
        $code = wp_remote_retrieve_response_code( $response );
        $res_body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            return new WP_Error( 'api_error', 'Cloud Error: ' . (isset($res_body['error']) ? $res_body['error'] : 'Unknown') );
        }

        return $res_body;
    }

    /**
     * Wrapper for the Daily Scan (Backwards Compatible)
     */
    public static function execute_scheduled_scan() {
        $result = self::request( 'gmc_scan' );
        
        if ( ! is_wp_error( $result ) && isset( $result['results'] ) ) {
            // Save Data
            update_option( 'woo_gmc_scan_data', array( 'timestamp' => time(), 'results' => $result['results'] ), false );
            
            // Trigger Email (You can keep your email logic here)
            // self::send_email_report($result['results']);
        }
    }
}