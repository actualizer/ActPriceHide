<?php declare(strict_types=1);

namespace Act\PriceHide\Subscriber;

use Act\PriceHide\Service\HidePriceResolver;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\PriceCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Content\Product\Events\ProductListingResultEvent;
use Shopware\Core\Content\Product\Events\ProductSearchResultEvent;
use Shopware\Core\Content\Product\Events\ProductSuggestResultEvent;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelEntityLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ProductListingResultSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly HidePriceResolver $hideResolver) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ProductListingResultEvent::class => 'onResult',
            ProductSearchResultEvent::class => 'onResult',
            ProductSuggestResultEvent::class => 'onResult',
            // Also catch products loaded outside standard listings (CMS product
            // sliders on the homepage, cross-selling, search suggest, etc.).
            'sales_channel.product.loaded' => 'onProductLoaded',
        ];
    }

    public function onResult(ProductListingResultEvent $event): void
    {
        if (!$this->hideResolver->shouldHide($event->getSalesChannelContext())) {
            return;
        }

        $this->zeroOut($event->getResult()->getEntities());
    }

    public function onProductLoaded(SalesChannelEntityLoadedEvent $event): void
    {
        if (!$this->hideResolver->shouldHide($event->getSalesChannelContext())) {
            return;
        }

        $this->zeroOut($event->getEntities());
    }

    /**
     * @param iterable<object> $entities
     */
    private function zeroOut(iterable $entities): void
    {
        $zero = $this->zeroPrice();
        foreach ($entities as $product) {
            if (!$product instanceof ProductEntity) {
                continue;
            }
            $product->setCalculatedPrice($zero);
            $product->setCalculatedPrices(new PriceCollection([]));
            $product->setCheapestPrice(null);
        }
    }

    private function zeroPrice(): CalculatedPrice
    {
        return new CalculatedPrice(0.0, 0.0, new CalculatedTaxCollection(), new TaxRuleCollection());
    }
}
