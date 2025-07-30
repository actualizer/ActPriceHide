<?php declare(strict_types=1);

namespace Act\PriceHide\Subscriber;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Storefront\Event\StorefrontRenderEvent;
use Shopware\Core\Framework\Struct\ArrayStruct;

class HeaderDataSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly SystemConfigService $systemConfigService
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            StorefrontRenderEvent::class => 'onStorefrontRender',
        ];
    }

    public function onStorefrontRender(StorefrontRenderEvent $event): void
    {
        $salesChannelContext = $event->getSalesChannelContext();
        $customer = $salesChannelContext->getCustomer();
        $customerGroup = $salesChannelContext->getCurrentCustomerGroup();

        // Get the customer group IDs that should show the price
        $showPriceCustomerGroups = $this->systemConfigService->get(
            'ActPriceHide.config.customerGroups', 
            $salesChannelContext->getSalesChannelId()
        );
        
        // Ensure it's an array
        if (!is_array($showPriceCustomerGroups)) {
            $showPriceCustomerGroups = [];
        }

        // Determine if price should be hidden
        $hidePrice = match (true) {
            empty($showPriceCustomerGroups) => [
                'hide' => true, 
                'reason' => $customer ? 'not_allowed' : 'not_logged_in'
            ],
            !in_array($customerGroup->getId(), $showPriceCustomerGroups) => [
                'hide' => true, 
                'reason' => $customer ? 'not_allowed' : 'not_logged_in'
            ],
            default => ['hide' => false, 'reason' => '']
        };

        // Add hidePrice as global twig variable - this works in 6.7.1+
        $event->setParameter('hidePrice', new ArrayStruct($hidePrice));
        
        // Also set it in request attributes for backward compatibility
        $event->getRequest()->attributes->set('hidePrice', $hidePrice);
    }
}