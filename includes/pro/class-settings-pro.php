<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cirrusly_Commerce_Settings_Pro {

    /**
     * Handle the upload and encryption of the Service Account JSON.
     *
     * @param array $input The current settings input array.
     * @param array $file  The $_FILES['cirrusly_service_account'] array.
     * @return array Modified input array.
     */
    public static function process_service_account_upload( $input, $file ) {
        
        // 1. Size Check
        if ( $file['size'] > 65536 ) { // 64KB
             add_settings_error( 'cirrusly_scan_config', 'size_error', 'File too large. Max 64KB.' );
             return $input;
        }

        // 2. MIME Check
        $is_valid_mime = false;
        $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        $finfo = finfo_open( FILEINFO_MIME_TYPE );
        $mime_type = finfo_file( $finfo, $file['tmp_name'] );
        finfo_close( $finfo );
        
        if ( $ext === 'json' && in_array( $mime_type, array( 'application/json', 'text/plain' ), true ) ) {
            $is_valid_mime = true;
        }
        
        if ( ! $is_valid_mime ) {
            add_settings_error( 'cirrusly_scan_config', 'mime_error', 'Invalid file type. JSON required.' );
            return $input;
        }

        // 3. Validate JSON Content
        $json_content = file_get_contents( $file['tmp_name'] );
        $data = json_decode( $json_content, true );

        if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
            add_settings_error( 'cirrusly_scan_config', 'json_error', 'Invalid JSON content.' );
            return $input;
        }

        $required_keys = array( 'type', 'project_id', 'private_key_id', 'private_key', 'client_email' );
        $missing = array_diff( $required_keys, array_keys($data) );

        if ( ! empty( $missing ) ) {
            add_settings_error( 'cirrusly_scan_config', 'keys_missing', 'Missing keys: ' . implode( ', ', $missing ) );
            return $input;
        }

        // 4. Encrypt & Store
        if ( class_exists( 'Cirrusly_Commerce_Security' ) ) {
            $encrypted = Cirrusly_Commerce_Security::encrypt_data( $json_content );
            if ( $encrypted ) {
                update_option( 'cirrusly_service_account_json', $encrypted, false );
                $input['service_account_uploaded'] = 'yes';
                $input['service_account_name'] = sanitize_file_name( $file['name'] );
                add_settings_error( 'cirrusly_scan_config', 'upload_success', 'Service Account JSON uploaded.', 'updated' );
            } else {
                add_settings_error( 'cirrusly_scan_config', 'encrypt_error', 'Encryption failed.' );
            }
        }

        return $input;
    }
}