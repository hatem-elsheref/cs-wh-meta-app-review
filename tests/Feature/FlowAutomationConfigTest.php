<?php

namespace Tests\Feature;

use App\Models\Flow;
use App\Services\FlowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

class FlowAutomationConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_flow_engine_does_not_touch_meta_when_automation_disabled(): void
    {
        Config::set('services.whatsapp.flow_automation_enabled', false);

        Flow::create([
            'nodes_json' => [
                ['id' => 'start', 'type' => 'start', 'data' => ['welcomeText' => 'Hello bot']],
            ],
            'edges_json' => [],
        ]);

        $mock = Mockery::mock(\App\Services\MetaWhatsAppService::class);
        $mock->shouldReceive('sendMessage')->never();
        $mock->shouldReceive('sendInteractive')->never();
        $this->app->instance(\App\Services\MetaWhatsAppService::class, $mock);

        $this->assertFalse(config('services.whatsapp.flow_automation_enabled'));

        $engine = $this->app->make(FlowEngine::class);
        $engine->processIncoming('966511111111', ['content' => 'Hi']);
    }
}
