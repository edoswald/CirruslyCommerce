<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Cirrusly_Commerce_Core {

    /**
     * Holds the global configuration settings for the audit system.
     *
     * @var array
     */
    public $global_config = array();

    /**
     * Initialize the core system by loading required dependencies and registering hooks.
     *
     * Loads necessary classes and sets up actions and filters that drive the plugin's behavior.
     */
    public function __construct() {
        $this->load_dependencies();
        $this->define_hooks();
    }

    /**
     * Retrieve the global configuration array.
     *
     * @return array The global configuration settings or an empty array if not set.
     */
    public function get_global_config() {
        return isset( $this->global_config ) ? $this->global_config : array();
    }

    /**
     * Loads plugin PHP dependencies required for different runtime contexts.
     *
     * Conditionally requires core utilities always, admin-only components when running in the admin area, and Pro-only libraries when the site has Pro enabled and the Pro files exist. 
     */
    private function load_dependencies() {
        // Utilities (Always needed)
        require_once plugin_dir_path( __FILE__ ) . 'class-security.php';

        // Admin-Specific Loading (Performance Win)
        if ( is_admin() ) {
            require_once plugin_dir_path( __FILE__ ) . 'admin/class-admin-assets.php';
            require_once plugin_dir_path( __FILE__ ) . 'admin/class-settings-manager.php';
            require_once plugin_dir_path( __FILE__ ) . 'admin/class-dashboard-ui.php';
        }

        // Pro-Only Loading (Freemius Best Practice)
        // Only load heavy API clients if the user is actually Pro
        if ( self::cirrusly_is_pro() && file_exists( plugin_dir_path( __FILE__ ) . 'pro/class-google-api-client.php' ) ) {
            require_once plugin_dir_path( __FILE__ ) . 'pro/class-google-api-client.php';
        }
    }

    /**
     * Register WordPress actions and filters used by the plugin.
     *
     * Initializes core modules, registers admin-only integrations (menus, settings, assets, dashboard UI),
     * sets up AJAX and scheduled-scan handlers, and applies runtime filters and save-post hooks.
     */
    private function define_hooks() {
        // Init other modules
        add_action('init', array('Cirrusly_Commerce_Audit', 'init'));
        
        // Reports (Weekly Email)
        if ( class_exists( 'Cirrusly_Commerce_Reports' ) ) {
            Cirrusly_Commerce_Reports::init();
        }

        // Admin Hooks
        if ( is_admin() ) {
            $settings = new Cirrusly_Commerce_Settings_Manager();
            $assets   = new Cirrusly_Commerce_Admin_Assets();
            $dash     = new Cirrusly_Commerce_Dashboard_UI();

            add_action( 'admin_menu', array( $settings, 'register_admin_menus' ) );
            add_action( 'admin_init', array( $settings, 'register_settings' ) );
            add_action( 'admin_enqueue_scripts', array( $assets, 'enqueue' ) );
            add_action( 'wp_dashboard_setup', array( $dash, 'register_widget' ) );
            add_action( 'admin_notices', array( $settings, 'render_onboarding_notice' ) );
            
            // Delegate Main Dashboard Page rendering to the UI class
            // Note: register_admin_menus in Settings Manager should point to $dash->render_main_dashboard()
        }

        // AJAX: Audit Save
        add_action( 'wp_ajax_cc_audit_save', array( $this, 'handle_audit_inline_save' ) );

        // Scheduled Scan (Delegated to Pro Class if exists, or basic handler)
        add_action( 'cirrusly_gmc_daily_scan', array( $this, 'execute_scheduled_scan_router' ) );

        // Runtime Logic
        add_action( 'save_post_product', array( $this, 'clear_metrics_cache' ) );
        add_filter( 'pre_option_woocommerce_enable_cost_of_goods_sold', '__return_yes' );
    }

    /**
     * Delegate execution of scheduled scans to the Pro Google API client when available.
     *
     * If the `Cirrusly_Commerce_Google_API_Client` class is present, calls its
     * static `execute_scheduled_scan()` method; otherwise performs no action.
     *
     * @return void
     */
    public function execute_scheduled_scan_router() {
        if ( class_exists('Cirrusly_Commerce_Google_API_Client') ) {
            Cirrusly_Commerce_Google_API_Client::execute_scheduled_scan();
        }
    }

    /**
     * Handle the AJAX request that saves an inline audit entry.
     *
     * Validates and processes data submitted via the `cc_audit_save` AJAX action, persists the audit result,
     * and emits the appropriate AJAX response for the requester.
     *
     * @return void
     */
    public function handle_audit_inline_save() {
        // (Keep the existing AJAX logic here, or move to Audit class)
        // ... existing code ...
    }

    /**
     * Clears the cached dashboard metrics.
     *
     * Deletes the 'cirrusly_dashboard_metrics' transient so dashboard metrics are regenerated on next request.
     */
    public function clear_metrics_cache() {
        delete_transient( 'cirrusly_dashboard_metrics' );
    }

    /**
     * Determines whether Pro features are available for this installation.
     *
     * When WP_DEBUG is enabled and the current user can manage options, the `cc_dev_mode` query
     * parameter overrides the status (value `'pro'` enables Pro). Otherwise, if the Freemius
     * helper `cc_fs()` exists, its `can_use_premium_code()` result determines Pro availability.
     *
     * @return bool `true` if Pro features are available, `false` otherwise.
     */
    public static function cirrusly_is_pro() {
    // Dev mode bypass - requires explicit constant opt-in
    if ( defined('CIRRUSLY_DEV_MODE') && CIRRUSLY_DEV_MODE 
         && defined('WP_DEBUG') && WP_DEBUG 
         && current_user_can('manage_options') 
         && isset( $_GET['cc_dev_mode'] ) ) {
         // phpcs:ignore WordPress.Security.NonceVerification.Recommended
         return sanitize_text_field( wp_unslash( $_GET['cc_dev_mode'] ) ) === 'pro';
        }
        if ( function_exists( 'cc_fs' ) ) {
             return cc_fs()->can_use_premium_code();
        }
        return false;
    }
}