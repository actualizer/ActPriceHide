# ActPriceHide

Hide prices and restrict cart access for unauthenticated visitors and configured customer groups on Shopware 6.7.

## Features

- Prices hidden for everyone outside the allowed customer-group list.
- Cart-access redirect to the login page for blocked users.
- Informational header bar in the storefront whenever prices are hidden (colours configurable).
- AJAX + normal-request compatibility (`hidePrice` set as request attribute + page extension).
- DE / EN snippets out of the box.
- Compatible with Shopware 6.7.1+.

## Price-leak protection

When prices are hidden the plugin actively closes every known leak vector so that numeric prices never reach an unauthorised visitor, search engine, or scraper:

- **Structured meta tags** (PDP) — `og:price:*`, `product:price:*`, `itemprop="price"` and Twitter price meta are suppressed. JSON-LD `schema.org/Offer` is dropped on product-detail pages when the `JSON_LD_DATA` feature flag is active.
- **`data-product-information` attribute on product cards** — the `price` key is removed entirely from the JSON blob that Shopware core emits on every card (listings, category pages, sliders, search, cross-selling, suggest, wishlist). The key is removed rather than zeroed — a zero would be indexed as "0 EUR".
- **Listing price-range aggregation** — the `price` aggregation is stripped from listing/search/suggest `Criteria`. No min/max values reach the XHR response that feeds filter sliders.
- **Server-rendered inline tracking scripts** — `gtag('event', 'view_item', {…})` and `dataLayer.push({…})` blocks emitted by tracker plugins (GA4, Google Ads, Meta Pixel via GTM, WbmTagManagerEcomm, etc.) are scanned at response time; `price`, `value`, `item_price`, `revenue` keys are removed from item objects, and outer `value` / `revenue` totals in the enclosing call are stripped as well.
- **Client-side `window.dataLayer.push` wrapper** — inline `<script>` at the top of `<head>` intercepts every subsequent push and applies the same strip rules. Covers interaction-triggered events (add-to-wishlist, quick-view, scroll-triggered `view_item_list`) that server-side filters cannot see because the payload is built in the browser at interaction time.
- **JS-plugin fallback** — a second install channel registered on `<body>` reads the config from `<meta>` tags and installs the wrapper on `DOMContentLoaded`. Kicks in when a customer theme overrides `layout/meta.html.twig` without calling `{{ parent() }}` and suppresses the primary inline script.

All of these respect the existing customer-group allowlist: a logged-in customer in an allowed group still sees every price in every channel.

### Operations

- **Kill-switch**: `ActPriceHide.config.priceLeakGuardEnabled` (default on). Toggles the whole dataLayer guard — the server-side HTML filters always stay active. Toggle in admin + cache clear, no redeploy.
- **CLI verification**:
  ```bash
  bin/console act:price-hide:verify-guard [--url=https://shop.example]
  ```
  Fetches the storefront and returns exit code `0` (inline primary channel active), `1` (fallback channel only — theme probably overrides `layout_head_meta_tags_charset` without `parent()`) or `2` (no guard detected at all). Suitable for deploy pipelines.
- **Admin guard-status card**: the plugin config page shows the current protection state directly below the info banner — green (primary channel), yellow (fallback only, theme override detected), red (not installed). Re-check button re-runs the probe.

## Requirements

- Shopware 6.7.1 or higher, < 6.8
- PHP 8.3 or higher

## Installation

```bash
bin/console plugin:refresh
bin/console plugin:install --activate ActPriceHide
bin/console cache:clear
```

## Configuration

Admin → Settings → System → Plugins → Actualize: Hide Prices and Cart → Config:

- **Customer groups to show prices**: allow-list of customer groups for whom prices remain visible. Everyone else gets the hide-all treatment.
- **Debug mode**: extra server-side dump output for development only.
- **Enable dataLayer price-leak guard**: kill-switch for the client-side JS wrapper; leave on unless you need to debug a tracker regression.
- **Header bar styling**: background and text colour for the informational header bar (theme.compile change-set).

## Architecture

- `HidePriceResolver::shouldHide(SalesChannelContext)` is the single source of truth for the hide decision, based on the customer-group allow-list.
- `StorefrontRenderSubscriber` / `ProductListingCriteriaSubscriber` / `DataProductInformationFilterSubscriber` / `InlineTrackingFilterSubscriber` / `PriceLeakGuardSubscriber` enforce the decision across render, listing criteria, HTML post-processing, inline tracking scripts, and the client-side dataLayer wrapper respectively.
- `KernelEvents::RESPONSE` filters run at priority `-128` / `-127` so they see the fully rendered HTML regardless of Twig template inheritance.
- Storefront JS plugin `ActPriceHideLeakGuard` provides the fallback install channel when theme overrides suppress the head-level inline script.

## Translations

DE (de-DE) and EN (en-GB) snippets are bundled for admin config, storefront header notices, and the guard-status card.

## License

MIT — see [LICENSE](LICENSE).
