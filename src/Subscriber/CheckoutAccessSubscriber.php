<?php declare(strict_types=1);

namespace Act\PriceHide\Subscriber;

use Act\PriceHide\Service\HidePriceResolver;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;

/**
 * Closes the whole purchase funnel while prices are hidden: cart display, cart
 * mutation and checkout. Guarding by route prefix rather than by a fixed list
 * keeps routes added by future Shopware versions covered by default.
 */
class CheckoutAccessSubscriber implements EventSubscriberInterface
{
    private const GUARDED_PREFIXES = ['frontend.checkout.', 'frontend.cart.'];

    /** Pages a visitor can land on directly, so they get somewhere they can act. */
    private const LOGIN_REDIRECT_ROUTES = [
        'frontend.checkout.cart.page',
        'frontend.checkout.confirm.page',
        'frontend.checkout.finish.page',
        'frontend.checkout.register.page',
    ];

    /** XHR fragments: a redirect would inject the login page into the offcanvas. */
    private const EMPTY_ROUTES = [
        'frontend.cart.offcanvas',
        'frontend.checkout.info',
    ];

    public function __construct(
        private readonly HidePriceResolver $hidePriceResolver,
        private readonly RouterInterface $router,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // kernel.response, not kernel.request: the SalesChannelContext is only
        // resolved on kernel.controller. Priority stays above the HTML price
        // filters at -128/-127, which need not run on a discarded response.
        return [
            KernelEvents::RESPONSE => ['onResponse', 0],
        ];
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $route = $request->attributes->get('_route');

        if (!\is_string($route) || !$this->isGuarded($route)) {
            return;
        }

        $context = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT);
        if (!$context instanceof SalesChannelContext || !$this->hidePriceResolver->shouldHide($context)) {
            return;
        }

        if (in_array($route, self::EMPTY_ROUTES, true)) {
            $event->setResponse(new Response('', Response::HTTP_NO_CONTENT));

            return;
        }

        if (!in_array($route, self::LOGIN_REDIRECT_ROUTES, true)) {
            // Cart mutation, order placement and cart.json. A redirect would be
            // followed as a GET, and cart.json would answer with an HTML page.
            $event->setResponse(new Response('Forbidden', Response::HTTP_FORBIDDEN));

            return;
        }

        // No redirectTo: AuthController::loginPage() hands a logged-in customer
        // straight back to that route, which for a customer outside the allowed
        // groups bounces between cart and login until the browser gives up.
        $loginUrl = $this->router->generate('frontend.account.login.page');

        // 302: the destination depends on plugin config and login state, a
        // permanent redirect would outlive both in browser and CDN caches.
        $event->setResponse(new RedirectResponse($loginUrl, Response::HTTP_FOUND));
    }

    private function isGuarded(string $route): bool
    {
        foreach (self::GUARDED_PREFIXES as $prefix) {
            if (str_starts_with($route, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
