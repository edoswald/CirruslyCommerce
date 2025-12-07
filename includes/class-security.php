<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Cirrusly_Commerce_Security {

    /**
     * Derives site-unique encryption and authentication keys from the WordPress Auth Salt.
     *
     * Uses hash_hmac with distinct contexts to generate cryptographically verified
     * separation between the encryption key and the HMAC key.
     *
     * @return array Associative array with 'enc' (Encryption Key) and 'auth' (HMAC Key).
     */
    private static function get_keys() {
        $salt = wp_salt( 'auth' );
        return array(
            'enc'  => hash_hmac( 'sha256', 'cc_encryption_context', $salt, true ),
            'auth' => hash_hmac( 'sha256', 'cc_authentication_context', $salt, true ),
        );
    }

    /**
     * Encrypt data using AES-256-CBC with HMAC-SHA256 integrity check (Encrypt-then-MAC).
     *
     * @param string $data The plaintext data.
     * @return string|false Base64 encoded string containing IV, Ciphertext, and HMAC, or false on failure.
     */
    public static function encrypt_data( $data ) {
        if ( empty( $data ) ) return false;

        $keys = self::get_keys();
        $method = 'aes-256-cbc';
        $iv_length = openssl_cipher_iv_length( $method );
        $iv = openssl_random_pseudo_bytes( $iv_length );

        // Encrypt (Raw binary)
        $ciphertext = openssl_encrypt( $data, $method, $keys['enc'], OPENSSL_RAW_DATA, $iv );
        
        if ( false === $ciphertext ) return false;

        // Calculate HMAC on IV + Ciphertext
        $hmac = hash_hmac( 'sha256', $iv . $ciphertext, $keys['auth'], true );

        // Return base64 encoded payload: IV . Ciphertext . HMAC
        return base64_encode( $iv . $ciphertext . $hmac );
    }

    /**
     * Decrypt data using AES-256-CBC with HMAC verification.
     *
     * @param string $data The base64 encoded encrypted string.
     * @return string|false The decrypted plaintext, or false on failure/tampering.
     */
    public static function decrypt_data( $data ) {
        if ( empty( $data ) ) return false;

        $keys = self::get_keys();
        $data = base64_decode( $data );
        if ( false === $data ) return false;

        $method = 'aes-256-cbc';
        $iv_length = openssl_cipher_iv_length( $method );
        $hmac_length = 32; // SHA-256 outputs 32 bytes

        // Basic validation: Length must cover IV + HMAC
        if ( strlen( $data ) < $iv_length + $hmac_length ) return false;

        // Extract components
        $iv = substr( $data, 0, $iv_length );
        $hmac = substr( $data, -$hmac_length );
        $ciphertext = substr( $data, $iv_length, -$hmac_length );

        // Verify HMAC (Authenticate)
        $calc_hmac = hash_hmac( 'sha256', $iv . $ciphertext, $keys['auth'], true );
        
        // Constant-time comparison to prevent timing attacks
        if ( ! hash_equals( $hmac, $calc_hmac ) ) {
            return false;
        }

        // Decrypt
        return openssl_decrypt( $ciphertext, $method, $keys['enc'], OPENSSL_RAW_DATA, $iv );
    }

}