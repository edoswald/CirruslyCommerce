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
    'render_callback' => array( $this, 'render_msrp_block' ),
    'attributes' => array(
        'textAlign' => array(
            'type' => 'string',
            'default' => 'left',
        ),
        'showStrikethrough' => array(
            'type' => 'boolean',
            'default' => true,
        ),
        'isBold' => array(
            'type' => 'boolean',
            'default' => false,
        ),
    ),
) );
	}

	/**
     * Render the MSRP block HTML for the current product.
     */
    public function render_msrp_block( $attributes, $content ) {
        global $product;

    // Check if productId attribute is set (for editor preview)
    if ( isset( $attributes['productId'] ) && $attributes['productId'] > 0 ) {
        $product = wc_get_product( $attributes['productId'] );
    }
        
    // 1. Ensure we have a product object
        if ( ! $product ) {
            $product_id = get_the_ID();
            if ( $product_id ) {
                $product = wc_get_product( $product_id );
            }
        }
        
        // If still no product (e.g. on a standard post), return empty.
        if ( ! $product || ! is_object( $product ) ) return '';

        // 2. Get the raw MSRP HTML from your Pricing class
        $msrp_html = '';
        if ( class_exists( 'Cirrusly_Commerce_Pricing' ) ) {
            $msrp_html = Cirrusly_Commerce_Pricing::get_msrp_html( $product );
        }
        
        // FIX: Handle "Edge Case" where the preview product has no MSRP.
        if ( empty( $msrp_html ) ) {
            // Check if we are inside the Block Editor (REST API Request)
            if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
                // Return a placeholder so the block is visible and selectable in the editor
                $msrp_html = '<div class="cw-msrp-container" style="color:#999;font-size:0.9em;margin-bottom:5px;line-height:1;border:1px dashed #ccc;padding:2px;">MSRP: <span class="cw-msrp-value" style="text-decoration:line-through;">$99.99</span> <small>(Preview)</small></div>';
            } else {
                // On the Frontend, truly return empty if there is no MSRP
                return '';
            }
        }

        // 3. Process Attributes
        $align = isset( $attributes['textAlign'] ) ? $attributes['textAlign'] : 'left';
        $is_bold = isset( $attributes['isBold'] ) ? $attributes['isBold'] : false;
        $strikethrough = isset( $attributes['showStrikethrough'] ) ? $attributes['showStrikethrough'] : true;

        // 4. Construct CSS Styles
        $style_parts = array();
        
        // Alignment: We use text-align on a block-level container
        $style_parts[] = 'text-align:' . esc_attr( $align );
        $style_parts[] = 'display:block';
        $style_parts[] = 'width:100%';

        if ( $is_bold ) {
            $style_parts[] = 'font-weight:bold';
        }

        // Logic to remove strikethrough if disabled
        if ( ! $strikethrough ) {
            $msrp_html = str_replace( 'text-decoration:line-through;', 'text-decoration:none;', $msrp_html );
        }

        // 5. Wrap and Return
        $styles = implode( '; ', $style_parts );
        
        return sprintf( 
            '<div class="cirrusly-msrp-block-wrapper" style="%s">%s</div>', 
            esc_attr( $styles ), 
            $msrp_html 
        );
    }

}