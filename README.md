# ActPriceHide - Shopware Plugin

A Shopware 6 plugin that provides advanced price visibility control and purchase-funnel access management. Hide prices and close cart and checkout for non-logged-in users or specific customer groups.

## Features

- Hide prices for non-logged-in users
- Close cart, cart mutation and checkout for non-logged-in users
- Customer group-based price visibility control
- Information bar display when prices are hidden
- Price hiding, checkout lockout and the information bar can be switched per sales channel
- Server-side lockout of every cart and checkout route, including `/checkout/cart.json` and order placement
- AJAX and normal page request compatibility
- Multi-language support (German & English)
- Compatible with Shopware 6.7.1+

## Price-Leak Protection

When prices are hidden, the plugin actively closes every known leak vector so that numeric prices never reach an unauthorised visitor, search engine, or scraper. All of these respect the existing customer-group allowlist: a logged-in customer in an allowed group still sees every price in every channel.

Server-side (introduced in v1.1.x–v1.2.0):

- **`data-product-information` attribute on product cards** — the `price` key is omitted entirely from the JSON blob that Shopware core emits on every card (listing, category, search, CMS sliders, cross-selling, suggest, wishlist). The key is removed rather than zeroed — a zero would be indexed as "0 EUR" in search results.
- **Listing price-range aggregation** — the `price` aggregation is stripped from listing/search/suggest `Criteria`, so min/max values do not appear in XHR responses or filter sliders. For search, the aggregation was still returned by the search filter endpoint up to v1.6.0, see below; search result pages and suggest never displayed prices.
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

### Purchase funnel (v1.3.0)

Cart markup renders from the line-item and summary templates, which emit their own price output and share no block with the product templates this plugin overrides. Hiding the cart button removes the entry point, not the route. Every route under `frontend.checkout.` and `frontend.cart.` is therefore closed server-side while prices are hidden — matching by prefix so routes added by future Shopware versions are covered by default:

| Route group | Response while prices are hidden |
| --- | --- |
| Landing pages: `cart.page`, `confirm.page`, `finish.page`, `register.page` | `302` to `/account/login` |
| XHR fragments: `frontend.cart.offcanvas`, `frontend.checkout.info` | `204`, empty body |
| Everything else: `cart.json`, cart mutation, `POST /checkout/order`, … | `403`, empty body |

`/checkout/cart.json` returned the full cart as JSON including `unitPrice`, `totalPrice` and `positionPrice`, bypassing every HTML filter. Cart mutation and order placement were reachable as well, so a customer outside the allowed groups could complete an order without ever seeing a price. Both were closed in v1.3.0.

The two widget routes are answered empty rather than redirected because their callers inject the response into the offcanvas or the header container. The redirect is `302`, never `301` — the destination depends on plugin configuration and login state. It carries no `redirectTo`: the core hands a logged-in customer straight back to that route, which for a customer outside the allowed groups bounced between cart and login until the browser gave up (fixed in v1.2.10).

This replaces the `<meta http-equiv="refresh">` used up to v1.2.7, which shipped the fully priced cart page and only then asked the browser to leave.

### Further leak vectors closed in v1.2.8

- **Footer VAT notice** — the `showVatNotice` render parameter is set to `false`, so the core footer drops its VAT/shipping line while prices are hidden.
- **OpenGraph product price** — `product:price:amount` and `product:price:currency` are dropped on the product detail page. The override now defers to the core block via `{{ parent() }}` whenever prices are visible; up to v1.2.7 it replaced the block unconditionally with a stale copy that also lost `ogTitle`, `ogDescription`, `openGraphMedia` and `og:video`.

### Further tracking formats and variant selection (v1.6.0)

- **Nested tracking payloads** — JSON objects in `<script>` blocks that contain an `item_id` (e.g. `window.x = {"<id>": {"item_id": …, "price": …, "listPrice": {…}, "extra": {…}}}`) are decoded and re-encoded without `price`, `value`, `item_price`, `revenue`, `listPrice`, `realPrice` and `item_startPrice` at any depth. Keys are removed, never set to `0` or `null`. The re-encoded JSON keeps `/` escaped and hex-encodes `<`, `>`, `&`, `'` and `"`, so no value can close the surrounding script element.
- **Script escaping of flat item objects** — flat item objects inside `gtag()` / `dataLayer.push()` calls are re-encoded with the same flags. Up to v1.5.0 they were re-encoded with unescaped slashes, which turned an escaped `<\/script>` inside a string value into a literal `</script>` and ended the script element early.
- **JavaScript object literals** — in scripts with an unquoted `item_id:` key the same price keys are removed (`price: '349'`, `realPrice: '349'`, …).
- **Item data attributes** — on elements carrying `data-item_id`, every `data-*price*` attribute is removed (`data-price`, `data-list-price`, `data-item_startPrice`, …).
- **Variant selection** — optional, see configuration item 8. The configurator is rendered on its own; price, tax notice, delivery information, buy form and offer microdata stay hidden. Switching variants reloads the page or the buy box through the same template, so the rule applies there as well.

### Price filter, price sorting and page-level tracking prices (v1.6.1)

