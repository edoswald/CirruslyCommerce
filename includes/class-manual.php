<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cirrusly_Commerce_Manual {

    /**
     * Render the manual page.
     * Loads the view file and enqueues necessary styles.
     */
    public static function render_page() {
        // Enqueue styles strictly for this page to avoid admin conflicts
        // Ideally, move the CSS from the <style> block into assets/css/admin.css
        wp_enqueue_style( 'cirrusly-admin-css', CIRRUSLY_COMMERCE_URL . 'assets/css/admin.css', array(), CIRRUSLY_COMMERCE_VERSION );
        
        // You can add specific inline styles here if they are truly unique to the manual
        wp_add_inline_style( 'cirrusly-admin-css', self::get_inline_styles() );

        // Load the View
        include plugin_dir_path( __FILE__ ) . 'admin/views/html-manual.php';
    }

    /**
     * Keep CSS out of the view to keep it clean.
     */
    private static function get_inline_styles() {
        return '
            .cirrusly-manual-nav a { text-decoration: none; margin-right: 15px; font-weight: 500; }
            .cirrusly-manual-nav a:hover { text-decoration: underline; }
            .cirrusly-manual-pro { background:#2271b1; color:#fff; padding:2px 6px; border-radius:3px; font-size:10px; vertical-align:middle; margin-left:5px; font-weight:bold; }
            .cirrusly-manual-section { margin-bottom: 40px; border-bottom: 1px solid #eee; padding-bottom: 20px; }
            .cirrusly-manual-section h3 { font-size: 1.3em; margin-bottom: 15px; display: flex; align-items: center; }
            .cirrusly-manual-section h4 { font-size: 1.1em; margin-top: 20px; margin-bottom: 10px; color: #23282d; }
            .cirrusly-manual-list li { margin-bottom: 8px; line-height: 1.5; }
            .cirrusly-callout { background: #f0f6fc; border-left: 4px solid #72aee6; padding: 15px; margin: 15px 0; }
            .cirrusly-alert { background: #fcf0f1; border-left: 4px solid #d63638; padding: 15px; margin: 15px 0; }
            .cirrusly-tip { background: #f0f9eb; border-left: 4px solid #00a32a; padding: 15px; margin: 15px 0; }
            code { background: #f0f0f1; padding: 2px 5px; border-radius: 3px; }
        ';
    }
}