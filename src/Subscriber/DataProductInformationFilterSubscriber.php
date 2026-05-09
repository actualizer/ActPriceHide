<?php declare(strict_types=1);

namespace Act\PriceHide\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Strips the `price` key from every `data-product-information` JSON attribute
 * in the rendered HTML when hidePrice is active for the current request.
 *
 * This is a response-level safety net for cases where the Twig override on
 * box-standard.html.twig does not reach the rendered output (e.g. when the
 * customer theme overrides the same template later in the inheritance chain).
 */
class DataProductInformationFilterSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            // Late priority so we run after the body has been fully rendered.
            KernelEvents::RESPONSE => ['onResponse', -128],
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
        $contentType = $response->headers->get('Content-Type') ?? '';
        if (!str_contains($contentType, 'text/html')) {
            return;
        }

        $html = $response->getContent();
        if (!is_string($html) || $html === '' || !str_contains($html, 'data-product-information=')) {
            return;
        }

        $patched = preg_replace_callback(
            '/data-product-information="([^"]*)"/',
            static function (array $match): string {
                $attr = $match[1];
                // Inside the attribute, JSON quotes are HTML-escaped as &quot;.
                // Strip ,"price":NUMBER and "price":NUMBER, (and bare "price":NUMBER if first key).
                $cleaned = preg_replace(
                    [
                        '/,&quot;price&quot;:-?[0-9.]+(?:[eE][+-]?[0-9]+)?/',
                        '/&quot;price&quot;:-?[0-9.]+(?:[eE][+-]?[0-9]+)?,/',
                        '/&quot;price&quot;:-?[0-9.]+(?:[eE][+-]?[0-9]+)?/',
                    ],
                    '',
                    $attr
                );
                return 'data-product-information="' . $cleaned . '"';
            },
            $html
        );

        if (is_string($patched) && $patched !== $html) {
            $response->setContent($patched);
        }
    }
}