Listing, search and suggest pages never displayed prices. Up to v1.6.0, however, only the filter slider and the price aggregation of category listings and suggest were removed. The request parameters behind them kept working, so prices could be read without ever being displayed:

- **Search filter endpoint** — `/widgets/search/filter?search=<product number>` returned the `price` aggregation with `min` equal to `max`, i.e. the exact price. The search dispatches its own criteria event, which is now handled like listing and suggest.
- **Price filter** — `min-price` / `max-price` narrowed listing and search results, which reveals any price by bisection. The price filter is now removed before it reaches the criteria, so it neither filters nor aggregates nor wraps the other filters' aggregations.
- **Price sorting** — sortings on a price field are removed from the available sortings (the dropdown no longer offers them). A request that still asks for one is answered in the order of the first remaining sorting.
- **Criteria parts on price fields in general** — every filter, post-filter, score query, aggregation and sorting whose field name contains `price` is dropped from listing, search and suggest criteria, whatever its name. Shopware versions that build search criteria from generic request parameters (`filter[…]`, `aggregations[…]`, `sort[…]`) are covered by this.
- **Page-level tracking prices** — JSON payloads in `<script>` blocks are also cleaned when they have no `item_id` but carry `productPrice`, `ecomm_pvalue` or `ecomm_totalvalue`. The client-side guard removes the same keys.
- **Hidden price inputs** — `<input type="hidden">` elements whose name contains `price` keep the element and lose the value.

Tracking keys are matched by name. A tracking integration that renders a price under a key not listed here is not covered until that key is added — after installing or updating one, check a product page's source for the product's real price.

## Known limitations

### Store API

The plugin protects the Storefront. Product data returned by the Store API (`/store-api/…`), as used by headless frontends and apps, still contains prices, and the Store API cart and checkout routes are not closed. Do not rely on the plugin to hide prices in a headless sales channel.

## Requirements

- Shopware 6.7.1 or higher
- PHP 8.4 or higher

## Installation

### Via Composer (recommended)

```bash
composer require actualizer/price-hide
bin/console plugin:refresh
bin/console plugin:install --activate ActPriceHide
bin/console cache:clear
```

### Manual

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
6. **Hide prices and checkout** (`ActPriceHide.config.enabled`, per sales channel, default on, since v1.4.0): switch it off in every sales channel that should behave like a normal shop. Only an explicit boolean "off" opens a channel; a channel without a value stays closed. On the CLI pass `--json` (`system:config:set ActPriceHide.config.enabled false --json -s <salesChannelId>`), otherwise the string `"false"` is stored and the channel stays closed.
7. **Show notice bar** (`ActPriceHide.config.showNoticeBar`, per sales channel, default on, since v1.5.0): switch it off where logging in does not unlock prices. Only the bar disappears; prices, cart and checkout stay hidden.
8. **Show variant selection while prices are hidden** (`ActPriceHide.config.showVariantSelection`, per sales channel, default off): keeps the variant configurator (e.g. colours) on the product page so visitors can browse the variants. Price, delivery information and buy button stay hidden. On the CLI pass `--json`.

## How it works

1. **Price Visibility Check**: The plugin checks if the current user is logged in and belongs to an allowed customer group
2. **Price Hiding**: If conditions are not met, prices are hidden across all storefront pages (product listings, detail pages, cart, etc.)
3. **Funnel Access Control**: Every route under `frontend.checkout.` and `frontend.cart.` is answered with a redirect, an empty fragment or `403` before the rendered response leaves the server
4. **Information Display**: Shows informational messages to users when prices are hidden

## Technical Details

### Architecture
- **Global Template Variables**: Uses Shopware's native template variable system for reliable data access
- **HidePriceResolver**: Single source of truth for the hide decision, shared by the render subscriber, listing-criteria subscriber, inline-tracking filter, dataLayer-guard subscriber, and cart-route guard.

### Events Used
- `StorefrontRenderEvent` - To inject price hiding logic into all storefront pages
- `KernelEvents::RESPONSE` (priority 0) - Purchase-funnel guard. Runs on the response rather than the request because the `SalesChannelContext` is only resolved on `kernel.controller`
- `KernelEvents::RESPONSE` (priority -128 / -127) - Post-rendering HTML filters for `data-product-information` attributes and inline tracking scripts
- `ProductListingCollectFilterEvent` - Removes the price filter before aggregations and post-filters are built
- `ProductListingCriteriaEvent`, `ProductSearchCriteriaEvent`, `ProductSuggestCriteriaEvent` (priority -1000) - Drop every criteria part on a price field
- Template overrides for price-sensitive areas

### Template Extensions
The plugin extends multiple templates to ensure consistent price hiding:
- Product listing pages
- Product detail pages
- Search suggestions
- Header cart button and widget
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
- **PHP Version**: 8.4+
- **AJAX Support**: Full compatibility with AJAX requests

## Support

For issues and feature requests, please use the GitHub issue tracker. Please report security vulnerabilities privately instead, as described in the [security policy](https://github.com/actualizer/ActPriceHide/security/policy).

## License

This plugin is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Credits

Developed by Actualize

---

Made with ❤️ for the Shopware Community
