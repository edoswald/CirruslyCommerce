=== Cirrusly Commerce ===

Contributors: edoswald
Tags: Google Merchant Center, WooCommerce, pricing, MSRP, profit margin
Requires at least: 5.8 
Tested up to: 6.9 
Stable tag: 1.4 
Requires PHP: 8.1 
License: GPLv2 or later 
License URI: http://www.gnu.org/licenses/gpl-2.0.html

The Financial Operating System for WooCommerce.

**Stop guessing if your Google Ads are profitable. Stop worrying about Merchant Center suspensions.**

Cirrusly Commerce is the financial operating system for WooCommerce stores. It is the only plugin that combines **Google Merchant Center compliance**, **Net Profit Auditing**, and **Dynamic Pricing** into a single, powerful suite.

Originally a set of code snippets used on our site Cirrusly Weather, Cirrusly Commerce works alongside your existing feed plugin to fix data errors, visualize true profit margins (after COGS and fees), and increase conversion rates with psychological pricing.

### 🚀 1. Google Merchant Center Compliance & Feed Repair
Your product feed is your business's lifeline. Don't let a suspension kill your revenue.

* **Diagnostics Scan:** Scan your entire catalog for critical policy violations like missing GTINs, prohibited words (e.g., "cure," "weight loss"), and title length issues.
* **Suspension Prevention:** actively blocks you from saving products with critical errors (such as banned words in titles), preventing bad data from ever reaching your feed.

### 📈 2. WooCommerce Profit Calculator & Margin Tracking
Revenue is vanity; profit is sanity. Most stores don't know their true margin after ad spend and fees.

* **Real-Time Net Profit:** See your exact Net Profit ($) and Margin (%) directly on the product edit screen.
* **Automated Cost Calculations:** We automatically deduct:
    * **Cost of Goods Sold (COGS)**
    * **Shipping Estimates**
    * **Payment Gateway Fees** (Stripe, PayPal, Square)

### 💰 3. Dynamic Pricing & Google Shopping Automation
Maximize your ROAS (Return on Ad Spend) with advanced pricing strategies.

* **MSRP Display:** Boost conversion by displaying "List Price" vs. "Our Price" comparisons on product pages.
* **Pricing Engine** Stop guessing how a sale affects your margins. See real-time margin data on the product edit screen as you enter prices.

### 📊 4. Financial Audit Dashboard
* **Loss Maker Report:** Instantly identify products that are losing money or have dangerously low margins.
* **Bulk COGS Management:** Quickly find and fix products missing cost data without opening every single product page.

### 🎨 5. Conversion Tools & Gutenberg Blocks
* **Smart Badges:** Automatically display badges for "Low Stock," "New Arrival," or "Best Seller" based on real inventory and sales data.
* **MSRP Block:** Customizable "Original Price" block for the Site Editor.

### Compatibility
Cirrusly Commerce is optimized to work with the best WooCommerce plugins:
* **Feed Plugins:** Product Feed PRO (AdTribes), Google Product Feed (Ademti), CTX Feed.
* **SEO Plugins:** Rank Math, Yoast SEO, All in One SEO (AIOSEO), SEOPress (Schema support included).
* **COGS Plugins:** WooCommerce Cost of Goods (SkyVerge), WPFactory.

### Like the Plugin?
Upgrade to Pro or Pro Plus for added functionality.
* **Real-Time API Sync (Pro):** Bypass the 24-hour feed fetch delay. Updates to price, stock status, or titles are pushed to Google's Content API immediately.
* **Intelligent Issue Deduplication (Pro Plus):** Uses Google NLP logic to group related errors, so you can fix bulk issues faster.
* **Multi-Profile Financials (Pro):** Calculate blended fee rates for stores using multiple payment processors (e.g., 60% Stripe + 40% PayPal) for 100% accuracy.
* **Inline Editing (Pro):** Update costs and prices directly from the audit table via AJAX.
* **CSV Import/Export (Pro):** Bulk manage your financial data via CSV for external analysis.
* **Countdown Timer (Pro):** Add urgency to your product pages.
* **Discount Notices (Pro):** Show dynamic "You saved $X!" messages in the cart.
* **Automated Discounts (Pro Plus):** Full integration with Google's "Automated Discounts" program. We validate Google's secure pricing tokens (JWT) to dynamically update the cart price to match the discounted ad price.
* **Psychological Repricing (Pro Plus):** Automatically round calculated prices to .99, .50, or the nearest 5 to maximize click-through rate (CTR) and conversion.

== External Services ==

This plugin sends data to the Google Platform API to enable Google Customer Reviews surveys for your customers.

