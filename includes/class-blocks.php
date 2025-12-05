<?php
/**
 * Cirrusly Commerce Blocks Class
 *
 * @package    Cirrusly_Commerce
 * @subpackage Cirrusly_Commerce/includes
 * @author     Ed Oswald <ed@weatherwhys.company>
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
	 * Register the MSRP block and its editor script.
	 *
	 * Registers the block editor JavaScript under the handle `cirrusly-block-msrp`
	 * and registers the block type `cirrusly/msrp`, wiring the editor script and
	 * the server-side render callback.
	 */
	public function register_blocks() {
		// 1. Register the JavaScript file found in assets/js/
		wp_register_script(
			'cirrusly-block-msrp', // Handle
			CIRRUSLY_COMMERCE_URL . 'assets/js/block-msrp.js', // Path to file
			// Updated dependencies to include 'wp-server-side-render'
			array( 'wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render' ), 
			CIRRUSLY_COMMERCE_VERSION,
			true // Load in footer
		);

		// 2. Register the block type in PHP, linking it to the script above.
        // FIX: Added render_callback so the block actually outputs HTML on the frontend
		register_block_type( 'cirrusly/msrp', array(
			'editor_script' => 'cirrusly-block-msrp',
            'render_callback' => array( $this, 'render_msrp_block' )
		) );
	}

    /**
     * Render the MSRP block HTML for the current product.
     *
     * Ensures a product context when on a product post type, then returns the MSRP HTML for that product or an empty string when no product is available or MSRP rendering is not provided.
     *
     * @param array  $attributes Block attributes.
     * @param string $content    Inner block content.
     * @return string The rendered HTML for the MSRP block, or an empty string if nothing can be rendered.
     */
    public function render_msrp_block( $attributes, $content ) {
        global $product;
        
        // Ensure we have a product object (e.g., inside a loop or on single page)
        if ( ! $product && get_post_type() === 'product' ) {
            $product = wc_get_product( get_the_ID() );
        }
        
        if ( ! $product ) return '';

        // Reuse the logic from the Pricing class to maintain consistency
        if ( class_exists( 'Cirrusly_Commerce_Pricing' ) ) {
            return Cirrusly_Commerce_Pricing::get_msrp_html( $product );
        }
        
        return '';
    }

}