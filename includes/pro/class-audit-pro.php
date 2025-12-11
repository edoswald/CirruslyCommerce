<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Cirrusly_Commerce_Audit_Pro {

    /**
     * Initialize Pro hooks.
     */
    public static function init() {
        add_action( 'admin_init', array( __CLASS__, 'handle_export' ) );
    }

    /**
     * Stream compiled audit data as a CSV download for the Cirrusly Audit export action.
     *
     * When the current admin request includes page=cirrusly-audit and action=export_csv this method
     * verifies the current user has the `edit_products` capability and that the Pro feature is active,
     * then sends CSV headers, writes a header row and one CSV row per compiled audit record, and
     * terminates the request after output.
     *
     * CSV filename pattern: store-audit-YYYY-MM-DD.csv
     *
     * Columns emitted: ID, Product Name, Type, Cost (COGS), Shipping Cost, Price, Net Profit, Margin %,
     * MAP, Google Min, MSRP. Price-like numeric columns that are not greater than zero are emitted as empty.
     */
    public static function handle_export() {
        if ( isset($_GET['page']) && $_GET['page'] === 'cirrusly-audit' && isset($_GET['action']) && $_GET['action'] === 'export_csv' ) {
            if ( ! current_user_can( 'edit_products' ) ) return;
            
            // Double check pro status
            if ( ! Cirrusly_Commerce_Core::cirrusly_is_pro() ) {
                wp_die('Export is a Pro feature.');
            }

            // Reuse the core calculation method
            $data = Cirrusly_Commerce_Audit::get_compiled_data();

            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="store-audit-' . gmdate('Y-m-d') . '.csv"');
            
            // Define headers
            $headers = array('ID', 'Product Name', 'Type', 'Cost (COGS)', 'Shipping Cost', 'Price', 'Net Profit', 'Margin %', 'MAP', 'Google Min', 'MSRP');
            
            // Helper to escape CSV fields manually to avoid fputcsv/fopen
            $csv_escape = function( $field ) {
                $field = (string) $field;
                // Check for delimiters, quotes, or newlines
                if ( strpbrk( $field, ",\"\n\r" ) !== false ) {
                    // Escape double quotes by doubling them and wrapping the field in quotes
                    $field = '"' . str_replace( '"', '""', $field ) . '"';
                }
                return $field;
            };

            // Output Header Row
            echo implode( ',', array_map( $csv_escape, $headers ) ) . "\n";
            
            // Output Data Rows
            foreach ( $data as $row ) {
                $fields = array(
                    $row['id'],
                    $row['name'],
                    $row['type'],
                    $row['item_cost'],
                    $row['ship_cost'],
                    $row['price'],
                    $row['net'],
                    number_format($row['margin'], 2) . '%',
                    $row['map'] > 0 ? $row['map'] : '',
                    $row['min_price'] > 0 ? $row['min_price'] : '',
                    $row['msrp'] > 0 ? $row['msrp'] : ''
                );
                
                echo implode( ',', array_map( $csv_escape, $fields ) ) . "\n";
            }
            
            exit;
        }
    }

    /**
     * Import product COGS and pricing fields from an uploaded CSV and update matching products' metadata.
     *
     * Processes the uploaded `csv_import` file (when the current user can edit products and the Pro feature is enabled),
     * reads the header to map columns (handles a leading BOM on the first column), requires an "ID" column, and for each
     * row updates available values on the matching post ID. Updated fields and their meta keys:
     * - "Cost (COGS)" -> `_cogs_total_value`
     * - "MAP" -> `_cirrusly_map_price`
     * - "Google Min" -> `_auto_pricing_min_price`
     * - "MSRP" -> `_alg_msrp`
     *
     * Price-like values are normalized with `wc_format_decimal()` before saving. On failure this method registers
     * appropriate settings errors; on success it clears the `cirrusly_audit_data` transient and registers a success message
     * indicating how many products were updated.
     * * Uses WP_Filesystem to read the uploaded file to comply with plugin standards.
     */
    public static function handle_import() {
        if ( isset($_FILES['csv_import']) && current_user_can('edit_products') ) {
            if ( ! Cirrusly_Commerce_Core::cirrusly_is_pro() ) {
                add_settings_error('cirrusly_audit', 'import_fail', 'Import is a Pro feature.', 'error');
                return;
            }

            // Initialize WP_Filesystem
            global $wp_filesystem;
            if ( empty( $wp_filesystem ) ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                WP_Filesystem();
            }

            $file = $_FILES['csv_import']['tmp_name'];

            // Check if file exists and is readable using WP_Filesystem
            if ( ! $file || ! $wp_filesystem->exists( $file ) || ! $wp_filesystem->is_readable( $file ) ) {
                add_settings_error( 'cirrusly_audit', 'import_fail', 'Could not read uploaded CSV file.', 'error' );
                return;
            }
            
            // Read file content
            $content = $wp_filesystem->get_contents( $file );
            if ( empty( $content ) ) {
                add_settings_error( 'cirrusly_audit', 'import_fail', 'CSV file is empty.', 'error' );
                return;
            }

            // Split content into lines, handling different newline characters
            $lines = preg_split( "/\r\n|\n|\r/", $content );
            // Remove empty lines
            $lines = array_filter( $lines, function($line) { return trim($line) !== ''; } );

            if ( empty( $lines ) ) {
                add_settings_error( 'cirrusly_audit', 'import_fail', 'CSV file appears to be empty or invalid.', 'error' );
                return;
            }
            
            // 1. Get Header Row & Map Indices
            $header_line = array_shift( $lines );
            
            // Remove BOM if present at the start of the header line
            $header_line = preg_replace( '/^\xEF\xBB\xBF/', '', $header_line );
            
            $header = str_getcsv( $header_line );

             $map = array();
             foreach ( $header as $index => $col_name ) {
                 $key = trim( (string) $col_name );
                 if ( '' === $key ) continue;
                 $map[ $key ] = $index;
             }

            if ( ! isset($map['ID']) ) {
                add_settings_error('cirrusly_audit', 'import_fail', 'Invalid CSV: Missing "ID" column.', 'error');
                return;
            }

            $count = 0;
            
            foreach ( $lines as $line ) {
                $row = str_getcsv( $line );
                
                $id_idx = $map['ID'];
                
                // Ensure row has the ID column
                if ( ! isset($row[$id_idx]) || empty($row[$id_idx]) ) continue;
                
                $pid = intval( $row[ $id_idx ] );
                if ( ! $pid || ! get_post( $pid ) ) continue;

                $updated = false;

                $update_field = function( $key, $meta_key, $is_price = true ) use ($row, $map, $pid, &$updated) {
                    if ( isset($map[$key]) && array_key_exists($map[$key], $row) && $row[$map[$key]] !== '' ) {
                        $val = $row[$map[$key]];
                        if ( $is_price ) $val = wc_format_decimal($val);
                        update_post_meta($pid, $meta_key, $val);
                        $updated = true;
                    }
                };

                $update_field('Cost (COGS)', '_cogs_total_value');
                $update_field('MAP', '_cirrusly_map_price');
                $update_field('Google Min', '_auto_pricing_min_price');
                $update_field('MSRP', '_alg_msrp');

                if ( $updated ) $count++;
            }
            
            // Updated transient name to match class-audit.php
            delete_transient( 'cirrusly_audit_data' ); 
            add_settings_error('cirrusly_audit', 'import_success', "Updated data for $count products.", 'success');
        }
    }
}