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
                .cc-manual-section h4 { font-size: 1.1em; margin-top: 20px; margin-bottom: 10px; color: #23282d; }
                .cc-manual-list li { margin-bottom: 8px; line-height: 1.5; }
                .cc-callout { background: #f0f6fc; border-left: 4px solid #72aee6; padding: 15px; margin: 15px 0; }
                .cc-alert { background: #fcf0f1; border-left: 4px solid #d63638; padding: 15px; margin: 15px 0; }
                .cc-notice-top { background: #fff; border-left: 4px solid #00a32a; box-shadow: 0 1px 1px rgba(0,0,0,.04); padding: 12px; margin-bottom: 20px; }
            </style>

            <div class="cc-notice-top">
                <p style="margin:0;"><strong>🚧 Work in Progress:</strong> We are currently working on a comprehensive version of this manual. In the meantime, please feel free to email us directly at <a href="mailto:support@cirruslyweather.com">support@cirruslyweather.com</a> with any questions.</p>
            </div>

            <div class="card" style="max-width: 1000px; padding: 40px; margin-top: 20px;">
                <h2 style="margin-top:0;">Cirrusly Commerce User Manual</h2>
                <p><strong>Version:</strong> <?php echo esc_html( CIRRUSLY_COMMERCE_VERSION ); ?></p>
                <hr>
                <nav class="cc-manual-nav" style="background:#f0f6fc; padding:15px; border-radius:4px; margin-bottom:30px;">
                    <strong>Quick Links:</strong> 
                    <a href="#intro">Introduction</a>
                    <a href="#dashboard">Dashboard</a>
                    <a href="#gmc">GMC Hub</a>
                    <a href="#audit">Financial Audit</a>
                    <a href="#profit">Profit Engine</a>
                    <a href="#pricing">Pricing Engine</a>
                    <a href="#badges">Badge Manager</a>
                    <a href="#troubleshoot">Troubleshooting & Setup</a>
                    <a href="#compat">Compatibility</a>
                    <a href="#keys">Meta Keys</a>
                </nav>

                <div class="cc-manual-section" id="intro">
                    <h3>Introduction</h3>
                    <p>Cirrusly Commerce is a comprehensive suite designed to optimize your WooCommerce store’s financial health and compliance with Google Merchant Center (GMC). It unifies product data management, margin analysis, and policy enforcement into a single workflow.</p>
                </div>

                <div class="cc-manual-section" id="dashboard">
                    <h3>Dashboard Overview</h3>
                    <p>The <strong>Dashboard</strong> acts as your mission control, providing a real-time snapshot of your store's health.</p>
                    <ul class="cc-manual-list">
                        <li><strong>Catalog Metrics:</strong> View your total product count, how many items are currently On Sale, and the calculated Average Margin across your entire catalog.</li>
                        <li><strong>GMC Health:</strong> A color-coded card showing Critical Issues (Red) and Warnings (Orange). Critical issues prevent products from being approved by Google.</li>
                        <li><strong>Store Integrity:</strong> Tracks products with "Missing Cost." Without a Cost of Goods Sold (COGS) value, the plugin cannot calculate your profit. Use the "Open Audit" button to fix these.</li>
                        <li><strong>System Info:</strong> Access the "System Info" toggle in the top header to copy your environment details for support requests.</li>
                    </ul>
                </div>

                <div class="cc-manual-section" id="gmc">
                    <h3>GMC Hub</h3>
                    <p>Located at <em>Cirrusly Commerce > GMC Hub</em>. This module ensures your data meets Google's strict policies to prevent account suspensions.</p>

                    <h4>1. Health Check (Scan)</h4>
                    <p>The scanner analyzes your product titles and descriptions for restricted terms and validates required identifiers (GTIN/EAN).</p>
                    <ul class="cc-manual-list">
                        <li><strong>Mark Custom:</strong> If a product (like a handmade item) has no GTIN, click "Mark Custom" in the results. This sets <code>identifier_exists</code> to "no" for Google feeds.</li>
                        <li><strong>Block Save on Critical Error:</strong> <span class="cc-manual-pro">PRO</span> Prevents you from saving a product if it contains banned medical terms (e.g., "Cure", "COVID"), stopping accidental policy violations.</li>
                        <li><strong>Auto-strip Banned Words:</strong> <span class="cc-manual-pro">PRO</span> Automatically removes known banned words from product titles during the scan process.</li>
                    </ul>

                    <h4>2. Promotion Manager</h4>
                    <p>Easily manage "Merchant Promotions" (coupon codes displayed on Google Shopping ads).</p>
                    <ul class="cc-manual-list">
                        <li><strong>ID Generation:</strong> Automatically generates valid <code>promotion_id</code> strings for your feeds based on WooCommerce coupons.</li>
                        <li><strong>One-Click Submit:</strong> <span class="cc-manual-pro">PRO</span> Sends promotion data directly to Google via the Content API, bypassing the need for manual CSV uploads in Merchant Center.</li>
                    </ul>

                    <h4>3. Site Content Scan</h4>
                    <p>Scans your WordPress Pages (Refund Policy, Terms of Service, Contact) to ensure you meet Google's "Misrepresentation" policy requirements. It checks for required legal text and secure checkout indicators.</p>
                </div>

                <div class="cc-manual-section" id="audit">
                    <h3>Financial Audit</h3>
                    <p>Located at <em>Cirrusly Commerce > Financial Audit</em>. This spreadsheet-like view allows for rapid financial optimization of your catalog.</p>
                    <ul class="cc-manual-list">
                        <li><strong>The Grid:</strong> Columns include Cost, Price, Net Margin (Profit %), and Stock status.</li>
                        <li><strong>Visual Alerts:</strong> Products with <strong>0.00</strong> cost are flagged red. Low margin products (< 15%) are highlighted yellow.</li>
                        <li><strong>Inline Edit:</strong> <span class="cc-manual-pro">PRO</span> Click directly on the "Cost" or "Est. Shipping" values in the table to update them instantly via AJAX without reloading the page.</li>
                        <li><strong>Bulk Tools:</strong> <span class="cc-manual-pro">PRO</span> Use the "Import COGS" feature to upload a simple CSV (ID, Cost) to update thousands of products at once.</li>
                    </ul>
                </div>

                <div class="cc-manual-section" id="profit">
                    <h3>Profit Engine</h3>
                    <p>Navigate to <em>Cirrusly Commerce > Settings > Profit Engine</em>. These global settings drive the "Net Profit" calculations used throughout the plugin.</p>
                    
                    <div class="cc-callout">
                        <strong>Why is this important?</strong><br>
                        WooCommerce only knows your product price. To calculate <em>Profit</em>, Cirrusly Commerce needs to know your costs: <strong>Cost of Goods + Shipping Label Cost + Payment Fees</strong>.
                    </div>

                    <h4>1. Payment Processor Fees</h4>
                    <p>Define the transaction fees deducted from your revenue.</p>
                    <ul class="cc-manual-list">
                        <li><strong>Standard:</strong> Enter your primary rate (e.g., Stripe is 2.9% + $0.30).</li>
                        <li><strong>Mixed Mode:</strong> <span class="cc-manual-pro">PRO</span> If you use multiple gateways (e.g., PayPal and Stripe), enable "Mixed Mode" to blend the rates based on your split (e.g., 60% Stripe, 40% PayPal) for a more accurate average cost.</li>
                    </ul>

                    <h4>2. Shipping Revenue Tiers</h4>
                    <p>Define what the <em>customer pays</em> for shipping based on the cart total. This is your shipping revenue.</p>
                    <ul>
                        <li>Example: Orders $0-$50 pay $5.99 shipping.</li>
                        <li>Example: Orders $50+ pay $0.00 (Free Shipping).</li>
                    </ul>

                    <h4>3. Internal Shipping Cost</h4>
                    <p>Define what <em>you pay</em> to buy the shipping label. This is set per <strong>Shipping Class</strong>.</p>
                    <ul>
                        <li>If a product is in the "Heavy" class, you might define the cost as $15.00.</li>
                        <li>The system subtracts this cost from the shipping revenue to find the "Shipping Margin".</li>
                    </ul>

                    <h4>4. Scenario Matrix</h4>
                    <p>Create multipliers to model expensive scenarios (e.g., "Overnight Shipping" = 5.0x Base Cost). Use these in the Pricing Engine to ensure you don't lose money on expedited orders.</p>
                </div>

                <div class="cc-manual-section" id="pricing">
                    <h3>Pricing Engine (Product Editor)</h3>
                    <p>Located on the "General" tab of the WooCommerce product edit screen. This panel provides real-time feedback on your item's profitability.</p>
                    <ul class="cc-manual-list">
                        <li><strong>Financial Inputs:</strong> Enter <strong>MSRP</strong> (Retail Price), <strong>MAP</strong> (Min Advertised Price), and <strong>Google Min</strong> (lowest auto-price).</li>
                        <li><strong>Real-Time Calculator:</strong> As you type a price, the "Net Margin" bar updates instantly. It accounts for the COGS, Payment Fees (from settings), and Shipping Costs (from settings).</li>
                        <li><strong>Apply Strategy:</strong> Use the dropdown to auto-calculate a price. Example: "Undercut Competitor" might set the price to <em>Competitor - 1%</em>, provided it stays above your floor margin.</li>
                        <li><strong>GMC Attributes:</strong> Use the sidebar to assign <strong>Promotion IDs</strong> or <strong>Custom Labels</strong> (e.g., "clearance", "summer") specifically for this product.</li>
                    </ul>
                </div>

                <div class="cc-manual-section" id="badges">
                    <h3>Badge Manager</h3>
                    <p>Navigate to <em>Cirrusly Commerce > Settings > Badge Manager</em>. This module replaces standard "Sale" badges with dynamic, conversion-focused labels.</p>
                    <ul class="cc-manual-list">
                        <li><strong>Discount Pills:</strong> Automatically displays "SAVE 20%" or "SAVE $15" based on the price difference.</li>
                        <li><strong>Calculation Base:</strong> Choose to calculate savings against the <strong>MSRP</strong> (for deeper discount perception) or the <strong>Regular Price</strong>.</li>
                        <li><strong>"New" Badge:</strong> Auto-labels products added within the last X days.</li>
                        <li><strong>Custom Tag Badges:</strong> Upload custom icons (e.g., "Vegan", "Made in USA") that appear automatically on products tagged with specific WooCommerce tags.</li>
                    </ul>
                </div>

                <div class="cc-manual-section" id="troubleshoot">
                    <h3>Troubleshooting & Setup Issues</h3>
                    <p>Common configuration pitfalls and how to solve them.</p>

                    <h4>1. MSRP Not Appearing on Frontend</h4>
                    <p>If you have entered an MSRP but it is not showing on your product page:</p>
                    <ul class="cc-manual-list">
                        <li><strong>Check Settings:</strong> Go to <em>Cirrusly Commerce > Settings > Pricing</em> and ensure "Enable Frontend Display" is checked.</li>
                        <li><strong>Block Themes (FSE):</strong> If you are using a modern Block Theme (like Twenty Twenty-Four), the standard WooCommerce hooks may not run. You must use the <strong>"MSRP Display" Block</strong> in the Site Editor to place the price manually.</li>
                    </ul>

                    <h4>2. Data Not Showing in Google Merchant Center</h4>
                    <p>Cirrusly Commerce creates the data, but it does not generate the XML feed itself.</p>
                    <div class="cc-callout">
                        <strong>Solution:</strong> You must map the fields in your feed plugin (e.g., Product Feed PRO or CTX Feed).<br>
                        <em>Example:</em> Map <code>g:price</code> to the standard Price, and map <code>g:sale_price</code> to our <strong>MSRP</strong> (<code>_alg_msrp</code>) if you want to show strike-through pricing on Google.
                    </div>

                    <h4>3. Profit Margin Seems Incorrect</h4>
                    <p>If the "Net Profit" calculated on the product page looks too low or too high:</p>
                    <ul class="cc-manual-list">
                        <li><strong>Check Shipping Classes:</strong> Ensure the product is assigned a Shipping Class, and that you have defined a "Label Cost" for that class in <em>Settings > Profit Engine</em>.</li>
                        <li><strong>Check Payment Fees:</strong> Verify your Payment Processor Fee settings. A default of 0% will make your profit look higher than it actually is.</li>
                        <li><strong>Verify COGS:</strong> Ensure the "Cost of Goods" field is not empty or zero.</li>
                    </ul>

                    <h4>4. Automated Emails Not Arriving</h4>
                    <p>If you aren't receiving the Weekly Profit Report:</p>
                    <ul class="cc-manual-list">
                        <li>Check that the WP-Cron system is running on your server.</li>
                        <li>Verify your server can send outgoing PHP mail (check your spam folder).</li>
                    </ul>
                </div>

                <div class="cc-manual-section" id="compat">
                    <h3>Plugin Compatibility</h3>
                    <p>Cirrusly Commerce integrates with the following plugins:</p>
                    <ul class="cc-manual-list">
                        <li><strong>Product Feed PRO (AdTribes):</strong> Custom fields (MSRP, MAP) appear in attribute mapping dropdowns automatically.</li>
                        <li><strong>Rank Math &amp; Yoast SEO:</strong> MSRP/GTIN fields are registered for schema markup automatically.</li>
                        <li><strong>WooCommerce Subscriptions:</strong> Pricing Engine supports recurring pricing fields.</li>
                        <li><strong>Flexible Shipping:</strong> Detects shipping classes for cost calculation.</li>
                        <li><strong>WPFactory MSRP:</strong> Shares the <code>_alg_msrp</code> key for seamless migration.</li>
                    </ul>
                </div>
                
                <div class="cc-manual-section" id="keys" style="border-bottom:0;">
                    <h3>Database Key Reference</h3>
                    <p>For developers or custom feed configurations. Use these keys when mapping fields in your Feed plugin:</p>
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