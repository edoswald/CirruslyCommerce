<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cirrusly_Commerce_Help {

    public static function init() {
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_script' ) );
        add_action( 'admin_footer', array( __CLASS__, 'render_modal' ) );
        
        // New Standard Action
        add_action( 'wp_ajax_cirrusly_submit_bug_report', array( __CLASS__, 'handle_bug_submission' ) );
        
        // Legacy Action (Deprecated)
        add_action( 'wp_ajax_cirrusly_submit_bug_report', array( __CLASS__, 'handle_legacy_submission' ) );
    }

    public static function handle_legacy_submission() {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'AJAX action cirrusly_submit_bug_report is deprecated. Use cirrusly_submit_bug_report.' );
        }
        self::handle_bug_submission();
    }

    public static function enqueue_script( $hook ) {
        if ( ! isset( $_GET['page'] ) || strpos( sanitize_text_field( wp_unslash( $_GET['page'] ) ), 'cirrusly-' ) === false ) {
            return;
        }

        // Updated selectors and action names in JS
        wp_add_inline_script( 'cirrusly-admin-base-js', 'jQuery(document).ready(function($){
            $("#cirrusly-open-help-center").click(function(e){
                e.preventDefault();
                $("#cirrusly-help-backdrop, #cirrusly-help-modal").fadeIn(200);
            });
            $("#cirrusly-close-help, #cirrusly-help-backdrop").click(function(){
                $("#cirrusly-help-backdrop, #cirrusly-help-modal").fadeOut(200);
                $("#cirrusly-help-main-view").show();
                $("#cirrusly-help-form-view").hide();
                $("#cirrusly-bug-response").html("").hide();
            });
            $("#cirrusly-btn-bug-report").click(function(e){
                e.preventDefault();
                $("#cirrusly-help-main-view").hide();
                $("#cirrusly-help-form-view").fadeIn(200);
            });
            $("#cirrusly-btn-back-help").click(function(e){
                e.preventDefault();
                $("#cirrusly-help-form-view").hide();
                $("#cirrusly-help-main-view").fadeIn(200);
            });
            $("#cirrusly-bug-report-form").on("submit", function(e){
                e.preventDefault();
                var $form = $(this);
                var $btn  = $form.find("button[type=submit]");
                var $msg  = $("#cirrusly-bug-response");
                $btn.prop("disabled", true).text("Sending...");
                $msg.hide().removeClass("notice-error notice-success");
                var formData = $form.serialize() + "&system_info=" + encodeURIComponent($("#cirrusly-sys-info-text").val());
                $.post(ajaxurl, formData, function(response) {
                    $btn.prop("disabled", false).text("Send Report");
                    if ( response.success ) {
                        $form[0].reset();
                        $("#cirrusly-help-form-view").hide();
                        $("#cirrusly-help-main-view").fadeIn();
                        alert("Report sent successfully! We will be in touch shortly.");
                    } else {
                        $msg.addClass("notice notice-error").html("<p>" + (response.data || "Unknown error") + "</p>").show();
                    }
                }).fail(function() {
                    $btn.prop("disabled", false).text("Send Report");
                    $msg.addClass("notice notice-error").html("<p>Server error. Please try again later.</p>").show();
                });
            });
        });' );
    }

    public static function render_button() {
        echo '<a href="#" id="cirrusly-open-help-center" class="button button-secondary"><span class="dashicons dashicons-editor-help" style="vertical-align:middle;margin-top:2px;"></span> Help Center</a>';
    }

    public static function render_modal() {
        if ( ! isset( $_GET['page'] ) || strpos( sanitize_text_field( wp_unslash( $_GET['page'] ) ), 'cirrusly-' ) === false ) {
            return;
        }
        $mailto = 'mailto:help@cirruslyweather.com?subject=Support%20Request';
        $current_user = wp_get_current_user();
        ?>
        <div id="cirrusly-help-backdrop" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999;"></div>
        <div id="cirrusly-help-modal" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%, -50%); width:650px; background:#fff; box-shadow:0 4px 25px rgba(0,0,0,0.15); z-index:10000; border-radius:6px; overflow:hidden;">
            <div class="cirrusly-help-header" style="background:#f0f0f1; padding:15px 20px; border-bottom:1px solid #c3c4c7; display:flex; justify-content:space-between; align-items:center;">
                <h3 style="margin:0; font-size:16px;">Cirrusly Help Center</h3>
                <button type="button" id="cirrusly-close-help" style="background:none; border:none; cursor:pointer; font-size:24px; line-height:1; color:#646970;">&times;</button>
            </div>
            <div id="cirrusly-help-main-view" class="cirrusly-help-body" style="padding:0; display:flex; height: 450px;">
                <div style="width:40%; padding:20px; border-right:1px solid #eee; background:#fff;">
                    <h4 style="margin-top:0;"><span class="dashicons dashicons-book-alt" style="color:#2271b1;"></span> Documentation</h4>
                    <p style="color:#666; font-size:13px; margin-bottom:15px;">Check the manual for setup guides and troubleshooting.</p>
                    <a href="<?php echo esc_url( admin_url('admin.php?page=cirrusly-manual') ); ?>" class="button" style="width:100%; text-align:center;">Open User Manual</a>
                    <hr style="border:0; border-top:1px solid #eee; margin:20px 0;">
                    <h4 style="margin-top:0;"><span class="dashicons dashicons-email-alt" style="color:#2271b1;"></span> Support</h4>
                    <p style="color:#666; font-size:13px; margin-bottom:15px;">Having trouble? Reach out to our team.</p>
                    <a href="<?php echo esc_url( $mailto ); ?>" class="button" style="width:100%; text-align:center; margin-bottom:10px;">Email Support</a>
                    <button type="button" id="cirrusly-btn-bug-report" class="button button-primary" style="width:100%; text-align:center;">
                        <span class="dashicons dashicons-warning" style="font-size:16px; vertical-align:middle; margin-right:5px;"></span> Submit Bug Report
                    </button>
                </div>
                <div style="width:60%; padding:20px; background:#f9f9f9;">
                    <h4 style="margin-top:0; display:flex; justify-content:space-between; align-items:center;">
                        <span>System Health</span>
                        <button type="button" class="button button-small" onclick="var copyText = document.getElementById('cirrusly-sys-info-text');navigator.clipboard.writeText(copyText.value).then(function(){alert('Copied to clipboard!');}).catch(function(){copyText.select();document.execCommand('copy');alert('Copied to clipboard!');});">Copy Log</button>
                    </h4>
                    <p style="color:#666; font-size:12px; margin-bottom:10px;">This info will be automatically attached to your bug report.</p>
                    <textarea id="cirrusly-sys-info-text" style="width:100%; height:320px; font-family:monospace; font-size:11px; background:#fff; border:1px solid #ccc; white-space:pre;" readonly><?php echo esc_textarea( self::get_system_info() ); ?></textarea>
                </div>
            </div>
            <div id="cirrusly-help-form-view" style="display:none; height:450px; padding:20px; background:#fff;">
                <h4 style="margin-top:0; margin-bottom:20px; display:flex; align-items:center;">
                    <button type="button" id="cirrusly-btn-back-help" class="button button-small" style="margin-right:10px;"><span class="dashicons dashicons-arrow-left-alt2"></span> Back</button>
                    Submit Bug Report
                </h4>
                <div id="cirrusly-bug-response" style="display:none; margin-bottom:15px; padding:10px;"></div>
                <form id="cirrusly-bug-report-form">
                    <input type="hidden" name="action" value="cirrusly_submit_bug_report">
                    <?php wp_nonce_field( 'cirrusly_bug_report_nonce', 'security' ); ?>
                    <div style="display:flex; gap:15px; margin-bottom:15px;">
                        <div style="flex:1;">
                            <label style="display:block; margin-bottom:5px; font-weight:600;">Your Email</label>
                            <input type="email" name="user_email" value="<?php echo esc_attr( $current_user->user_email ); ?>" required style="width:100%;">
                        </div>
                        <div style="flex:1;">
                            <label style="display:block; margin-bottom:5px; font-weight:600;">Subject</label>
                            <input type="text" name="subject" placeholder="e.g., Fatal error on checkout" required style="width:100%;">
                        </div>
                    </div>
                    <div style="margin-bottom:15px;">
                        <label style="display:block; margin-bottom:5px; font-weight:600;">Issue Description</label>
                        <textarea name="message" rows="8" style="width:100%;" placeholder="Please describe what happened, what you expected to happen, and any steps to reproduce the issue." required></textarea>
                    </div>
                    <div style="text-align:right; border-top:1px solid #eee; padding-top:15px;">
                        <button type="submit" class="button button-primary button-large">Send Report</button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    public static function handle_bug_submission() {
        $nonce = isset( $_POST['security'] ) ? sanitize_text_field( wp_unslash( $_POST['security'] ) ) : '';
        $verified = false;

        // Check new standard nonce
        if ( wp_verify_nonce( $nonce, 'cirrusly_bug_report_nonce' ) ) {
            $verified = true;
        }
        // Check legacy nonce (deprecated)
        elseif ( wp_verify_nonce( $nonce, 'cirrusly_bug_report_nonce' ) ) {
            $verified = true;
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'Legacy nonce cirrusly_bug_report_nonce used in handle_bug_submission. Use cirrusly_bug_report_nonce.' );
            }
        }

        if ( ! $verified ) {
            wp_send_json_error( 'Security check failed. Please refresh the page.' );
        }

        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'edit_products' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }
        
        $user_email = isset( $_POST['user_email'] ) ? sanitize_email( wp_unslash( $_POST['user_email'] ) ) : '';
        $subject    = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
        $message    = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
        $sys_info   = isset($_POST['system_info']) ? sanitize_textarea_field( wp_unslash( $_POST['system_info'] ) ) : 'Not provided';

        if ( ! is_email( $user_email ) ) {
            wp_send_json_error( 'Please provide a valid email address.' );
        }
        if ( ! class_exists( 'Cirrusly_Commerce_Mailer' ) ) {
            if ( defined( 'CIRRUSLY_COMMERCE_PATH' ) ) {
                require_once CIRRUSLY_COMMERCE_PATH . 'class-mailer.php';
            } else {
                wp_send_json_error( 'Mailer class not found.' );
            }
        }
        $admin_subject = '[Bug Report] ' . $subject;
        $admin_body    = '<h3>New Bug Report</h3>';
        $admin_body   .= '<p><strong>User:</strong> ' . esc_html( $user_email ) . '</p>';
        $admin_body   .= '<p><strong>Description:</strong><br>' . nl2br( esc_html( $message ) ) . '</p>';
        $admin_body   .= '<hr><h4>System Information</h4>';
        $admin_body   .= '<pre style="background:#f0f0f1; padding:10px; border:1px solid #ccc;">' . esc_html( $sys_info ) . '</pre>';
        $to = 'help@cirruslyweather.com'; 
        $sent = Cirrusly_Commerce_Mailer::send_html( $to, $admin_subject, $admin_body );
        if ( $sent ) {
            wp_send_json_success( 'Report sent.' );
        } else {
            wp_send_json_error( 'Could not send email. Please verify your server email settings.' );
        }
    }

    public static function get_system_info() {
        global $wp_version;
        $out  = "### System Info ###\n";
        $out .= "Site URL: " . site_url() . "\n";
        $out .= "WP Version: " . $wp_version . "\n";
        $out .= "WooCommerce: " . (class_exists('WooCommerce') ? WC()->version : 'Not Installed') . "\n";
        $out .= "Cirrusly Commerce: " . ( defined('CIRRUSLY_COMMERCE_VERSION') ? CIRRUSLY_COMMERCE_VERSION : 'Unknown' ) . "\n";
        $out .= "PHP Version: " . phpversion() . "\n";
        
        $server_software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : 'Unknown';
        $out .= "Server Software: " . esc_html( $server_software ) . "\n";
        
        $out .= "Active Plugins:\n";
        $plugins = get_option('active_plugins');
        if ( is_array( $plugins ) ) {
            foreach( $plugins as $p ) { $out .= "- " . esc_html( $p ) . "\n"; }
        }
        return $out;
    }

    public static function render_system_info() {
        echo esc_html( self::get_system_info() );
    }
}