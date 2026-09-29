<?php declare(strict_types=1);

namespace Act\PriceHide\Tests\Unit\Service;

use Act\PriceHide\Service\HidePriceResolver;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerGroup\CustomerGroupEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Test\Generator;
use Shopware\Core\Test\Stub\SystemConfigService\StaticSystemConfigService;

class HidePriceResolverTest extends TestCase
{
    private const GROUP_ID = '0193f0a3b9c47d2a8e6b1f5c3d9e7a41';

    public function testHidesWhenSwitchIsMissingAndNoGroupIsAllowed(): void
    {
        $context = $this->context();

        static::assertTrue($this->resolver($context, [])->shouldHide($context));
    }

    public function testHidesNothingWhenSwitchedOffEvenWithoutAllowedGroups(): void
    {
        $context = $this->context();

        static::assertFalse($this->resolver($context, ['ActPriceHide.config.enabled' => false])->shouldHide($context));
    }

    public function testShowsPricesToAnAllowedGroupWhenSwitchedOn(): void
    {
        $context = $this->context();
        $config = [
            'ActPriceHide.config.enabled' => true,
            'ActPriceHide.config.customerGroups' => [self::GROUP_ID],
        ];

        static::assertFalse($this->resolver($context, $config)->shouldHide($context));
    }

    public function testHidesFromAGroupOutsideTheListWhenSwitchedOn(): void
    {
        $context = $this->context();
        $config = [
            'ActPriceHide.config.enabled' => true,
            'ActPriceHide.config.customerGroups' => ['0193f0a3b9c47d2a8e6b1f5c3d9e7a42'],
        ];

        static::assertTrue($this->resolver($context, $config)->shouldHide($context));
    }

    /**
     * @param array<string, mixed> $channelConfig
     */
    private function resolver(SalesChannelContext $context, array $channelConfig): HidePriceResolver
    {
        return new HidePriceResolver(new StaticSystemConfigService([$context->getSalesChannelId() => $channelConfig]));
    }

    private function context(): SalesChannelContext
    {
        $group = new CustomerGroupEntity();
        $group->setId(self::GROUP_ID);

        return Generator::generateSalesChannelContext(currentCustomerGroup: $group);
    }
}
