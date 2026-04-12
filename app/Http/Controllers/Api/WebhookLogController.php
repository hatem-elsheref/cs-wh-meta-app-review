<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WebhookLog;
use Illuminate\Http\Request;

class WebhookLogController extends Controller
{
    public function index(Request $request)
    {
        $query = WebhookLog::query()->orderBy('created_at', 'desc');

        if ($request->has('event_type')) {
            $query->where('event_type', $request->event_type);
        }

        if ($request->has('direction')) {
            $query->where('direction', $request->direction);
        }

        $logs = $query->paginate(50);

        return response()->json([
            'data' => $logs->items(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    public function show(int $id)
    {
        $log = WebhookLog::findOrFail($id);

        return response()->json([
            'data' => $log,
        ]);
    }
}