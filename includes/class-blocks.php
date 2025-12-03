<?php
/**
 * Cirrusly Commerce Blocks Class
 *
 * @package    Cirrusly_Commerce
 * @subpackage Cirrusly_Commerce/includes
 * @author     Ed Oswald <ed@cirruslycommerce.com>
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cirrusly_Commerce_Blocks {

	/**
	 * Cirrusly_Commerce_Blocks Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_blocks' ) );
	}

	/**
	 * Register blocks.
	 */
	public function register_blocks() {
		// 1. Register the JavaScript file found in assets/js/
		wp_register_script(
			'cirrusly-block-msrp', // Handle
			CIRRUSLY_COMMERCE_ASSETS_URL . 'js/block-msrp.js', // Path to file
			array( 'wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n' ), // Dependencies
			CIRRUSLY_COMMERCE_VERSION,
			true // Load in footer
		);

		// 2. Register the block type in PHP, linking it to the script above.
		// NOTE: The block name 'cirrusly/msrp' must match the name used in your JS file exactly.
		register_block_type( 'cirrusly/msrp', array(
			'editor_script' => 'cirrusly-block-msrp',
		) );
	}

}
