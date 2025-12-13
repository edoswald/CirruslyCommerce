<?php 
defined( 'ABSPATH' ) || exit; 
?>

<div class="wrap">
    <?php Cirrusly_Commerce_Core::render_global_header( __( 'User Manual', 'cirrusly-commerce' ) ); ?>

    <div class="card cirrusly-manual-container" style="max-width: 1200px; margin-top: 20px; padding: 0;">
        
        <div class="cirrusly-manual-grid" style="display: flex;">
            <div class="cirrusly-manual-sidebar" style="width: 250px; background: #f0f6fc; padding: 20px; border-right: 1px solid #dcdcde;">
                <h3 style="margin-top: 0; font-size: 1.1em; color: #1d2327;">Table of Contents</h3>
                <ul style="margin: 0; list-style: none; line-height: 2;">
                    <li><a href="#intro" style="text-decoration:none;">Introduction</a></li>
                    <li><a href="#support" style="text-decoration:none;">Plugin Support</a></li>
                    <li><a href="#installation" style="text-decoration:none;">Installation & License</a></li>
                    <li><a href="#connect" style="text-decoration:none;">Connect & API</a></li>
                    <li><a href="#setup-finance" style="text-decoration:none;">Setup: Finance & Visuals</a></li>
                    <li><a href="#settings" style="text-decoration:none;">Fine-Tuning Settings</a></li>
                    <li><a href="#profit-engine-settings" style="text-decoration:none;">Profit Engine Settings</a></li>
                    <li><a href="#badge-manager" style="text-decoration:none;">Badge Manager</a></li>
                    <li><a href="#dashboard" style="text-decoration:none;">The Dashboard</a></li>
                    <li><a href="#compliance" style="text-decoration:none;">Compliance Hub</a></li>
                    <li><a href="#store-audit" style="text-decoration:none;">Store Audit</a></li>
                    <li><a href="#pricing-engine" style="text-decoration:none;">Pricing Engine</a></li>
                    <li><a href="#automation" style="text-decoration:none;">Automation</a></li>
                    <li><a href="#dev-ref" style="text-decoration:none;">Database Key Reference</a></li>
                    <li><a href="#troubleshooting" style="text-decoration:none;">Troubleshooting</a></li>
                </ul>
            </div>

            <div class="cirrusly-manual-content" style="flex: 1; padding: 40px;">
                
                <div id="intro" class="cirrusly-manual-section" style="margin-bottom: 50px;">
                    <h2 style="margin-top:0;">What is Cirrusly Commerce?</h2>
                    <p>Cirrusly Commerce is an all-in-one suite designed to optimize your WooCommerce store’s financial health and Google Merchant Center compliance. Our reasons for building this were many, but primarily:</p>
                    <ul>
                        <li>We were struggling with Google Merchant Center policies (disapprovals, suspensions) and vague Google feedback.</li>
                        <li>Margins were too low because we weren't accounting for fees and shipping correctly.</li>
                        <li>Existing plugins were expensive and bloated (requiring 5-6 plugins for what should be one).</li>
                    </ul>
                    <p><strong>This plugin optimizes WooCommerce for Google Merchant Center by:</strong></p>
                    <ul style="list-style:disc; margin-left:20px;">
                        <li>Adding <strong>MSRP</strong> and <strong>MAP</strong> fields.</li>
                        <li>Calculates true margins (factoring in shipping costs and fees).</li>
                        <li>Enabling features like Google Customer Reviews, Pricing Suggestions, and Automated Discounts.</li>
                        <li>Scanning for "trigger words" that cause suspensions.</li>
                        <li>Including countdowns and badging logic.</li>
                    </ul>
                    <p>The average user would save $50-$100 per year on plugin costs and eliminate at least two plugins, if not more.</p>
                </div>

                <div id="support" class="cirrusly-manual-section" style="margin-bottom: 50px;">
                    <h3>Third-Party Plugin Support</h3>
                    <p>It has been tested alongside and is known to be compatible (or hardcoded to support) the following WooCommerce plugins:</p>
                    <ul>
                        <li>Product Feed PRO (AdTribes)</li>
                        <li>Google Product Feed (Ademti)</li>
                        <li>Rank Math SEO, Yoast SEO, All in One SEO (AIOSEO), & SEOPress (Schema Injection included)</li>
                        <li>WooCommerce Cost of Goods (SkyVerge) & WPFactory</li>
                    </ul>
                    <p><em>Note: As long as other plugins aren’t moving data to or reading from a different database (like ATUM), there shouldn’t be issues.</em></p>
                </div>

                <div id="installation" class="cirrusly-manual-section" style="margin-bottom: 50px;">
                    <h3>Installation</h3>
                    <div class="cirrusly-alert">
                        <strong>IMPORTANT:</strong> Before installing, you MUST enable the native WooCommerce Cost of Goods Sold (COGS) feature.
                    </div>
                    <ol>
                        <li>Navigate to <strong>WooCommerce > Settings > Advanced</strong>.</li>
                        <li>Check the box: "Allows entering cost of goods sold information for products".</li>
                        <li>Save changes.</li>
                    </ol>
                    <p>Cirrusly Commerce uses this native field for calculations. Running the plugin without this enabled will cause stability issues.</p>
                    
                    <h4>1. License</h4>
                    <p>When you first install, the Setup Wizard will guide you. You do not need a subscription to use the plugin; only Premium features require a subscription. Pro offers a 3-day trial and Pro Plus offers a 7-day trial (no credit card required to start).</p>
                </div>

                <div id="connect" class="cirrusly-manual-section" style="margin-bottom: 50px;">
                    <h3>2. Connect</h3>
                    <p>You will need your <strong>Merchant ID</strong> from the Google Merchant Center dashboard (upper-right corner). Copy this correctly or reviews will not be submitted.</p>
                    
                    <div style="background: #f0f6fc; padding: 15px; border-left: 4px solid #72aee6; margin-top: 20px;">
                        <h4 style="margin-top:0;">Pro / Pro Plus: Real-Time API Sync</h4>
                        <p>To enable Real-Time API Sync, you must generate a Google Service Account Key (JSON).</p>
                        <ol>
                            <li><strong>Create a Project in Google Cloud:</strong> Go to Google Cloud Console > New Project.</li>
                            <li><strong>Enable Content API:</strong> Search for "Content API for Shopping" and click Enable.</li>
                            <li><strong>Create Service Account:</strong> Go to IAM & Admin > Service Accounts > Create Service Account. Role: <em>Content API Editor</em>.</li>
                            <li><strong>Generate Key:</strong> Click the Email address > Keys > Add Key > Create new key (JSON).</li>
                        </ol>
                        <p>Upload this JSON file in the Setup Wizard or Settings to enable real-time updates.</p>
                    </div>
                </div>

                <div id="setup-finance" class="cirrusly-manual-section" style="margin-bottom: 50px;">
                    <h3>3. Finance & 4. Visuals (Setup Wizard)</h3>
                    <p><strong>Finance:</strong> Enter your typical payment fees (e.g., Stripe is 2.9% + 30 cents) and a default shipping cost. If these vary, use a weighted average.</p>
                    <p><strong>Visuals:</strong></p>
                    <ul>
                        <li><strong>MSRP Strikethrough:</strong> Display MSRP on product pages.</li>
                        <li><strong>Sale Badging:</strong> Show percentage discount.</li>
                        <li><strong>New Badge:</strong> Highlight products added within the last 30 days.</li>
                    </ul>
                </div>

                <div id="settings" class="cirrusly-manual-section" style="margin-bottom: 50px;">
                    <h3>Fine-Tuning Your Settings</h3>
                    <p>Navigate to <strong>Cirrusly Commerce > Settings</strong>. You will see tabs for General Settings, Profit Engine, and Badge Manager.</p>
                    
                    <h4>General Settings</h4>
                    <ul>
                        <li><strong>Integrations:</strong> Enable/Disable Google Customer Reviews, adjust GMC ID.</li>
                        <li><strong>Frontend Display:</strong> Toggle MSRP visibility and positioning.</li>
                        <li><strong>Automation:</strong> Opt-in to daily compliance reports.</li>
                        <li><strong>Content API (Pro):</strong> Manage your Service Account JSON here.</li>
                    </ul>
                </div>

                <div id="profit-engine-settings" class="cirrusly-manual-section" style="margin-bottom: 50px;">
                    <h3><span class="dashicons dashicons-money-alt" style="color:#2271b1;"></span> Profit Engine</h3>
                    <p>Fine-tune shipping costs and fees for accurate margin reporting.</p>
                    <ul>
                        <li><strong>Shipping Revenue Tiers:</strong> Add tiers based on order price (e.g., Orders $0-$10 cost customer $3.99).</li>
                        <li><strong>Shipping Class Costs (Base Cost):</strong> Enter your internal cost for shipping specific classes (compatible with Octolize Flexible Shipping).</li>
                        <li><strong>Payment Fees:</strong> Adjust processing fees.</li>
                        <li><strong>Matrix Multipliers:</strong> Multipliers for expedited shipping vs standard.</li>
                    </ul>
                </div>

                <div id="badge-manager" class="cirrusly-manual-section" style="margin-bottom: 50px;">
                    <h3><span class="dashicons dashicons-awards" style="color:#2271b1;"></span> Badge Manager</h3>
                    <p>Control visual cues on your storefront.</p>
                    <ul>
                        <li><strong>Global Settings:</strong> Set badge size (Small/Medium/Large) and Discount Base (MSRP vs Regular Price).</li>
                        <li><strong>Custom Tag Badges:</strong> Map specific Product Tags (e.g., "vegan") to custom images/icons.</li>
                        <li><strong>Smart Badges (Pro):</strong>
                            <ul>
                                <li><em>Low Stock:</em> Warning when inventory < 5.</li>
                                <li><em>Best Seller:</em> Highlights top performers.</li>
                                <li><em>Scheduler:</em> Schedule event badges (e.g., "Black Friday").</li>
                            </ul>
                        </li>
                    </ul>
                </div>

                <div id="dashboard" class="cirrusly-manual-section" style="margin-bottom: 50px;">
                    <h3>The Dashboard</h3>
                    <p>Your command center (Cirrusly Commerce > Dashboard). It includes:</p>
                    <ul>
                        <li><strong>Store Pulse:</strong> Quick view of revenue and orders (Last 7 Days).</li>
                        <li><strong>Catalog Snapshot:</strong> Product count, items on sale, low stock alerts.</li>
                        <li><strong>Profit Engine:</strong> Average margin, unprofitable products list, missing cost data.</li>
                        <li><strong>GMC Health:</strong> Critical issues, warnings, and content policy checks.</li>
                        <li><strong>Sync Status (Pro):</strong> Real-Time API connection status.</li>
                    </ul>
                </div>

                <div id="compliance" class="cirrusly-manual-section" style="margin-bottom: 50px;">
                    <h3><span class="dashicons dashicons-shield" style="color:#2271b1;"></span> Compliance Hub</h3>
                    <p>Simplifies compliance by identifying data quality and content issues.</p>
                    
                    <h4>Health Check</h4>
                    <p>Scans for missing GTINs, images, or restricted terms. You can "Mark as Custom" for handmade items to fix "Missing GTIN" errors.</p>
                    <p><strong>Pro Feature:</strong> <em>Block Save on Critical Error</em> prevents saving products with prohibited terms.</p>

                    <h4>Promotion Manager</h4>
                    <p>Manage sales and special offers. <strong>Pro users</strong> can sync directly with GMC to view active promotions and one-click submit new ones. Free users can generate code snippets for manual feeds.</p>

                    <h4>Site Content</h4>
                    <p>Checks for mandatory legal pages (Refund, Terms, Privacy) and scans pages for prohibited terms (e.g., "cure", "guaranteed").</p>
                </div>

                <div id="store-audit" class="cirrusly-manual-section" style="margin-bottom: 50px;">
                    <h3>Store Audit: Understanding Margins</h3>
                    <p>Navigate to <strong>Cirrusly Commerce > Store Audit</strong>. This tool lists all products to identify financial leaks.</p>
                    <table class="widefat striped">
                        <thead><tr><th>Metric</th><th>Description</th></tr></thead>
                        <tbody>
                            <tr><td><strong>Total Cost</strong></td><td>COGS + Shipping Costs.</td></tr>
                            <tr><td><strong>Ship P/L</strong></td><td>Are you losing money on shipping?</td></tr>
                            <tr><td><strong>Net Profit</strong></td><td>Price - Cost - Ship P/L.</td></tr>
                            <tr><td><strong>Margin</strong></td><td>Keep above 15% (30% is optimal).</td></tr>
                        </tbody>
                    </table>
                    <p><strong>Alerts:</strong> Red text indicates net loss. Orange indicates low margin (< 15%).</p>
                </div>

                <div id="pricing-engine" class="cirrusly-manual-section" style="margin-bottom: 50px;">
                    <h3>Using the Pricing Engine</h3>
                    <p>On the product edit screen (General tab), you will find custom fields:</p>
                    <ul>
                        <li><strong>Google Min Price ($):</strong> Lowest acceptable price (required for Automated Discounts).</li>
                        <li><strong>MAP ($):</strong> Minimum Advertised Price.</li>
                        <li><strong>MSRP ($):</strong> Manufacturer’s Suggested Retail Price.</li>
                        <li><strong>Base Ship ($):</strong> Auto-filled based on Shipping Class.</li>
                        <li><strong>Sale Timer End:</strong> Date/Time for the countdown block.</li>
                    </ul>
                    
                    <h4>Strategies</h4>
                    <p><strong>Regular Price Strategy:</strong> Set price based on MSRP match/discount OR Cost + Target Margin (15%, 20%, 30%).</p>
                    <p><strong>Sale Discount:</strong> Calculate off MSRP or Regular Price.</p>
                    <p><strong>Live Profit Display:</strong> As you type prices, Profit and Margin values update instantly.</p>
                </div>

                <div id="automation" class="cirrusly-manual-section" style="margin-bottom: 50px; background: #f6f7f7; padding: 20px; border-left: 4px solid #72aee6;">
                    <h3 style="margin-top:0;">Automation (Pro Features)</h3>
                    <ul>
                        <li><strong>Automated Health Scans:</strong> Daily scan for compliance issues with email reports.</li>
                        <li><strong>Workflow Rules:</strong> "Block Save on Critical Error" and "Auto-strip Banned Words".</li>
                        <li><strong>Weekly Profit Reports:</strong> Email summaries of financial health.</li>
                        <li><strong>Instant Disapproval Alerts:</strong> Immediate notification via API if Google disapproves a product.</li>
                        <li><strong>Automated Discounts (Pro Plus):</strong> Dynamic pricing integration with Google Shopping ads.</li>
                    </ul>
                </div>

                <div id="dev-ref" class="cirrusly-manual-section" style="margin-bottom: 50px;">
                    <h3><span class="dashicons dashicons-database" style="color:#2271b1;"></span> Database Key Reference</h3>
                    <p>Use these keys to map data in feed plugins (like Product Feed Pro).</p>
                    <table class="widefat striped">
                        <thead>
                            <tr><th>Field Name</th><th>Meta Key</th></tr>
                        </thead>
                        <tbody>
                            <tr><td>Cost of Goods</td><td><code>_cogs_total_value</code></td></tr>
                            <tr><td>GMC Floor Price</td><td><code>_auto_pricing_min_price</code></td></tr>
                            <tr><td>MSRP Price</td><td><code>_alg_msrp</code></td></tr>
                            <tr><td>MAP Price</td><td><code>_cirrusly_map_price</code></td></tr>
                            <tr><td>Promotion ID</td><td><code>_gmc_promotion_id</code></td></tr>
                            <tr><td>Custom Label 0</td><td><code>_gmc_custom_label_0</code></td></tr>
                            <tr><td>Identifier Exists</td><td><code>_gla_identifier_exists</code></td></tr>
                            <tr><td>Base Shipping</td><td><code>_cirrusly_est_shipping</code></td></tr>
                            <tr><td>Sale Timer End</td><td><code>_cirrusly_sale_end</code></td></tr>
                        </tbody>
                    </table>
                </div>

                <div id="troubleshooting" class="cirrusly-manual-section">
                    <h3>Troubleshooting</h3>
                    <ul>
                        <li><strong>Why can't I save my product?</strong> You likely have "Block Save on Critical Error" enabled and are using a restricted term (e.g., "cure"). Remove the term or disable the setting.</li>
                        <li><strong>API Sync Status "Inactive":</strong> You must upload the Google Service Account JSON in Settings (Pro only).</li>
                        <li><strong>Missing Profit Margins:</strong> Ensure "Cost of Goods" is entered and the native WooCommerce COGS feature is enabled.</li>
                        <li><strong>MSRP not in Feed:</strong> You must map <code>_alg_msrp</code> to <code>g:price</code> or <code>g:sale_price</code> in your feed plugin.</li>
                        <li><strong>MSRP not on page:</strong> Check "MSRP Price: Show Strikethrough" in Settings. For Block Themes, use the "MSRP Display" block.</li>
                    </ul>
                </div>

            </div>
        </div>
    </div>
</div>