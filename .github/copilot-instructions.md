# Cirrusly Commerce: AI Coding Instructions

## Architecture Overview

**Cirrusly Commerce** is a WordPress/WooCommerce plugin that combines Google Merchant Center compliance, financial auditing, and dynamic pricing. The codebase uses a **modular class-based architecture** with conditional loading based on context and licensing.

### Core Structure

- **Entry Point:** [`cirrusly-commerce.php`](../cirrusly-commerce.php) - Initializes Freemius SDK and loads Core class
- **Core Bootstrap:** [`includes/class-core.php`](../includes/class-core.php) - Lazy-loads subsystems, registers hooks, manages Pro checks
- **Main Subsystems:**
  - **GMC (Google Merchant Center):** [`includes/class-gmc.php`](../includes/class-gmc.php) - Compliance scanning, product meta handling
  - **Audit (Financial):** [`includes/class-audit.php`](../includes/class-audit.php) - Per-product P&L calculations, transient caching
  - **Pricing:** [`includes/class-pricing.php`](../includes/class-pricing.php) - MSRP display, margin calculations
  - **Badges:** [`includes/class-badges.php`](../includes/class-badges.php) - Smart badge rendering (frontend JS DOM manipulation)
  - **Blocks:** [`includes/class-blocks.php`](../includes/class-blocks.php) - Gutenberg MSRP, Countdown, Discount Notice blocks
  - **Automated Discounts:** [`includes/pro/class-automated-discounts.php`](../includes/pro/class-automated-discounts.php) - Google JWT token verification & price overrides

### Loading Patterns

1. **Context-aware loading** - Admin vs frontend vs AJAX (see `is_admin()`, `DOING_AJAX` checks in Core)
2. **Pro feature gates** - Use `Cirrusly_Commerce_Core::cirrusly_is_pro()` for licensing checks
3. **Conditional file inclusion** - Check file existence before `require_once` to support free/pro variants
4. **Static initialization pattern:**
   - GMC and Audit use static `init()` methods called by Core (prevents duplicate hook registration)
   - Pricing and Blocks instantiate on construct (no separate init)

## Critical Patterns & Conventions

### 1. **Transient Caching for Expensive Operations**
The plugin heavily caches computed data:
- **`cirrusly_audit_data`** (1 hour) - Compiled per-product financials with costs, fees, margins
- **`cirrusly_dashboard_metrics`** - Dashboard KPIs
- **`cirrusly_active_promos_stats`** - Promotion statistics
- **`cirrusly_analytics_cache_version`** - Version key for analytics (update to invalidate all related transients)

**Cache clearing:** Always delete related transients after product/option updates (see [`class-core.php:clear_metrics_cache()`](../includes/class-core.php)).

