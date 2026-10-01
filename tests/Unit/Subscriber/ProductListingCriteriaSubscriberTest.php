<?php declare(strict_types=1);

namespace Act\PriceHide\Tests\Unit\Subscriber;

use Act\PriceHide\Service\HidePriceResolver;
use Act\PriceHide\Subscriber\ProductListingCriteriaSubscriber;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerGroup\CustomerGroupEntity;
use Shopware\Core\Content\Product\Events\ProductListingCollectFilterEvent;
use Shopware\Core\Content\Product\Events\ProductListingCriteriaEvent;
use Shopware\Core\Content\Product\Events\ProductSearchCriteriaEvent;
use Shopware\Core\Content\Product\Events\ProductSuggestCriteriaEvent;
use Shopware\Core\Content\Product\SalesChannel\Listing\Filter;
use Shopware\Core\Content\Product\SalesChannel\Listing\FilterCollection;
use Shopware\Core\Content\Product\SalesChannel\Sorting\ProductSortingCollection;
use Shopware\Core\Content\Product\SalesChannel\Sorting\ProductSortingEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\FilterAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\TermsAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\EntityAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\MaxAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\StatsAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Query\ScoreQuery;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Test\Generator;
use Shopware\Core\Test\Stub\SystemConfigService\StaticSystemConfigService;
use Symfony\Component\HttpFoundation\Request;

class ProductListingCriteriaSubscriberTest extends TestCase
{
    private const GROUP_ID = '0193f0a3b9c47d2a8e6b1f5c3d9e7a41';

    public function testListensOnListingSearchAndSuggest(): void
    {
        $events = ProductListingCriteriaSubscriber::getSubscribedEvents();

        static::assertArrayHasKey(ProductListingCriteriaEvent::class, $events);
        static::assertArrayHasKey(ProductSearchCriteriaEvent::class, $events);
        static::assertArrayHasKey(ProductSuggestCriteriaEvent::class, $events);
        static::assertArrayHasKey(ProductListingCollectFilterEvent::class, $events);
    }

    public function testRemovesThePriceFilterBeforeItReachesTheCriteria(): void
    {
        $context = $this->context();
        $filters = new FilterCollection();
        $filters->add(new Filter(
            'price',
            true,
            [new StatsAggregation('price', 'product.cheapestPrice', true, true, false, false)],
            new RangeFilter('product.cheapestPrice', [RangeFilter::GTE => 30, RangeFilter::LTE => 40]),
            ['min' => 30.0, 'max' => 40.0],
        ));
        $filters->add(new Filter(
            'budget',
            true,
            [new MaxAggregation('budget', 'product.cheapestPrice')],
            new RangeFilter('product.cheapestPrice', [RangeFilter::LTE => 40]),
            [],
        ));
        $filters->add(new Filter(
            'manufacturer',
            false,
            [new EntityAggregation('manufacturer', 'product.manufacturerId', 'product_manufacturer')],
            new EqualsAnyFilter('product.manufacturerId', []),
            [],
        ));

        $this->subscriber($context, true)->onCollectFilter(new ProductListingCollectFilterEvent(new Request(), $filters, $context));

        static::assertSame(['manufacturer'], $filters->getKeys());
    }

    public function testKeepsThePriceFilterWhenPricesAreVisible(): void
    {
        $context = $this->context();
        $filters = new FilterCollection();
        $filters->add(new Filter(
            'price',
            false,
            [new StatsAggregation('price', 'product.cheapestPrice', true, true, false, false)],
            new RangeFilter('product.cheapestPrice', []),
            [],
        ));

        $this->subscriber($context, false)->onCollectFilter(new ProductListingCollectFilterEvent(new Request(), $filters, $context));

        static::assertSame(['price'], $filters->getKeys());
    }

    public function testStripsEveryPricePartFromTheSearchCriteria(): void
    {
        $context = $this->context();
        $criteria = new Criteria();
        $criteria->addAggregation(
            new StatsAggregation('price', 'product.cheapestPrice', true, true, false, false),
            new MaxAggregation('probe', 'cheapestPrice'),
            new FilterAggregation(
                'rating',
                new MaxAggregation('rating', 'product.ratingAverage'),
                [new RangeFilter('product.cheapestPrice', [RangeFilter::LTE => 40])],
            ),
            new TermsAggregation('shipping', 'product.shippingFree'),
        );
        $criteria->addFilter(
            new EqualsFilter('product.active', true),
            new RangeFilter('cheapestPrice', [RangeFilter::GTE => 30]),
            new MultiFilter(MultiFilter::CONNECTION_OR, [
                new EqualsFilter('product.shippingFree', true),
                new RangeFilter('product.price', [RangeFilter::LTE => 40]),
            ]),
        );
        $criteria->addPostFilter(
            new RangeFilter('product.cheapestPrice', [RangeFilter::LTE => 40]),
            new EqualsFilter('product.shippingFree', true),
        );
        $criteria->addQuery(
            new ScoreQuery(new EqualsFilter('product.name', 'box'), 100),
            new ScoreQuery(new RangeFilter('product.purchasePrices', [RangeFilter::LTE => 40]), 500),
        );

        $this->subscriber($context, true)->onCriteria(new ProductSearchCriteriaEvent(new Request(), $criteria, $context));

        static::assertSame(['shipping'], array_keys($criteria->getAggregations()));
        static::assertSame([['product.active']], array_map(static fn ($f) => $f->getFields(), $criteria->getFilters()));
        static::assertSame([['product.shippingFree']], array_map(static fn ($f) => $f->getFields(), $criteria->getPostFilters()));
        static::assertSame([['product.name']], array_map(static fn ($q) => $q->getFields(), $criteria->getQueries()));
    }

