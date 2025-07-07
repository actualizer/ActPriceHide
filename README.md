# ActPriceHide - Shopware Plugin

A Shopware 6 plugin that provides advanced price visibility control and cart access management. Hide prices and restrict cart access for non-logged-in users or specific customer groups.

## Features

- ✅ Hide prices for non-logged-in users
- ✅ Restrict cart access for non-logged-in users
- ✅ Customer group-based price visibility control
- ✅ Information bar display when prices are hidden
- ✅ Automatic redirect to login page on cart access
- ✅ AJAX and normal page request compatibility
- ✅ Multi-language support (German & English)
- ✅ Compatible with Shopware 6.6.10 - 6.7.x

## Requirements

- Shopware 6.6.10 or higher (up to 6.7.x)
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

### Events Used
- `StorefrontRenderEvent` - To inject price hiding logic into all storefront pages
- Template overrides for price-sensitive areas

### Template Extensions
The plugin extends multiple templates to ensure consistent price hiding:
- Product listing pages
- Product detail pages
- Cart and checkout pages
- Search suggestions
- Header cart widget

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

## File Structure

```
ActPriceHide/
├── composer.json
├── LICENSE
├── README.md
├── src/
│   ├── ActPriceHide.php
│   ├── Resources/
│   │   ├── config/
│   │   │   ├── config.xml
│   │   │   └── services.xml
│   │   ├── snippet/
│   │   │   ├── de_DE/
│   │   │   │   └── messages.de-DE.json
│   │   │   └── en_GB/
│   │   │       └── messages.en-GB.json
│   │   └── views/
│   │       └── storefront/
│   │           ├── base.html.twig
│   │           ├── component/
│   │           │   ├── buy-widget/
│   │           │   ├── listing/
│   │           │   └── product/
│   │           ├── layout/
│   │           │   └── header/
│   │           └── page/
│   │               ├── checkout/
│   │               └── product-detail/
│   └── Subscriber/
│       └── StorefrontRenderSubscriber.php
```

## Development

### Building/Testing
After making changes to templates or translations:
```bash
bin/console cache:clear
bin/console theme:compile
```

### Debugging
The plugin respects Shopware's logging configuration. Check your log files for any price hiding logic errors.

## Compatibility

- **Shopware Version**: 6.6.10 - 6.7.x
- **PHP Version**: 8.3+
- **Template Compatibility**: Uses Shopware 6.6+ template structure
- **AJAX Support**: Full compatibility with AJAX requests

## Support

For issues and feature requests, please use the GitHub issue tracker.

## License

This plugin is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Credits

Developed by Actualize

---

Made with ❤️ for the Shopware Community
