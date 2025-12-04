<?php
/**
 * Cirrusly Commerce Countdown Module
 * Handles the lightweight countdown timer logic and injection.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cirrusly_Commerce_Countdown {

    /**
     * Initialize the countdown feature by registering the shortcode and attaching WordPress/WooCommerce hooks.
     *
     * Registers the [cw_countdown] shortcode, hooks the countdown injection into the single product summary,
     * and registers actions to enqueue inline assets and render the frontend worker script.
     */
    public function __construct() {
        // Register Shortcode
        add_shortcode( 'cw_countdown', array( $this, 'render_shortcode' ) );

        // Auto-Inject on Product Page
        add_action( 'woocommerce_single_product_summary', array( $this, 'inject_countdown' ), 11 );

        // Load Assets (CSS/JS)
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_footer', array( $this, 'render_worker_script' ) );
    }

    /**
     * Retrieve configured Smart Rules for the countdown pro feature when the pro plugin is active.
     *
     * Returns the array of rule definitions stored in the 'cirrusly_countdown_rules' option. If the
     * Cirrusly pro core is not present or pro is not active, an empty array is returned.
     *
     * @return array The configured smart rules as an associative array, or an empty array if no rules exist or pro is inactive.
     */
    private function get_smart_rules() {
        // Only return rules if PRO is active
        if ( ! class_exists( 'Cirrusly_Commerce_Core' ) || ! Cirrusly_Commerce_Core::cirrusly_is_pro() ) {
            return array();
        }

        // Example Pro Logic: Retrieve from DB options in real app
        // For now, we return the structure you defined, but this could be dynamic.
        return get_option( 'cirrusly_countdown_rules', array() ); 
    }

    /**
     * Render a countdown timer based on shortcode attributes.
     *
     * @param array $atts Shortcode attributes. Supported keys:
     *                    - 'end'   (string) Required. Target date/time as a parsable date string.
     *                    - 'label' (string) Optional. Text label shown with the timer.
     *                    - 'align' (string) Optional. One of 'left', 'center', 'right' (default 'left').
     * @return string HTML markup for the countdown timer, or an empty string if `end` is missing or the target is not in the future.
     */
    public function render_shortcode( $atts ) {
        $a = shortcode_atts( array(
            'end'   => '',
            'label' => '',
            'align' => 'left', 
        ), $atts );

        if ( empty( $a['end'] ) ) return '';

        return $this->generate_timer_html( $a['end'], $a['label'], $a['align'] );
    }

    /**
     * Injects a countdown timer into WooCommerce single product pages when applicable.
     *
     * Checks for a per-product manual end date first; if a valid future date is found,
     * outputs the timer HTML and stops. If no manual end date is present, evaluates
     * configured smart rules and outputs the timer for the first matching rule whose
     * end date is in the future. When a timer is output it is echoed directly (along
     * with a small spacer).
     *
     * This method only takes effect on single product pages.
     *
     * @return void
     */
    public function inject_countdown() {
        if ( ! is_product() ) return;
        global $product;
        $pid = $product->get_id();

        // --- PRIORITY 1: Manual Product Meta (Free Feature) ---
        // We will save this meta in class-pricing.php
        $manual_end = get_post_meta( $pid, '_cw_sale_end', true );
        
        if ( ! empty( $manual_end ) ) {
            // Check if date is valid and in future
            if ( $this->is_date_future( $manual_end ) ) {
                echo wp_kses_post( $this->generate_timer_html( $manual_end, 'Sale Ends In:', 'left' ) );
                echo '<div style="margin-bottom: 15px;"></div>';
                return; // Stop processing if manual override exists
            }
        }

        // --- PRIORITY 2: Smart Rules (Pro Feature) ---
        $rules = $this->get_smart_rules();
        foreach ( $rules as $rule ) {
            // Safety checks for rule structure
            if ( empty($rule['term']) || empty($rule['taxonomy']) || empty($rule['end']) ) continue;

            if ( has_term( $rule['term'], $rule['taxonomy'], $pid ) ) {
                 if ( $this->is_date_future( $rule['end'] ) ) {
                    $align = isset($rule['align']) ? $rule['align'] : 'left';
                    $label = isset($rule['label']) ? $rule['label'] : 'Ends in:';
                    echo wp_kses_post( $this->generate_timer_html( $manual_end, 'Sale Ends In:', 'left' ) );
                    echo '<div style="margin-bottom: 15px;"></div>';
                    break; // Apply first matching rule
                 }
            }
        }
    }

    /**
     * Build a CLS-friendly countdown timer HTML for a given end date.
     *
     * Generates markup pre-populated with days, hours, minutes, and seconds remaining
     * and includes a data-end attribute (milliseconds) for client-side updates.
     *
     * @param string $end_date End date/time string parseable by DateTime or strtotime.
     * @param string $label Optional label shown before the timer; empty to omit.
     * @param string $align Alignment for the timer content: 'left', 'center', or 'right'.
     * @return string The rendered HTML for the countdown, or an empty string if the end date is past or invalid.
     */
    private function generate_timer_html( $end_date, $label, $align ) {
        // 1. Timezone & Target Calc
        $timezone_string = get_option( 'timezone_string' ) ?: 'America/New_York';
        try {
            $dt = new DateTime( $end_date, new DateTimeZone( $timezone_string ) );
            $target_timestamp = $dt->getTimestamp(); 
        } catch ( Exception $e ) {
            $target_timestamp = strtotime( $end_date );
        }

        $now = time();
        if ( $target_timestamp < $now ) return ''; // Expired

        // 2. Pre-Calculate for CLS
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

    /**
     * Determines whether a date/time string represents a future moment relative to the site's timezone.
     *
     * Uses the WordPress `timezone_string` option (defaults to "America/New_York") when parsing the input.
     *
     * @param string $date_str A date/time string parseable by DateTime (in the site's timezone).
     * @return bool `true` if the parsed date/time is later than the current time, `false` otherwise or on parse failure.
     */
    private function is_date_future( $date_str ) {
        $timezone_string = get_option( 'timezone_string' ) ?: 'America/New_York';
        try {
            $dt = new DateTime( $date_str, new DateTimeZone( $timezone_string ) );
            return $dt->getTimestamp() > time();
        } catch (Exception $e) { return false; }
    }

    /**
     * Enqueues and registers the inline CSS used by the countdown timer on WooCommerce product pages.
     *
     * The method does nothing when not on a single product page; when on a product page it registers
     * a minimal stylesheet handle and attaches the countdown component's CSS via inline styles.
     */
    public function enqueue_assets() {
        if ( ! is_product() ) return;
        
        // Inline CSS for performance (as requested)
        $css = "
        .cw-countdown-wrapper { display: flex; align-items: center; font-family: inherit; font-weight: 700; color: #000; gap: 8px; line-height: 1.2; flex-wrap: wrap; min-height: 42px; box-sizing: border-box; }
        .cw-timer-label { font-size: 16px; margin-right: 5px; white-space: nowrap; }
        .cw-timer-digits { display: flex; align-items: baseline; gap: 4px; }
        .cw-val { font-size: 22px; font-weight: 800; font-variant-numeric: tabular-nums; line-height: 1; min-width: 28px; text-align: center; display: inline-block; }
        .cw-unit { font-size: 9px; text-transform: uppercase; color: #555; text-align: center; font-weight: 600; margin-top: 2px; }
        .cw-time-group { display: flex; flex-direction: column; align-items: center; }
        .cw-sep { font-size: 20px; font-weight: 800; color: #000; position: relative; top: -4px; }
        ";
        // Register a minimal handle to attach inline styles
        wp_register_style( 'cw-countdown-style', false );
        wp_enqueue_style( 'cw-countdown-style' );
        wp_add_inline_style( 'cw-countdown-style', $css );
    }

    /**
     * Injects an inline JavaScript worker that updates countdown timers on product pages.
     *
     * Finds elements with the classes `cw-countdown-wrapper cw-init-needed`, initializes each by
     * reading the `data-end` timestamp (milliseconds since epoch), then updates days/hours/minutes/seconds
     * display values every second. When a timer reaches its end, the script hides that timer.
     */
    public function render_worker_script() {
        if ( ! is_product() ) return;
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
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
        });
        </script>
        <?php
    }
}