* **Service:** Google Platform API (Google Customer Reviews)
* **Data Transmitted:** Google Merchant Center ID (Merchant ID) and the customer's Order ID.
* **Trigger Point:** Data is transmitted on the WooCommerce "Order Received" (Thank You) page via the `woocommerce_thankyou` hook.
* **Privacy Policy:** [Google Privacy Policy](https://policies.google.com/privacy)
* **Terms of Service:** [Google Terms of Service](https://policies.google.com/terms)

== Installation ==

1.  Upload the plugin files to the `/wp-content/plugins/cirrusly-commerce` directory, or install directly from the WordPress plugins screen.
2.  Activate the plugin.
3.  **Run the Setup Wizard:** Follow the milestone-based onboarding to connect your Google Merchant Center ID and configure your payment fees.
4.  **Check your Dashboard:** Go to **Cirrusly Commerce > Dashboard** to see your store's health score.

**Note:** Please ensure "Cost of Goods Sold" is enabled in **WooCommerce > Settings > Advanced**.

== Frequently Asked Questions ==

= Does this replace my Google Feed plugin? =
No. Cirrusly Commerce is a **Compliance and Optimization** layer. We work *alongside* plugins like Product Feed PRO. They generate the XML file; we ensure the data inside it doesn't get you banned and is priced profitably.

= How does the Real-Time API Sync improve SEO? =
By keeping your price and stock status in perfect sync with Google, you improve your "Quality Score" in Merchant Center. This can lead to lower CPCs and better ad placement because Google trusts your data accuracy.

= Can I track profit for Stripe and PayPal separately? =
Yes. The Pro version allows you to set a "Split Profile" (e.g., 70% Stripe / 30% PayPal), calculating a weighted average fee for your entire store to give you a realistic Net Profit margin.

= What is the difference between Free, Pro, and Pro Plus? =
* **Free:** Health scans, Profit calculation, MSRP display, and Manual auditing.
* **Pro:** Real-time API sync, Inline editing, Smart Badges, and CSV export.
* **Pro Plus:** Automated Discounts (Google Sync), Psychological Pricing, and the full Analytics Dashboard.

== Screenshots ==

1. **Compliance Hub:** Instant scan results showing critical Google Merchant Center policy violations.
2. **Profit Engine:** Real-time Net Profit and Margin calculation on the product page.
3. **Financial Audit:** A spreadsheet-style view of your catalog's financial health.
4. **Analytics Dashboard:** Visual charts for P&L, Revenue, and Inventory Velocity.
5. **Setup Wizard:** Easy 5-step onboarding to configure fees and API connections.

== Changelog ==

= 1.4 =
* **Enhancement:** Frontend asset registration refactored for better architecture and compliance.
* **Security:** Tightened nonce verification and file-upload sanitization to align with WordPress security best practices.
* **Refactor:** Migrated option/transient naming for consistency and future extensibility.
* **Enhancement:** Centralized admin inline JavaScript for improved maintainability.
* **Enhancement (Pro):** Refactored analytics data preparation and chart rendering.
* **Enhancement (Pro):** Moved to service worker for API calls.

= 1.3.3 =
* **New Feature:** Admin Setup Wizard - Automated onboarding runs on activation with milestone-based prompts.
* **New Feature:** Analytics Dashboard (Pro Plus) - Real-time P&L summaries, inventory velocity tracking, and daily GMC performance snapshots.
* **Enhancement:** Intelligent Issue Deduplication (Pro Plus) - Signature-based deduplication with Google NLP integration merges related audit issues.
* **Enhancement:** Addition of helpful explainer text and tooltips throughout the UI.
* **Fix:** Corrected audit regex that was mistakenly flagging acceptable terms (who vs WHO, cure being found in secure, etc.)
* **Fix:** Fix for orders not appearing in analytics and other functions due to strict adherence to WordPress default statuses. Plugin now correctly handles custom order statuses.
* **Fix:** Small security and best practices improvements to align with WP Plugin Directory guidelines.

= 1.3 =
* **Refactor:** Split plugin into three tiers: Free, Pro, and Pro Plus.
* **New Feature:** Gutenberg Blocks - MSRP display, Sale Countdown, Smart Badges, Automated Discount Notice.
* **UI Update:** "GMC Hub" is now "Compliance Hub".
* **Enhancement:** MSRP injection location is now customizable on the product page via Hooks or Blocks.
* **Requirement:** Plugin now requires PHP 8.1.

= 1.2.1 =
* **New Feature:** Introduced Freemius architecture for license management.
* **Enhancement:** Added "System Info" tool for troubleshooting.
* **Enhancement:** Pricing Engine now supports 5%, 15%, and 25% "Off MSRP" strategies.
* **Enhancement:** Pricing Engine now supports "Nearest 5/0" rounding.
* **UI Update:** Reorganized settings into tabbed cards.

= 1.1 =
* **New Feature:** Payment Processor Fees configuration.
* **New Feature:** "Profit at a Glance" column in All Products list.
* **New Feature:** "New Arrival" Badge module.
* **Enhancement:** Expanded GMC Health Check for suspicious image names.

= 1.0 =
* Initial release.