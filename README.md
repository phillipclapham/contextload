# ContextLoad

**Context-aware plugin loading for WooCommerce on managed WordPress hosting.**

ContextLoad is a WordPress mu-plugin that suppresses unnecessary plugin bootstrap based on request context. Plugins not needed for the current page type (checkout, cart, frontend, etc.) never get `require_once`'d — their PHP execution cost is completely eliminated.

On a WooCommerce site with 45 active plugins, ContextLoad reduced checkout TTFB by **1.12 seconds (24.6%)** by loading only the 15 plugins actually needed for checkout. No visual degradation, no functional impact. Just faster pages.

## The Problem

WordPress loads every active plugin on every request. A WooCommerce site with 40+ plugins bootstraps all of them on the checkout page — even SEO plugins, page builders, import tools, image optimizers, and review widgets that have nothing to do with completing a purchase.

Each plugin's bootstrap has a PHP cost: autoloaders register, `init` hooks fire, options load, classes instantiate. On managed hosting where checkout is always uncached (sessions, payment processing), this cost is paid on every single checkout request.

The traditional solution is [Plugin Organizer](https://wordpress.org/plugins/developer/flavor/) — a customer-facing plugin with per-page URL rules. It works, but it adds its own overhead, requires manual configuration per page, and isn't designed for fleet deployment across managed hosting.

## How ContextLoad Is Different

| | Plugin Organizer | ContextLoad |
|---|---|---|
| **Type** | Regular plugin with admin UI | mu-plugin, zero UI |
| **Configuration** | Per-page URL rules in database | Context-based JSON config file |
| **Targeting** | URL patterns | Request context (checkout, cart, frontend, ajax, etc.) |
| **Setup** | Customer configures manually | Curated defaults, zero-config |
| **Deployment** | Per-site installation | Fleet-deployable mu-plugin |
| **Overhead** | Plugin bootstrap + rule engine + DB queries | One file read + one array filter |

ContextLoad is **platform infrastructure**, not a customer tool. It ships as an mu-plugin with a JSON config file, loads before all regular plugins, and makes its filtering decision based on request context — not URL patterns.

## How It Works

1. **mu-plugin loads before regular plugins** (WordPress guarantees this)
2. **Detects request context** from `$_SERVER['REQUEST_URI']` and `$_SERVER['SCRIPT_NAME']`:
   - `checkout`, `cart`, `account`, `shop`, `product` (WooCommerce pages)
   - `admin`, `ajax` (with action sub-context), `rest`, `cron`
   - `frontend` (everything else)
3. **Filters `option_active_plugins`** — removes plugins not needed for the current context
4. **WordPress proceeds normally** — only loads the filtered plugin list

WooCommerce page slugs are auto-detected from WooCommerce settings and cached to a PHP file (zero database queries on the hot path). The cache auto-invalidates when WooCommerce page settings change.

## Empirical Results

**Site:** Pascal Beds staging (WooCommerce, Woodmart theme, Premium 10 plan)
**Method:** A/B curl TTFB measurement, 7 runs per condition, same server, same time window

| Page | Baseline (45 plugins) | ContextLoad (15 on checkout) | Delta |
|------|----------------------|------------------------------|-------|
| **Checkout** | 4.55s | 3.43s | **-1.12s (24.6%)** |
| **Cart** | 2.49s | 1.85s | **-0.64s (25.6%)** |
| Homepage | 0.088s | 0.088s | unchanged (cached) |

Visually verified: full page layout intact, WPBakery shortcodes rendered, all widgets (WhatsApp, Trustpilot, sticky menu) present.

## Installation

### 1. Deploy files

Copy both files to `wp-content/mu-plugins/`:

```
wp-content/mu-plugins/
  contextload.php
  contextload-config.json
```

### 2. Build WooCommerce context cache

```bash
wp contextload build-cache
```

This reads WooCommerce page settings (checkout page ID, cart page ID, etc.), resolves their slugs, and writes a cache file so ContextLoad can detect page contexts without querying the database on every request.

### 3. Simulate

Before activating, see what would be suppressed:

```bash
wp contextload simulate /checkout/
```

Output:
```
URL: /checkout/
Detected context: checkout

Active plugins: 45
Suppress rules: 30

  SUPPRESS: seo-by-rank-math/rank-math.php
  PROTECT:  woocommerce/woocommerce.php
  PROTECT:  woo-stripe-payment/stripe-payments.php
  LOAD:     some-unknown-plugin/plugin.php
  ...

Success: Would load 15, suppress 30 of 45 plugins.
```

- **SUPPRESS** — plugin matches a suppress rule, will not load in this context
- **PROTECT** — plugin matches a `never_suppress` rule, always loads
- **LOAD** — plugin doesn't match any rule, loads by default (fail-open)

### 4. Start in dryrun mode

The default config ships with `"mode": "dryrun"`. In this mode, ContextLoad logs what it *would* suppress but loads everything normally. Check `debug.log` for output:

```
ContextLoad: [DRYRUN] Context: checkout | Plugins: 15/45 loaded | Suppressed: 30 (...)
```

Browse the site, verify no errors, check that page layouts render correctly.

### 5. Activate

Edit `contextload-config.json` and change:

```json
"mode": "active"
```

ContextLoad now suppresses plugins for real. Measure TTFB, verify visual integrity, confirm payment flows work.

### 6. Emergency rollback

Any of these instantly disables ContextLoad:

- Set `"mode": "disabled"` in the JSON config
- Add `define('CONTEXTLOAD_DISABLED', true);` to `wp-config.php`
- Delete the JSON config file (mu-plugin detects missing config, loads everything)
- Delete the mu-plugin file

## Configuration

### Config Structure

```json
{
  "version": "1.0",
  "mode": "dryrun",

  "never_suppress": [
    "woocommerce",
    "woocommerce-gateway-*",
    "wordfence",
    "wp-rocket"
  ],

  "contexts": {
    "checkout": {
      "suppress": [
        "wordpress-seo",
        "contact-form-7",
        "revslider"
      ]
    }
  }
}
```

### Mode

- `dryrun` — logs what would be suppressed, loads everything (safe for initial deployment)
- `active` — actually suppresses plugins
- `disabled` — ContextLoad does nothing
- **Unknown mode defaults to `disabled`** (typo-safe — a config error won't accidentally suppress plugins)

### never_suppress (Global Safety Rail)

Plugins listed here **always load on every request**, regardless of context rules. Use glob patterns for plugin families:

```json
"never_suppress": [
  "woocommerce",
  "woocommerce-gateway-*",
  "woocommerce-shipping-*"
]
```

This is your safety net. Payment gateways, WooCommerce core, security plugins, theme dependencies, and caching plugins belong here.

### Pattern Matching

Patterns match against plugin **directory names** (the most intuitive unit):

- `"wordpress-seo"` — exact match on directory `wordpress-seo/`
- `"woocommerce-gateway-*"` — glob match, catches all gateway plugins
- `"wordpress-seo/wp-seo.php"` — exact file path match (rarely needed)

`never_suppress` uses glob patterns (safety coverage). `suppress` uses exact directory names (precision).

### Context Detection

| Context | Detection Method |
|---------|-----------------|
| `cron` | `DOING_CRON` constant or `wp-cron.php` script |
| `ajax` | `admin-ajax.php` script (with `:action` sub-context) |
| `rest` | `/wp-json/` in path or `rest_route` parameter |
| `admin` | `WP_ADMIN` constant or `/wp-admin/` in script path |
| `checkout` | WooCommerce checkout page slug in URL path |
| `cart` | WooCommerce cart page slug in URL path |
| `account` | WooCommerce my-account page slug in URL path |
| `shop` | WooCommerce shop page slug in URL path |
| `product` | WooCommerce product permalink base in URL path |
| `frontend` | Everything else (default) |

Contexts with no suppress rules in the config load all plugins (fail-open).

## WP-CLI Commands

```bash
# Build WooCommerce slug cache from current settings
wp contextload build-cache

# Show config status, mode, and cache state
wp contextload status

# Simulate suppression for a specific URL
wp contextload simulate /checkout/
wp contextload simulate /cart/
wp contextload simulate /blog/my-post/
```

## Safety Design

ContextLoad is designed to **fail open** at every level:

- **Missing config file** → all plugins load
- **Invalid JSON** → all plugins load
- **Unknown mode** → defaults to `disabled`, not `active`
- **Unknown context** (no matching rules) → all plugins load
- **`never_suppress` always wins** over suppress rules
- **WP-CLI** → all plugins load (CLI needs full stack for maintenance)
- **Multisite** → ContextLoad disables itself (v1 is single-site only)
- **WordPress installation/upgrade** → ContextLoad disables itself

The worst failure mode is "all plugins load" — which is normal WordPress behavior. ContextLoad can never make things worse than the baseline.

## Customizing for a Site

The default config includes ~100 common plugins known to be safe to suppress on checkout (SEO, page builders, forms, backup, image optimization, sliders, social sharing, etc.). For a specific site:

1. Run `wp contextload simulate /checkout/` to see which of their plugins match
2. Add site-specific plugins to the suppress list
3. Add site-specific dependencies to `never_suppress` (theme core plugins, page builders used on checkout, etc.)
4. **Always visual-test** — some themes use page builder shortcodes on checkout/cart pages

### Common never_suppress additions per site

- **WPBakery sites:** `js_composer` (renders shortcodes in page content and widget areas)
- **Elementor sites:** `elementor`, `elementor-pro` (if checkout is built with Elementor)
- **ACF-dependent themes:** `advanced-custom-fields-pro` (if theme uses ACF on checkout)

## Known Limitations

- **`is_plugin_active()` returns false for suppressed plugins.** This is inherent to the mechanism. WooCommerce and other plugins that check `is_plugin_active()` at load time will see suppressed plugins as inactive. This is correct behavior (the plugin isn't active on this request), but it's a config authoring constraint: verify that no always-loaded plugin makes critical decisions based on a suppressed plugin's presence.

- **REST API is a single context.** All plugins load on `/wp-json/` requests. This is intentional: modern WooCommerce (Blocks checkout) uses REST for payment processing. Suppressing plugins on REST risks silent checkout failure. The performance gain on REST is minimal (no template rendering), while the risk is high.

- **Plugin dependency chains.** If Plugin A depends on Plugin B and you suppress B, Plugin A may fatal. Dryrun mode is designed to surface these issues before activation. Always test in dryrun first.

## Architecture

```
Request → mu-plugin loads → detect context from URL
  → read JSON config → filter option_active_plugins
  → WordPress loads only the filtered plugin list
  → WooCommerce page renders with fewer plugins
  → customer sees faster checkout
```

The hot path (every request) is:
1. Read JSON config from disk (~0.1ms, OS file cache)
2. Read WC slug cache from PHP file (~0.05ms, opcache)
3. Parse URL into path segments (~0.01ms)
4. Filter plugin array against patterns (~0.1ms for 45 plugins)

Total ContextLoad overhead: **<0.3ms per request**. The savings from not loading 30 plugins: **1,000+ ms**.

## Requirements

- WordPress 5.5+ (for `wp_cache_get_multiple` compatibility awareness)
- WooCommerce (for context detection — without WooCommerce, all frontend requests are context `frontend`)
- PHP 7.4+
- Single-site WordPress (multisite support planned for v2)

## License

GPLv2 or later.
