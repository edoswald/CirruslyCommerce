<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cirrusly_Commerce_Compatibility {

    public function __construct() {
        // AdTribes (Product Feed PRO) - Already supported
        add_filter( 'woosea_custom_attributes', array( $this, 'add_woosea_attributes' ) );
        
        // Rank Math SEO - Already supported
        add_filter( 'rank_math/vars/register_extra_replacements', array( $this, 'register_rank_math_vars' ) );
        
        // Yoast SEO - Already supported
        add_filter( 'wpseo_schema_product', array( $this, 'add_yoast_schema_data' ), 10, 2 );

        // NEW: All in One SEO (AIOSEO) Schema
        add_filter( 'aioseo_schema_output', array( $this, 'add_aioseo_schema_data' ) );

        // NEW: SEOPress Schema
        add_filter( 'seopress_json_ld_product', array( $this, 'add_seopress_schema_data' ) );

        // NEW: Google Product Feed (Ademti / WooCommerce Official)
        add_filter( 'woocommerce_gpf_elements', array( $this, 'add_ademti_feed_elements' ), 10, 2 );
    }

    /**
     * AdTribes Feed Support
     */
    public function add_woosea_attributes( $attributes ) {
        $extra = array(
            'cogs_total_value'      => 'Cost of Goods (Cirrusly)',
            'auto_pricing_min_price'=> 'GMC Floor Price (Cirrusly)',
            'alg_msrp'              => 'MSRP (Cirrusly)',
            'cirrusly_map_price'    => 'MAP Price (Cirrusly)',
            'gmc_promotion_id'      => 'Promotion ID (Cirrusly)',
            'gmc_custom_label_0'    => 'Custom Label 0 (Cirrusly)',
        );
        return array_merge( $attributes, $extra );
    }

    /**
     * Rank Math Variable Support
     */
    public function register_rank_math_vars() {
        if ( function_exists( 'rank_math_register_var' ) ) {
            rank_math_register_var( 'cc_msrp', array(
                'name'        => 'MSRP',
                'description' => 'Product MSRP from Cirrusly Commerce',
                'variable'    => 'cc_msrp',
                'callback'    => array( $this, 'get_msrp_for_rm' ),
            ) );
        }
    }

    public function get_msrp_for_rm() {
        global $post;
        return $post ? get_post_meta( $post->ID, '_alg_msrp', true ) : '';
    }

    /**
     * Yoast SEO Schema Support
     */
    public function add_yoast_schema_data( $data, $presentation ) {
        $product_id = $presentation->source->ID;
        $msrp = get_post_meta( $product_id, '_alg_msrp', true );

        if ( $msrp ) {
            // "suggestedRetailPrice" is a standard Schema.org property
            $data['suggestedRetailPrice'] = $msrp;
        }
        
        return $data;
    }

    /**
     * NEW: All in One SEO (AIOSEO) Schema Injection
     */
    public function add_aioseo_schema_data( $graphs ) {
        if ( ! is_array( $graphs ) ) return $graphs;

        foreach ( $graphs as $index => $graph ) {
            if ( isset( $graph['@type'] ) && ($graph['@type'] === 'Product' || $graph['@type'] === 'IndividualProduct') ) {
                $product_id = get_the_ID();
                $msrp = get_post_meta( $product_id, '_alg_msrp', true );
                
                if ( $msrp ) {
                    $graphs[ $index ]['offers']['priceSpecification'] = array(
                        '@type' => 'UnitPriceSpecification',
                        'price' => $msrp,
                        'priceType' => 'https://schema.org/ListPrice' // Google recommended for MSRP
                    );
                }
            }
        }
        return $graphs;
    }

    /**
     * NEW: SEOPress Schema Injection
     */
    public function add_seopress_schema_data( $data ) {
        if ( ! is_singular( 'product' ) ) return $data;
        
        $id = get_the_ID();
        $msrp = get_post_meta( $id, '_alg_msrp', true );

        if ( $msrp ) {
            // SEOPress allows direct array manipulation
            $data['offers']['priceSpecification'] = array(
                '@type' => 'UnitPriceSpecification',
                'price' => $msrp,
                'priceCurrency' => get_woocommerce_currency(),
                'priceType' => 'https://schema.org/ListPrice'
            );
        }
        return $data;
    }

    /**
     * NEW: Google Product Feed (Ademti/Official)
     * Maps custom fields so they appear in the feed plugin's dropdowns.
     */
    public function add_ademti_feed_elements( $elements, $feed_item ) {
        // Register MSRP so it can be mapped in their UI
        $elements['cc_msrp'] = array(
            'label' => 'MSRP (Cirrusly)',
            'value' => get_post_meta( $feed_item->product_id, '_alg_msrp', true )
        );
        
        // Register Google Min Price
        $elements['cc_min_price'] = array(
            'label' => 'Google Min Price (Cirrusly)',
            'value' => get_post_meta( $feed_item->product_id, '_auto_pricing_min_price', true )
        );

        return $elements;
    }
}