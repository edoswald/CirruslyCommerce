<?php
/**
 * Plugin Name: Cirrusly Commerce
 * Description: All-in-one suite: GMC Assistant, Promotion Manager, Pricing Engine, and Store Financial Audit that doesn't cost an arm and a leg.
 * Version: 1.2.0
 * Author: Cirrusly Weather
 * Author URI: https://cirruslyweather.com
 * Text Domain: cirrusly-commerce
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define Constants
define( 'CIRRUSLY_COMMERCE_VERSION', '1.2.0' );
define( 'CIRRUSLY_COMMERCE_PATH', plugin_dir_path( __FILE__ ) );
define( 'CIRRUSLY_COMMERCE_URL', plugin_dir_url( __FILE__ ) );

// -------------------------------------------------------------------------
// FREEMIUS INTEGRATION
// -------------------------------------------------------------------------
if ( ! function_exists( 'cc_fs' ) ) {
    /**
     * Get the Freemius SDK instance for the plugin.
     *
     * Initializes the SDK on first call and returns the shared instance.
     *
     * @return object Freemius SDK instance.
     */
    function cc_fs() {
        global $cc_fs;

        if ( ! isset( $cc_fs ) ) {
            // Include Freemius SDK.
            require_once dirname( __FILE__ ) . '/vendor/freemius/start.php';

            $cc_fs = fs_dynamic_init( array(
                'id'                  => '22048',
                'slug'                => 'cirrusly-commerce',
                'type'                => 'plugin',
                'public_key'          => 'pk_34dc77b4bc7764037f0e348daac4a',
                'is_premium'          => true,
                'premium_suffix'      => 'Pro',
                // If your plugin is a serviceware, set this option to false.
                'has_premium_version' => true,
                'has_addons'          => false,
                'has_paid_plans'      => true,
                // Automatically removed in the free version. If you're not using the
                // auto-generated free version, delete this line before uploading to wp.org.
                'wp_org_gatekeeper'   => 'OA7#BoRiBNqdf52FvzEf!!074aRLPs8fspif$7K1#4u4Csys1fQlCecVcUTOs2mcpeVHi#C2j9d09fOTvbC0HloPT7fFee5WdS3G',
                'trial'               => array(
                    'days'               => 3,
                    'is_require_payment' => false,
                ),
                'menu'                => array(
                    'slug'           => 'cirrusly-settings',
                    'support'        => false,
                ),
            ) );
        }

        return $cc_fs;
    }

    // Init Freemius.
    cc_fs();
    // Signal that SDK was initiated.
    do_action( 'cc_fs_loaded' );
}

// Init Freemius.
cirrusly_commerce_fs();
// Signal that SDK was initiated.
do_action( 'cirrusly_commerce_fs_loaded' );

// Include the main class.
if ( ! class_exists( 'Cirrusly_Commerce' ) ) {
	include_once dirname( __FILE__ ) . '/includes/class-core.php';
}

/**
 * Main instance of Cirrusly_Commerce.
 *
 * Returns the main instance of Cirrusly_Commerce to prevent the need to use globals.
 *
 * @since  1.0.0
 * @return Cirrusly_Commerce
 */
class Cirrusly_Commerce_Main {

    private static $instance = null;

