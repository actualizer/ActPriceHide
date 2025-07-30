<?php declare(strict_types=1);

namespace Act\PriceHide;

use Act\PriceHide\Subscriber\StorefrontRenderSubscriber;
use Act\PriceHide\Subscriber\HeaderDataSubscriber;
use Shopware\Core\Framework\Plugin;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class ActPriceHide extends Plugin
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->autowire(StorefrontRenderSubscriber::class)
            ->addTag('kernel.event_subscriber');
            
        $container->autowire(HeaderDataSubscriber::class)
            ->addTag('kernel.event_subscriber');
    }

}
