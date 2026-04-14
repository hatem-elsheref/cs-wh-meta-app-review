<?php

namespace Tests\Unit;

use App\Services\OrderTrackingService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderTrackingServiceTest extends TestCase
{
    public function test_track_order_rejects_non_digits(): void
    {
        Http::fake();
        $s = new OrderTrackingService;
        $r = $s->trackOrder('ABC12', '966500000000');
        $this->assertFalse($r['ok']);
        $this->assertArrayHasKey('res_ar', $r);
        $this->assertArrayHasKey('res_en', $r);
        Http::assertNothingSent();
    }

    public function test_track_order_ready_with_tracking_url(): void
    {
        Http::fake([
            'https://portal.isnaad.sa/api/order-tracking/2062380' => Http::response([
                'status' => true,
                'message' => 'data loaded successfully',
                'data' => [
                    'status' => 'ready',
                    'store' => 'Isnaad Store',
                    'order_status' => 'ready',
                    'carrier' => 'Aramex',
                    'tracking_number' => '45762223673',
                    'url' => 'https://www.aramex.com/track/results?ShipmentNumber=45762223673',
                ],
            ], 200),
        ]);

        $s = new OrderTrackingService;
        $r = $s->trackOrder('2062380', '966500000000');

        $this->assertTrue($r['ok']);
        $this->assertStringContainsString('2062380', $r['res_en']);
        $this->assertStringContainsString('Store: Isnaad Store', $r['res_en']);
        $this->assertStringContainsString('Order status: ready', $r['res_en']);
        $this->assertStringContainsString('Carrier: Aramex', $r['res_en']);
        $this->assertStringContainsString('Tracking number: 45762223673', $r['res_en']);
        $this->assertSame('ready', $r['tracking_status']);
        $this->assertStringContainsString('45762223673', (string) $r['tracking_url']);
        $this->assertSame('Isnaad Store', $r['store']);
        $this->assertSame('ready', $r['order_status']);
        $this->assertSame('Aramex', $r['carrier']);
        $this->assertSame('45762223673', $r['tracking_number']);
    }

    public function test_track_order_preparing(): void
    {
        Http::fake([
            'https://portal.isnaad.sa/api/order-tracking/100' => Http::response([
                'status' => true,
                'message' => 'data loaded successfully',
                'data' => ['status' => 'preparing', 'url' => null],
            ], 200),
        ]);

        $r = (new OrderTrackingService)->trackOrder('100', 'x');
        $this->assertTrue($r['ok']);
        $this->assertSame('preparing', $r['tracking_status']);
        $this->assertStringContainsString('working on it', strtolower($r['res_en']));
    }

    public function test_track_order_out_of_stock(): void
    {
        Http::fake([
            'https://portal.isnaad.sa/api/order-tracking/200' => Http::response([
                'status' => true,
                'data' => ['status' => 'out_of_stock', 'url' => null],
            ], 200),
        ]);

        $r = (new OrderTrackingService)->trackOrder('200', 'x');
        $this->assertFalse($r['ok']);
        $this->assertSame('out_of_stock', $r['tracking_status']);
        $this->assertStringContainsString('cannot start processing', strtolower($r['res_en']));
    }

    public function test_track_order_not_found(): void
    {
        Http::fake([
            'https://portal.isnaad.sa/api/order-tracking/999' => Http::response([
                'status' => true,
                'data' => ['status' => 'not_found', 'url' => null],
            ], 200),
        ]);

        $r = (new OrderTrackingService)->trackOrder('999', 'x');
        $this->assertFalse($r['ok']);
        $this->assertSame('not_found', $r['tracking_status']);
        $this->assertStringContainsString('correct order number or tracking number', strtolower($r['res_en']));
    }

    public function test_track_order_api_status_false_yields_not_found(): void
    {
        Http::fake([
            'https://portal.isnaad.sa/api/order-tracking/888' => Http::response([
                'status' => false,
                'message' => 'not found',
            ], 200),
        ]);

        $r = (new OrderTrackingService)->trackOrder('888', 'x');
        $this->assertFalse($r['ok']);
        $this->assertSame('not_found', $r['tracking_status']);
    }

    public function test_track_order_http_error(): void
    {
        Http::fake([
            'https://portal.isnaad.sa/api/order-tracking/777' => Http::response('', 503),
        ]);

        $r = (new OrderTrackingService)->trackOrder('777', 'x');
        $this->assertFalse($r['ok']);
        $this->assertSame('http_error', $r['tracking_status']);
    }

    public function test_check_order_returns_account_id_on_success(): void
    {
        Http::fake();
        $s = new OrderTrackingService;
        $r = $s->checkOrder('555', '966500000000');
        $this->assertTrue($r['ok']);
        $this->assertArrayHasKey('account_id', $r);
    }

    public function test_check_order_fails_when_order_ends_with_404(): void
    {
        Http::fake();
        $s = new OrderTrackingService;
        $r = $s->checkOrder('123404', '966500000000');
        $this->assertFalse($r['ok']);
    }

    public function test_order_missed_added_returns_shipping_number(): void
    {
        Http::fake([
            'https://portal.isnaad.sa/api/order-missed/2062380/10' => Http::response([
                'status' => true,
                'data' => [
                    'status' => 'added',
                    'shipping_number' => 123,
                    'result' => 'added',
                ],
            ], 200),
        ]);

        $r = (new OrderTrackingService)->orderMissed('2062380', '10', 'x');
        $this->assertTrue($r['ok']);
        $this->assertSame('added', $r['issue_status']);
        $this->assertSame(123, $r['shipping_number']);
    }
}
