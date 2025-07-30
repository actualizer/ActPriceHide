<?php declare(strict_types=1);

namespace Act\PriceHide;

use Act\PriceHide\Subscriber\StorefrontRenderSubscriber;
use Shopware\Core\Framework\Plugin;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class ActPriceHide extends Plugin
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->autowire(StorefrontRenderSubscriber::class)
            ->addTag('kernel.event_subscriber');
    }

}
