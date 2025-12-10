<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cirrusly_Commerce_Help {

    /**
     * Register the admin-side hooks required for the help center UI and bug report handling.
     *
     * Attaches:
     * - 'admin_enqueue_scripts' → enqueue_script to inject the help center scripts.
     * - 'admin_footer' → render_modal to render the help center modal markup.
     * - 'wp_ajax_cc_submit_bug_report' → handle_bug_submission to process AJAX bug reports.
     */
    public static function init() {
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_script' ) );
        add_action( 'admin_footer', array( __CLASS__, 'render_modal' ) );
        add_action( 'wp_ajax_cc_submit_bug_report', array( __CLASS__, 'handle_bug_submission' ) );
    }

    /**
     * Enqueues inline admin JavaScript that initializes the Cirrusly Help Center UI on Cirrusly admin pages.
     *
     * The script is attached to the 'cirrusly-admin-base-js' handle and provides modal open/close behavior,
     * view switching between main and bug-report form, system-info copying, and AJAX submission for the bug report form.
     *
     * @param string $hook The current admin page hook suffix; the inline script is added only when the `page` query
     *                     parameter contains the substring 'cirrusly-'.
     */
    public static function enqueue_script( $hook ) {
        if ( ! isset( $_GET['page'] ) || strpos( $_GET['page'], 'cirrusly-' ) === false ) {
            return;
        }

        // Attach to our plugin's base admin handle instead of 'common'
        wp_add_inline_script( 'cirrusly-admin-base-js', 'jQuery(document).ready(function($){
            // --- UI Interactions ---
            $("#cc-open-help-center").click(function(e){
                e.preventDefault();
                $("#cc-help-backdrop, #cc-help-modal").fadeIn(200);
            });
            $("#cc-close-help, #cc-help-backdrop").click(function(){
                $("#cc-help-backdrop, #cc-help-modal").fadeOut(200);
                $("#cc-help-main-view").show();
                $("#cc-help-form-view").hide();
                $("#cc-bug-response").html("").hide();
            });
            $("#cc-btn-bug-report").click(function(e){
                e.preventDefault();
                $("#cc-help-main-view").hide();
                $("#cc-help-form-view").fadeIn(200);
            });
            $("#cc-btn-back-help").click(function(e){
                e.preventDefault();
                $("#cc-help-form-view").hide();
                $("#cc-help-main-view").fadeIn(200);
            });
            $("#cc-bug-report-form").on("submit", function(e){
                e.preventDefault();
                var $form = $(this);
                var $btn  = $form.find("button[type=submit]");
                var $msg  = $("#cc-bug-response");
                $btn.prop("disabled", true).text("Sending...");
                $msg.hide().removeClass("notice-error notice-success");
                var formData = $form.serialize() + "&system_info=" + encodeURIComponent($("#cc-sys-info-text").val());
                $.post(ajaxurl, formData, function(response) {
                    $btn.prop("disabled", false).text("Send Report");
                    if ( response.success ) {
                        $form[0].reset();
                        $("#cc-help-form-view").hide();
                        $("#cc-help-main-view").fadeIn();
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

    / **
     * Outputs the Help Center button into the admin interface.
     *
     * The button is rendered as an anchor element with id "cc-open-help-center",
     * classes "button button-secondary", and includes the help dashicon. It is
     * intended to act as the trigger for opening the Help Center modal.
     */
    public static function render_button() {
        echo '<a href="#" id="cc-open-help-center" class="button button-secondary"><span class="dashicons dashicons-editor-help" style="vertical-align:middle;margin-top:2px;"></span> Help Center</a>';
    }

    /**
     * Outputs the Help Center modal and backdrop HTML for Cirrusly admin pages.
     *
     * Renders a modal with a documentation link, support mailto, system health log (populated from get_system_info()),
     * and a bug report form (prefilled with the current user's email). The modal is only printed when the current
     * admin page query parameter `page` contains `cirrusly-`.
     */
    public static function render_modal() {
        if ( ! isset( $_GET['page'] ) || strpos( $_GET['page'], 'cirrusly-' ) === false ) {
            return;
        }
        $mailto = 'mailto:help@cirruslyweather.com?subject=Support%20Request';
        $current_user = wp_get_current_user();
        ?>
        <div id="cc-help-backdrop" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999;"></div>
        <div id="cc-help-modal" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%, -50%); width:650px; background:#fff; box-shadow:0 4px 25px rgba(0,0,0,0.15); z-index:10000; border-radius:6px; overflow:hidden;">
            <div class="cc-help-header" style="background:#f0f0f1; padding:15px 20px; border-bottom:1px solid #c3c4c7; display:flex; justify-content:space-between; align-items:center;">
                <h3 style="margin:0; font-size:16px;">Cirrusly Help Center</h3>
                <button type="button" id="cc-close-help" style="background:none; border:none; cursor:pointer; font-size:24px; line-height:1; color:#646970;">&times;</button>
            </div>
            <div id="cc-help-main-view" class="cc-help-body" style="padding:0; display:flex; height: 450px;">
                <div style="width:40%; padding:20px; border-right:1px solid #eee; background:#fff;">
                    <h4 style="margin-top:0;"><span class="dashicons dashicons-book-alt" style="color:#2271b1;"></span> Documentation</h4>
                    <p style="color:#666; font-size:13px; margin-bottom:15px;">Check the manual for setup guides and troubleshooting.</p>
                    <a href="<?php echo esc_url( admin_url('admin.php?page=cirrusly-manual') ); ?>" class="button" style="width:100%; text-align:center;">Open User Manual</a>
                    <hr style="border:0; border-top:1px solid #eee; margin:20px 0;">
                    <h4 style="margin-top:0;"><span class="dashicons dashicons-email-alt" style="color:#2271b1;"></span> Support</h4>
                    <p style="color:#666; font-size:13px; margin-bottom:15px;">Having trouble? Reach out to our team.</p>
                    <a href="<?php echo esc_url( $mailto ); ?>" class="button" style="width:100%; text-align:center; margin-bottom:10px;">Email Support</a>
                    <button type="button" id="cc-btn-bug-report" class="button button-primary" style="width:100%; text-align:center;">
                        <span class="dashicons dashicons-warning" style="font-size:16px; vertical-align:middle; margin-right:5px;"></span> Submit Bug Report
                    </button>
                </div>
                <div style="width:60%; padding:20px; background:#f9f9f9;">
                    <h4 style="margin-top:0; display:flex; justify-content:space-between; align-items:center;">
                        <span>System Health</span>
                        <button type="button" class="button button-small" onclick="var copyText = document.getElementById('cc-sys-info-text');navigator.clipboard.writeText(copyText.value).then(function(){alert('Copied to clipboard!');}).catch(function(){copyText.select();document.execCommand('copy');alert('Copied to clipboard!');});">Copy Log</button>
                    </h4>
                    <p style="color:#666; font-size:12px; margin-bottom:10px;">This info will be automatically attached to your bug report.</p>
                    <textarea id="cc-sys-info-text" style="width:100%; height:320px; font-family:monospace; font-size:11px; background:#fff; border:1px solid #ccc; white-space:pre;" readonly><?php echo esc_textarea( self::get_system_info() ); ?></textarea>
                </div>
            </div>
            <div id="cc-help-form-view" style="display:none; height:450px; padding:20px; background:#fff;">
                <h4 style="margin-top:0; margin-bottom:20px; display:flex; align-items:center;">
                    <button type="button" id="cc-btn-back-help" class="button button-small" style="margin-right:10px;"><span class="dashicons dashicons-arrow-left-alt2"></span> Back</button>
                    Submit Bug Report
                </h4>
                <div id="cc-bug-response" style="display:none; margin-bottom:15px; padding:10px;"></div>
                <form id="cc-bug-report-form">
                    <input type="hidden" name="action" value="cc_submit_bug_report">
                    <?php wp_nonce_field( 'cc_bug_report_nonce', 'security' ); ?>
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

    /**
     * Process an AJAX bug report submission, validate and sanitize input, and send a formatted email to support.
     *
     * Verifies the request nonce and current user's capabilities, validates the reporter's email, ensures the mailer
     * dependency is available, and sends an HTML email to help@cirruslyweather.com containing the user-provided
     * subject, message, and system information. Responses are returned to the AJAX caller as JSON success or error messages.
     */
    public static function handle_bug_submission() {
        if ( ! isset( $_POST['security'] ) || ! wp_verify_nonce(sanitize_text_field( wp_unslash( $_POST['security'] ) ), 'cc_bug_report_nonce' ) ) {
            wp_send_json_error( 'Security check failed. Please refresh the page.' );
        }
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'edit_products' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }
        $user_email = sanitize_email( $_POST['user_email'] );
        $subject    = sanitize_text_field( $_POST['subject'] );
        $message    = sanitize_textarea_field( $_POST['message'] );
        $sys_info   = isset($_POST['system_info']) ? sanitize_textarea_field( $_POST['system_info'] ) : 'Not provided';
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

    /**
     * Build a plain-text summary of the current site and environment.
     *
     * The returned text includes site URL, WordPress version, WooCommerce version (or "Not Installed"),
     * Cirrusly Commerce version (or "Unknown"), PHP version, server software, and a newline-separated list
     * of active plugin file paths.
     *
     * @return string A multiline plain-text system information block suitable for inclusion in bug reports.
     */
    public static function get_system_info() {
        global $wp_version;
        $out  = "### System Info ###\n";
        $out .= "Site URL: " . site_url() . "\n";
        $out .= "WP Version: " . $wp_version . "\n";
        $out .= "WooCommerce: " . (class_exists('WooCommerce') ? WC()->version : 'Not Installed') . "\n";
        $out .= "Cirrusly Commerce: " . ( defined('CIRRUSLY_COMMERCE_VERSION') ? CIRRUSLY_COMMERCE_VERSION : 'Unknown' ) . "\n";
        $out .= "PHP Version: " . phpversion() . "\n";
        $out .= "Server Software: " . esc_html( sanitize_text_field( $_SERVER['SERVER_SOFTWARE'] ) ) . "\n";        $out .= "Active Plugins:\n";
        $plugins = get_option('active_plugins');
        if ( is_array( $plugins ) ) {
            foreach( $plugins as $p ) { $out .= "- " . esc_html( $p ) . "\n"; }
        }
        return $out;
    }

    /**
     * Outputs the site's system information as escaped plain text for display in the admin UI.
     *
     * The output is escaped for safe HTML rendering.
     */
    public static function render_system_info() {
        echo esc_html( self::get_system_info() );
    }
}