<?php declare(strict_types=1);

namespace Act\PriceHide\Subscriber;

use Act\PriceHide\Service\HidePriceResolver;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Event\StorefrontRenderEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Exposes dataLayer-guard configuration to Twig. The inline head script
 * and the JS-plugin fallback read these parameters to decide whether to
 * install a wrapper around window.dataLayer.push and what to strip.
 *
 * PriceHide gates per customer group (hide-all when user is not in the
 * allowed group) — there is no per-product id list. The `hideAll` flag
 * alone tells the guard to strip price / value / item_price / revenue
 * from every push once active.
 */
class PriceLeakGuardSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly HidePriceResolver $resolver,
        private readonly SystemConfigService $systemConfig,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            StorefrontRenderEvent::class => 'onRender',
        ];
    }

    public function onRender(StorefrontRenderEvent $event): void
    {
        $ctx = $event->getSalesChannelContext();

        // system_config returns null on fresh installs — honour the
        // config.xml defaultValue of true.
        $raw = $this->systemConfig->get(
            'ActPriceHide.config.priceLeakGuardEnabled',
            $ctx->getSalesChannelId(),
        );
        $enabled = $raw === null ? true : (bool) $raw;

        $event->setParameter('priceLeakGuardEnabled', $enabled);
        $event->setParameter('priceLeakGuardProtectedIds', []);
        $event->setParameter('priceLeakGuardHideAll', $enabled && $this->resolver->shouldHide($ctx));
    }
}
