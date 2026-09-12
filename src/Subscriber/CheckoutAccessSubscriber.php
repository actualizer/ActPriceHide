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
 * Blocks the cart routes while prices are hidden. Cart markup renders from the
 * line-item and summary templates, which share no block with the product
 * templates this plugin overrides — suppressing the header cart button removes
 * the entry point, not the route.
 */
class CheckoutAccessSubscriber implements EventSubscriberInterface
{
    private const REDIRECT_ROUTE = 'frontend.checkout.cart.page';

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

        if ($route !== self::REDIRECT_ROUTE && !in_array($route, self::EMPTY_ROUTES, true)) {
            return;
        }

        $context = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT);
        if (!$context instanceof SalesChannelContext || !$this->hidePriceResolver->shouldHide($context)) {
            return;
        }

        if ($route !== self::REDIRECT_ROUTE) {
            $event->setResponse(new Response('', Response::HTTP_NO_CONTENT));

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
}
