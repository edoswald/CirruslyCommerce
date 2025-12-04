<?php
/**
 * Cirrusly Commerce Countdown Module
 * Handles the lightweight countdown timer logic and injection.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cirrusly_Commerce_Countdown {

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
     * Get Rules (Pro Feature Placeholder)
     * In a full implementation, this would pull from get_option('cirrusly_countdown_rules').
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
     * 1. Shortcode Handler
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
     * 2. Injection Logic (The "Brain")
     * Checks Per-Product (Free) first, then Smart Rules (Pro).
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
                echo $this->generate_timer_html( $manual_end, 'Sale Ends In:', 'left' );
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
                    echo $this->generate_timer_html( $rule['end'], $label, $align );
                    echo '<div style="margin-bottom: 15px;"></div>';
                    break; // Apply first matching rule
                 }
            }
        }
    }

    /**
     * Helper: Generate the HTML (CLS-Proof)
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
                <div class="cw-time-group"><span class="cw-val cw-days"><?php echo $d_str; ?></span><span class="cw-unit">DAYS</span></div>
                <span class="cw-sep">:</span>
                <div class="cw-time-group"><span class="cw-val cw-hours"><?php echo $h_str; ?></span><span class="cw-unit">HRS</span></div>
                <span class="cw-sep">:</span>
                <div class="cw-time-group"><span class="cw-val cw-mins"><?php echo $m_str; ?></span><span class="cw-unit">MINS</span></div>
                <span class="cw-sep">:</span>
                <div class="cw-time-group"><span class="cw-val cw-secs"><?php echo $s_str; ?></span><span class="cw-unit">SECS</span></div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function is_date_future( $date_str ) {
        $timezone_string = get_option( 'timezone_string' ) ?: 'America/New_York';
        try {
            $dt = new DateTime( $date_str, new DateTimeZone( $timezone_string ) );
            return $dt->getTimestamp() > time();
        } catch (Exception $e) { return false; }
    }

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
        wp_add_inline_style( 'wc-block-style', $css ); // Attach to WC styles or your own handle
        // Fallback if no specific handle is active, print in head:
        echo '<style>' . $css . '</style>';
    }

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