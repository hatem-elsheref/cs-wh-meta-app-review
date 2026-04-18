<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MetaSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

        $metaConfigured = false;
        try {
            $metaConfigured = (bool) MetaSetting::query()->first()?->access_token;
        } catch (\Throwable) {
            $metaConfigured = false;
        }

        $failedJobsCount = null;
        if ($dbOk && Schema::hasTable('failed_jobs')) {
            try {
                $failedJobsCount = (int) DB::table('failed_jobs')->count();
            } catch (\Throwable) {
                $failedJobsCount = null;
            }
        }

        $ok = $dbOk;

        return response()->json([
            'ok' => $ok,
            'checks' => [
                'db' => ['ok' => $dbOk, 'error' => $dbError],
                'meta_configured' => ['ok' => $metaConfigured],
                'queue' => [
                    'connection' => config('queue.default'),
                    'failed_jobs' => $failedJobsCount,
                ],
            ],
        ], $ok ? 200 : 503);
    }
}

