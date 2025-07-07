<?php declare(strict_types=1);

namespace Act\PriceHide\Subscriber;

use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Storefront\Event\StorefrontRenderEvent;
use Symfony\Component\VarDumper\VarDumper;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\RouterInterface;
use Shopware\Core\Content\Product\Events\ProductListingResultEvent;
use Shopware\Core\Content\Product\Events\ProductSearchResultEvent;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

class StorefrontRenderSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly RouterInterface $router
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            StorefrontRenderEvent::class => 'onStorefrontRender',
            ProductListingResultEvent::class => 'onProductListingResult',
            ProductSearchResultEvent::class => 'onProductSearchResult'
        ];
    }

    public function onStorefrontRender(StorefrontRenderEvent $event): void
    {
        $salesChannelContext = $event->getSalesChannelContext();
        $customer = $salesChannelContext->getCustomer();
        $customerGroup = $salesChannelContext->getCurrentCustomerGroup();
        $request = $event->getRequest();

        // Get the customer group IDs that should show the price
        $showPriceCustomerGroups = $this->systemConfigService->get('ActPriceHide.config.customerGroups', $salesChannelContext->getSalesChannelId());
        
        // Ensure it's an array
        if (!is_array($showPriceCustomerGroups)) {
            $showPriceCustomerGroups = [];
        }
        
        // Check if debug mode is enabled in plugin config
        $debugMode = $this->systemConfigService->get('ActPriceHide.config.debugMode', $salesChannelContext->getSalesChannelId());

        // Dump the customer group for debugging purposes only if debug mode is enabled
        if ($debugMode) {
            VarDumper::dump([
                'customerGroupId' => $customerGroup->getId(),
                'customerGroupName' => $customerGroup->getName(),
                'showPriceCustomerGroups' => $showPriceCustomerGroups,
                'isEmptyShowPriceCustomerGroups' => empty($showPriceCustomerGroups),
                'isInShowPriceCustomerGroups' => in_array($customerGroup->getId(), $showPriceCustomerGroups)
            ]);
        }

        $page = $event->getParameters()['page'] ?? null;

        // Set request attributes for all requests (works for both normal and AJAX)
        $hidePrice = match (true) {
            empty($showPriceCustomerGroups) => ['hide' => true, 'reason' => $customer ? 'not_allowed' : 'not_logged_in'],
            !in_array($customerGroup->getId(), $showPriceCustomerGroups) => ['hide' => true, 'reason' => $customer ? 'not_allowed' : 'not_logged_in'],
            default => ['hide' => false, 'reason' => '']
        };
        
        $request->attributes->set('hidePrice', $hidePrice);
        
        // If the page is not available, we can't do anything.
        if ($page !== null) {
            $page->addExtension('hidePrice', new ArrayEntity($hidePrice));
            
            // Wenn es die Warenkorb-Seite ist und Preise versteckt sind, zur Login-Seite umleiten
            if ($hidePrice['hide'] && $request->getPathInfo() === '/checkout/cart') {
                $loginUrl = $this->router->generate('frontend.account.login.page', [
                    'redirectTo' => 'frontend.checkout.cart.page'
                ]);
                
                $event->setParameter('redirectUrl', $loginUrl);
            }
        }
    }

    public function onProductListingResult(ProductListingResultEvent $event): void
    {
        $this->handlePriceHiding($event->getSalesChannelContext(), $event->getRequest());
    }

    public function onProductSearchResult(ProductSearchResultEvent $event): void
    {
        $this->handlePriceHiding($event->getSalesChannelContext(), $event->getRequest());
    }

    private function handlePriceHiding(SalesChannelContext $salesChannelContext, Request $request): void
    {
        $customer = $salesChannelContext->getCustomer();
        $customerGroup = $salesChannelContext->getCurrentCustomerGroup();

        // Get the customer group IDs that should show the price
        $showPriceCustomerGroups = $this->systemConfigService->get('ActPriceHide.config.customerGroups', $salesChannelContext->getSalesChannelId());
        
        // Ensure it's an array
        if (!is_array($showPriceCustomerGroups)) {
            $showPriceCustomerGroups = [];
        }
        
        // Check if debug mode is enabled in plugin config
        $debugMode = $this->systemConfigService->get('ActPriceHide.config.debugMode', $salesChannelContext->getSalesChannelId());

        // Dump the customer group for debugging purposes only if debug mode is enabled
        if ($debugMode) {
            VarDumper::dump([
                'context' => 'AJAX Request',
                'customerGroupId' => $customerGroup->getId(),
                'customerGroupName' => $customerGroup->getName(),
                'showPriceCustomerGroups' => $showPriceCustomerGroups,
                'isEmptyShowPriceCustomerGroups' => empty($showPriceCustomerGroups),
                'isInShowPriceCustomerGroups' => in_array($customerGroup->getId(), $showPriceCustomerGroups)
            ]);
        }

        // Add hidePrice extension to the request attributes and as global twig variable
        $hidePrice = match (true) {
            empty($showPriceCustomerGroups) => ['hide' => true, 'reason' => $customer ? 'not_allowed' : 'not_logged_in'],
            !in_array($customerGroup->getId(), $showPriceCustomerGroups) => ['hide' => true, 'reason' => $customer ? 'not_allowed' : 'not_logged_in'],
            default => ['hide' => false, 'reason' => '']
        };
        
        $request->attributes->set('hidePrice', $hidePrice);
        $request->attributes->set('_hidePrice', $hidePrice); // Alternative key for templates
    }
}
