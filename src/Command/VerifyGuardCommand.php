<?php declare(strict_types=1);

namespace Act\PriceHide\Command;

use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Post-deploy sanity check. Fetches the given URL (or the first sales
 * channel domain if none is provided) and reports whether the dataLayer
 * price-leak guard is installed via the primary or fallback channel.
 *
 * Intended for CI / deploy pipelines and for manual verification when a
 * customer shop theme might be overriding the head block without calling
 * parent().
 */
#[AsCommand(
    name: 'act:price-hide:verify-guard',
    description: 'Verify the ActPriceHide dataLayer guard is installed on the storefront.',
)]
class VerifyGuardCommand extends Command
{
    public function __construct(private readonly EntityRepository $salesChannelDomainRepository)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'url',
            null,
            InputOption::VALUE_REQUIRED,
            'URL to check. Defaults to the first sales channel domain.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $url = $input->getOption('url');
        if (!is_string($url) || $url === '') {
            $url = $this->autodetectUrl();
        }
        if ($url === null) {
            $output->writeln('<error>No --url given and no sales channel domain found.</error>');
            return Command::FAILURE;
        }

        $output->writeln(sprintf('Checking: <info>%s</info>', $url));

        $html = @file_get_contents($url);
        if ($html === false) {
            $output->writeln('<error>HTTP fetch failed.</error>');
            return Command::FAILURE;
        }

        $inlinePresent = str_contains($html, 'data-act-price-leak-guard="ph"');
        $metaPresent = str_contains($html, 'act-price-leak-guard-ph-hide-all');

        if ($inlinePresent) {
            $output->writeln('<info>[OK] Guard active via primary channel (inline script in head).</info>');
            return Command::SUCCESS;
        }

        if ($metaPresent) {
            $output->writeln('<comment>[WARN] Guard active via fallback channel only (JS plugin). Inline script suppressed — check your theme for a layout/meta.html.twig override without parent().</comment>');
            return 1;
        }

        $output->writeln('<error>[FAIL] Guard NOT detected. Either priceLeakGuardEnabled is off in plugin config, or your theme overrides layout_head_meta_tags_charset without calling parent().</error>');
        return 2;
    }

    private function autodetectUrl(): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('salesChannel.typeId', Defaults::SALES_CHANNEL_TYPE_STOREFRONT));
        $criteria->addFilter(new EqualsFilter('salesChannel.active', true));
        $criteria->setLimit(1);

        $domain = $this->salesChannelDomainRepository->search($criteria, Context::createDefaultContext())->first();
        if ($domain === null || !method_exists($domain, 'getUrl')) {
            return null;
        }
        $url = $domain->getUrl();
        return is_string($url) && $url !== '' ? $url : null;
    }
}
