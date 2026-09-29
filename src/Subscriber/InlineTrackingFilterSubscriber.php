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

    private const PRICE_KEYS = ['price', 'value', 'item_price', 'revenue', 'listPrice', 'realPrice', 'item_startPrice'];

    private const SCRIPT_REGEX = '/(<script\b[^>]*>)(.*?)(<\/script>)/is';

    private const ITEM_TAG_REGEX = '/<[a-zA-Z][^<>]*\sdata-item_id\s*=[^<>]*>/';

    private const PRICE_ATTRIBUTE_REGEX = '/\sdata-[\w-]*price[\w-]*\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i';

    private const LITERAL_ITEM_ID_REGEX = '/(?<![\w"\'$])item_id\s*:/';

    private const LITERAL_PRICE_ENTRY_REGEX = '/(?<![\w"\'$.])(?:price|value|item_price|revenue|listPrice|realPrice|item_startPrice)\s*:\s*(?:\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|-?\d+(?:\.\d+)?|null|true|false)\s*,?/';

    // Re-encoded JSON lands inside <script>: keep "/" escaped and hex-encode <, >, &, ', "
    // so a string value can never close the script element.
    private const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

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
        if ($content === '' || !str_contains($content, 'item_id')) {
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
                $encoded = json_encode($data, self::JSON_FLAGS);
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

        // Pass 3 — scripts carrying items: nested JSON payloads and JS object literals.
        $result = preg_replace_callback(
            self::SCRIPT_REGEX,
            static function (array $match): string {
                if (!str_contains($match[2], 'item_id')) {
                    return $match[0];
                }

                return $match[1] . self::stripScript($match[2]) . $match[3];
            },
            $filtered,
        );
        if ($result !== null) {
            $filtered = $result;
        }

        // Pass 4 — price data attributes on elements that describe an item.
        $result = preg_replace_callback(
            self::ITEM_TAG_REGEX,
            static fn (array $match): string => preg_replace(self::PRICE_ATTRIBUTE_REGEX, '', $match[0]) ?? $match[0],
            $filtered,
        );
        if ($result !== null) {
            $filtered = $result;
        }

        if ($filtered !== $content) {
            $response->setContent($filtered);
        }
    }

    private static function stripScript(string $script): string
    {
        $script = self::stripNestedJson($script);

        if (preg_match(self::LITERAL_ITEM_ID_REGEX, $script) !== 1) {
            return $script;
        }

        return preg_replace(self::LITERAL_PRICE_ENTRY_REGEX, '', $script) ?? $script;
    }

    private static function stripNestedJson(string $script): string
    {
        $offset = 0;
        while (preg_match('/[=(]\s*\{/', $script, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $match[0][1] + \strlen($match[0][0]) - 1;
            $end = self::findClosingBrace($script, $start);
            if ($end === null) {
                $offset = $start + 1;
                continue;
            }

            $candidate = substr($script, $start, $end - $start + 1);
            $data = str_contains($candidate, '"item_id"') ? json_decode($candidate) : null;
            if (!$data instanceof \stdClass) {
                $offset = $start + 1;
                continue;
            }

            $encoded = json_encode(self::stripKeys($data), self::JSON_FLAGS);
            if ($encoded === false) {
                $offset = $end + 1;
                continue;
            }

            $script = substr_replace($script, $encoded, $start, $end - $start + 1);
            $offset = $start + \strlen($encoded);
        }

        return $script;
    }

    private static function findClosingBrace(string $text, int $start): ?int
    {
        $depth = 0;
        $inString = false;
        $length = \strlen($text);

        for ($i = $start; $i < $length; ++$i) {
            $char = $text[$i];
            if ($inString) {
                if ($char === '\\') {
                    ++$i;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                ++$depth;
            } elseif ($char === '}' && --$depth === 0) {
                return $i;
            }
        }

        return null;
    }

    private static function stripKeys(mixed $node): mixed
    {
        if ($node instanceof \stdClass) {
            foreach (get_object_vars($node) as $key => $value) {
                if (\in_array($key, self::PRICE_KEYS, true)) {
                    unset($node->{$key});
                    continue;
                }
                $node->{$key} = self::stripKeys($value);
            }

            return $node;
        }

        if (\is_array($node)) {
            return array_map(self::stripKeys(...), $node);
        }

        return $node;
    }
}
