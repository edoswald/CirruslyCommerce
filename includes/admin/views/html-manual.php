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
                    <li><a href="#gmc" style="text-decoration:none;">GMC Hub & Health</a></li>
                    <li><a href="#pricing" style="text-decoration:none;">Pricing & Profits</a></li>
                    <li><a href="#badges" style="text-decoration:none;">Smart Badges</a></li>
                    <li><a href="#countdown" style="text-decoration:none;">Countdown Timers</a></li>
                    <li><a href="#shortcodes" style="text-decoration:none;">Shortcodes & Blocks</a></li>
                    <li><a href="#pro" style="text-decoration:none;"><strong>Pro Features</strong></a></li>
                    <li><a href="#dev-ref" style="text-decoration:none;">Developer Reference</a></li>
                </ul>
            </div>

            <div class="cirrusly-manual-content" style="flex: 1; padding: 40px;">
                
                <div id="intro" class="cirrusly-manual-section" style="margin-bottom: 50px;">
                    <h2 style="margin-top:0;">Welcome to Cirrusly Commerce</h2>
                    <p>Cirrusly Commerce is an all-in-one suite designed to optimize your WooCommerce store's financial health, presentation, and Google Merchant Center compliance.</p>
                    <p>This manual covers setup, configuration, and advanced usage for developers.</p>
                </div>

                <hr>

                <div id="gmc" class="cirrusly-manual-section" style="margin-bottom: 50px;">
                    <h3><span class="dashicons dashicons-cloud" style="color:#2271b1;"></span> Google Merchant Center (GMC) Hub</h3>
                    <p>The GMC Hub acts as your command center for product feed health.</p>
                    <ul>
                        <li><strong>Health Scan:</strong> Automatically detects products missing GTINs (UPC/EAN) or containing prohibited words (like "Free Shipping" in titles).</li>
                        <li><strong>Custom Products:</strong> Products marked as "Custom" (e.g., handmade items) are flagged to tell Google they do not require a GTIN.</li>
                        <li><strong>Promotion ID:</strong> Map internal promotions to Google Merchant Center Promotion IDs for ad campaigns.</li>
                    </ul>
                </div>

                <div id="pricing" class="cirrusly-manual-section" style="margin-bottom: 50px;">
                    <h3><span class="dashicons dashicons-money-alt" style="color:#2271b1;"></span> Pricing & Profit Engine</h3>
                    <p>Stop guessing your profits. The Pricing engine allows you to input cost data to calculate real-time margins.</p>
                    
                    <h4>Key Metrics:</h4>
                    <table class="widefat striped" style="margin-bottom: 20px;">
                        <thead><tr><th>Metric</th><th>Description</th></tr></thead>
                        <tbody>
                            <tr><td><strong>Cost of Goods (COGS)</strong></td><td>The raw cost to acquire or manufacture the item.</td></tr>
                            <tr><td><strong>Est. Shipping</strong></td><td>Your internal cost to ship this item (not what the customer pays).</td></tr>
                            <tr><td><strong>MSRP</strong></td><td>Manufacturer's Suggested Retail Price. Used for "Strike-through" pricing display.</td></tr>
                            <tr><td><strong>MAP</strong></td><td>Minimum Advertised Price. Internal reference for compliance.</td></tr>
                        </tbody>
                    </table>
                    <p><em>Configure your revenue tiers and payment processor fees in <strong>Settings > Profit Engine</strong> to get accurate Net Profit calculations.</em></p>
                </div>

                <div id="badges" class="cirrusly-manual-section" style="margin-bottom: 50px;">
                    <h3><span class="dashicons dashicons-awards" style="color:#2271b1;"></span> Smart Badges</h3>
                    <p>Automate your store's visual merchandising. Badges are automatically injected into product loops and single product pages.</p>
                    <ul>
                        <li><strong>Sale Badge:</strong> Shows "Save X%" or "Save up to X%" for variable products. Calculated based on Regular Price or MSRP (configurable).</li>
                        <li><strong>New Arrival:</strong> Automatically highlights products created within the last 30 days.</li>
                        <li><strong>Custom Badges:</strong> Upload custom icons (e.g., "Vegan", "Made in USA") and link them to specific Product Tags.</li>
                    </ul>
                </div>

                <div id="countdown" class="cirrusly-manual-section" style="margin-bottom: 50px;">
                    <h3><span class="dashicons dashicons-clock" style="color:#2271b1;"></span> Countdown Timers</h3>
                    <p>Create urgency with sale countdowns. Timers can be set:</p>
                    <ol>
                        <li><strong>Manually:</strong> On the "General" tab of product data.</li>
                        <li><strong>Globally (Pro):</strong> Via "Smart Rules" in Settings (e.g., "Apply to all T-Shirts").</li>
                    </ol>
                </div>

                <div id="shortcodes" class="cirrusly-manual-section" style="margin-bottom: 50px;">
                    <h3><span class="dashicons dashicons-editor-code" style="color:#2271b1;"></span> Shortcodes & Blocks</h3>
                    <p>Use these tools to display Cirrusly features anywhere on your site.</p>

                    <h4>Gutenberg Blocks</h4>
                    <ul>
                        <li><strong>Cirrusly MSRP:</strong> Displays the MSRP with strikethrough styling.</li>
                        <li><strong>Cirrusly Countdown:</strong> Renders the countdown timer for the current product.</li>
                        <li><strong>Cirrusly Badges:</strong> Displays active badges for the product.</li>
                        <li><strong>Discount Notice:</strong> (Pro) Shows a "Price Unlocked" message if an automated discount is active.</li>
                    </ul>

                    <h4>Shortcode</h4>
                    <p><code>[cirrusly_countdown end="2025-12-31" label="Sale Ends:"]</code></p>
                    <p>Attributes:</p>
                    <ul style="list-style:disc; margin-left:20px;">
                        <li><code>end</code>: Date string (YYYY-MM-DD).</li>
                        <li><code>label</code>: Text to display before timer.</li>
                        <li><code>align</code>: left, center, or right.</li>
                    </ul>
                </div>

                <div id="pro" class="cirrusly-manual-section" style="margin-bottom: 50px; background: #f6f7f7; padding: 20px; border-left: 4px solid #72aee6;">
                    <h3 style="margin-top:0;">Pro Features</h3>
                    <p>Upgrade to unlock advanced automation:</p>
                    <ul style="margin-bottom: 0;">
                        <li><strong>Automated Discounts:</strong> Google Shopping "Automated Discounts" integration (dynamic pricing).</li>
                        <li><strong>Smart Countdown Rules:</strong> Apply timers globally by Category or Tag.</li>
                        <li><strong>Smart Badges:</strong> "Low Stock", "Best Seller", and "Scheduled Event" badges.</li>
                        <li><strong>GMC API Sync:</strong> Real-time status fetching and "Instant Disapproval" alerts.</li>
                        <li><strong>Weekly Profit Reports:</strong> Email summaries of store performance.</li>
                    </ul>
                </div>

                <div id="dev-ref" class="cirrusly-manual-section">
                    <h3><span class="dashicons dashicons-database" style="color:#2271b1;"></span> Developer Reference: Meta Keys</h3>
                    <p>Use these keys when mapping data in 3rd-party feed plugins (like Product Feed PRO or Google Product Feed).</p>
                    
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th>Field Name</th>
                                <th>Meta Key</th>
                                <th>Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>MSRP</strong></td>
                                <td><code>_alg_msrp</code></td>
                                <td>Manufacturer's Suggested Retail Price.</td>
                            </tr>
                            <tr>
                                <td><strong>Cost of Goods</strong></td>
                                <td><code>_cogs_total_value</code></td>
                                <td>Total cost of the item (float).</td>
                            </tr>
                            <tr>
                                <td><strong>Est. Shipping Cost</strong></td>
                                <td><code>_cirrusly_est_shipping</code></td>
                                <td>Internal shipping cost estimate.</td>
                            </tr>
                            <tr>
                                <td><strong>MAP Price</strong></td>
                                <td><code>_cirrusly_map_price</code></td>
                                <td>Minimum Advertised Price.</td>
                            </tr>
                            <tr>
                                <td><strong>Auto-Pricing Min</strong></td>
                                <td><code>_auto_pricing_min_price</code></td>
                                <td>Floor price for Google Automated Discounts (Pro).</td>
                            </tr>
                            <tr>
                                <td><strong>GMC Promotion ID</strong></td>
                                <td><code>_gmc_promotion_id</code></td>
                                <td>The ID of the promotion in Google Merchant Center.</td>
                            </tr>
                            <tr>
                                <td><strong>Custom Label 0</strong></td>
                                <td><code>_gmc_custom_label_0</code></td>
                                <td>Used for grouping products in Google Ads campaigns.</td>
                            </tr>
                            <tr>
                                <td><strong>Sale End Date</strong></td>
                                <td><code>_cirrusly_sale_end</code></td>
                                <td>Date string (YYYY-MM-DD) for manual countdowns.</td>
                            </tr>
                            <tr>
                                <td><strong>GMC Identifier Exists</strong></td>
                                <td><code>_gla_identifier_exists</code></td>
                                <td>'yes' or 'no'. Controls "identifier_exists" in feeds.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>
    </div>
</div>