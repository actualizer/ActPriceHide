<?php declare(strict_types=1);

namespace Act\PriceHide\Subscriber;

use Act\PriceHide\Service\HidePriceResolver;
use Shopware\Core\Content\Product\Events\ProductListingCriteriaEvent;
use Shopware\Core\Content\Product\Events\ProductSuggestCriteriaEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ProductListingCriteriaSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly HidePriceResolver $hideResolver) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ProductListingCriteriaEvent::class => 'onCriteria',
            ProductSuggestCriteriaEvent::class => 'onCriteria',
        ];
    }

    public function onCriteria(ProductListingCriteriaEvent $event): void
    {
        if (!$this->hideResolver->shouldHide($event->getSalesChannelContext())) {
            return;
        }
        $this->stripPriceAggregation($event->getCriteria());
    }

    private function stripPriceAggregation(Criteria $criteria): void
    {
        $kept = [];
        foreach ($criteria->getAggregations() as $agg) {
            $name = $agg->getName();
            if ($name === 'price' || $name === 'product.price') {
                continue;
            }
            $kept[] = $agg;
        }
        $criteria->resetAggregations();
        foreach ($kept as $agg) {
            $criteria->addAggregation($agg);
        }
    }
}
