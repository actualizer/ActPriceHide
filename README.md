# ActPriceHide - Shopware Plugin

A Shopware 6 plugin that provides advanced price visibility control and cart access management. Hide prices and restrict cart access for non-logged-in users or specific customer groups.

## Features

- Hide prices for non-logged-in users
- Restrict cart access for non-logged-in users
- Customer group-based price visibility control
- Information bar display when prices are hidden
- Automatic redirect to login page on cart access
- AJAX and normal page request compatibility
- Multi-language support (German & English)
- Compatible with Shopware 6.7.1+

## Price-Leak Protection

When prices are hidden, the plugin actively closes every known leak vector so that numeric prices never reach an unauthorised visitor, search engine, or scraper. All of these respect the existing customer-group allowlist: a logged-in customer in an allowed group still sees every price in every channel.

Server-side (introduced in v1.1.x–v1.2.0):

- **`data-product-information` attribute on product cards** — the `price` key is omitted entirely from the JSON blob that Shopware core emits on every card (listing, category, search, CMS sliders, cross-selling, suggest, wishlist). The key is removed rather than zeroed — a zero would be indexed as "0 EUR" in search results.
- **Listing price-range aggregation** — the `price` aggregation is stripped from listing/search/suggest `Criteria`, so min/max values do not appear in XHR responses or filter sliders.
- **JSON-LD `schema.org/Offer`** on the product detail page — the `page_product_detail_json_ld_script` block is suppressed, so no structured-data price reaches search engines or scrapers.
- **Server-rendered inline tracking scripts** — `gtag('event', 'view_item', {…})` and `dataLayer.push({…})` blocks rendered by tracker plugins (GA4, Google Ads, Meta Pixel via GTM, WbmTagManagerEcomm, etc.) are scanned at response time; `price`, `value`, `item_price`, `revenue` keys are removed from item objects, and outer `value` / `revenue` totals in the enclosing call are stripped as well.

Client-side (introduced in v1.2.0):

- **`window.dataLayer.push` wrapper** — inline `<script>` at the top of `<head>` intercepts every subsequent push and applies the same strip rules. Covers interaction-triggered events (add-to-wishlist, quick-view, scroll-triggered `view_item_list`) that server-side filters cannot see because the payload is built in the browser at interaction time.
- **JS-plugin fallback** — a second install channel registered on `<body>` reads the config from `<meta>` tags and installs the wrapper on `DOMContentLoaded`. Kicks in when a customer theme overrides `layout/meta.html.twig` without calling `{{ parent() }}` and suppresses the primary inline script.

### Operations (v1.2.2)

- **Kill-switch**: `ActPriceHide.config.priceLeakGuardEnabled` (default on). Toggles the client-side dataLayer guard — the server-side HTML filters always stay active. Toggle in admin + cache clear, no redeploy.
- **CLI verification**:
  ```bash
  bin/console act:price-hide:verify-guard [--url=https://shop.example]
  ```
  Fetches the storefront and returns exit code `0` (inline primary channel active), `1` (fallback channel only — theme probably overrides `layout_head_meta_tags_charset` without `parent()`), or `2` (no guard detected at all). Suitable for deploy pipelines.
- **Admin guard-status card**: the plugin config page shows the current protection state directly below the info banner — green (primary channel), yellow (fallback only), red (not installed). Re-check button re-runs the probe.

## Requirements

- Shopware 6.7.1 or higher
- PHP 8.3 or higher

## Installation

1. Download or clone this plugin into your `custom/plugins/` directory
2. Install and activate the plugin via CLI:
   ```bash
   bin/console plugin:refresh
   bin/console plugin:install --activate ActPriceHide
   bin/console cache:clear
   ```

## Configuration

1. Go to Admin Panel → Settings → System → Plugins
2. Find "Actualize: Price Hide" and click on the three dots
3. Click "Config" to access plugin settings
4. Configure customer groups that should see prices
5. Set up redirect behavior and display options

## How it works

1. **Price Visibility Check**: The plugin checks if the current user is logged in and belongs to an allowed customer group
2. **Price Hiding**: If conditions are not met, prices are hidden across all storefront pages (product listings, detail pages, cart, etc.)
3. **Cart Access Control**: Cart functionality is restricted when prices are hidden
4. **Information Display**: Shows informational messages to users when prices are hidden
5. **Login Redirect**: Automatically redirects users to login page when trying to access cart

## Technical Details

### Architecture
- **Global Template Variables**: Uses Shopware's native template variable system for reliable data access
- **HeaderDataSubscriber**: Dedicated subscriber for header-specific data injection
- **HidePriceResolver**: Single source of truth for the hide decision, shared by the render subscriber, listing-criteria subscriber, inline-tracking filter, and dataLayer-guard subscriber.

### Events Used
- `StorefrontRenderEvent` - To inject price hiding logic into all storefront pages
- `KernelEvents::RESPONSE` (priority -128 / -127) - Post-rendering HTML filters for `data-product-information` attributes and inline tracking scripts
- Template overrides for price-sensitive areas

### Template Extensions
The plugin extends multiple templates to ensure consistent price hiding:
- Product listing pages
- Product detail pages
- Cart and checkout pages
- Search suggestions
- Header cart widget
- `layout/meta.html.twig` for the head-level dataLayer-guard inline script

### AJAX Compatibility
All template files support both AJAX and normal page requests through dual checking logic:
- Request attributes for AJAX requests
- Page extensions for normal page loads

## Translations

The plugin includes translations for:
- **German (de-DE)**: Preis verstecken
- **English (en-GB)**: Price hide

Translation keys:
- `header.priceHideInfoNotLoggedIn`
- `header.priceHideInfoNotAllowed`
- `header.priceHideInfoAriaLabel` (accessibility)

## Development

### Building/Testing
After making changes to templates or translations:
```bash
bin/console cache:clear
bin/console theme:compile
```

If the client-side dataLayer guard is touched, rebuild the storefront and admin bundles:
```bash
./bin/build-storefront.sh
./bin/build-administration.sh
```

### Debugging
The plugin respects Shopware's logging configuration. Check your log files for any price hiding logic errors.

## Compatibility

- **Shopware Version**: 6.7.1+
- **PHP Version**: 8.3+
- **AJAX Support**: Full compatibility with AJAX requests

## Support

For issues and feature requests, please use the GitHub issue tracker.

## License

This plugin is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Credits

Developed by Actualize

---

Made with ❤️ for the Shopware Community
