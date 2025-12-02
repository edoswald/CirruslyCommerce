<?php
/**
 * Plugin Name: Cirrusly Commerce
 * Description: All-in-one suite: GMC Assistant, Promotion Manager, Pricing Engine, and Store Financial Audit that doesn't cost an arm and a leg.
 * Version: 1.1
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
define( 'CIRRUSLY_COMMERCE_VERSION', '1.1' );
define( 'CIRRUSLY_COMMERCE_PATH', plugin_dir_path( __FILE__ ) );
define( 'CIRRUSLY_COMMERCE_URL', plugin_dir_url( __FILE__ ) );

// Autoloader-style requires
require_once CIRRUSLY_COMMERCE_PATH . 'includes/class-core.php';
require_once CIRRUSLY_COMMERCE_PATH . 'includes/class-gmc.php';
require_once CIRRUSLY_COMMERCE_PATH . 'includes/class-pricing.php';
require_once CIRRUSLY_COMMERCE_PATH . 'includes/class-audit.php';
require_once CIRRUSLY_COMMERCE_PATH . 'includes/class-reviews.php';
require_once CIRRUSLY_COMMERCE_PATH . 'includes/class-manual.php';
require_once CIRRUSLY_COMMERCE_PATH . 'includes/class-blocks.php';
require_once CIRRUSLY_COMMERCE_PATH . 'includes/class-compatibility.php';
require_once CIRRUSLY_COMMERCE_PATH . 'includes/class-badges.php';

/**
 * Main Instance Class
 */
class Cirrusly_Commerce_Main {

    private static $instance = null;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
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
    }

    public function activate() {
        // 1. Schedule Scans
        if ( ! wp_next_scheduled( 'cirrusly_gmc_daily_scan' ) ) {
            wp_schedule_event( time(), 'daily', 'cirrusly_gmc_daily_scan' );
        }
        
        // 2. Force Enable Native COGS on Activation
        update_option( 'woocommerce_enable_cost_of_goods_sold', 'yes' );
    }

    public function deactivate() {
        wp_clear_scheduled_hook( 'cirrusly_gmc_daily_scan' );
    }

    public function add_settings_link( $links ) {
        $settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=cirrusly-settings' ) ) . '">Settings</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }
}

// Boot
Cirrusly_Commerce_Main::instance();
