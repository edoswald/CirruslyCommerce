<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cirrusly_Commerce_Compatibility {

    public function __construct() {
        add_filter( 'woosea_custom_attributes', array( $this, 'add_woosea_attributes' ) );
        add_filter( 'rank_math/vars/register_extra_replacements', array( $this, 'register_rank_math_vars' ) );
        add_filter( 'wpseo_schema_product', array( $this, 'add_yoast_schema_data' ), 10, 2 );
    }

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

    public function add_yoast_schema_data( $data, $presentation ) {
        $product_id = $presentation->source->ID;
        $msrp = get_post_meta( $product_id, '_alg_msrp', true );

        if ( $msrp ) {
            $data['suggestedRetailPrice'] = $msrp;
        }
        
        return $data;
    }
}