<?php

namespace Tests\Feature;

use App\Models\Flow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FlowControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_cannot_access_flow_api(): void
    {
        $user = User::factory()->create(['role' => 'agent', 'status' => 'approved']);
        Sanctum::actingAs($user);

        $this->getJson('/api/flow')->assertForbidden();
    }

    public function test_admin_gets_default_flow_when_none_exists(): void
    {
        $user = User::factory()->admin()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/flow');

        $response->assertOk();
        $response->assertJsonPath('data.nodes.0.id', 'start');
        $response->assertJsonPath('data.nodes.0.type', 'start');
        $this->assertDatabaseCount('flows', 1);
    }

    public function test_admin_can_update_flow(): void
    {
        $user = User::factory()->admin()->create();
        Sanctum::actingAs($user);

        $nodes = [
            [
                'id' => 'start',
                'type' => 'start',
                'position' => ['x' => 0, 'y' => 0],
                'data' => ['welcomeText' => 'Hi'],
            ],
            [
                'id' => 'end_1',
                'type' => 'end_flow',
                'position' => ['x' => 200, 'y' => 0],
                'data' => ['closingText' => 'Bye'],
            ],
        ];
        $edges = [
            [
                'id' => 'e1',
                'source' => 'start',
                'target' => 'end_1',
                'sourceHandle' => 'begin',
                'targetHandle' => null,
            ],
        ];

        $response = $this->putJson('/api/flow', [
            'nodes' => $nodes,
            'edges' => $edges,
        ]);

        $response->assertOk();
        $response->assertJsonPath('message', 'Flow saved');

        $flow = Flow::query()->first();
        $this->assertNotNull($flow);
        $this->assertCount(2, $flow->nodes_json);
        $this->assertCount(1, $flow->edges_json);
    }

    public function test_flow_update_validation_requires_node_positions(): void
    {
        $user = User::factory()->admin()->create();
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/flow', [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'data' => []],
            ],
            'edges' => [],
        ]);

        $response->assertStatus(422);
    }
}