    / **
     * Retrieve the singleton instance of Cirrusly_Commerce_Main, creating it if necessary.
     *
     * @return Cirrusly_Commerce_Main The single instance of the main plugin class.
     */
    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Bootstraps plugin modules, registers lifecycle hooks, and adds the plugin settings link.
     *
     * Instantiates core plugin modules, registers activation and deactivation handlers, and
     * injects the Settings (and upgrade) link into the plugin list in the admin.
     */
    public function __construct() {
        // Include Module Classes
        require_once CIRRUSLY_COMMERCE_PATH . 'includes/class-gmc.php';
        require_once CIRRUSLY_COMMERCE_PATH . 'includes/class-pricing.php';
        require_once CIRRUSLY_COMMERCE_PATH . 'includes/class-audit.php';
        require_once CIRRUSLY_COMMERCE_PATH . 'includes/class-reviews.php';
        require_once CIRRUSLY_COMMERCE_PATH . 'includes/class-blocks.php';
        require_once CIRRUSLY_COMMERCE_PATH . 'includes/class-compatibility.php';
        require_once CIRRUSLY_COMMERCE_PATH . 'includes/class-badges.php';

        // Initialize Modules
        new Cirrusly_Commerce_Core();
        new Cirrusly_Commerce_GMC();
        new Cirrusly_Commerce_Pricing();
        new Cirrusly_Commerce_Audit();
        new Cirrusly_Commerce_Reviews();
        new Cirrusly_Commerce_Blocks();
        new Cirrusly_Commerce_Compatibility();
        new Cirrusly_Commerce_Badges();

        // Register Hooks
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

        // Add Settings Link to Plugin List
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_settings_link' ) );

        // Register Custom Cron Schedules
        add_filter( 'cron_schedules', array( $this, 'add_weekly_cron_schedule' ) );
    }

    /**
     * Registers a 'weekly' cron schedule if it does not already exist.
     *
     * @param array $schedules The existing cron schedules.
     * @return array The modified cron schedules with the weekly interval.
     */
    public function add_weekly_cron_schedule( $schedules ) {
        if ( ! isset( $schedules['weekly'] ) ) {
            $schedules['weekly'] = array(
                'interval' => 7 * 24 * 60 * 60, // 604,800 seconds
                'display'  => __( 'Weekly', 'cirrusly-commerce' ),
            );
        }
        return $schedules;
    }

    /**
     * Run activation tasks for the plugin.
     *
     * Schedules a daily 'cirrusly_gmc_daily_scan' cron event if not already scheduled, schedules a weekly
     * 'cirrusly_weekly_profit_report' cron event if not already scheduled, and enables the
     * WooCommerce "cost of goods sold" option by setting the `woocommerce_enable_cost_of_goods_sold`
     * option to "yes".
     */
    public function activate() {
        // 1. Schedule Scans
        if ( ! wp_next_scheduled( 'cirrusly_gmc_daily_scan' ) ) {
            wp_schedule_event( time(), 'daily', 'cirrusly_gmc_daily_scan' );
        }

        // 2. Schedule Weekly Reports [ADDED]
        if ( ! wp_next_scheduled( 'cirrusly_weekly_profit_report' ) ) {
            wp_schedule_event( time(), 'weekly', 'cirrusly_weekly_profit_report' );
        }
        
        // 3. Force Enable Native COGS on Activation
        update_option( 'woocommerce_enable_cost_of_goods_sold', 'yes' );
        
    }

    / **
     * Run plugin deactivation routines.
     *
     * Clears the plugin's scheduled cron hooks and notifies Freemius of the deactivation event when the Freemius SDK is available.
     */
    public function deactivate() {
        wp_clear_scheduled_hook( 'cirrusly_gmc_daily_scan' );
        wp_clear_scheduled_hook( 'cirrusly_weekly_profit_report' ); // [ADDED]
        
        // Freemius deactivation hook (safe check)
        if ( function_exists('cc_fs') && cc_fs() ) {
            cc_fs()->_deactivate_plugin_event();
        }
    }

    /**
     * Insert the plugin Settings link into the plugin action links and add a "Go Pro" link for free users when the Freemius SDK is available.
     *
     * @param array $links Existing plugin action links.
     * @return array The modified links array with the Settings link prepended and a "Go Pro" link added when applicable.
     */
    public function add_settings_link( $links ) {
        $settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=cirrusly-settings' ) ) . '">Settings</a>';
        array_unshift( $links, $settings_link );
        
        // Add "Go Pro" link if Free AND Freemius is loaded
        if ( function_exists('cc_fs') && cc_fs() && cc_fs()->is_not_paying() ) {
            $links['go_pro'] = '<a href="' . cc_fs()->get_upgrade_url() . '" style="color:#d63638;font-weight:bold;">Go Pro</a>';
        }
        
        return $links;
    }
}

// Boot
Cirrusly_Commerce_Main::instance();