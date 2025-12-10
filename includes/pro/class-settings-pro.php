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
    public static function cirrusly_process_service_account_upload( $input, $file ) {
        
        // 0. Basic Upload Check
        if ( empty( $file['name'] ) || ! empty( $file['error'] ) ) {
            return $input;
        }

        // 1. Sanitize Filename (CRITICAL STEP)
        // We do this BEFORE checking extensions to prevent traversal attacks
        $safe_filename = sanitize_file_name( $file['name'] );

        // 2. WP Standard File Type Check
        // Replaces your manual finfo/pathinfo logic with the WP standard
        $file_type = wp_check_filetype( $safe_filename, array( 'json' => 'application/json' ) );

        // Strict check: Must be .json extension AND mapped to application/json
        if ( 'json' !== $file_type['ext'] || 'application/json' !== $file_type['type'] ) {
             add_settings_error( 'cirrusly_scan_config', 'mime_error', 'Security Risk: Invalid file type. Standard JSON required.' );
             return $input;
        }

        // 3. Size Check (Moved after type check is fine, or before)
        if ( $file['size'] > 65536 ) { // 64KB
             add_settings_error( 'cirrusly_scan_config', 'size_error', 'File too large. Max 64KB.' );
             return $input;
        }

        // 4. Validate JSON Content
        // Use tmp_name for reading content, never the user-supplied name
        $json_content = file_get_contents( $file['tmp_name'] );
        $data         = json_decode( $json_content, true );

        if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
            add_settings_error( 'cirrusly_scan_config', 'json_error', 'Invalid JSON content.' );
            return $input;
        }

        $required_keys = array( 'type', 'project_id', 'private_key_id', 'private_key', 'client_email' );
        $missing = array_diff( $required_keys, array_keys( $data ) );

        if ( ! empty( $missing ) ) {
            add_settings_error( 'cirrusly_scan_config', 'keys_missing', 'Missing keys: ' . implode( ', ', $missing ) );
            return $input;
        }

        // 5. Encrypt & Store
        if ( class_exists( 'Cirrusly_Commerce_Security' ) ) {
            $encrypted = Cirrusly_Commerce_Security::encrypt_data( $json_content );
            if ( $encrypted ) {
                update_option( 'cirrusly_service_account_json', $encrypted, false ); // Valid use of update_option
                
                $input['service_account_uploaded'] = 'yes';
                // Use the sanitized filename we created earlier
                $input['service_account_name']     = $safe_filename; 
                
                add_settings_error( 'cirrusly_scan_config', 'upload_success', 'Service Account JSON uploaded successfully.', 'updated' );
            } else {
                add_settings_error( 'cirrusly_scan_config', 'encrypt_error', 'Encryption failed.' );
            }
        }

        return $input;
    }
}