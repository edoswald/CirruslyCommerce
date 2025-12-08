<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cirrusly_Commerce_GMC {

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
     *
     * Updates the product's `_gla_identifier_exists` meta to `'no'` when the
     * POST field `gmc_is_custom_product` is present, otherwise sets it to `'yes'`.
     * When present in POST, saves sanitized values for `_gmc_promotion_id` and
     * `_gmc_custom_label_0`. Deletes the transient `cirrusly_active_promos_stats`.
     *
     * @param int $post_id The ID of the product post being saved.
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
     * Update a product's GMC identifier flag based on quick/bulk edit input and clear cached promotion stats.
     *
     * When the request includes `gmc_is_custom_product`, the product meta `_gla_identifier_exists` is set to `no`.
     * When the request indicates a quick edit (`woocommerce_quick_edit`) but not a bulk edit, the meta is set to `yes`.
     *
     * @param \WC_Product $product The product being edited; its ID is used to update post meta.
     */
    public function save_quick_bulk_edit( $product ) {
        if ( ! isset( $_POST['woocommerce_meta_nonce'] ) || ! wp_verify_nonce( $_POST['woocommerce_meta_nonce'], 'woocommerce_save_data' ) ) {
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
     *
     * Verifies the current user has the 'edit_products' capability and the request nonce for the provided `pid`,
     * updates the product meta `_gla_identifier_exists` to `'no'` for that post ID, then redirects to the
     * Cirrusly GMC scan page and exits. If the user lacks capability, the request is terminated with an error;
     * nonce verification will also halt the request on failure.
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
     * Returns the set of GMC-monitored terms grouped by category and their enforcement metadata.
     *
     * Each top-level key is a category (e.g., 'promotional', 'medical'). Each category maps terms to an
     * associative array with keys:
     * - `severity`: enforcement level such as "Medium", "High", or "Critical".
     * - `scope`: where the term is monitored (e.g., "title", "all").
     * - `reason`: short explanation for monitoring or restriction.
     *
     * @return array<string, array<string, array{severity:string,scope:string,reason:string}>> Associative array of categories to term metadata.
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
                'cure'        => array('severity' => 'Critical', 'scope' => 'all', 'reason' => 'Medical claim.'),
                'heal'        => array('severity' => 'Critical', 'scope' => 'all', 'reason' => 'Medical claim implying permanent fix.'),
                'virus'       => array('severity' => 'Critical', 'scope' => 'all', 'reason' => 'Prohibited sensitive event claim.'),
                'covid'       => array('severity' => 'Critical', 'scope' => 'all', 'reason' => 'Sensitive event.'),
                'guaranteed'  => array('severity' => 'Medium',   'scope' => 'all', 'reason' => 'Must have linked policy.')
            )
        );
    }

    /**
     * Performs the Google Merchant Center health scan logic on local products.
     * Moved from UI class to allow scheduled background scanning.
     * * @return array List of products with issues.
     */
    public static function run_gmc_scan_logic( $batch_size = 100, $paged = 1 ) {
        if ( ! current_user_can( 'edit_products' ) ) {
            return array();
        }
        
        $results = array();
        
        // 1. Fetch Pro Statuses (Real data from Google)
        $google_issues = array();
        // Check if Pro class exists to avoid dependency errors
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

        foreach ( $products as $pid ) {
            $product_issues = array();
            $p = wc_get_product( $pid );
            if ( ! $p ) continue;

            // CHECK: GTIN / MPN Existence
            $is_custom = get_post_meta( $pid, '_gla_identifier_exists', true );
            
            // Basic health check simulation
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

            // CHECK: Price - Fixed if statement to handle free products
            if ( '' === $p->get_price() ) {
                $product_issues[] = array(
                    'type' => 'critical',
                    'msg'  => 'Missing Price',
                    'reason' => 'Price is mandatory.'
                );
            }

            // Merge Google API Issues
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