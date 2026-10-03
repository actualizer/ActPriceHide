# Security Policy

## Supported versions

Security fixes are released for the latest version only. Please check that the issue still occurs on the latest release before reporting it.

## Reporting a vulnerability

Please do **not** open a public issue for security problems.

Report it privately via GitHub instead: [Report a vulnerability](https://github.com/actualizer/ActPriceHide/security/advisories/new). Only you and the maintainers can see the report. If you cannot use GitHub, send an email to plugin@actualize.de.

Please include:

- the plugin version and Shopware version
- the steps to reproduce
- what an attacker gains

## What to expect

- An acknowledgement within 7 days.
- Once confirmed, a fix is released as a new version. The advisory is published after that release, crediting you unless you prefer otherwise.

## Scope

In scope are the guarantees this plugin makes while prices are hidden:

- A numeric price reaching a visitor who must not see it (not logged in, or not in an allowed customer group) through any Storefront response: HTML, JSON or XHR responses, structured data, or tracking payloads.
- Using the cart, changing it, entering the checkout or placing an order through a Storefront route while the purchase funnel is closed.

Out of scope:

- Store API responses. The plugin filters the Storefront only; headless or Store API sales channels are not covered.
- Client-side tracking leaks while the `priceLeakGuardEnabled` setting is switched off.
- Vulnerabilities in Shopware itself. Please report those to Shopware: <https://github.com/shopware/shopware/security/policy>.