### 2. **Post Meta for Financial Data**
Products store costs and pricing as post meta:
- `_cogs_total_value` - Cost of goods
- `_cirrusly_est_shipping` - Estimated shipping cost
- `_cirrusly_map_price` - Minimum advertised price
- `_auto_pricing_min_price` - GMC floor price
- `_alg_msrp` - MSRP (Manufacturer's Suggested Retail Price)
- `_gla_identifier_exists` - Google product identifier flag
- `_gmc_promotion_id` - Active promotion ID

Use `get_post_meta()` / `update_post_meta()` directly; avoid WooCommerce Product CRUD when possible for bulk operations.

### 3. **Nonce & Security Patterns**
Always use custom nonces (not WooCommerce defaults):
- AJAX saves: Create nonce with `wp_create_nonce('cirrusly_audit_save')`, validate with `check_ajax_referer()`
- Product meta saves: Use `cirrusly_gmc_nonce` for custom nonce (see [`class-gmc.php:save_product_meta()`](../includes/class-gmc.php))
- Verify nonce **before** capability check in AJAX handlers

Example (from Core):
```php
if ( ! current_user_can( 'edit_products' ) || ! check_ajax_referer( 'cirrusly_audit_save', '_nonce', false ) ) {
    wp_send_json_error( __( 'Permission denied', 'cirrusly-commerce' ) );
}
```

### 4. **Financial Calculations (Audit)**
The audit module computes **per-product net profit** using:
- **Item Cost** + **Shipping Cost** = Total Cost
- **Price** × **Revenue Tier Multiplier** = Revenue charge
- **Price** × **Payment Fee %** + **Payment Fee Flat** = Payment fees (supports split profiles)
- **Margin %** = (Price - Total Cost - Fees) / Price

Revenue tiers and shipping classes are JSON-encoded in `cirrusly_scan_config` option. See [`class-audit.php:get_compiled_data()`](../includes/class-audit.php) for full calculation logic.

### 5. **Pro Feature Organization**
Pro-only code lives in `/pro/` subdirectories:
- API client credentials encrypted with AES-256-CBC (see [`class-security.php`](../includes/class-security.php))
- Google API calls routed through [`class-google-api-client.php`](../includes/pro/class-google-api-client.php) - single gateway
- Each subsystem checks Pro status before loading Pro files

**Always check Pro status before using Pro classes:**
```php
if ( Cirrusly_Commerce_Core::cirrusly_is_pro() && file_exists( __DIR__ . '/pro/class-*.php' ) ) {
    require_once __DIR__ . '/pro/class-*.php';
}
```

### 6. **Frontend Badge Rendering**
Badges use **hidden payload pattern** to avoid theme conflicts:
1. Server renders badge HTML in hidden `<div class="cirrusly-badge-payload">`
2. Frontend JS moves payload into product image wrapper on page load
3. CSS hides WooCommerce native sale badges
4. Re-runs badge relocation on AJAX grid updates

This decouples badge display from theme template changes.

### 7. **Options & Settings**
Configuration is stored in WordPress options (not post meta). Key prefixes:
- `cirrusly_scan_config` - GMC merchant ID, daily scan toggle, payment fees, revenue tiers
- `cirrusly_shipping_config` - Shipping cost matrix, fee tiers
- `cirrusly_badge_config` - Badge sizing, custom badges
- `cirrusly_msrp_config` - MSRP display options
- `cirrusly_audit_data` (transient) - Compiled audit results

Use `sanitize_*` callbacks in `register_setting()` to validate on save.

### 8. **SEO Plugin Integration**
Compatibility layer ([`class-compatibility.php`](../includes/class-compatibility.php)) adds product schema data to:
- Yoast SEO (wpseo_schema_product filter)
- All in One SEO (aioseo_schema_output filter)
- SEOPress (seopress_json_ld_product filter)
- Rank Math (rank_math/vars/register_extra_replacements)
- Product Feed PRO / Ademti (woosea_custom_attributes, woocommerce_gpf_elements)

Always test with these plugins enabled when modifying pricing or MSRP fields.

## Developer Workflows

### Testing Pro Features
Dev mode override in `class-core.php::cirrusly_is_pro()`:
```
?cirrusly_dev_mode=pro    // Enable Pro features
?cirrusly_dev_mode=free   // Force free mode
```
Requires WP_DEBUG=true and admin capability.

### Debugging Audit Calculations
1. Check `get_transient('cirrusly_audit_data')` for cached results
2. Force refresh in PHP: `Cirrusly_Commerce_Audit::get_compiled_data(true)`
3. Review revenue tiers and payment fees in `get_option('cirrusly_scan_config')`

### Clearing Caches
- `delete_transient('cirrusly_audit_data')` - Forces audit recalculation
- `update_option('cirrusly_analytics_cache_version', time())` - Invalidates all analytics transients (Redis-safe)
- Called automatically on `save_post_product` action

## Common Pitfalls to Avoid

1. **Calling `init()` methods multiple times** - Static flag prevents duplicate hook registration (GMC, Audit)
2. **Missing transient invalidation** - Product updates must clear dependent transients
3. **Direct database queries** - Use WordPress options/meta APIs for object-cache compatibility
4. **Accessing meta without isset()** - `get_post_meta()` returns empty string on missing, not null
5. **Forgetting nonce verification order** - Check capability first, then nonce
6. **Pro files in free version** - Always check file existence AND Pro status
7. **Sanitization inconsistencies** - Use `wp_unslash()` before `sanitize_*()` for $_POST/$_GET

## Key Files Reference

| File | Purpose |
|------|---------|
| [cirrusly-commerce.php](../cirrusly-commerce.php) | Plugin header, Freemius init, Core loader |
| [includes/class-core.php](../includes/class-core.php) | Subsystem loader, Pro gate, cron router |
| [includes/class-audit.php](../includes/class-audit.php) | Per-product financials (cost, fees, margin) |
| [includes/class-gmc.php](../includes/class-gmc.php) | GMC scanning, product meta save |
| [includes/class-pricing.php](../includes/class-pricing.php) | MSRP display, frontend price logic |
| [includes/class-badges.php](../includes/class-badges.php) | Smart badge rendering, frontend JS |
| [includes/class-blocks.php](../includes/class-blocks.php) | Gutenberg block registration |
| [includes/class-security.php](../includes/class-security.php) | AES-256-CBC encryption for API keys |
| [includes/admin/class-settings-manager.php](../includes/admin/class-settings-manager.php) | Settings registration, cron scheduling |
| [includes/pro/class-google-api-client.php](../includes/pro/class-google-api-client.php) | Google API gateway (all Pro API calls route here) |
| [includes/pro/class-automated-discounts.php](../includes/pro/class-automated-discounts.php) | Google JWT verification, dynamic pricing |
