<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cirrusly_Commerce_Blocks {

    public function __construct() {
        add_action( 'init', array( $this, 'register_blocks' ) );
    }

    public function register_blocks() {
        wp_register_script(
            'cirrusly-msrp-block',
            CIRRUSLY_COMMERCE_URL . 'assets/js/block-msrp.js',
            array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-data' ), 
            CIRRUSLY_COMMERCE_VERSION,
            true
        );

        register_block_type( 'cirrusly-commerce/msrp', array(
            'apiVersion'      => 2,
            'editor_script'   => 'cirrusly-msrp-block',
            'render_callback' => array( $this, 'render_msrp_block' ),
            'attributes'      => array(
                'className' => array( 'type' => 'string', 'default' => '' ),
                'textAlign' => array( 'type' => 'string', 'default' => 'left' ),
                'showStrikethrough' => array( 'type' => 'boolean', 'default' => true ),
                'isBold' => array( 'type' => 'boolean', 'default' => false ),
            ),
        ) );
    }

    public function render_msrp_block( $attributes, $content ) {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
        global $product;

        if ( ! is_object( $product ) ) {
            $product_id = get_the_ID();
            if ( $product_id && 'product' === get_post_type( $product_id ) ) {
                $product = wc_get_product( $product_id );
            }
        }

        if ( ! is_object( $product ) ) {
            return '';
        }

        if ( class_exists( 'Cirrusly_Commerce_Pricing' ) ) {
            $html = Cirrusly_Commerce_Pricing::get_msrp_html( $product );
            
            if ( empty($html) ) return '';

            $style = array();
            if ( isset($attributes['textAlign']) ) {
                $style[] = 'text-align:' . esc_attr($attributes['textAlign']);
            }
            if ( isset($attributes['isBold']) && $attributes['isBold'] ) {
                $style[] = 'font-weight:bold';
            }
            
            if ( isset($attributes['showStrikethrough']) && !$attributes['showStrikethrough'] ) {
                $html = str_replace( 'text-decoration:line-through;', 'text-decoration:none;', $html );
            }

            $style_str = !empty($style) ? ' style="' . implode(';', $style) . '"' : '';
            
            return '<div class="cirrusly-msrp-block-wrapper ' . esc_attr($attributes['className']) . '"' . $style_str . '>' . wp_kses_post($html) . '</div>';
        }

        return '';
    }
}