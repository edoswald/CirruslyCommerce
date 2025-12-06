<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cirrusly_Commerce_Help {

    /**
     * Initialize the help module.
     * Hooks into admin assets to load the modal JS and admin footer to render the hidden modal HTML.
     */
    public static function init() {
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_script' ) );
        add_action( 'admin_footer', array( __CLASS__, 'render_modal' ) );
    }

    /**
     * Enqueue the lightweight JS required for the modal interaction.
     */
    public static function enqueue_script( $hook ) {
        // Only run on Cirrusly pages
        if ( ! isset( $_GET['page'] ) || strpos( $_GET['page'], 'cirrusly-' ) === false ) {
            return;
        }

        wp_add_inline_script( 'common', 'jQuery(document).ready(function($){
            // Help Center Modal Interactions
            $("#cc-open-help-center").click(function(e){
                e.preventDefault();
                $("#cc-help-backdrop, #cc-help-modal").fadeIn(200);
            });
            $("#cc-close-help, #cc-help-backdrop").click(function(){
                $("#cc-help-backdrop, #cc-help-modal").fadeOut(200);
            });
        });' );
    }

    /**
     * Renders the "Help Center" button. 
     * Designed to be called inside the page header actions area.
     */
    public static function render_button() {
        echo '<a href="#" id="cc-open-help-center" class="button button-secondary"><span class="dashicons dashicons-editor-help" style="vertical-align:middle;margin-top:2px;"></span> Help Center</a>';
    }

    /**
     * Renders the hidden modal markup in the footer.
     */
    public static function render_modal() {
        // Only render on Cirrusly pages
        if ( ! isset( $_GET['page'] ) || strpos( $_GET['page'], 'cirrusly-' ) === false ) {
            return;
        }

        $mailto = 'mailto:help@cirruslyweather.com?subject=Support%20Request';
        $google_form_url = 'https://docs.google.com/forms/d/e/YOUR-FORM-ID/viewform'; 
        
        ?>
        <div id="cc-help-backdrop" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999;"></div>
        <div id="cc-help-modal" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%, -50%); width:650px; background:#fff; box-shadow:0 4px 25px rgba(0,0,0,0.15); z-index:10000; border-radius:6px; overflow:hidden;">
            
            <div class="cc-help-header" style="background:#f0f0f1; padding:15px 20px; border-bottom:1px solid #c3c4c7; display:flex; justify-content:space-between; align-items:center;">
                <h3 style="margin:0; font-size:16px;">Cirrusly Help Center</h3>
                <button type="button" id="cc-close-help" style="background:none; border:none; cursor:pointer; font-size:24px; line-height:1; color:#646970;">&times;</button>
            </div>
            
            <div class="cc-help-body" style="padding:0; display:flex; height: 450px;">
                <div style="width:40%; padding:20px; border-right:1px solid #eee; background:#fff;">
                    
                    <h4 style="margin-top:0;"><span class="dashicons dashicons-book-alt" style="color:#2271b1;"></span> Documentation</h4>
                    <p style="color:#666; font-size:13px; margin-bottom:15px;">Check the manual for setup guides and troubleshooting.</p>
                    <a href="<?php echo esc_url( admin_url('admin.php?page=cirrusly-manual') ); ?>" class="button" style="width:100%; text-align:center;">Open User Manual</a>
                    
                    <hr style="border:0; border-top:1px solid #eee; margin:20px 0;">

                    <h4 style="margin-top:0;"><span class="dashicons dashicons-email-alt" style="color:#2271b1;"></span> Support</h4>
                    <p style="color:#666; font-size:13px; margin-bottom:15px;">Having trouble? Reach out to our team.</p>
                    
                    <a href="<?php echo esc_url( $mailto ); ?>" class="button button-primary" style="width:100%; text-align:center; margin-bottom:10px;">Email Support</a>
                    <a href="<?php echo esc_url( $google_form_url ); ?>" target="_blank" class="button" style="width:100%; text-align:center;">
                        <span class="dashicons dashicons-external" style="font-size:14px; vertical-align:middle;"></span> Submit Bug Report
                    </a>

                </div>

                <div style="width:60%; padding:20px; background:#f9f9f9;">
                    <h4 style="margin-top:0; display:flex; justify-content:space-between; align-items:center;">
                        <span>System Health</span>
                        <button type="button" class="button button-small" onclick="var copyText = document.getElementById('cc-sys-info-text');copyText.select();document.execCommand('copy');alert('Copied to clipboard!');">Copy Log</button>
                    </h4>
                    <p style="color:#666; font-size:12px; margin-bottom:10px;">Please copy this log when submitting a bug report.</p>
                    <textarea id="cc-sys-info-text" style="width:100%; height:320px; font-family:monospace; font-size:11px; background:#fff; border:1px solid #ccc; white-space:pre;" readonly><?php self::render_system_info(); ?></textarea>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Generates the system info report text.
     */
    public static function render_system_info() {
        global $wp_version;
        echo "### System Info ###\n";
        echo "Site URL: " . site_url() . "\n";
        echo "WP Version: " . $wp_version . "\n";
        echo "WooCommerce: " . (class_exists('WooCommerce') ? WC()->version : 'Not Installed') . "\n";
        echo "Cirrusly Commerce: " . CIRRUSLY_COMMERCE_VERSION . "\n";
        echo "PHP Version: " . phpversion() . "\n";
        echo "Server Software: " . esc_html( $_SERVER['SERVER_SOFTWARE'] ) . "\n";
        echo "Active Plugins:\n";
        $plugins = get_option('active_plugins');
        foreach($plugins as $p) { echo "- " . esc_html( $p ) . "\n"; }
    }
}