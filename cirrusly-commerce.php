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

define( 'CIRRUSLY_COMMERCE_VERSION', '1.1.1' );
define( 'CIRRUSLY_COMMERCE_FILE', __FILE__ );
define( 'CIRRUSLY_COMMERCE_ABSPATH', plugin_dir_path( __FILE__ ) );
define( 'CIRRUSLY_COMMERCE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CIRRUSLY_COMMERCE_ASSETS_URL', CIRRUSLY_COMMERCE_PLUGIN_URL . 'assets/' );

if ( ! function_exists( 'cirrusly_commerce_fs' ) ) {
	// Create a helper function for easy SDK access.
	function cirrusly_commerce_fs() {
		global $cirrusly_commerce_fs;

		if ( ! isset( $cirrusly_commerce_fs ) ) {
			// Define the path to the Freemius SDK
			$fs_start_path = dirname( __FILE__ ) . '/vendor/freemius/start.php';

			// FIX: Check if the Freemius SDK exists before requiring it.
			// This prevents a Fatal Error if the 'vendor' directory is missing (e.g. gitignored repo install).
			if ( ! file_exists( $fs_start_path ) ) {
				// Optionally log this error or add an admin notice here if needed.
				return null; 
			}

			// Activate helper for the Freemius SDK.
			require_once $fs_start_path;

			$cirrusly_commerce_fs = fs_dynamic_init( array(
				'id'                  => '16238',
				'slug'                => 'cirrusly-commerce',
				'type'                => 'plugin',
				'public_key'          => 'pk_5575505e60938361719661448695d',
				'is_premium'          => true,
				'is_premium_only'     => false,
				'has_addons'          => false,
				'has_paid_plans'      => true,
				'menu'                => array(
					'slug'       => 'cirrusly-commerce',
					'first-path' => 'admin.php?page=cirrusly-commerce',
					'support'    => false,
				),
			) );
		}

		return $cirrusly_commerce_fs;
	}
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
function cirrusly_commerce() {
	return Cirrusly_Commerce::instance();
}

// Global for backwards compatibility.
$GLOBALS['cirrusly_commerce'] = cirrusly_commerce();
