<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Cirrusly_Commerce_Badges_Pro {

    /**
     * Build HTML for configured "smart" product badges based on product data and badge settings.
     *
     * Generates zero or more badge <span> elements for inventory ("Low Stock" when stock > 0 and < 5),
     * performance ("Best Seller" when total sales > 50), scheduler ("Event" when current time is within
     * configured start/end), and sentiment ("Customer Fave" when analysis indicates strong positive sentiment).
     *
     * @param \WC_Product $product The product to evaluate.
     * @param array $badge_cfg Configuration array. Recognized keys:
     *                         - 'smart_inventory' (string) : 'yes' to enable inventory badge.
     *                         - 'smart_performance' (string): 'yes' to enable performance badge.
     *                         - 'smart_scheduler' (string) : 'yes' to enable scheduler badge.
     *                         - 'scheduler_start' (string)  : start datetime for scheduler badge.
     *                         - 'scheduler_end' (string)    : end datetime for scheduler badge.
     * @return string HTML string containing the concatenated badge elements (may be empty).
     */
    public static function get_smart_badges_html( $product, $badge_cfg ) {
        $output = '';

        // 1. SMART: INVENTORY (Low Stock)
        if ( ! empty($badge_cfg['smart_inventory']) && $badge_cfg['smart_inventory'] === 'yes' ) {
            if ( $product->managing_stock() && $product->get_stock_quantity() > 0 && $product->get_stock_quantity() < 5 ) {
                $output .= '<span class="cw-badge-pill" style="background-color:#dba617;">Low Stock</span>';
            }
        }

        // 2. SMART: PERFORMANCE (Best Seller)
        if ( ! empty($badge_cfg['smart_performance']) && $badge_cfg['smart_performance'] === 'yes' ) {
            if ( $product->get_total_sales() > 50 ) {
                $output .= '<span class="cw-badge-pill" style="background-color:#00a32a;">Best Seller</span>';
            }
        }

        // 3. SMART: SCHEDULER (Date Range)
        if ( ! empty($badge_cfg['smart_scheduler']) && $badge_cfg['smart_scheduler'] === 'yes' ) {
            $start = !empty($badge_cfg['scheduler_start']) ? strtotime($badge_cfg['scheduler_start']) : 0;
            $end   = !empty($badge_cfg['scheduler_end']) ? strtotime($badge_cfg['scheduler_end']) : 0;
            $now   = time();

            if ( $start && $end && $now >= $start && $now <= $end ) {
                $output .= '<span class="cw-badge-pill" style="background-color:#826eb4;">Event</span>';
            }
        }

        // 4. SMART: SENTIMENT (NLP)
        // Check for cached sentiment or run analysis
    if ( ! empty($badge_cfg['smart_sentiment']) && $badge_cfg['smart_sentiment'] === 'yes' ) {
        // Check for cached sentiment or run analysis
        $sentiment_html = self::get_sentiment_badge( $product );
        if ( $sentiment_html ) {
            $output .= $sentiment_html;
        }
    }

        return $output;
    }

    /**
     * Produce a sentiment-based badge HTML when recent reviews indicate strong positive sentiment.
     *
     * Analyzes up to five recent approved reviews for the given product and returns a "Customer Fave" badge
     * HTML if the aggregated sentiment exceeds a positive threshold. Results are cached: a positive badge is
     * cached for seven days; absence of a positive badge is cached for one day. If required dependencies are
     * missing or an error occurs during analysis, the function returns an empty string.
     *
     * @param object $product Product object (e.g., WC_Product) whose reviews will be analyzed.
     * @return string Badge HTML when strong positive sentiment is detected, empty string otherwise.
     */
    private static function get_sentiment_badge( $product ) {
        $cache_key = 'cc_sentiment_' . $product->get_id();
        $cached = get_transient( $cache_key );
        if ( false !== $cached ) return $cached;

        $comments = get_comments( array(
            'post_id' => $product->get_id(),
            'number'  => 5,
            'status'  => 'approve',
            'type'    => 'review',
        ) ); 
        
        if ( empty( $comments ) ) {
            set_transient( $cache_key, '', DAY_IN_SECONDS );
            return '';
        }

        // Dependency Check
        if ( ! class_exists( 'Cirrusly_Commerce_Google_API_Client' ) ) return '';
        
        $client = Cirrusly_Commerce_Google_API_Client::get_client();
        if ( is_wp_error( $client ) ) return '';

        try {
            $service = new Google\Service\CloudNaturalLanguage( $client );
            
            $combined_text = implode( "\n\n", array_map( function( $c ) {
                return $c->comment_content;
            }, $comments ) );
            
            $doc = new Google\Service\CloudNaturalLanguage\Document();
            $doc->setContent( $combined_text );
            $doc->setType( 'PLAIN_TEXT' );
            
            $request = new Google\Service\CloudNaturalLanguage\AnalyzeSentimentRequest();
            $request->setDocument( $doc );
            
            $resp = $service->documents->analyzeSentiment( $request );
            $score = $resp->getDocumentSentiment()->getScore();

            if ( $score > 0.6 ) {
                $html = '<span class="cw-badge-pill" style="background-color:#e0115f;">Customer Fave ❤️</span>';
                set_transient( $cache_key, $html, 7 * DAY_IN_SECONDS );
                return $html;
            }

        } catch ( Exception $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'Cirrusly Sentiment Analysis Error: ' . $e->getMessage() );
            }
        }
        
        set_transient( $cache_key, '', DAY_IN_SECONDS );
        return '';
    }
}