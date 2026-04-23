<?php declare(strict_types=1);

namespace Act\PriceHide\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Response-level filter that strips price / value / item_price / revenue
 * fields from server-rendered inline tracking scripts (gtag() /
 * dataLayer.push()) when hidePrice is active for the current request.
 *
 * Why: tracker plugins like ActMultiTracking / WbmTagManagerEcomm render
 * the product price directly into the HTML, e.g.
 *
 *     gtag('event', 'view_item', {
 *         'currency': 'EUR',
 *         'value': 35.0,
 *         'items': [{"item_id":"SW10000","price":35.0, ...}]
 *     });
 *
 * By the time the browser parses the page, SEO crawlers, view-source,
 * and any HTML reader have already seen the numeric value. A client-side
 * dataLayer.push wrapper cannot help here — the leak precedes any JS.
 *
 * Because PriceHide gates globally per customer group (hide-all when the
 * current user is not in the allowed group), the filter strips every
 * item object regardless of identifier once hidePrice.hide is true.
 *
 * Fail-open: any regex or JSON failure leaves the response untouched.
 * A broken tracker chain is worse than a missed strip.
 */
class InlineTrackingFilterSubscriber implements EventSubscriberInterface
{
    // Flat item objects carrying an "item_id". Value may be a UUID or a
    // productNumber; we strip regardless — hide-all applies to every item.
    private const ITEM_OBJECT_REGEX = '/\{[^{}]*?"item_id"\s*:\s*"[^"]+"[^{}]*?\}/';

    private const STRIP_KEYS_IN_ITEM = ['price', 'value', 'item_price', 'revenue'];

    private const OUTER_NUMERIC_KEYS = ['value', 'revenue'];

    public static function getSubscribedEvents(): array
    {
        return [
            // Just after DataProductInformationFilterSubscriber (-128).
            KernelEvents::RESPONSE => ['onResponse', -127],
        ];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $hidePrice = $event->getRequest()->attributes->get('hidePrice');
        if (!is_array($hidePrice) || !($hidePrice['hide'] ?? false)) {
            return;
        }

        $response = $event->getResponse();
        if (!$response->isSuccessful()) {
            return;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');
        if ($contentType !== '' && !str_contains($contentType, 'text/html')) {
            return;
        }

        $content = (string) $response->getContent();
        if ($content === '' || !str_contains($content, '"item_id"')) {
            return;
        }

        // Pass 1 — rewrite each item object, dropping price-bearing keys.
        $filtered = preg_replace_callback(
            self::ITEM_OBJECT_REGEX,
            static function (array $match): string {
                $data = json_decode($match[0], true);
                if (!is_array($data)) {
                    return $match[0];
                }
                foreach (self::STRIP_KEYS_IN_ITEM as $key) {
                    unset($data[$key]);
                }
                $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                return $encoded === false ? $match[0] : $encoded;
            },
            $content,
        );

        if ($filtered === null) {
            return;
        }

        // Pass 2 — strip outer "value"/"revenue" totals from each gtag
        // and dataLayer.push call. Simple per-call substring processing
        // avoids pcre backtracking issues on large responses.
        $patterns = [
            '/gtag\s*\(\s*[\'"]event[\'"][^)]*\)/',
            '/dataLayer\s*\.\s*push\s*\(\s*\{[^)]*\}\s*\)/',
        ];

        foreach ($patterns as $pattern) {
            $result = preg_replace_callback(
                $pattern,
                static function (array $match): string {
                    $call = $match[0];
                    foreach (self::OUTER_NUMERIC_KEYS as $key) {
                        $call = preg_replace(
                            '/[\'"]' . preg_quote($key, '/') . '[\'"]\s*:\s*-?[0-9]+(?:\.[0-9]+)?\s*,?\s*/',
                            '',
                            $call,
                        ) ?? $call;
                    }
                    return $call;
                },
                $filtered,
            );
            if ($result !== null) {
                $filtered = $result;
            }
        }

        if ($filtered !== $content) {
            $response->setContent($filtered);
        }
    }
}
