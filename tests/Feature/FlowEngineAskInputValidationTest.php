<?php

namespace Tests\Feature;

use App\Models\ConversationState;
use App\Models\Flow;
use App\Services\FlowEngine;
use App\Services\MetaWhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class FlowEngineAskInputValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_invalid_ask_input_sends_one_message_not_triple(): void
    {
        $mock = Mockery::mock(MetaWhatsAppService::class);
        $mock->shouldReceive('sendMessage')
            ->once()
            ->with('15557777', 'Error short only');
        $mock->shouldReceive('sendInteractive')->never();
        $this->app->instance(MetaWhatsAppService::class, $mock);

        $flow = Flow::create([
            'nodes_json' => [
                ['id' => 'start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => ['welcomeText' => '']],
                ['id' => 'ask', 'type' => 'ask_input', 'position' => ['x' => 0, 'y' => 0], 'data' => [
                    'questionText' => 'Question full prompt text',
                    'variableName' => 'store_id',
                    'validateType' => 'digits',
                    'errorMessage' => 'Error short only',
                ]],
                ['id' => 'after', 'type' => 'send_message', 'position' => ['x' => 0, 'y' => 0], 'data' => ['text' => 'Done']],
            ],
            'edges_json' => [
                ['id' => 'e1', 'source' => 'start', 'target' => 'ask', 'sourceHandle' => 'begin', 'targetHandle' => null],
                ['id' => 'e2', 'source' => 'ask', 'target' => 'after', 'sourceHandle' => 'answer', 'targetHandle' => null],
            ],
        ]);

        ConversationState::create([
            'phone' => '15557777',
            'flow_id' => $flow->id,
            'current_node_id' => 'ask',
            'mode' => 'auto',
            'language' => 'AR',
            'variables' => [],
            'message_history' => [],
            'session_started_at' => now()->subMinute(),
            'awaiting_input' => [
                'kind' => 'ask_input',
                'nodeId' => 'ask',
                'variableName' => 'store_id',
                'validateType' => 'digits',
                'errorMessage' => 'Error short only',
                'retries' => 0,
            ],
        ]);

        app(FlowEngine::class)->processIncoming('15557777', ['content' => 'Shared contact: someone']);

        $state = ConversationState::where('phone', '15557777')->first();
        $this->assertSame(1, (int) ($state->awaiting_input['retries'] ?? 0));
        $this->assertSame('ask', $state->current_node_id);
    }
}
