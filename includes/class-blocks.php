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
     */
    public function render_msrp_block( $attributes, $content ) {
        global $product;
        
        // Ensure we have a product object
        if ( ! $product && get_post_type() === 'product' ) {
            $product = wc_get_product( get_the_ID() );
        }
        
        // If used inside a Query Loop block, the global $product might need resetting
        if ( ! $product ) {
            return ''; 
        }

        // 1. Get the raw MSRP HTML
        $msrp_html = '';
        if ( class_exists( 'Cirrusly_Commerce_Pricing' ) ) {
            $msrp_html = Cirrusly_Commerce_Pricing::get_msrp_html( $product );
        }

        if ( empty( $msrp_html ) ) {
            return '';
        }

        // 2. Process Attributes for Styling
        $align = isset( $attributes['textAlign'] ) ? $attributes['textAlign'] : 'left';
        $is_bold = isset( $attributes['isBold'] ) && $attributes['isBold'];
        $strikethrough = isset( $attributes['showStrikethrough'] ) ? $attributes['showStrikethrough'] : true;

        // 3. Construct CSS Styles
        $styles = array();
        $styles[] = 'text-align:' . esc_attr( $align );
        $styles[] = 'display:block'; // Ensure it takes full width to respect alignment
        
        if ( $is_bold ) {
            $styles[] = 'font-weight:bold';
        }

        // Handle Strikethrough Logic
        // Since get_msrp_html hardcodes the strikethrough style, we might need to remove it if the user disabled it.
        // The original class outputs: text-decoration:line-through;
        if ( ! $strikethrough ) {
            // We strip the line-through style if the user unchecked it
            $msrp_html = str_replace( 'text-decoration:line-through;', 'text-decoration:none;', $msrp_html );
        }

        $style_string = implode( '; ', $styles );

        // 4. Return Wrapped HTML
        // We wrap the output in a div with the calculated styles
        return sprintf( 
            '<div class="cirrusly-msrp-block-wrapper" style="%s">%s</div>', 
            esc_attr( $style_string ), 
            $msrp_html // already sanitized in get_msrp_html via wc_price / manual construction
        );
    }

}