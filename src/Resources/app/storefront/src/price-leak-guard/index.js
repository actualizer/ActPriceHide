const Plugin = window.PluginBaseClass;

/**
 * Secondary install channel for the dataLayer price-leak guard.
 *
 * The primary channel is an inline <script> rendered by
 * layout/meta.html.twig near the top of <head>. When a customer theme
 * overrides that block without calling {{ parent() }}, the inline script
 * never runs. This plugin detects that case on DOMContentLoaded and
 * installs the same wrapper retroactively.
 *
 * Data source: <meta name="act-price-leak-guard-ph-hide-all"> rendered
 * by the same Twig override. PriceHide gates globally per customer
 * group, so a single hideAll flag is enough — no per-product id list.
 */
export default class ActPriceLeakGuardPlugin extends Plugin {
    init() {
        if (window.__actPriceLeakGuardInstalled) {
            return;
        }

        const hideAllMeta = document.querySelector('meta[name="act-price-leak-guard-ph-hide-all"]');
        if (!hideAllMeta) {
            return;
        }

        if (window.console && console.warn) {
            console.warn('[ActPriceLeakGuard] primary install missed, fallback active');
        }

        const hideAll = hideAllMeta.getAttribute('content') === 'true';
        this._install([], hideAll);
    }

    _install(ids, hideAll) {
        const PRICE_KEYS = ['price', 'value', 'item_price', 'revenue'];
        const ID_KEYS = ['id', 'item_id', 'product_id', 'sku'];

        const state = {
            idSet: new Set(ids.map(String)),
            hideAll: !!hideAll,
            extend(moreIds, moreHideAll) {
                (moreIds || []).forEach((id) => state.idSet.add(String(id)));
                if (moreHideAll) { state.hideAll = true; }
            },
        };

        function hasPriceKey(obj) {
            for (let i = 0; i < PRICE_KEYS.length; i++) {
                if (Object.prototype.hasOwnProperty.call(obj, PRICE_KEYS[i])) { return true; }
            }
            return false;
        }

        function sanitize(node, seen) {
            if (!node || typeof node !== 'object') { return; }
            if (seen.has(node)) { return; }
            seen.add(node);
            if (Array.isArray(node)) {
                for (let i = 0; i < node.length; i++) { sanitize(node[i], seen); }
                return;
            }
            if (hasPriceKey(node)) {
                let match = state.hideAll;
                if (!match) {
                    for (let j = 0; j < ID_KEYS.length; j++) {
                        const id = node[ID_KEYS[j]];
                        if (id != null && state.idSet.has(String(id))) { match = true; break; }
                    }
                }
                if (match) {
                    PRICE_KEYS.forEach((k) => { delete node[k]; });
                }
            }
            const keys = Object.keys(node);
            for (let m = 0; m < keys.length; m++) { sanitize(node[keys[m]], seen); }
        }

        window.dataLayer = window.dataLayer || [];
        const originalPush = window.dataLayer.push;

        window.dataLayer.push = function () {
            try {
                const seen = new WeakSet();
                for (let i = 0; i < arguments.length; i++) { sanitize(arguments[i], seen); }
            } catch (e) {
                if (window.console && console.warn) {
                    console.warn('[ActPriceLeakGuard] sanitize failed, passing through:', e);
                }
            }
            return originalPush.apply(window.dataLayer, arguments);
        };

        try {
            const seen = new WeakSet();
            for (let i = 0; i < window.dataLayer.length; i++) {
                sanitize(window.dataLayer[i], seen);
            }
        } catch { /* retro-sanitize is best-effort */ }

        window.__actPriceLeakGuardInstalled = state;
    }
}
