<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cirrusly_Commerce_Mailer {

    /**
     * Send an HTML email using the site's default "From" headers.
     *
     * @param string $to      Recipient email address.
     * @param string $subject Email subject line.
     * @param string $message HTML content of the email.
     * @return bool           True on success, false on failure.
     */
    public static function send_html( $to, $subject, $message ) {
    if ( ! is_email( $to ) ) {
        return false;
    }
    
        $headers = self::get_headers();

        // Enforce HTML content type for this specific email
        add_filter( 'wp_mail_content_type', array( __CLASS__, 'get_html_content_type' ) );
        
    try {
        $result = wp_mail( $to, $subject, $message, $headers );
    } finally {
        // Clean up filter to avoid affecting other plugins/emails
        remove_filter( 'wp_mail_content_type', array( __CLASS__, 'get_html_content_type' ) );
        }

        return $result;
    }

    /**
     * Helper to return text/html content type.
     */
    public static function get_html_content_type() {
        return 'text/html';
    }

    /**
     * Generate standard From headers based on site settings.
     * Refactors logic previously found in Core.
     */
    private static function get_headers() {
        $admin_email = get_option( 'admin_email' );
    if ( ! is_email( $admin_email ) ) {
        $admin_email = ''; // Let wp_mail use its default
    }
        $site_title  = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
        
        // You could add a filter here to allow overriding the sender
        return array( 'From: ' . $site_title . ' <' . $admin_email . '>' );
    }
}