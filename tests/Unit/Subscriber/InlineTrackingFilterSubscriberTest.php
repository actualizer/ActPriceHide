<?php declare(strict_types=1);

namespace Act\PriceHide\Tests\Unit\Subscriber;

use Act\PriceHide\Subscriber\InlineTrackingFilterSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class InlineTrackingFilterSubscriberTest extends TestCase
{
    public function testStripsPricesFromNestedJsonPayload(): void
    {
        $payload = '{"0193f0a3b9c47d2a8e6b1f5c3d9e7a41":{"item_id":"02698","item_name":"ASF 75 SC","price":349.0,'
            . '"listPrice":{"net":300.0,"gross":349.0},"extra":{"minPurchase":1,"realPrice":349.0,"item_startPrice":349.0}}}';
        $html = '<script type="text/javascript">window.ga4Product = ' . $payload . ';</script>';

        $filtered = $this->filter($html);

        static::assertSame(1, preg_match('/window\.ga4Product = (\{.*\});/s', $filtered, $match));
        $data = json_decode($match[1], true);
        static::assertIsArray($data);
        $item = $data['0193f0a3b9c47d2a8e6b1f5c3d9e7a41'];
        static::assertSame('02698', $item['item_id']);
        static::assertSame(['item_id', 'item_name', 'extra'], array_keys($item));
        static::assertSame(['minPurchase' => 1], $item['extra']);
    }

    public function testStripsPriceAttributesFromItemElements(): void
    {
        $html = '<span class="ga4-item" data-id="abc" data-item_id="02698" data-list-price="300"'
            . ' data-item_startPrice="349" data-price="349" data-currency="CHF"></span>';

        $filtered = $this->filter($html);

        static::assertSame('<span class="ga4-item" data-id="abc" data-item_id="02698" data-currency="CHF"></span>', $filtered);
    }

    public function testStripsPricesFromJsObjectLiterals(): void
    {
        $html = "<script>window.ga4Product['x'] = { item_brand: 'Canton', item_id: '02698', currency: 'CHF',"
            . " price: '349', extra: { minPurchase: '1', realPrice: '349', item_startPrice: '349' } };</script>";

        $filtered = $this->filter($html);

        static::assertStringNotContainsString('349', $filtered);
        static::assertStringContainsString("item_id: '02698'", $filtered);
        static::assertStringContainsString("currency: 'CHF'", $filtered);
        static::assertStringContainsString("minPurchase: '1'", $filtered);
    }

    public function testKeepsScriptEscapingWhenReencodingJson(): void
    {
        $html = '<script>window.items = {"a":{"item_id":"1","item_name":"<\/script><b>x","price":5,"extra":{"k":1}}};</script>';

        $filtered = $this->filter($html);

        static::assertSame(1, substr_count($filtered, '</script>'));
        static::assertStringNotContainsString('"price"', $filtered);
    }

    public function testKeepsScriptEscapingInFlatTrackingItems(): void
    {
        $html = '<script>gtag("event","view_item",{"items":[{"item_id":"1","item_name":"<\/script><img src=x onerror=alert(1)>","price":5}]});</script>';

        $filtered = $this->filter($html);

        static::assertSame(1, substr_count($filtered, '</script>'));
        static::assertStringNotContainsString('<img', $filtered);
        static::assertStringNotContainsString('"price"', $filtered);
    }

    public function testLeavesScriptsWithoutItemsUntouched(): void
    {
        $html = '<script>var item_id_hint = 1; var slider = { price: 5, value: 3 };</script>'
            . '<div data-price="5"></div>';

        static::assertSame($html, $this->filter($html));
    }

    public function testLeavesResponseUntouchedWhenPricesAreVisible(): void
    {
        $html = '<script>window.ga4Product = {"a":{"item_id":"1","price":5}};</script>';

        static::assertSame($html, $this->filter($html, false));
    }

    private function filter(string $html, bool $hide = true): string
    {
        $request = new Request();
        $request->attributes->set('hidePrice', ['hide' => $hide]);
        $response = new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);

        $event = new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        (new InlineTrackingFilterSubscriber())->onResponse($event);

        return (string) $response->getContent();
    }
}
