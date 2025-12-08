<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cirrusly_Commerce_Countdown_Pro {

    /**
     * Evaluate smart rules against a specific product to see if a countdown applies.
     *
     * @param WC_Product $product The product object.
     * @return array|false Configuration array ('end', 'label', 'align') if a rule matches, false otherwise.
     */
    public static function check_smart_rules( $product ) {
        if ( ! is_object( $product ) ) return false;
        
        $rules = get_option( 'cirrusly_countdown_rules', array() );
        $pid   = $product->get_id();
        
        // Helper to check if a date string is in the future
        $is_future = function( $date ) {
            $tz = get_option( 'timezone_string' ) ?: 'America/New_York';
            try {
                $dt = new DateTime( $date, new DateTimeZone( $tz ) );
                return $dt->getTimestamp() > time();
            } catch ( Exception $e ) { 
                return false; 
            }
        };

        foreach ( $rules as $rule ) {
            // Skip invalid rules
            if ( empty( $rule['term'] ) || empty( $rule['taxonomy'] ) || empty( $rule['end'] ) ) {
                continue;
            }

            // Check if product has the term (Category, Tag, etc.)
            if ( has_term( $rule['term'], $rule['taxonomy'], $pid ) ) {
                 // Check if the rule's end date is still valid
                 if ( $is_future( $rule['end'] ) ) {
                    return array(
                        'end'   => $rule['end'],
                        'label' => isset( $rule['label'] ) ? $rule['label'] : 'Ends in:',
                        'align' => isset( $rule['align'] ) ? $rule['align'] : 'left',
                    );
                 }
            }
        }
        
        return false;
    }
}