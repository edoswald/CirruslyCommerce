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
     * Initialize the countdown feature.
     */
    public function __construct() {
        add_shortcode( 'cw_countdown', array( $this, 'render_shortcode' ) );
        add_action( 'woocommerce_single_product_summary', array( $this, 'inject_countdown' ), 11 );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_footer', array( $this, 'render_worker_script' ) );
    }

    private static function get_smart_rules() {
        if ( ! class_exists( 'Cirrusly_Commerce_Core' ) || ! Cirrusly_Commerce_Core::cirrusly_is_pro() ) {
            return array();
        }
        return get_option( 'cirrusly_countdown_rules', array() ); 
    }

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
     * Helper to find the active configuration for a product.
     * Returns array('end' => string, 'label' => string, 'align' => string) or false.
     */
    public static function get_smart_countdown_config( $product ) {
        if ( ! is_object( $product ) ) return false;
        $pid = $product->get_id();

        // --- PRIORITY 1: Manual Product Meta ---
        $manual_end = get_post_meta( $pid, '_cw_sale_end', true );
        if ( ! empty( $manual_end ) && self::is_date_future( $manual_end ) ) {
            return array(
                'end'   => $manual_end,
                'label' => 'Sale Ends In:',
                'align' => 'left'
            );
        }

        // --- PRIORITY 2: Smart Rules (Pro Feature) ---
        $rules = self::get_smart_rules();
        foreach ( $rules as $rule ) {
            if ( empty($rule['term']) || empty($rule['taxonomy']) || empty($rule['end']) ) continue;

            if ( has_term( $rule['term'], $rule['taxonomy'], $pid ) ) {
                 if ( self::is_date_future( $rule['end'] ) ) {
                    return array(
                        'end'   => $rule['end'],
                        'label' => isset($rule['label']) ? $rule['label'] : 'Ends in:',
                        'align' => isset($rule['align']) ? $rule['align'] : 'left',
                    );
                 }
            }
        }
        return false;
    }

    public function inject_countdown() {
        if ( ! is_product() ) return;
        global $product;
        
        // Fetch full config (date + styles)
        $config = self::get_smart_countdown_config( $product );
        
        if ( $config && is_array( $config ) ) {
            echo wp_kses_post( self::generate_timer_html( $config['end'], $config['label'], $config['align'] ) );
            echo '<div style="margin-bottom: 15px;"></div>';
        }
    }

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
        wp_register_style( 'cw-countdown-style', false );
        wp_enqueue_style( 'cw-countdown-style' );
        wp_add_inline_style( 'cw-countdown-style', $css );
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