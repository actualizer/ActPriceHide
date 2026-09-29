<?php declare(strict_types=1);

namespace Act\PriceHide\Service;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class HidePriceResolver
{
    public function __construct(private readonly SystemConfigService $systemConfig) {}

    public function shouldHide(SalesChannelContext $ctx): bool
    {
        // Only an explicit "off" opens the channel; a missing value keeps it closed (fail-closed).
        if ($this->systemConfig->get('ActPriceHide.config.enabled', $ctx->getSalesChannelId()) === false) {
            return false;
        }

        $groups = $this->systemConfig->get('ActPriceHide.config.customerGroups', $ctx->getSalesChannelId());
        if (!is_array($groups) || $groups === []) {
            return true;
        }
        return !in_array($ctx->getCurrentCustomerGroup()->getId(), $groups, true);
    }
}
