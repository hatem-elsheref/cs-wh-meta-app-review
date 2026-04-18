<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Flow;
use App\Support\AdminAudit;
use Illuminate\Http\Request;

class FlowController extends Controller
{
    public function show()
    {
        $flow = Flow::query()->first();

        if (! $flow) {
            $flow = Flow::create([
                'nodes_json' => [
                    [
                        'id' => 'start',
                        'type' => 'start',
                        'position' => ['x' => 100, 'y' => 120],
                        'data' => ['welcomeText' => 'Welcome!'],
                    ],
                ],
                'edges_json' => [],
            ]);
        }

        return response()->json([
            'data' => [
                'id' => (string) $flow->id,
                'nodes' => $flow->nodes_json ?? [],
                'edges' => $flow->edges_json ?? [],
                'updatedAt' => optional($flow->updated_at)->toISOString(),
            ],
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'nodes' => 'required|array',
            'edges' => 'required|array',

            'nodes.*.id' => 'required|string',
            'nodes.*.type' => 'required|string',
            'nodes.*.position' => 'required|array',
            'nodes.*.position.x' => 'required|numeric',
            'nodes.*.position.y' => 'required|numeric',
            'nodes.*.data' => 'nullable|array',

            'edges.*.id' => 'required|string',
            'edges.*.source' => 'required|string',
            'edges.*.target' => 'required|string',
            'edges.*.sourceHandle' => 'nullable|string',
            'edges.*.targetHandle' => 'nullable|string',
        ]);

        $flow = Flow::query()->first();
        if (! $flow) {
            $flow = new Flow();
        }

        $flow->nodes_json = $data['nodes'];
        $flow->edges_json = $data['edges'];
        $flow->save();

        AdminAudit::log($request, 'flow.updated', $flow, [
            'nodes_count' => count($data['nodes']),
            'edges_count' => count($data['edges']),
        ]);

        return response()->json([
            'message' => 'Flow saved',
            'data' => [
                'id' => (string) $flow->id,
                'updatedAt' => optional($flow->updated_at)->toISOString(),
            ],
        ]);
    }
}

