<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MetaSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function __invoke(Request $request)
    {
        $dbOk = true;
        $dbError = null;
        try {
            DB::select('select 1');
        } catch (\Throwable $e) {
            $dbOk = false;
            $dbError = $e->getMessage();
        }

        $metaConfigured = (bool) MetaSetting::query()->value('access_token');

        $ok = $dbOk;

        return response()->json([
            'ok' => $ok,
            'checks' => [
                'db' => ['ok' => $dbOk, 'error' => $dbError],
                'meta_configured' => ['ok' => $metaConfigured],
                'queue' => ['connection' => config('queue.default')],
            ],
        ], $ok ? 200 : 503);
    }
}

