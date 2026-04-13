<?php

namespace Tests\Unit;

use App\Services\OrderTrackingService;
use PHPUnit\Framework\TestCase;

class OrderTrackingServiceTest extends TestCase
{
    public function test_track_order_rejects_non_digits(): void
    {
        $s = new OrderTrackingService;
        $r = $s->trackOrder('ABC12', '966500000000');
        $this->assertFalse($r['ok']);
        $this->assertArrayHasKey('res_ar', $r);
        $this->assertArrayHasKey('res_en', $r);
    }

    public function test_track_order_success_for_numeric_stub(): void
    {
        $s = new OrderTrackingService;
        $r = $s->trackOrder('12345', '966500000000');
        $this->assertTrue($r['ok']);
        $this->assertNotSame('', $r['res_en']);
    }

    public function test_track_order_fails_for_stub_not_found_prefix(): void
    {
        $s = new OrderTrackingService;
        $r = $s->trackOrder('99001', '966500000000');
        $this->assertFalse($r['ok']);
    }

    public function test_check_order_returns_account_id_on_success(): void
    {
        $s = new OrderTrackingService;
        $r = $s->checkOrder('555', '966500000000');
        $this->assertTrue($r['ok']);
        $this->assertArrayHasKey('account_id', $r);
    }

    public function test_check_order_fails_when_order_ends_with_404(): void
    {
        $s = new OrderTrackingService;
        $r = $s->checkOrder('123404', '966500000000');
        $this->assertFalse($r['ok']);
    }
}
