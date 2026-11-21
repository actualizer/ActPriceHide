<?php declare(strict_types=1);

namespace Act\PriceHide\Tests\Unit\Template;

use PHPUnit\Framework\TestCase;
use Shopware\Storefront\Storefront;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;

class DiscountBadgeTemplateTest extends TestCase
{
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
     * Located through the Storefront bundle class, so it works with Shopware installed
     * as a package (vendor/shopware/storefront) and as a source checkout (src/Storefront).
     */
    private static function coreTemplate(): string
    {
        $bundleDir = \dirname((string) (new \ReflectionClass(Storefront::class))->getFileName());
        $path = $bundleDir . '/Resources/views/storefront/component/product/card/badges.html.twig';
        static::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * @param array<string, mixed> $page
     */
    private function render(Request $request, array $page = []): string
    {
        $override = (string) file_get_contents(self::OVERRIDE);
        $override = (string) preg_replace("/sw_extends '[^']+'/", "extends 'core'", $override);

        $twig = new Environment(new ArrayLoader([
            'core' => self::coreTemplate(),
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
