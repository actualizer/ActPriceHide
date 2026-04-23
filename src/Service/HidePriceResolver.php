<?php declare(strict_types=1);

namespace Act\PriceHide\Service;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class HidePriceResolver
{
    public function __construct(private readonly SystemConfigService $systemConfig) {}

    public function shouldHide(SalesChannelContext $ctx): bool
    {
        $groups = $this->systemConfig->get('ActPriceHide.config.customerGroups', $ctx->getSalesChannelId());
        if (!is_array($groups) || $groups === []) {
            return true;
        }
        return !in_array($ctx->getCurrentCustomerGroup()->getId(), $groups, true);
    }
}
