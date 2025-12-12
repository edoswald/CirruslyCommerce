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
     * - 'smart_inventory' (string) : 'yes' to enable inventory badge.
     * - 'smart_performance' (string): 'yes' to enable performance badge.
     * - 'smart_scheduler' (string) : 'yes' to enable scheduler badge.
     * - 'scheduler_start' (string)  : start datetime for scheduler badge.
     * - 'scheduler_end' (string)    : end datetime for scheduler badge.
     * - 'smart_sentiment' (string)  : 'yes' to enable sentiment badge.

     * @return string HTML string containing the concatenated badge elements (may be empty).
     */
    public static function get_smart_badges_html( $product, $badge_cfg ) {
        $output = '';

        // 1. SMART: INVENTORY (Low Stock)
        if ( ! empty($badge_cfg['smart_inventory']) && $badge_cfg['smart_inventory'] === 'yes' ) {
            if ( $product->managing_stock() && $product->get_stock_quantity() > 0 && $product->get_stock_quantity() < 5 ) {
                $output .= '<span class="cirrusly-badge-pill" style="background-color:#dba617;">Low Stock</span>';
            }
        }

        // 2. SMART: PERFORMANCE (Best Seller)
        if ( ! empty($badge_cfg['smart_performance']) && $badge_cfg['smart_performance'] === 'yes' ) {
            if ( $product->get_total_sales() > 50 ) {
                $output .= '<span class="cirrusly-badge-pill" style="background-color:#00a32a;">Best Seller</span>';
            }
        }

        // 3. SMART: SCHEDULER (Date Range)
        if ( ! empty($badge_cfg['smart_scheduler']) && $badge_cfg['smart_scheduler'] === 'yes' ) {
            $start = !empty($badge_cfg['scheduler_start']) ? strtotime($badge_cfg['scheduler_start']) : false;
            $end   = !empty($badge_cfg['scheduler_end']) ? strtotime($badge_cfg['scheduler_end']) : false;
            $now   = time();

            if ( $start !== false && $end !== false && $now >= $start && $now <= $end ) {
                    $output .= '<span class="cirrusly-badge-pill" style="background-color:#826eb4;">Event</span>';
            }
        }

        // 4. SMART: SENTIMENT (NLP)
        // Check for cached sentiment or run analysis
        if ( ! empty($badge_cfg['smart_sentiment']) && $badge_cfg['smart_sentiment'] === 'yes' ) {
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
     * Analyzes up to five recent approved reviews for the given product via the Worker API.
     * Returns a "Customer Fave" badge HTML if the aggregated sentiment exceeds a positive threshold (0.6).
     * Results are cached: a positive badge is cached for seven days; absence of a positive badge is cached for one day.
     *
     * @param object $product Product object (e.g., WC_Product) whose reviews will be analyzed.
     * @return string Badge HTML when strong positive sentiment is detected, empty string otherwise.
     */
    private static function get_sentiment_badge( $product ) {
        $cache_key = 'cirrusly_sentiment_' . $product->get_id();
        $cached = get_transient( $cache_key );
        if ( false !== $cached ) return $cached;

        $comments = get_comments( array(
            'post_id' => $product->get_id(),
            'number'  => 5,
            'status'  => 'approve',
            'type'    => 'review',
            'orderby' => 'comment_date',
            'order'   => 'DESC',
        ) ); 
        
        if ( empty( $comments ) ) {
            set_transient( $cache_key, '', DAY_IN_SECONDS );
            return '';
        }

        // Dependency Check: Use the Proxy Client
        if ( ! class_exists( 'Cirrusly_Commerce_Google_API_Client' ) ) {
            set_transient( $cache_key, '', DAY_IN_SECONDS );
            return '';
        }

        try {
            // Combine text from recent reviews
            $combined_text = implode( "\n\n", array_map( function( $c ) {
                return $c->comment_content;
            }, $comments ) );

            // Allow sites to redact/transform user content before external analysis.
            $combined_text = apply_filters( 'cirrusly_commerce_nlp_review_text', $combined_text, $product, $comments );

            // Sanitize: Handle array or non-string returns from filters and strip tags
            if ( is_array( $combined_text ) ) {
                $combined_text = implode( "\n\n", array_map( 'wp_strip_all_tags', $combined_text ) );
            } else {
                $combined_text = wp_strip_all_tags( (string) $combined_text );
            }

           // Defensive: filters can return non-strings; also re-strip tags post-filter.
            if ( ! is_string( $combined_text ) ) {
                $combined_text = '';
            }
            $combined_text = trim( wp_strip_all_tags( $combined_text ) );
            if ( $combined_text === '' ) {
                set_transient( $cache_key, '', DAY_IN_SECONDS );
                return '';
            }

            // Prevent oversized payloads
            $combined_text = function_exists( 'mb_substr' )
                ? mb_substr( $combined_text, 0, 8000 )
                : substr( $combined_text, 0, 8000 );

            // Send to Worker via Proxy with SHORT TIMEOUT (2s)
            // Note: Cirrusly_Commerce_Google_API_Client must support the $options argument.
            $response = Cirrusly_Commerce_Google_API_Client::request( 
                'nlp_analyze', 
                array( 'text' => $combined_text ),
                array( 'timeout' => 2 ) 
            );

            if ( is_wp_error( $response ) ) {
                // On timeout or error, schedule background refresh if not already locked
                $lock_key = 'cirrusly_sentiment_lock_' . $product->get_id();
                
                if ( ! get_transient( $lock_key ) ) {
                    set_transient( $lock_key, 'locked', 5 * MINUTE_IN_SECONDS );
                    wp_schedule_single_event( time(), 'cirrusly_analyze_sentiment_cron', array( $product->get_id() ) );
                }
                
                // Return safe fallback (empty) to avoid blocking render
                return '';
            }

            return self::process_sentiment_response( $response, $cache_key );

        } catch ( Exception $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'Cirrusly Sentiment Analysis Error: ' . $e->getMessage() );
            }
        }
        
        return '';
    }

    /**
     * Async handler for background sentiment analysis.
     * * @param int $product_id
     */
    public static function analyze_sentiment_async( $product_id ) {
        if ( ! class_exists( 'Cirrusly_Commerce_Google_API_Client' ) ) return;

        $product = wc_get_product( $product_id );
        if ( ! $product ) return;

        // Re-fetch comments
        $comments = get_comments( array(
            'post_id' => $product_id,
            'number'  => 5,
            'status'  => 'approve',
            'type'    => 'review',
            'orderby' => 'comment_date',
            'order'   => 'DESC',
        ) );

        if ( empty( $comments ) ) {
            delete_transient( 'cirrusly_sentiment_lock_' . $product_id );
            return;
        }

        $combined_text = implode( "\n\n", array_map( function( $c ) { return $c->comment_content; }, $comments ) );
        $combined_text = apply_filters( 'cirrusly_commerce_nlp_review_text', $combined_text, $product, $comments );
        
        // Re-sanitize
        if ( is_array( $combined_text ) ) {
            $combined_text = implode( "\n\n", array_map( 'wp_strip_all_tags', $combined_text ) );
        } else {
            $combined_text = wp_strip_all_tags( (string) $combined_text );
        }
        $combined_text = function_exists( 'mb_substr' ) ? mb_substr( $combined_text, 0, 8000 ) : substr( $combined_text, 0, 8000 );

        // Long timeout for background process (20s)
        $response = Cirrusly_Commerce_Google_API_Client::request( 
            'nlp_analyze', 
            array( 'text' => $combined_text ),
            array( 'timeout' => 20 )
        );

        if ( ! is_wp_error( $response ) ) {
            self::process_sentiment_response( $response, 'cirrusly_sentiment_' . $product_id );
        }

        // Release Lock
        delete_transient( 'cirrusly_sentiment_lock_' . $product_id );
    }

    /**
     * Helper: Process API response and update cache.
     */
    private static function process_sentiment_response( $response, $cache_key ) {
        // Check if sentiment score exists in response (Worker compatibility check)
        $score = 0.0;
        if ( isset( $response['sentiment']['score'] ) ) {
            $score = (float) $response['sentiment']['score'];
        } elseif ( isset( $response['documentSentiment']['score'] ) ) {
            $score = (float) $response['documentSentiment']['score'];
        }

        // Threshold: > 0.6 indicates clear positive sentiment
        if ( $score > 0.6 ) {
            $html = '<span class="cirrusly-badge-pill" style="background-color:#e0115f;">Customer Fave ❤️</span>';
            set_transient( $cache_key, $html, 7 * DAY_IN_SECONDS );
            return $html;
        }

        set_transient( $cache_key, '', DAY_IN_SECONDS );
        return '';
    }
}

// Register Cron Hook
add_action( 'cirrusly_analyze_sentiment_cron', array( 'Cirrusly_Commerce_Badges_Pro', 'analyze_sentiment_async' ) );