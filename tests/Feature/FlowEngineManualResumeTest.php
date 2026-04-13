<?php

namespace Tests\Feature;

use App\Models\ConversationState;
use App\Models\Flow;
use App\Services\FlowEngine;
use App\Services\MetaWhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class FlowEngineManualResumeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_manual_mode_keyword_resets_to_auto_and_restarts_flow(): void
    {
        $mock = Mockery::mock(MetaWhatsAppService::class);
        $mock->shouldReceive('sendInteractive')->once();
        $this->app->instance(MetaWhatsAppService::class, $mock);

        $flow = Flow::create([
            'nodes_json' => [
                ['id' => 'start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => ['welcomeText' => '']],
                ['id' => 'pick', 'type' => 'interactive_menu', 'position' => ['x' => 0, 'y' => 0], 'data' => [
                    'mode' => 'buttons',
                    'headerText' => '',
                    'bodyText' => 'Lang?',
                    'buttonLabel' => '',
                    'sections' => [],
                    'buttons' => [['id' => 'a', 'title' => 'A']],
                    'saveSelectionAs' => '',
                ]],
                ['id' => 'sw', 'type' => 'switch_mode', 'position' => ['x' => 0, 'y' => 0], 'data' => [
                    'mode' => 'manual',
                    'autoRevertMinutes' => 0,
                    'triggerWords' => 'stop,cancel,انهاء',
                ]],
            ],
            'edges_json' => [
                ['id' => 'e1', 'source' => 'start', 'target' => 'pick', 'sourceHandle' => 'begin', 'targetHandle' => null],
            ],
        ]);

        ConversationState::create([
            'phone' => '15559999',
            'flow_id' => $flow->id,
            'current_node_id' => 'pick',
            'mode' => 'manual',
            'language' => 'EN',
            'variables' => ['order_number' => '123'],
            'message_history' => [['role' => 'user', 'content' => 'old', 'timestamp' => now()->toISOString()]],
            'session_started_at' => now()->subHour(),
            'awaiting_input' => null,
        ]);

        app(FlowEngine::class)->processIncoming('15559999', ['content' => 'please STOP now']);

        $state = ConversationState::where('phone', '15559999')->first();
        $this->assertSame('auto', $state->mode);
        $this->assertSame('pick', $state->current_node_id);
        $this->assertSame([], $state->variables ?? []);
        $this->assertSame([], $state->message_history ?? []);
        $this->assertNotNull($state->awaiting_input);
        $this->assertSame('pick', $state->awaiting_input['nodeId'] ?? null);
    }

    public function test_manual_mode_unrelated_message_still_ignored(): void
    {
        $mock = Mockery::mock(MetaWhatsAppService::class);
        $mock->shouldReceive('sendMessage')->never();
        $mock->shouldReceive('sendInteractive')->never();
        $this->app->instance(MetaWhatsAppService::class, $mock);

        $flow = Flow::create([
            'nodes_json' => [
                ['id' => 'start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => ['welcomeText' => '']],
                ['id' => 'sw', 'type' => 'switch_mode', 'position' => ['x' => 0, 'y' => 0], 'data' => [
                    'mode' => 'manual',
                    'autoRevertMinutes' => 0,
                    'triggerWords' => 'stop',
                ]],
            ],
            'edges_json' => [],
        ]);

        ConversationState::create([
            'phone' => '15558888',
            'flow_id' => $flow->id,
            'current_node_id' => 'start',
            'mode' => 'manual',
            'language' => 'EN',
            'variables' => [],
            'message_history' => [],
            'session_started_at' => now(),
        ]);

        app(FlowEngine::class)->processIncoming('15558888', ['content' => 'hello agent only']);

        $state = ConversationState::where('phone', '15558888')->first();
        $this->assertSame('manual', $state->mode);
    }

    public function test_builtin_close_resumes_without_custom_trigger_words(): void
    {
        $mock = Mockery::mock(MetaWhatsAppService::class);
        $mock->shouldReceive('sendInteractive')->once();
        $this->app->instance(MetaWhatsAppService::class, $mock);

        $flow = Flow::create([
            'nodes_json' => [
                ['id' => 'start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => ['welcomeText' => '']],
                ['id' => 'pick', 'type' => 'interactive_menu', 'position' => ['x' => 0, 'y' => 0], 'data' => [
                    'mode' => 'buttons',
                    'headerText' => '',
                    'bodyText' => 'Lang?',
                    'buttonLabel' => '',
                    'sections' => [],
                    'buttons' => [['id' => 'a', 'title' => 'A']],
                    'saveSelectionAs' => '',
                ]],
            ],
            'edges_json' => [
                ['id' => 'e1', 'source' => 'start', 'target' => 'pick', 'sourceHandle' => 'begin', 'targetHandle' => null],
            ],
        ]);

        ConversationState::create([
            'phone' => '15556666',
            'flow_id' => $flow->id,
            'current_node_id' => 'pick',
            'mode' => 'manual',
            'language' => 'EN',
            'variables' => [],
            'message_history' => [],
            'session_started_at' => now(),
        ]);

        app(FlowEngine::class)->processIncoming('15556666', ['content' => 'Please close the conversation']);

        $this->assertSame('auto', ConversationState::where('phone', '15556666')->first()->mode);
    }
}
