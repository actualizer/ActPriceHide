<?php declare(strict_types=1);

namespace Act\PriceHide\Tests\Unit\Template;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;

class DiscountBadgeTemplateTest extends TestCase
{
    private const CORE = __DIR__ . '/../../../../../../vendor/shopware/storefront/Resources/views/storefront/component/product/card/badges.html.twig';
    private const OVERRIDE = __DIR__ . '/../../../src/Resources/views/storefront/component/product/card/badges.html.twig';

    public function testDiscountBadgeStaysVisibleWhenPricesAreVisible(): void
    {
        $html = $this->render(new Request());

        static::assertStringContainsString('badge-discount', $html);
        static::assertStringContainsString('badge-topseller', $html);
    }

    public function testDiscountBadgeIsHiddenViaRequestAttribute(): void
    {
        $html = $this->render(new Request(attributes: ['hidePrice' => (object) ['hide' => true]]));

        static::assertStringNotContainsString('badge-discount', $html);
    }

    public function testDiscountBadgeIsHiddenViaPageExtension(): void
    {
        $html = $this->render(new Request(), ['extensions' => ['hidePrice' => (object) ['hide' => true]]]);

        static::assertStringNotContainsString('badge-discount', $html);
    }

    public function testOtherBadgesSurviveWhilePricesAreHidden(): void
    {
        $html = $this->render(new Request(attributes: ['hidePrice' => (object) ['hide' => true]]));

        static::assertStringContainsString('badge-topseller', $html);
        static::assertStringContainsString('badge-new', $html);
    }

    /**
     * @param array<string, mixed> $page
     */
    private function render(Request $request, array $page = []): string
    {
        $override = (string) file_get_contents(self::OVERRIDE);
        $override = (string) preg_replace("/sw_extends '[^']+'/", "extends 'core'", $override);

        $twig = new Environment(new ArrayLoader([
            'core' => (string) file_get_contents(self::CORE),
            'override' => $override,
        ]));
        $twig->addFilter(new TwigFilter('trans', static fn (string $s): string => $s));
        $twig->addFilter(new TwigFilter('sw_sanitize', static fn (string $s): string => $s, ['is_safe' => ['html']]));
        $twig->addGlobal('app', (object) ['request' => $request]);

        $price = (object) ['listPrice' => (object) ['percentage' => 20], 'regulationPrice' => null, 'unitPrice' => 8.0];
        $product = (object) [
            'calculatedPrice' => $price,
            'calculatedPrices' => new \ArrayObject(),
            'variantListingConfig' => null,
            'parentId' => null,
            'markAsTopseller' => true,
            'isNew' => true,
        ];

        return $twig->render('override', ['product' => $product, 'page' => (object) $page]);
    }
}
