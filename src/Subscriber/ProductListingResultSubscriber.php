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
        ];
    }

    public function onResult(ProductListingResultEvent $event): void
    {
        if (!$this->hideResolver->shouldHide($event->getSalesChannelContext())) {
            return;
        }

        // Null out calculated prices so Twig's data-product-information attribute
        // serializes as price=0 and no price data leaks into the DOM.
        $zero = $this->zeroPrice();
        foreach ($event->getResult()->getEntities() as $product) {
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
