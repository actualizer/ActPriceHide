<?php declare(strict_types=1);

namespace Act\PriceHide\Subscriber;

use Act\PriceHide\Service\HidePriceResolver;
use Shopware\Core\Content\Product\Events\ProductListingCollectFilterEvent;
use Shopware\Core\Content\Product\Events\ProductListingCriteriaEvent;
use Shopware\Core\Content\Product\Events\ProductSearchCriteriaEvent;
use Shopware\Core\Content\Product\Events\ProductSuggestCriteriaEvent;
use Shopware\Core\Content\Product\SalesChannel\Listing\Filter as ListingFilter;
use Shopware\Core\Content\Product\SalesChannel\Sorting\ProductSortingCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Aggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\Filter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ProductListingCriteriaSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly HidePriceResolver $hideResolver) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ProductListingCollectFilterEvent::class => 'onCollectFilter',
            // Search and suggest dispatch under their own class name. Run late so
            // parts added by other listeners are covered as well.
            ProductListingCriteriaEvent::class => ['onCriteria', -1000],
            ProductSuggestCriteriaEvent::class => ['onCriteria', -1000],
            ProductSearchCriteriaEvent::class => ['onCriteria', -1000],
        ];
    }

    public function onCollectFilter(ProductListingCollectFilterEvent $event): void
    {
        if (!$this->hideResolver->shouldHide($event->getSalesChannelContext())) {
            return;
        }

        $filters = $event->getFilters();
        foreach ($filters->getElements() as $name => $filter) {
            if ($name === 'price' || self::filterTouchesPrice($filter)) {
                $filters->remove($name);
            }
        }
    }

    public function onCriteria(ProductListingCriteriaEvent $event): void
    {
        if (!$this->hideResolver->shouldHide($event->getSalesChannelContext())) {
            return;
        }

        $criteria = $event->getCriteria();
        $this->stripPriceParts($criteria);
        $this->stripPriceSorting($criteria);
    }

    // Request parameters can add filters, queries and aggregations on any field,
    // so every part is matched by field instead of by name.
    private function stripPriceParts(Criteria $criteria): void
    {
        $aggregations = array_filter(
            $criteria->getAggregations(),
            static fn (Aggregation $agg): bool => !\in_array($agg->getName(), ['price', 'product.price'], true)
                && !self::touchesPrice($agg->getFields()),
        );
        $criteria->resetAggregations();
        $criteria->addAggregation(...array_values($aggregations));

        $keep = static fn (Filter $filter): bool => !self::touchesPrice($filter->getFields());

        $filters = array_filter($criteria->getFilters(), $keep);
        $criteria->resetFilters();
        $criteria->addFilter(...array_values($filters));

        $postFilters = array_filter($criteria->getPostFilters(), $keep);
        $criteria->resetPostFilters();
        $criteria->addPostFilter(...array_values($postFilters));

        $queries = array_filter($criteria->getQueries(), $keep);
        $criteria->resetQueries();
        $criteria->addQuery(...array_values($queries));
    }

    private function stripPriceSorting(Criteria $criteria): void
    {
        $available = $criteria->getExtension('sortings');
        if ($available instanceof ProductSortingCollection) {
            foreach ($available->getElements() as $id => $sorting) {
                if (self::touchesPrice(array_column($sorting->getFields(), 'field'))) {
                    $available->remove($id);
                }
            }
        }

        $requested = $criteria->getSorting();
        $kept = array_filter(
            $requested,
            static fn (FieldSorting $sorting): bool => !self::touchesPrice([$sorting->getField()]),
        );
        if (\count($kept) === \count($requested)) {
            return;
        }

        // A price sorting leaves only its tie-breaker behind, so the whole order is replaced.
        $fallback = $available instanceof ProductSortingCollection ? $available->first() : null;

        $criteria->resetSorting();
        $criteria->addSorting(...($fallback?->createDalSorting() ?? array_values($kept)));
    }

    private static function filterTouchesPrice(ListingFilter $filter): bool
    {
        if (self::touchesPrice($filter->getFilter()->getFields())) {
            return true;
        }

        foreach ($filter->getAggregations() as $aggregation) {
            if (self::touchesPrice($aggregation->getFields())) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $fields
     */
    private static function touchesPrice(array $fields): bool
    {
        foreach ($fields as $field) {
            if (\is_string($field) && stripos($field, 'price') !== false) {
                return true;
            }
        }

        return false;
    }
}
