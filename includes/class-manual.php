<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cirrusly_Commerce_Manual {
    /**
     * Render the Cirrusly Commerce user manual page in the WordPress admin.
     *
     * Outputs a self-contained HTML page (including inline CSS, navigation, and content sections)
     * that documents plugin features, admin workflows, and developer meta keys. Inserts the
     * current plugin version from CIRRUSLY_COMMERCE_VERSION. This method is purely presentational
     * and does not alter application state or persistent data.
     */
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
                .cc-tip { background: #f0f9eb; border-left: 4px solid #00a32a; padding: 15px; margin: 15px 0; }
                code { background: #f0f0f1; padding: 2px 5px; border-radius: 3px; }
            </style>

            <div class="cc-notice-top">
                <p style="margin:0;"><strong>🚧 Work in Progress:</strong> We are currently working on a comprehensive version of this manual. In the meantime, please feel free to email us directly at <a href="mailto:support@cirruslyweather.com">support@cirruslyweather.com</a> with any questions.</p>
            </div>


            <div class="card" style="max-width: 1000px; padding: 40px; margin-top: 20px;">
                <h2 style="margin-top:0;">Cirrusly Commerce User Manual</h2>
                <p><strong>Version:</strong> <?php echo esc_html( CIRRUSLY_COMMERCE_VERSION ); ?></p>
                <hr>
                <nav class="cc-manual-nav" style="background:#f0f6fc; padding:15px; border-radius:4px; margin-bottom:30px; line-height: 2;">
                    <strong>Quick Links:</strong> 
                    <a href="#intro">Introduction</a>
                    <a href="#dashboard">Dashboard & Widgets</a>
                    <a href="#gmc">GMC Hub</a>
                    <a href="#reviews">Google Reviews</a>
                    <a href="#audit">Financial Audit</a>
                    <a href="#profit">Profit Engine</a>
                    <a href="#pricing">Pricing & MSRP</a>
                    <a href="#countdown">Countdown Timer</a>
                    <a href="#badges">Badge Manager</a>
                    <a href="#reports">Email Reports</a>
                    <a href="#troubleshoot">Troubleshooting</a>
                </nav>

                <div class="cc-manual-section" id="intro">
                    <h3>Introduction</h3>
                    <p>Cirrusly Commerce is a comprehensive suite designed to optimize your WooCommerce store’s financial health and compliance with Google Merchant Center (GMC). It unifies product data management, margin analysis, and policy enforcement into a single workflow.</p>
                </div>

                <div class="cc-manual-section" id="dashboard">
                    <h3>Dashboard & Widgets</h3>
                    <p>The <strong>Dashboard</strong> acts as your mission control, providing a real-time snapshot of your store's health. Additionally, a summary widget is available on your main WordPress Dashboard.</p>
                    
                    <h4>Main Dashboard (Cirrusly Commerce > Dashboard)</h4>
                    <ul class="cc-manual-list">
                        <li><strong>Store Pulse:</strong> A 7-day rolling view of your Revenue and Order count.</li>
                        <li><strong>Catalog Metrics:</strong> View your total product count, On Sale items, Low Stock alerts, and calculated Average Margin.</li>
                        <li><strong>GMC Health:</strong> A color-coded card showing Critical Issues (Red) and Warnings (Orange).</li>
                        <li><strong>Store Integrity:</strong> Tracks products with "Missing Cost." Without a Cost of Goods Sold (COGS) value, profit cannot be calculated.</li>
                    </ul>

                    <h4>WordPress Admin Widget</h4>
                    <p>We inject a "Cirrusly Commerce Overview" widget onto your main WordPress start screen. This allows you to see your <strong>Revenue Pulse</strong> and <strong>Critical GMC Issues</strong> immediately after logging in, without navigating to the plugin settings.</p>
                </div>

                <div class="cc-manual-section" id="gmc">
                    <h3>GMC Hub</h3>
                    <p>Located at <em>Cirrusly Commerce > GMC Hub</em>. This module ensures your data meets Google's strict policies.</p>

                    <h4>1. Health Check (Scan)</h4>
                    <p>The scanner analyzes your product titles and descriptions for restricted terms and validates required identifiers (GTIN/EAN). You can schedule this to run automatically in <em>Settings > General</em>.</p>
                    <ul class="cc-manual-list">
                        <li><strong>Mark Custom:</strong> If a product (like a handmade item) has no GTIN, click "Mark Custom" to set <code>identifier_exists</code> to "no".</li>
                        <li><strong>Block Save on Critical Error:</strong> <span class="cc-manual-pro">PRO</span> Prevents saving products containing banned medical terms (e.g., "Cure", "COVID").</li>
                        <li><strong>Service Account Integration:</strong> <span class="cc-manual-pro">PRO</span> By uploading your Google Cloud Service Account JSON in Settings, you unlock direct API communication for faster updates.</li>
                    </ul>

                    <h4>2. Promotion Manager</h4>
                    <p>Manage "Merchant Promotions" (coupon codes displayed on Google Shopping ads).</p>
                    <ul class="cc-manual-list">
                        <li><strong>ID Generation:</strong> Automatically generates valid <code>promotion_id</code> strings for your feeds.</li>
                        <li><strong>One-Click Submit:</strong> <span class="cc-manual-pro">PRO</span> Sends promotion data directly to Google via the Content API, bypassing manual CSV uploads. <strong>Requires Service Account.</strong></li>
                    </ul>

                    <h4>3. Site Content Scan</h4>
                    <p>Scans WordPress Pages (Refund Policy, TOS, Contact) to ensure they meet Google's "Misrepresentation" policy requirements.</p>
                </div>

                <div class="cc-manual-section" id="reviews">
                    <h3>Google Customer Reviews</h3>
                    <p>Located in <em>Settings > General</em>. This feature integrates the Google Customer Reviews survey opt-in on your "Order Received" (Thank You) page.</p>
                    <ol class="cc-manual-list">
                        <li>Go to <em>Settings > General</em>.</li>
                        <li>Enter your <strong>Merchant ID</strong>.</li>
                        <li>Check "Enable Survey".</li>
                    </ol>
                    <div class="cc-tip">
                        <strong>Note:</strong> The survey only appears to customers after they complete a purchase. Google handles the email delivery based on the estimated delivery date.
                    </div>
                </div>

                <div class="cc-manual-section" id="audit">
                    <h3>Financial Audit</h3>
                    <p>Located at <em>Cirrusly Commerce > Financial Audit</em>. This spreadsheet-like view allows for rapid financial optimization.</p>
                    <ul class="cc-manual-list">
                        <li><strong>Visual Alerts:</strong> Products with <strong>0.00</strong> cost are flagged red. Low margin products (< 15%) are yellow.</li>
                        <li><strong>Inline Edit:</strong> <span class="cc-manual-pro">PRO</span> Click directly on the "Cost" or "Est. Shipping" values in the table to update them instantly via AJAX.</li>
                        <li><strong>Filtering:</strong> Use the filters to show only "Loss Makers" (negative profit) or "Missing Cost" items.</li>
                    </ul>
                </div>

                <div class="cc-manual-section" id="profit">
                    <h3>Profit Engine</h3>
                    <p>Navigate to <em>Cirrusly Commerce > Settings > Profit Engine</em>. These global settings drive the "Net Profit" calculations.</p>

                    <h4>1. Payment Processor Fees</h4>
                    <p>Define the transaction fees deducted from your revenue.</p>
                    <ul class="cc-manual-list">
                        <li><strong>Single Mode:</strong> Enter your primary rate (e.g., Stripe is 2.9% + $0.30).</li>
                        <li><strong>Mixed Mode:</strong> <span class="cc-manual-pro">PRO</span> If you use multiple gateways (e.g., PayPal + Stripe), enable "Mixed Mode". You can define a "Profile Split" (e.g., 60% of orders use Primary, 40% use Secondary) to blend the fees into a weighted average cost.</li>
                    </ul>

                    <h4>2. Shipping Revenue Tiers & Costs</h4>
                    <p><strong>Revenue Tiers:</strong> Define what the customer pays (e.g., Orders $0-$50 pay $5.99).<br>
                    <strong>Internal Costs:</strong> Define what YOU pay per Shipping Class (e.g., "Heavy" items cost $15.00 to ship).</p>

                    <h4>3. Scenario Matrix</h4>
                    <p>Create multipliers to model expensive scenarios (e.g., "Overnight Shipping" = 5.0x Base Cost). These appear in the Product Pricing calculator to test "What if?" scenarios.</p>
                </div>

                <div class="cc-manual-section" id="pricing">
                    <h3>Pricing Engine & MSRP</h3>
                    
                    <h4>Real-Time Calculator</h4>
                    <p>On the product edit screen ("General" tab), the Pricing Engine bar updates instantly as you type a price. It subtracts COGS, Payment Fees, and Shipping Costs to show <strong>Net Margin %</strong>.</p>

                    <h4>MSRP / RRP Display</h4>
                    <p>You can display a strike-through "List Price" on the frontend.</p>
                    <ul class="cc-manual-list">
                        <li><strong>Enable:</strong> Go to <em>Settings > General > Frontend Display</em>.</li>
                        <li><strong>Positioning:</strong> Choose where the MSRP appears on the <strong>Product Page</strong> (e.g., Before Price, Inline, After Meta) and on the <strong>Loop/Catalog</strong> (e.g., Before Price).</li>
                        <li><strong>Block Themes:</strong> If you use a Full Site Editing (FSE) theme, use the dedicated <strong>"MSRP Display" Block</strong> in the editor.</li>
                    </ul>
                </div>

                <div class="cc-manual-section" id="countdown">
                    <h3>Countdown Timer</h3>
                    <p>Create urgency with lightweight, CLS-free countdown timers.</p>
                    
                    <h4>Smart Rules <span class="cc-manual-pro">PRO</span></h4>
                    <p>In <em>Settings > General</em>, scroll to "Smart Countdown Rules". You can auto-inject timers onto products based on taxonomy.</p>
                    <ul>
                        <li><strong>Taxonomy:</strong> <code>product_cat</code>, <code>product_tag</code>, or <code>product_brand</code> (depending on plugin).</li>
                        <li><strong>Term:</strong> The slug (e.g., <code>flash-sale</code>).</li>
                        <li><strong>End Date:</strong> YYYY-MM-DD HH:MM:SS format.</li>
                    </ul>

                    <h4>Shortcode</h4>
                    <p>Place a timer manually in descriptions:</p>
                    <code>[cw_countdown end="2025-12-31 23:59" label="Offer Ends:" align="center"]</code>
                </div>

                <div class="cc-manual-section" id="badges">
                    <h3>Badge Manager</h3>
                    <p>Navigate to <em>Cirrusly Commerce > Settings > Badge Manager</em>.</p>
                    
                    <h4>Smart Badges <span class="cc-manual-pro">PRO</span></h4>
                    <p>Toggle these automatic logic rules to boost conversion:</p>
                    <ul class="cc-manual-list">
                        <li><strong>Inventory:</strong> Displays a badge when stock is &le; 5 (uses WooCommerce stock management).</li>
                        <li><strong>Performance:</strong> Displays a "Best Seller" badge on your top-performing products.</li>
                        <li><strong>Scheduler:</strong> Displays an event badge (e.g., "Black Friday") across the store between specific dates.</li>
                    </ul>

                    <h4>Custom Tag Badges</h4>
                    <p>Upload custom icons (e.g., "Vegan", "Made in USA") that appear automatically on products tagged with specific WooCommerce tags.</p>
                </div>

                <div class="cc-manual-section" id="reports">
                    <h3>Email Reports & Alerts</h3>
                    <p>Stay informed without logging in. Configure these in <em>Settings > General > Advanced Alerts</em>.</p>
                    <ul class="cc-manual-list">
                        <li><strong>Weekly Profit Report:</strong> <span class="cc-manual-pro">PRO</span> Sends a summary every week detailing revenue, orders, and total estimated margin.</li>
                        <li><strong>Scan Reports:</strong> If "Enable Email Report" is checked in Automation settings, you will receive a digest whenever the Daily Health Scan finds new issues.</li>
                        <li><strong>Instant Disapproval:</strong> <span class="cc-manual-pro">PRO</span> Receive an immediate email if the API detects a product has been disapproved by Google.</li>
                    </ul>
                </div>

                <div class="cc-manual-section" id="troubleshoot">
                    <h3>Troubleshooting</h3>
                    
                    <h4>1. API Error: "User cannot access account"</h4>
                    <p><strong>Cause:</strong> The Service Account email (from your JSON file) has not been added to Google Merchant Center.</p>
                    <p><strong>Fix:</strong> Log into Merchant Center > Settings > People & Access. Add the email address found in your JSON file (ending in <code>.iam.gserviceaccount.com</code>) as a user.</p>

                    <h4>2. MSRP Not Showing</h4>
                    <p>If enabled in settings but invisible:
                    <ul>
                        <li>Check if your theme hooks into standard WooCommerce locations.</li>
                        <li>If using a Page Builder (Elementor/Divi), you may need to add the MSRP shortcode or block manually.</li>
                    </ul>

                    <h4>3. Profit Calculation Looks Wrong</h4>
                    <p>Check <strong>Profit Engine > Payment Processor Fees</strong>. If set to 0%, your margin will look artificially high. Also ensure you have defined <strong>Internal Shipping Costs</strong> for your Shipping Classes.</p>
                </div>

                <div class="cc-manual-section" id="keys" style="border-bottom:0;">
                    <h3>Database Key Reference</h3>
                    <p>For developers or custom feed configurations. Use these meta keys when mapping fields in your Feed plugin:</p>
                    <table class="widefat striped" style="max-width:600px;">
                        <thead><tr><th>Field</th><th>Meta Key</th></tr></thead>
                        <tbody>
                            <tr><td>Cost of Goods</td><td><code>_cogs_total_value</code></td></tr>
                            <tr><td>Estimated Shipping Cost</td><td><code>_cw_est_shipping</code></td></tr>
                            <tr><td>GMC Floor Price</td><td><code>_auto_pricing_min_price</code></td></tr>
                            <tr><td>MSRP Price</td><td><code>_alg_msrp</code></td></tr>
                            <tr><td>MAP Price</td><td><code>_cirrusly_map_price</code></td></tr>
                            <tr><td>Promotion ID</td><td><code>_gmc_promotion_id</code></td></tr>
                            <tr><td>Sale Timer End</td><td><code>_cw_sale_end</code></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }
}