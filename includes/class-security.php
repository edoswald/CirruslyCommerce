<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Cirrusly_Commerce_Security {

    /**
     * Derives a site-unique encryption key from the WordPress Auth Salt.
     *
     * @return string Hex-encoded 32-byte (64-character) SHA-256 hash derived from wp_salt('auth').
     */
    private static function get_encryption_key() {
        return hash( 'sha256', wp_salt( 'auth' ) );
    }

    /**
     * Encrypt data using AES-256-CBC.
     *
     * @param string $data The plaintext data.
     * @return string|false Base64 encoded string containing IV and encrypted data, or false on failure.
     */
    private static function encrypt_data( $data ) {
        if ( empty( $data ) ) return false;

        $key = self::get_encryption_key();
        $method = 'aes-256-cbc';
        $iv_length = openssl_cipher_iv_length( $method );
        $iv = openssl_random_pseudo_bytes( $iv_length );

        $encrypted = openssl_encrypt( $data, $method, $key, OPENSSL_RAW_DATA, $iv );
        
        if ( false === $encrypted ) return false;

        // Store IV + Encrypted Data together, base64 encoded
        return base64_encode( $iv . $encrypted );
    }

    /**
     * Decrypt data using AES-256-CBC.
     *
     * @param string $data The base64 encoded encrypted string.
     * @return string|false The decrypted plaintext, or false on failure.
     */
    public static function decrypt_data( $data ) {
        if ( empty( $data ) ) return false;

        $key = self::get_encryption_key();
        $data = base64_decode( $data );
        $method = 'aes-256-cbc';
        $iv_length = openssl_cipher_iv_length( $method );

        // Basic validation
        if ( strlen( $data ) <= $iv_length ) return false;

        $iv = substr( $data, 0, $iv_length );
        $encrypted_payload = substr( $data, $iv_length );

        return openssl_decrypt( $encrypted_payload, $method, $key, OPENSSL_RAW_DATA, $iv );

    }

}
?>