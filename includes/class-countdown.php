<?php
/**
 * Cirrusly Commerce Countdown Module
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cirrusly_Commerce_Countdown {

    /**
     * Initialize the countdown feature.
     */
    public function __construct() {
        add_shortcode( 'cw_countdown', array( $this, 'render_shortcode' ) );
        add_action( 'woocommerce_single_product_summary', array( $this, 'inject_countdown' ), 11 );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        // Removed wp_footer action
    }

    /**
     * Render a countdown timer based on shortcode attributes.
     *
     * Accepted attributes:
     * - `end` (string): target end date/time; when empty no output is produced.
     * - `label` (string): optional text displayed with the timer.
     * - `align` (string): layout alignment; one of 'left', 'center', or 'right' (default 'left').
     *
     * @param array|string $atts Shortcode attributes or query string of attributes.
     * @return string The countdown HTML markup, or an empty string if no valid `end` is provided.
     */
    public function render_shortcode( $atts ) {
        $a = shortcode_atts( array(
            'end'   => '',
            'label' => '',
            'align' => 'left', 
        ), $atts );
        if ( empty( $a['end'] ) ) return '';
        return self::generate_timer_html( $a['end'], $a['label'], $a['align'] );
    }

    /**
     * Determine the active countdown configuration for a product.
     *
     * Checks product-level manual sale end meta first; if that date is in the future, returns
     * a manual countdown configuration. If no manual configuration is active and the pro
     * feature is available, delegates to the pro smart-rules checker. Returns false when
     * no active countdown configuration is found.
     *
     * @param object $product Product object (expected WC_Product or similar) to evaluate.
     * @return array|false Array with keys:
     *                     - `end`   : string end date/time for the countdown.
     *                     - `label` : string label to display (e.g., "Sale Ends In:").
     *                     - `align` : string alignment value, one of 'left', 'center', 'right'.
     *                    Returns `false` if no active countdown configuration exists.
     */
    public static function get_smart_countdown_config( $product ) {
        if ( ! is_object( $product ) ) return false;
        $pid = $product->get_id();

        // --- PRIORITY 1: Manual Product Meta (Free Feature) ---    
        $manual_end = get_post_meta( $pid, '_cw_sale_end', true );
        if ( ! empty( $manual_end ) && self::is_date_future( $manual_end ) ) {
            return array(
                'end'   => $manual_end,
                'label' => 'Sale Ends In:',
                'align' => 'left'
            );
        }
    
        // --- PRIORITY 2: Smart Rules (Pro Feature) ---
        if ( class_exists( 'Cirrusly_Commerce_Core' ) && Cirrusly_Commerce_Core::cirrusly_is_pro() ) {
            $pro_class = plugin_dir_path( __FILE__ ) . 'pro/class-countdown-pro.php';
            if ( file_exists( $pro_class ) ) {
                require_once $pro_class;
                return Cirrusly_Commerce_Countdown_Pro::check_smart_rules( $product );
            }
        }
        return false;
    }

    /**
     * Outputs the countdown timer HTML for the current single product when a countdown configuration is available.
     *
     * If executed on a single product page and a valid countdown configuration exists for the current product,
     * this method echoes the sanitized countdown markup and a small bottom spacer.
     */
    public function inject_countdown() {
        if ( ! is_product() ) return;
        global $product;
        $config = self::get_smart_countdown_config( $product );
        if ( $config && is_array( $config ) ) {
            echo wp_kses_post( self::generate_timer_html( $config['end'], $config['label'], $config['align'] ) );
            echo '<div style="margin-bottom: 15px;"></div>';
        }
    }

    /**
     * Render HTML markup for a countdown timer targeting the given end date.
     *
     * @param string $end_date End date/time string parseable by DateTime/strtotime.
     * @param string $label Optional label shown before the timer.
     * @param string $align Alignment of the timer content; expected values: 'left', 'center', 'right'.
     * @return string HTML markup for the countdown timer, or an empty string if the end date is in the past or cannot be parsed.
     */
    public static function generate_timer_html( $end_date, $label, $align ) {
        $timezone_string = get_option( 'timezone_string' ) ?: 'America/New_York';
        try {
            $dt = new DateTime( $end_date, new DateTimeZone( $timezone_string ) );
            $target_timestamp = $dt->getTimestamp(); 
        } catch ( Exception $e ) {
            $target_timestamp = strtotime( $end_date );
        }
        $now = time();
        if ( $target_timestamp < $now ) return ''; 
        $diff = $target_timestamp - $now;
        $days = floor($diff / (60 * 60 * 24));
        $hours = floor(($diff % (60 * 60 * 24)) / (60 * 60));
        $mins = floor(($diff % (60 * 60)) / 60);
        $secs = floor($diff % 60);
        $d_str = str_pad( $days, 2, '0', STR_PAD_LEFT );
        $h_str = str_pad( $hours, 2, '0', STR_PAD_LEFT );
        $m_str = str_pad( $mins, 2, '0', STR_PAD_LEFT );
        $s_str = str_pad( $secs, 2, '0', STR_PAD_LEFT );
        $js_target = $target_timestamp * 1000; 
        $justify = 'flex-start';
        if ( $align === 'center' ) $justify = 'center';
        if ( $align === 'right' )  $justify = 'flex-end';
        ob_start();
        ?>
        <div class="cw-countdown-wrapper cw-init-needed" 
             data-end="<?php echo esc_attr( $js_target ); ?>"
             style="justify-content: <?php echo esc_attr( $justify ); ?>;">
            <?php if ( ! empty( $label ) ) : ?>
                <span class="cw-timer-label"><?php echo esc_html( $label ); ?> </span>
            <?php endif; ?>
            <div class="cw-timer-digits">
                <div class="cw-time-group"><span class="cw-val cw-days"><?php echo esc_html( $d_str ); ?></span><span class="cw-unit">DAYS</span></div>
                <span class="cw-sep">:</span>
                <div class="cw-time-group"><span class="cw-val cw-hours"><?php echo esc_html( $h_str ); ?></span><span class="cw-unit">HRS</span></div>
                <span class="cw-sep">:</span>
                <div class="cw-time-group"><span class="cw-val cw-mins"><?php echo esc_html( $m_str ); ?></span><span class="cw-unit">MINS</span></div>
                <span class="cw-sep">:</span>
                <div class="cw-time-group"><span class="cw-val cw-secs"><?php echo esc_html( $s_str ); ?></span><span class="cw-unit">SECS</span></div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function is_date_future( $date_str ) {
        $timezone_string = get_option( 'timezone_string' ) ?: 'America/New_York';
        try {
            $dt = new DateTime( $date_str, new DateTimeZone( $timezone_string ) );
            return $dt->getTimestamp() > time();
        } catch (Exception $e) { return false; }
    }

    /**
     * Inlines the countdown's frontend CSS and JavaScript on single product pages.
     *
     * The CSS and JS are attached to the 'cirrusly-frontend-base' asset handle so the countdown
     * markup rendered on product pages receives styling and a DOMContentLoaded-driven initializer.
     * The initializer updates countdown digits every second and hides timers whose end time has passed.
     */
    public function enqueue_assets() {
        if ( ! is_product() ) return;
        
        $css = "
        .cw-countdown-wrapper { display: flex; align-items: center; font-family: inherit; font-weight: 700; color: #000; gap: 8px; line-height: 1.2; flex-wrap: wrap; min-height: 42px; box-sizing: border-box; }
        .cw-timer-label { font-size: 16px; margin-right: 5px; white-space: nowrap; }
        .cw-timer-digits { display: flex; align-items: baseline; gap: 4px; }
        .cw-val { font-size: 22px; font-weight: 800; font-variant-numeric: tabular-nums; line-height: 1; min-width: 28px; text-align: center; display: inline-block; }
        .cw-unit { font-size: 9px; text-transform: uppercase; color: #555; text-align: center; font-weight: 600; margin-top: 2px; }
        .cw-time-group { display: flex; flex-direction: column; align-items: center; }
        .cw-sep { font-size: 20px; font-weight: 800; color: #000; position: relative; top: -4px; }
        ";
        // Attach CSS to frontend base
        wp_add_inline_style( 'cirrusly-frontend-base', $css );
        
        // Convert the previous raw script to an inline script string
        $js = "document.addEventListener('DOMContentLoaded', function() {
            const timers = document.querySelectorAll('.cw-countdown-wrapper.cw-init-needed');
            timers.forEach(function(timer) {
                timer.classList.remove('cw-init-needed');
                const endDate = parseInt(timer.getAttribute('data-end'));
                if (isNaN(endDate)) return;
                const els = {
                    d: timer.querySelector('.cw-days'),
                    h: timer.querySelector('.cw-hours'),
                    m: timer.querySelector('.cw-mins'),
                    s: timer.querySelector('.cw-secs')
                };
                function update() {
                    const now = new Date().getTime();
                    const distance = endDate - now;
                    if (distance < 0) { timer.style.display = 'none'; return; }
                    const d = Math.floor(distance / (1000 * 60 * 60 * 24));
                    const h = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                    const m = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
                    const s = Math.floor((distance % (1000 * 60)) / 1000);
                    if(els.d) els.d.innerText = d < 10 ? '0' + d : d;
                    if(els.h) els.h.innerText = h < 10 ? '0' + h : h;
                    if(els.m) els.m.innerText = m < 10 ? '0' + m : m;
                    if(els.s) els.s.innerText = s < 10 ? '0' + s : s;
                }
                setInterval(update, 1000);
            });
        });";

        // Attach JS to frontend base
        wp_add_inline_script( 'cirrusly-frontend-base', $js );
    }
}