<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cirrusly_Commerce_GMC {

    /**
     * Track initialization state to prevent duplicate hook registration.
     *
     * @var bool
     */
    private static $initialized = false;

    /**
     * CONSTRUCTOR: Left empty to allow instantiation without side effects.
     * Used by the scanner to access logic methods without re-registering hooks.
     */
    public function __construct() {
        // Intentionally empty
    }

    /**
     * INITIALIZER: Registers hooks and loads sub-modules.
     * Must be called ONCE by the Core class.
     */
    public function init() {
        // [Security] Prevent multiple calls to init()
        if ( self::$initialized ) {
            return;
        }
        self::$initialized = true;

        // 1. Load Sub-Modules
        if ( is_admin() ) {
            require_once plugin_dir_path( __FILE__ ) . 'admin/class-gmc-ui.php';
            new Cirrusly_Commerce_GMC_UI();
        }

        // 2. Pro Logic Loading (API & Automation)
        // Note: Ensure Core class is loaded before running this check
        if ( class_exists('Cirrusly_Commerce_Core') && Cirrusly_Commerce_Core::cirrusly_is_pro() && file_exists( plugin_dir_path( __FILE__ ) . 'pro/class-gmc-pro.php' ) ) {
            require_once plugin_dir_path( __FILE__ ) . 'pro/class-gmc-pro.php';
            new Cirrusly_Commerce_GMC_Pro();
        }

        // 3. Core Data Handlers (Always needed)
        add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_meta' ) );
        add_action( 'woocommerce_product_quick_edit_save', array( $this, 'save_quick_bulk_edit' ) );
        add_action( 'woocommerce_product_bulk_edit_save', array( $this, 'save_quick_bulk_edit' ) );

        // 4. Handle "Mark as Custom" action (Redirect logic)
        add_action( 'admin_post_cc_mark_custom', array( $this, 'handle_mark_custom' ) );
    }

    /**
     * Entry point for the Admin Page (Called by admin menu)
     */
    public static function render_page() {
        if ( class_exists( 'Cirrusly_Commerce_GMC_UI' ) ) {
            $ui = new Cirrusly_Commerce_GMC_UI();
            $ui->render_gmc_hub_page();
        }
    }

    /**
     * Persist GMC-related product meta and clear related promo statistics.
     */
    public function save_product_meta( $post_id ) {
        if ( ! isset( $_POST['woocommerce_meta_nonce'] ) || ! wp_verify_nonce( $_POST['woocommerce_meta_nonce'], 'woocommerce_save_data' ) ) {
            return;
        }       
        $val = isset( $_POST['gmc_is_custom_product'] ) ? 'no' : 'yes';
        update_post_meta( $post_id, '_gla_identifier_exists', $val );
        
        if ( isset( $_POST['_gmc_promotion_id'] ) ) {
            update_post_meta( $post_id, '_gmc_promotion_id', sanitize_text_field( wp_unslash( $_POST['_gmc_promotion_id'] ) ) );
        }
        if ( isset( $_POST['_gmc_custom_label_0'] ) ) {
            update_post_meta( $post_id, '_gmc_custom_label_0', sanitize_text_field( wp_unslash( $_POST['_gmc_custom_label_0'] ) ) );
        }
        
        delete_transient( 'cirrusly_active_promos_stats' );
    }

    /**
     * Update a product's GMC identifier flag based on quick/bulk edit input.
     * Includes updated nonce verification for Quick/Bulk edit contexts.
     */
    public function save_quick_bulk_edit( $product ) {
        // [Security] Verify correct nonce for Quick vs Bulk Edit
        $nonce_verified = false;
        if ( isset( $_POST['woocommerce_quick_edit_nonce'] ) && wp_verify_nonce( $_POST['woocommerce_quick_edit_nonce'], 'woocommerce_quick_edit' ) ) {
            $nonce_verified = true;
        } elseif ( isset( $_POST['_wpnonce'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'bulk-posts' ) ) {
            $nonce_verified = true;
        }

        if ( ! $nonce_verified ) {
            return;
        } 
        
        $post_id = $product->get_id();
        if ( isset( $_REQUEST['gmc_is_custom_product'] ) ) {
            update_post_meta( $post_id, '_gla_identifier_exists', 'no' );
        } elseif ( isset( $_REQUEST['woocommerce_quick_edit'] ) && ! isset( $_REQUEST['bulk_edit'] ) ) {
            update_post_meta( $post_id, '_gla_identifier_exists', 'yes' );
        }
        delete_transient( 'cirrusly_active_promos_stats' );
    }

    /**
     * Marks a product as non-custom and redirects to the GMC admin scan tab.
     */
    public function handle_mark_custom() {
        if ( ! current_user_can( 'edit_products' ) ) wp_die('No permission');
        $pid = intval( $_GET['pid'] );
        if ( $pid <= 0 ) {
            wp_die( 'Invalid product ID' );
        }
        check_admin_referer( 'cc_mark_custom_' . $pid );
        update_post_meta( $pid, '_gla_identifier_exists', 'no' );
        wp_redirect( admin_url('admin.php?page=cirrusly-gmc&tab=scan&msg=custom_marked') );
        exit;
    }

    /**
     * Returns the set of GMC-monitored terms.
     */
    public static function get_monitored_terms() {
        return array(
            'promotional' => array(
                'free shipping' => array('severity' => 'Medium', 'scope' => 'title', 'reason' => 'Allowed in descriptions, but prohibited in titles.'),
                'sale'          => array('severity' => 'Medium', 'scope' => 'title', 'reason' => 'Prohibited in titles. Use "Sale Price".'),
                'buy one'       => array('severity' => 'Medium', 'scope' => 'title', 'reason' => 'Promotional text.'),
                'best price'    => array('severity' => 'High',   'scope' => 'title', 'reason' => 'Subjective claim (Misrepresentation).'),
                'cheapest'      => array('severity' => 'High',   'scope' => 'title', 'reason' => 'Subjective claim.'),
            ),
            'medical' => array( 
                'cure'        => array('severity' => 'Critical', 'scope' => 'all', 'reason' => 'Medical claim (Prohibited).'),
                'heal'        => array('severity' => 'Critical', 'scope' => 'all', 'reason' => 'Medical claim implying permanent fix.'),
                'virus'       => array('severity' => 'Critical', 'scope' => 'all', 'reason' => 'Prohibited sensitive event claim.'),
                'covid'       => array('severity' => 'Critical', 'scope' => 'all', 'reason' => 'Sensitive event.'),
                'guaranteed'  => array('severity' => 'Medium',   'scope' => 'all', 'reason' => 'Must have linked policy.')
            ),
            'misrepresentation' => array(
                'miracle'       => array('severity' => 'Critical', 'scope' => 'all', 'reason' => 'Unrealistic claim (Misrepresentation).'),
                'magic'         => array('severity' => 'High',     'scope' => 'all', 'reason' => 'Unrealistic claim unless referring to a game/trick.'),
                'fda approved'  => array('severity' => 'Critical', 'scope' => 'all', 'reason' => 'False affiliation. Verification required.'),
                'cdc'           => array('severity' => 'Critical', 'scope' => 'all', 'reason' => 'Government affiliation implied.'),
                'who'           => array('severity' => 'Critical', 'scope' => 'all', 'reason' => 'International body affiliation implied.'),
                'instant weight loss' => array('severity' => 'Critical', 'scope' => 'all', 'reason' => 'Prohibited weight loss claim.')
            )
        );
    }

    /**
     * Performs the Google Merchant Center health scan logic on local products.
     * Uses strict regex boundaries and optionally calls Pro NLP analysis.
     */
    public static function run_gmc_scan_logic( $batch_size = 100, $paged = 1 ) {
        // Allow cron/CLI contexts, but require capability for interactive requests.
        if ( ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! current_user_can( 'edit_products' ) ) {
            return array();
        }

        // Normalize paging parameters to safe integers.
        $batch_size = max( 1, (int) $batch_size );
        $paged      = max( 1, (int) $paged );

        $results = array();
        
        // 1. Fetch Pro Statuses (Real data from Google)
        $google_issues = array();
        if ( class_exists( 'Cirrusly_Commerce_Core' ) && 
             Cirrusly_Commerce_Core::cirrusly_is_pro() && 
             class_exists( 'Cirrusly_Commerce_GMC_Pro' ) ) {
            $google_issues = Cirrusly_Commerce_GMC_Pro::fetch_google_real_statuses();
        }

        // 2. Scan Local Products
        $args = array(
            'post_type'      => 'product',
            'posts_per_page' => $batch_size,
            'paged'          => $paged,
            'post_status'    => 'publish',
            'fields'         => 'ids'
        );
        $products = get_posts( $args );

        // Load rules once
        $monitored_terms = self::get_monitored_terms();

        foreach ( $products as $pid ) {
            $product_issues = array();
            $p = wc_get_product( $pid );
            if ( ! $p ) continue;

            // --- METADATA CHECKS ---
            $is_custom = get_post_meta( $pid, '_gla_identifier_exists', true );
            
            // CHECK: GTIN / MPN Existence
            if ( 'no' !== $is_custom && ! $p->get_sku() ) {
                $product_issues[] = array(
                    'type' => 'warning',
                    'msg'  => 'Missing SKU (Identifier)',
                    'reason' => 'Products generally require unique identifiers.'
                );
            }
            
            // CHECK: Missing Image
            if ( ! $p->get_image_id() ) {
                $product_issues[] = array(
                    'type' => 'critical',
                    'msg'  => 'Missing Image',
                    'reason' => 'Google requires an image URL.'
                );
            }

            // CHECK: Price
            if ( '' === $p->get_price() ) {
                $product_issues[] = array(
                    'type' => 'critical',
                    'msg'  => 'Missing Price',
                    'reason' => 'Price is mandatory.'
                );
            }

            // --- CONTENT POLICY CHECKS (Local) ---
            // Prepare text content: Clean, lowercase, and tag-stripped
            $title_raw   = $p->get_name();
            $desc_raw    = $p->get_description() . ' ' . $p->get_short_description();
            $title_clean = strtolower( wp_strip_all_tags( $title_raw ) );
            $desc_clean  = strtolower( wp_strip_all_tags( $desc_raw ) );

            foreach ( $monitored_terms as $category => $terms ) {
                foreach ( $terms as $word => $rule ) {
                    // Use \b and 'u' modifier for robust word boundary detection
                    // preg_quote ensures special chars in terms don't break regex
                    $pattern = '/\b' . preg_quote( $word, '/' ) . '\b/iu';
                    $found   = false;

                    // Check Title
                    if ( preg_match( $pattern, $title_clean ) ) {
                        $found = true;
                    }

                    // Check Description (if scope is 'all')
                    if ( ! $found && isset( $rule['scope'] ) && 'all' === $rule['scope'] ) {
                        if ( preg_match( $pattern, $desc_clean ) ) {
                            $found = true;
                        }
                    }

                    if ( $found ) {
                        $product_issues[] = array(
                            'type'   => ( isset($rule['severity']) && 'Critical' === $rule['severity'] ) ? 'critical' : 'warning',
                            'msg'    => 'Restricted Term (' . ucfirst($category) . '): "' . ucfirst($word) . '"',
                            'reason' => isset($rule['reason']) ? $rule['reason'] : 'Potential policy violation.'
                        );
                    }
                }
            }
            
            // --- ADVANCED NLP & EDITORIAL CHECK (Pro Only) ---
            // Checks for Editorial Standards (Caps, Punctuation) and NLP Misrepresentation
            if ( class_exists( 'Cirrusly_Commerce_GMC_Pro' ) && method_exists( 'Cirrusly_Commerce_GMC_Pro', 'scan_product_with_nlp' ) ) {
                $nlp_issues = Cirrusly_Commerce_GMC_Pro::scan_product_with_nlp( $p, $product_issues );
                if ( ! empty( $nlp_issues ) ) {
                    $product_issues = array_merge( $product_issues, $nlp_issues );
                }
            }

            // --- MERGE GOOGLE API ISSUES ---
            if ( isset( $google_issues[ $pid ] ) ) {
                foreach ( $google_issues[ $pid ] as $g_issue ) {
                    $product_issues[] = $g_issue;
                }
            }

            if ( ! empty( $product_issues ) ) {
                $results[] = array(
                    'product_id' => $pid,
                    'issues'     => $product_issues
                );
            }
        }

        return array(
            'results' => $results,
            'has_more' => count( $products ) === $batch_size
        );
    }
}
?>