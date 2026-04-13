<?php

namespace Tests\Feature;

use App\Models\ConversationState;
use App\Models\Flow;
use App\Services\FlowEngine;
use App\Services\MetaWhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class FlowEngineLanguageConditionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_condition_on_reserved_variable_reads_conversation_language(): void
    {
        $sent = null;
        $mock = Mockery::mock(MetaWhatsAppService::class);
        $mock->shouldReceive('sendMessage')->once()->withArgs(function ($phone, $msg) use (&$sent) {
            $sent = [$phone, $msg];

            return true;
        });
        $this->app->instance(MetaWhatsAppService::class, $mock);

        $flow = Flow::create([
            'nodes_json' => [
                ['id' => 'start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => ['welcomeText' => '']],
                ['id' => 'c1', 'type' => 'condition', 'position' => ['x' => 0, 'y' => 0], 'data' => [
                    'variable' => '__language', 'operator' => '==', 'value' => 'EN',
                ]],
                ['id' => 'm_en', 'type' => 'send_message', 'position' => ['x' => 0, 'y' => 0], 'data' => ['text' => 'EN_OK']],
                ['id' => 'm_ar', 'type' => 'send_message', 'position' => ['x' => 0, 'y' => 0], 'data' => ['text' => 'AR_OK']],
            ],
            'edges_json' => [
                ['id' => 'e1', 'source' => 'start', 'target' => 'c1', 'sourceHandle' => 'begin', 'targetHandle' => null],
                ['id' => 'e2', 'source' => 'c1', 'target' => 'm_en', 'sourceHandle' => 'true', 'targetHandle' => null],
                ['id' => 'e3', 'source' => 'c1', 'target' => 'm_ar', 'sourceHandle' => 'false', 'targetHandle' => null],
            ],
        ]);

        ConversationState::create([
            'phone' => '15550001',
            'flow_id' => $flow->id,
            'current_node_id' => 'start',
            'mode' => 'auto',
            'language' => 'EN',
            'variables' => ['__language' => 'AR'],
            'message_history' => [],
            'session_started_at' => now(),
        ]);

        app(FlowEngine::class)->processIncoming('15550001', ['text' => 'hi']);

        $this->assertSame(['15550001', 'EN_OK'], $sent);
    }

    public function test_condition_false_branch_when_language_not_matching(): void
    {
        $sent = null;
        $mock = Mockery::mock(MetaWhatsAppService::class);
        $mock->shouldReceive('sendMessage')->once()->withArgs(function ($phone, $msg) use (&$sent) {
            $sent = [$phone, $msg];

            return true;
        });
        $this->app->instance(MetaWhatsAppService::class, $mock);

        $flow = Flow::create([
            'nodes_json' => [
                ['id' => 'start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => ['welcomeText' => '']],
                ['id' => 'c1', 'type' => 'condition', 'position' => ['x' => 0, 'y' => 0], 'data' => [
                    'variable' => '__language', 'operator' => '==', 'value' => 'EN',
                ]],
                ['id' => 'm_en', 'type' => 'send_message', 'position' => ['x' => 0, 'y' => 0], 'data' => ['text' => 'EN_OK']],
                ['id' => 'm_ar', 'type' => 'send_message', 'position' => ['x' => 0, 'y' => 0], 'data' => ['text' => 'AR_OK']],
            ],
            'edges_json' => [
                ['id' => 'e1', 'source' => 'start', 'target' => 'c1', 'sourceHandle' => 'begin', 'targetHandle' => null],
                ['id' => 'e2', 'source' => 'c1', 'target' => 'm_en', 'sourceHandle' => 'true', 'targetHandle' => null],
                ['id' => 'e3', 'source' => 'c1', 'target' => 'm_ar', 'sourceHandle' => 'false', 'targetHandle' => null],
            ],
        ]);

        ConversationState::create([
            'phone' => '15550002',
            'flow_id' => $flow->id,
            'current_node_id' => 'start',
            'mode' => 'auto',
            'language' => 'AR',
            'variables' => [],
            'message_history' => [],
            'session_started_at' => now(),
        ]);

        app(FlowEngine::class)->processIncoming('15550002', ['text' => 'hi']);

        $this->assertSame(['15550002', 'AR_OK'], $sent);
    }
}
