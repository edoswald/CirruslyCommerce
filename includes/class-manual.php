<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cirrusly_Commerce_Manual {
    public static function render_page() {
        echo '<div class="wrap">';
        Cirrusly_Commerce_Core::render_global_header( 'User Manual' );
        ?>
            <style>
                .cc-manual-nav a { text-decoration: none; margin-right: 15px; font-weight: 500; }
                .cc-manual-nav a:hover { text-decoration: underline; }
                .cc-manual-pro { background:#2271b1; color:#fff; padding:2px 6px; border-radius:3px; font-size:10px; vertical-align:middle; margin-left:5px; font-weight:bold; }
                .cc-manual-section { margin-bottom: 40px; border-bottom: 1px solid #eee; padding-bottom: 20px; }
                .cc-manual-section h3 { font-size: 1.3em; margin-bottom: 15px; display: flex; align-items: center; }
                .cc-manual-list li { margin-bottom: 8px; }
            </style>

            <div class="card" style="max-width: 1000px; padding: 40px; margin-top: 20px;">
                <h2 style="margin-top:0;">Cirrusly Commerce User Manual</h2>
                <p><strong>Version:</strong> <?php echo esc_html( CIRRUSLY_COMMERCE_VERSION ); ?></p>
                <hr>
                <nav class="cc-manual-nav" style="background:#f0f6fc; padding:15px; border-radius:4px; margin-bottom:30px;">
                    <strong>Quick Links:</strong> 
                    <a href="#intro">Introduction</a>
                    <a href="#compat">Compatibility</a>
                    <a href="#general">General Settings</a>
                    <a href="#profit">Profit Engine</a>
                    <a href="#gmc">GMC Hub</a>
                    <a href="#audit">Store Audit</a>
                    <a href="#badges">Badge Manager</a>
                    <a href="#pricing">Pricing Engine</a>
                    <a href="#keys">Meta Keys</a>
                </nav>

                <div class="cc-manual-section" id="intro">
                    <h3>What is Cirrusly Commerce?</h3>
                    <p>Cirrusly Commerce is an all-in-one suite designed to optimize your WooCommerce store’s financial health and Google Merchant Center (GMC) compliance.</p>
                </div>

                <div class="cc-manual-section" id="compat">
                    <h3>Plugin Compatibility</h3>
                    <p>Cirrusly Commerce is designed to work alongside popular WooCommerce plugins:</p>
                    <ul class="cc-manual-list">
                        <li><strong>Product Feed PRO (AdTribes):</strong> Our custom fields (MSRP, MAP, etc.) automatically appear in the attribute mapping dropdowns.</li>
                        <li><strong>Rank Math & Yoast SEO:</strong> MSRP and other fields are registered for use in meta tags and schema output.</li>
                        <li><strong>WooCommerce Subscriptions:</strong> The Pricing Engine detects subscription pricing fields automatically.</li>
                        <li><strong>Flexible Shipping (Octolize):</strong> Shipping classes created here are automatically detected for cost calculation.</li>
                        <li><strong>WPFactory MSRP:</strong> We use the same database key (<code>_alg_msrp</code>) so you can switch between plugins without losing data.</li>
                    </ul>
                </div>

                <div class="cc-manual-section" id="general">
                    <h3>General Settings</h3>
                    <p>Navigate to <em>Cirrusly Commerce > Settings > General</em> to configure these options:</p>
                    <ul class="cc-manual-list">
                        <li><strong>Integrations (Google Customer Reviews):</strong> Enable this to automatically inject the Google Reviews survey code on your "Thank You" / Order Received page. You must provide your Google Merchant ID.</li>
                        <li><strong>Daily Health Scan:</strong> When enabled, the system runs a background check every 24 hours to identify critical GMC issues (like missing GTINs or banned medical terms).</li>
                        <li><strong>Frontend Display:</strong> Toggle the "Show Strikethrough MSRP" option to automatically display MSRP prices on product pages. For more control, leave this off and use the Gutenberg block instead.</li>
                        <li><strong>Hide Pro Features:</strong> If you are on the free version and want to declutter the interface, check this to hide upgrade notices.</li>
                    </ul>
                    <p><strong>Pro Features:</strong> <span class="cc-manual-pro">PRO</span></p>
                    <ul class="cc-manual-list">
                        <li><strong>Content API Connection:</strong> Upload your Google Service Account JSON to enable real-time syncing of price and stock data directly to Google Merchant Center.</li>
                        <li><strong>Advanced Alerts:</strong> Enable email notifications for weekly profit reports or instant alerts when a product is disapproved by Google.</li>
                    </ul>
                </div>

                <div class="cc-manual-section" id="profit">
                    <h3>Profit Engine (Shipping & Fees)</h3>
                    <p>Navigate to <em>Cirrusly Commerce > Settings > Profit Engine</em>. These settings are crucial for the "Net Profit" calculations seen in the Audit and on product pages.</p>
                    
                    <h4>1. Payment Processor Fees</h4>
                    <p>Enter the average fee you pay per transaction (e.g., Stripe is typically 2.9% + $0.30). <span class="cc-manual-pro">PRO</span> users can configure multiple gateway profiles (e.g., blend PayPal and Stripe rates).</p>

                    <h4>2. Shipping Revenue Tiers</h4>
                    <p>Define how much you charge customers for shipping based on the cart total. <em>Example: $0 - $50 orders pay $5.99; Orders over $50 pay $0.00.</em></p>

                    <h4>3. Internal Shipping Cost</h4>
                    <p>Define how much it costs <strong>YOU</strong> to ship items based on their Shipping Class. If a product has no class, the "Default" cost is used.</p>

                    <h4>4. Scenario Matrix</h4>
                    <p>Create multipliers for specific shipping scenarios (e.g., "Overnight" = 5.0x Cost). These are used in the Pricing Engine on the product edit screen to stress-test your margins.</p>
                </div>

                <div class="cc-manual-section" id="gmc">
                    <h3>GMC Hub</h3>
                    <p>Located at <em>Cirrusly Commerce > GMC Hub</em>. This module ensures your data meets Google's strict policies.</p>

                    <h4>Health Check (Scan)</h4>
                    <p>Run a diagnostics scan to find issues like missing GTINs or banned words.</p>
                    <ul class="cc-manual-list">
                        <li><strong>Mark Custom:</strong> If a product (like a handmade item) has no GTIN/Barcode, click "Mark Custom" in the results table. This sets <code>identifier_exists</code> to "no".</li>
                        <li><strong>Block Save on Critical Error:</strong> <span class="cc-manual-pro">PRO</span> Prevents you from saving a product if it contains banned medical terms (e.g., "Cure", "COVID").</li>
                        <li><strong>Auto-strip Banned Words:</strong> <span class="cc-manual-pro">PRO</span> Automatically removes known banned words from titles during the scan process.</li>
                    </ul>

                    <h4>Promotion Manager</h4>
                    <p>Generate valid promotion IDs and CSV snippets for Google's Merchant Center Promotions feed.</p>
                    <ul class="cc-manual-list">
                        <li><strong>One-Click Submit:</strong> <span class="cc-manual-pro">PRO</span> Send promotion data directly to Google via the API instead of manually uploading a CSV.</li>
                    </ul>

                    <h4>Site Content Scan</h4>
                    <p>Scans your Pages (not just products) for required legal pages (Refund Policy, TOS) and restricted terms that might cause account-level suspensions.</p>
                </div>

                <div class="cc-manual-section" id="audit">
                    <h3>Store Audit</h3>
                    <p>Located at <em>Cirrusly Commerce > Store Audit</em>. This tool provides a spreadsheet-like view of your entire catalog's financial performance.</p>
                    <ul class="cc-manual-list">
                        <li><strong>Filtering:</strong> Use the controls to filter by low margin (< 5%, 15%, 25%), Category, or Out of Stock status.</li>
                        <li><strong>Alerts:</strong> Products with missing costs or 0 weight will be flagged with a badge.</li>
                        <li><strong>Inline Edit:</strong> <span class="cc-manual-pro">PRO</span> Click directly on the Cost value in the table to edit it without opening the product page.</li>
                        <li><strong>Export CSV:</strong> <span class="cc-manual-pro">PRO</span> Download the full audit report for external analysis.</li>
                        <li><strong>Bulk Import COGS:</strong> <span class="cc-manual-pro">PRO</span> Upload a CSV (Column A: ID, Column D: Cost) to bulk update Cost of Goods Sold.</li>
                    </ul>
                </div>

                <div class="cc-manual-section" id="badges">
                    <h3>Badge Manager</h3>
                    <p>Navigate to <em>Cirrusly Commerce > Settings > Badge Manager</em>.</p>
                    <ul class="cc-manual-list">
                        <li><strong>Enable Module:</strong> Replaces default WooCommerce sale badges with dynamic percentage "Pills" (e.g., "SAVE 20%").</li>
                        <li><strong>Badge Size & Base:</strong> Choose the visual size and whether savings are calculated from the MSRP or the Regular Price.</li>
                        <li><strong>"New" Badge:</strong> Define how many days a product is considered "New" to display the blue "NEW" badge.</li>
                        <li><strong>Custom Tag Badges:</strong> Upload custom images (e.g., "Vegan", "Made in USA") that appear automatically on products with specific Tags.</li>
                    </ul>
                    <p><strong>Smart Badges:</strong> <span class="cc-manual-pro">PRO</span></p>
                    <ul class="cc-manual-list">
                        <li><strong>Inventory:</strong> Automatically show "Low Stock" badge when quantity is below 5.</li>
                        <li><strong>Performance:</strong> Show "Best Seller" badge for top-performing products.</li>
                        <li><strong>Scheduler:</strong> Schedule badges to appear only during specific date ranges.</li>
                    </ul>
                </div>

                <div class="cc-manual-section" id="pricing">
                    <h3>Pricing Engine (Product Editor)</h3>
                    <p>Located on the "General" tab of the product edit screen. Enter your financial data to see real-time profit margins:</p>
                    <ul class="cc-manual-list">
                        <li><strong>Google Min ($):</strong> The lowest price Google is allowed to auto-discount this item to (used with Automated Discounts).</li>
                        <li><strong>MAP ($):</strong> Minimum Advertised Price. Used for internal policy compliance.</li>
                        <li><strong>MSRP ($):</strong> Manufacturer Suggested Retail Price.</li>
                    </ul>
                    <p>Use the "Apply Strategy" dropdown to auto-calculate optimal prices (e.g., "10% Off MSRP") based on your entered costs.</p>
                    <p><strong>GMC Data Tab:</strong> Use the "Google Merchant Center Attributes" sidebar to set Promotion IDs or Custom Labels for individual products.</p>
                </div>

                <div class="cc-manual-section" id="support">
                    <h3>Support</h3>
                    <p>If you encounter issues or have questions, please use the button below to email our support team directly. Be sure to include your plugin version number.</p>
                    <p>
                        <a href="mailto:help@cirruslyweather.com?subject=Support%20Request%3A%20Cirrusly%20Commerce" class="button button-primary button-large">Email Support</a>
                    </p>
                </div>
                
                <div class="cc-manual-section" id="keys" style="border-bottom:0;">
                    <h3>Database Key Reference</h3>
                    <p>If you are using a custom feed solution or need to access raw data, use these meta keys:</p>
                    <table class="widefat striped" style="max-width:600px;">
                        <thead><tr><th>Field</th><th>Meta Key</th></tr></thead>
                        <tbody>
                            <tr><td>Cost of Goods</td><td><code>_cogs_total_value</code></td></tr>
                            <tr><td>Estimated Shipping Cost</td><td><code>_cw_est_shipping</code></td></tr>
                            <tr><td>GMC Floor Price</td><td><code>_auto_pricing_min_price</code></td></tr>
                            <tr><td>MSRP Price</td><td><code>_alg_msrp</code></td></tr>
                            <tr><td>MAP Price</td><td><code>_cirrusly_map_price</code></td></tr>
                            <tr><td>Promotion ID</td><td><code>_gmc_promotion_id</code></td></tr>
                            <tr><td>Custom Label 0</td><td><code>_gmc_custom_label_0</code></td></tr>
                            <tr><td>Identifier Exists</td><td><code>_gla_identifier_exists</code></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }
}