    public function testReplacesAPriceSortingWithTheFirstRemainingOne(): void
    {
        $context = $this->context();
        $sortings = new ProductSortingCollection([
            $this->sorting('name-asc', 'product.name', FieldSorting::ASCENDING),
            $this->sorting('price-asc', 'product.cheapestPrice', FieldSorting::ASCENDING),
            $this->sorting('price-desc', 'product.cheapestPrice', FieldSorting::DESCENDING),
        ]);
        $criteria = new Criteria();
        $criteria->addSorting(
            new FieldSorting('product.cheapestPrice', FieldSorting::ASCENDING),
            new FieldSorting('id', FieldSorting::ASCENDING),
        );
        $criteria->addExtension('sortings', $sortings);

        $this->subscriber($context, true)->onCriteria(new ProductListingCriteriaEvent(new Request(), $criteria, $context));

        static::assertSame(['name-asc'], array_values($sortings->map(static fn (ProductSortingEntity $s) => $s->getKey())));
        static::assertSame(
            ['product.name', 'id'],
            array_map(static fn (FieldSorting $s) => $s->getField(), $criteria->getSorting()),
        );
        static::assertSame(FieldSorting::ASCENDING, $criteria->getSorting()[0]->getDirection());
    }

    public function testKeepsANonPriceSortingAsRequested(): void
    {
        $context = $this->context();
        $sortings = new ProductSortingCollection([
            $this->sorting('name-asc', 'product.name', FieldSorting::ASCENDING),
            $this->sorting('topseller', 'product.sales', FieldSorting::DESCENDING),
        ]);
        $criteria = new Criteria();
        $criteria->addSorting(new FieldSorting('product.sales', FieldSorting::DESCENDING));
        $criteria->addExtension('sortings', $sortings);

        $this->subscriber($context, true)->onCriteria(new ProductListingCriteriaEvent(new Request(), $criteria, $context));

        static::assertCount(2, $sortings);
        static::assertSame('product.sales', $criteria->getSorting()[0]->getField());
    }

    public function testLeavesTheCriteriaUntouchedWhenPricesAreVisible(): void
    {
        $context = $this->context();
        $sortings = new ProductSortingCollection([
            $this->sorting('price-asc', 'product.cheapestPrice', FieldSorting::ASCENDING),
        ]);
        $criteria = new Criteria();
        $criteria->addAggregation(new StatsAggregation('price', 'product.cheapestPrice', true, true, false, false));
        $criteria->addPostFilter(new RangeFilter('product.cheapestPrice', [RangeFilter::LTE => 40]));
        $criteria->addSorting(new FieldSorting('product.cheapestPrice', FieldSorting::ASCENDING));
        $criteria->addExtension('sortings', $sortings);

        $this->subscriber($context, false)->onCriteria(new ProductSearchCriteriaEvent(new Request(), $criteria, $context));

        static::assertSame(['price'], array_keys($criteria->getAggregations()));
        static::assertCount(1, $criteria->getPostFilters());
        static::assertSame('product.cheapestPrice', $criteria->getSorting()[0]->getField());
        static::assertCount(1, $sortings);
    }

    private function subscriber(SalesChannelContext $context, bool $hide): ProductListingCriteriaSubscriber
    {
        $config = [
            'ActPriceHide.config.enabled' => true,
            'ActPriceHide.config.customerGroups' => [$hide ? '0193f0a3b9c47d2a8e6b1f5c3d9e7a42' : self::GROUP_ID],
        ];

        return new ProductListingCriteriaSubscriber(
            new HidePriceResolver(new StaticSystemConfigService([$context->getSalesChannelId() => $config])),
        );
    }

    private function sorting(string $key, string $field, string $order): ProductSortingEntity
    {
        $sorting = new ProductSortingEntity();
        $sorting->setId(md5($key));
        $sorting->setKey($key);
        $sorting->setFields([['field' => $field, 'order' => $order, 'priority' => 1, 'naturalSorting' => 0]]);

        return $sorting;
    }

    private function context(): SalesChannelContext
    {
        $group = new CustomerGroupEntity();
        $group->setId(self::GROUP_ID);

        return Generator::generateSalesChannelContext(currentCustomerGroup: $group);
    }
}
