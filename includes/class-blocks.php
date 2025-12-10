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
        // Register Custom Block Category
        add_filter( 'block_categories_all', array( $this, 'register_block_category' ), 10, 2 );
	}

    /**
     * Add "Cirrusly Commerce" to the Gutenberg Block Inserter.
     */
    public function register_block_category( $categories, $post ) {
        return array_merge(
            $categories,
            array(
                array(
                    'slug'  => 'cirrusly',
                    'title' => __( 'Cirrusly Commerce', 'cirrusly-commerce' ),
                    'icon'  => 'cloud', // Dashicon
                ),
            )
        );
    }

	/**
	 * Register blocks and editor scripts.
	 */
	public function register_blocks() {
        $deps = array( 'wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render', 'wp-date' );

		// 1. MSRP Block
		wp_register_script(
			'cirrusly-block-msrp',
			CIRRUSLY_COMMERCE_URL . 'assets/js/block-msrp.js', 
			$deps, 
			CIRRUSLY_COMMERCE_VERSION,
			true
		);
        register_block_type( 'cirrusly/msrp', array(
            'editor_script' => 'cirrusly-block-msrp',
            'render_callback' => array( $this, 'render_msrp_block' ),
            'attributes' => array(
                'textAlign' => array( 'type' => 'string', 'default' => 'left' ),
                'showStrikethrough' => array( 'type' => 'boolean', 'default' => true ),
                'isBold' => array( 'type' => 'boolean', 'default' => false ),
            ),
        ) );

        // 2. Countdown Block
        wp_register_script(
            'cirrusly-block-countdown',
            CIRRUSLY_COMMERCE_URL . 'assets/js/block-countdown.js',
            $deps,
            CIRRUSLY_COMMERCE_VERSION,
            true
        );
        register_block_type( 'cirrusly/countdown', array(
            'editor_script' => 'cirrusly-block-countdown',
            'render_callback' => array( $this, 'render_countdown_block' ),
            'attributes' => array(
                'textAlign' => array( 'type' => 'string', 'default' => 'left' ),
                'label' => array( 'type' => 'string', 'default' => 'Sale Ends In:' ),
                'useMeta' => array( 'type' => 'boolean', 'default' => true ),
                'manualDate' => array( 'type' => 'string', 'default' => '' ),
            ),
        ) );

        // 3. Badges Block
        wp_register_script(
            'cirrusly-block-badges',
            CIRRUSLY_COMMERCE_URL . 'assets/js/block-badges.js',
            $deps,
            CIRRUSLY_COMMERCE_VERSION,
            true
        );
        register_block_type( 'cirrusly/badges', array(
            'editor_script' => 'cirrusly-block-badges',
            'render_callback' => array( $this, 'render_badges_block' ),
            'attributes' => array(
                'align' => array( 'type' => 'string', 'default' => 'left' ),
            ),
        ) );

        // 4. Discount Notice Block
        wp_register_script(
            'cirrusly-block-discount-notice',
            CIRRUSLY_COMMERCE_URL . 'assets/js/block-discount-notice.js',
            $deps,
            CIRRUSLY_COMMERCE_VERSION,
            true
        );
        register_block_type( 'cirrusly/discount-notice', array(
            'editor_script' => 'cirrusly-block-discount-notice',
            'render_callback' => array( $this, 'render_discount_notice_block' ),
            'attributes' => array(
                'message' => array( 'type' => 'string', 'default' => '⚡ Exclusive Price Unlocked!' ),
            ),
        ) );
	}

	/**
     * Render the MSRP block.
     */
    public function render_msrp_block( $attributes, $content ) {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
        global $product;
        $product = $this->ensure_product_context( $attributes, $product );
        
        // If still no product (empty store?), fail gracefully
        if ( ! $product || ! is_object( $product ) ) {
            if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return '<div class="cw-placeholder">Add a product to preview MSRP.</div>';
            return '';
        }

        $msrp_html = '';
        // FIXED: Check for and call the Frontend class directly, as that is where get_msrp_html resides.
        if ( class_exists( 'Cirrusly_Commerce_Pricing_Frontend' ) ) {
            $msrp_html = Cirrusly_Commerce_Pricing_Frontend::get_msrp_html( $product );
        }
        
        if ( empty( $msrp_html ) ) {
            if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
                $msrp_html = '<div class="cw-msrp-container" style="color:#999;font-size:0.9em;margin-bottom:5px;line-height:1;border:1px dashed #ccc;padding:2px;">MSRP: <span class="cw-msrp-value" style="text-decoration:line-through;">$99.99</span> <small>(Preview)</small></div>';
            } else {
                return '';
            }
        }

        $align = isset( $attributes['textAlign'] ) ? $attributes['textAlign'] : 'left';
        $is_bold = isset( $attributes['isBold'] ) ? $attributes['isBold'] : false;
        $strikethrough = isset( $attributes['showStrikethrough'] ) ? $attributes['showStrikethrough'] : true;

        $style_parts = array( 'text-align:' . esc_attr( $align ), 'display:block', 'width:100%' );
        if ( $is_bold ) $style_parts[] = 'font-weight:bold';
        if ( ! $strikethrough ) $msrp_html = str_replace( 'text-decoration:line-through;', 'text-decoration:none;', $msrp_html );

        return sprintf( '<div class="cirrusly-msrp-block-wrapper" style="%s">%s</div>', implode( '; ', $style_parts ), $msrp_html );
    }

    /**
     * Render the Countdown Block
     */
    public function render_countdown_block( $attributes, $content ) {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
        global $product;
        $product = $this->ensure_product_context( $attributes, $product );
        if ( ! $product || ! is_object( $product ) ) {
             if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return '<div class="cw-placeholder">Add a product to preview Timer.</div>';
             return '';
        }

        $end_date = '';

        // Priority 1: Smart / Meta (if enabled)
        if ( ! empty( $attributes['useMeta'] ) ) {
             if ( class_exists( 'Cirrusly_Commerce_Countdown' ) ) {
                 $config = Cirrusly_Commerce_Countdown::get_smart_countdown_config( $product );
                 if ( $config && is_array( $config ) && ! empty( $config['end'] ) ) {
                     $end_date = $config['end'];
                 }
             }
        }
        
        // Priority 2: Manual Override (Block Attributes)
        if ( ! $end_date && ! empty( $attributes['manualDate'] ) ) {
            $end_date = $attributes['manualDate'];
        }

        if ( empty( $end_date ) ) {
             if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
                 return '<div style="padding:10px; border:1px dashed #ccc; text-align:center;">[Countdown Timer: No active date found for this product]</div>';
             }
             return '';
        }

        if ( class_exists( 'Cirrusly_Commerce_Countdown' ) ) {
            return Cirrusly_Commerce_Countdown::generate_timer_html( 
                $end_date, 
                $attributes['label'], 
                $attributes['textAlign'] 
            );
        }
        return '';
    }

    /**
     * Render the Badges Block
     */
    public function render_badges_block( $attributes, $content ) {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
        global $product;
        $product = $this->ensure_product_context( $attributes, $product );
        if ( ! $product || ! is_object( $product ) ) {
            if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return '<div class="cw-placeholder">Add a product to preview Badges.</div>';
            return '';
        }

        $html = '';
        if ( class_exists( 'Cirrusly_Commerce_Badges' ) ) {
            $html = Cirrusly_Commerce_Badges::get_badge_html( $product );
        }

        if ( empty( $html ) ) {
             if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
                 return '<div style="padding:5px; border:1px dashed #ccc; text-align:center;">[Smart Badges: No active badges for product]</div>';
             }
             return '';
        }

        $align = isset( $attributes['align'] ) ? $attributes['align'] : 'left';
        return '<div class="cw-badge-container cw-block-render" style="text-align:' . esc_attr( $align ) . '">' . $html . '</div>';
    }

    /**
     * Render the Discount Notice Block
     */
    public function render_discount_notice_block( $attributes, $content ) {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
        global $product;
        // Notice block might be global, but usually context-aware
        $product = $this->ensure_product_context( $attributes, $product );
        
        $has_discount = false;
        if ( $product && is_object($product) && class_exists( 'Cirrusly_Commerce_Automated_Discounts' ) ) {
            $discount = Cirrusly_Commerce_Automated_Discounts::get_active_discount( $product->get_id() );
            if ( $discount ) $has_discount = true;
        }

        // Always show in Editor
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            $has_discount = true; 
        }

        if ( ! $has_discount ) return '';

        $message = ! empty( $attributes['message'] ) ? $attributes['message'] : '⚡ Exclusive Price Unlocked!';
        
        return sprintf(
            '<div class="cw-discount-notice" style="background:#e0f7fa; color:#006064; padding:10px; border-radius:4px; text-align:center; font-weight:bold; margin-bottom:15px;">%s</div>',
            esc_html( $message )
        );
    }

    /**
     * Helper to get product object in Editor (using block attributes) or Frontend.
     * UPDATED: Now fetches the latest product if no context is found in the Editor.
     */
    private function ensure_product_context( $attributes, $global_product ) {
        // 1. Check if specific product ID passed via attributes (uncommon in basic blocks but possible)
        if ( isset( $attributes['productId'] ) && $attributes['productId'] > 0 ) {
            return wc_get_product( $attributes['productId'] );
        }

        // 2. Check Global Product (Frontend / Single Product Template)
        if ( $global_product && is_object( $global_product ) ) {
            return $global_product;
        }

        // 3. Try get_the_ID() - Works in Query Loops on Frontend
        $post_id = get_the_ID();
        if ( $post_id && 'product' === get_post_type( $post_id ) ) {
            return wc_get_product( $post_id );
        }

        // 4. PREVIEW FIX: If we are in the REST API (Editor) and still have no product...
        // This happens in Site Editor > Templates where get_the_ID() is the template ID.
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            // Fetch the most recent product to use as a mock
            $recent_products = wc_get_products( array( 
                'limit' => 1, 
                'orderby' => 'date', 
                'order' => 'DESC' 
            ) );
            
            if ( ! empty( $recent_products ) ) {
                return reset( $recent_products );
            }
        }

        return null;
    }
}