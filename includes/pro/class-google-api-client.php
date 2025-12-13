<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cirrusly_Commerce_Google_API_Client {

    const API_ENDPOINT = 'https://api.cirruslyweather.com/index.php';

    /**
     * GENERIC REQUEST METHOD
     * call this like: self::request('nlp_analyze', ['text' => '...'], ['timeout' => 5])
     * @param string $action  The API action code.
     * @param array  $payload Data to send.
     * @param array  $args    Optional. Overrides for wp_remote_post args (e.g. timeout).
     */
    public static function request( $action, $payload = array(), $args = array() ) {
        // 1. Get Google Credentials
        $json_key    = get_option( 'cirrusly_service_account_json' );
        $scan_config = get_option( 'cirrusly_scan_config', array() );
        $merchant_id = isset( $scan_config['merchant_id_pro'] ) ? sanitize_text_field( $scan_config['merchant_id_pro'] ) : '';

        if ( empty( $json_key ) ) return new WP_Error( 'missing_creds', 'Service Account JSON missing' );

        // 2. Get API Key (Replaces Freemius Token)
        $api_key = isset( $scan_config['api_key'] ) ? sanitize_text_field( $scan_config['api_key'] ) : '';

        if ( empty( $api_key ) ) {
            return new WP_Error( 'no_token', 'API License Key missing. Please enter it in Settings > General.' );
        }

        // 3. Decrypt Google JSON
        $json_raw = Cirrusly_Commerce_Security::decrypt_data( $json_key );
        if ( ! $json_raw ) {
            $test = json_decode( $json_key, true );
            if ( isset( $test['private_key'] ) ) {
                $json_raw = $json_key; // It was unencrypted
            } else {
                return new WP_Error( 'decrypt_fail', 'Could not decrypt Google keys' );
            }
        }

        // 4. Build Body
        $body = array(
            'action'               => $action,
            'service_account_json' => $json_raw,
            'merchant_id'          => $merchant_id,
            'payload'              => $payload
        );

        // 5. Send Request
        // Merge defaults with passed args (e.g. allow override of timeout)
        $default_headers = array(
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $api_key, // required
        );

        $request_args = wp_parse_args(
            $args,
            array(
                'headers' => array(),
                'timeout' => 45,
            )
        );
        
        // Body must not be overridable; always use the constructed payload.
        $request_args['body'] = wp_json_encode( $body );


        // Merge headers but keep required defaults (defaults override user values).
        $user_headers = is_array( $request_args['headers'] ) ? $request_args['headers'] : array();
        $request_args['headers'] = array_merge( $user_headers, $default_headers );

        $response = wp_remote_post( self::API_ENDPOINT, $request_args );

        if ( is_wp_error( $response ) ) return $response;
        
        $code = wp_remote_retrieve_response_code( $response );
        $res_body = json_decode( wp_remote_retrieve_body( $response ), true );
    
        if ( ! is_array( $res_body ) ) {
            return new WP_Error( 'invalid_response', 'API returned invalid JSON' );
        }

        if ( $code !== 200 ) {
            // Pass through the error from the worker (e.g., "Invalid License")
            return new WP_Error( 'api_error', 'Cloud Error: ' . (isset($res_body['error']) ? $res_body['error'] : 'Unknown') );
        }

        return $res_body;
    }

    /**
     * Wrapper for the Daily Scan
     */
    public static function execute_scheduled_scan() {
        $result = self::request( 'gmc_scan' );
    
        if ( is_wp_error( $result ) ) {
            error_log( 'Cirrusly Commerce GMC Scan failed: ' . $result->get_error_message() );
            return;
        }
    
        if ( isset( $result['results'] ) ) {
            update_option( 'cirrusly_gmc_scan_data', array( 'timestamp' => time(), 'results' => $result['results'] ), false );
        }
    }
}