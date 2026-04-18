<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MetricsController extends Controller
{
    public function __invoke(Request $request)
    {
        $now = CarbonImmutable::now('UTC');

        $period = (string) $request->query('period', 'today'); // today|week|month
        $startParam = $request->query('start');
        $endParam = $request->query('end');

        $start = null;
        $end = null;

        try {
            if (is_string($startParam) && $startParam !== '') {
                $start = CarbonImmutable::parse($startParam, 'UTC');
            }
            if (is_string($endParam) && $endParam !== '') {
                $end = CarbonImmutable::parse($endParam, 'UTC');
            }
        } catch (\Throwable) {
            return response()->json([
                'error' => 'Invalid start/end datetime. Use ISO-8601 UTC strings.',
                'code' => 'INVALID_RANGE',
            ], 422);
        }

        if (! $start || ! $end) {
            $start = match ($period) {
                'week' => $now->startOfWeek(),
                'month' => $now->startOfMonth(),
                default => $now->startOfDay(),
            };
            $end = $now;
        }

        if ($start->greaterThan($end)) {
            return response()->json([
                'error' => 'Invalid range: start must be before end.',
                'code' => 'INVALID_RANGE',
            ], 422);
        }

        $direction = $request->query('direction');
        $type = $request->query('type');
        $statusFilter = $request->query('status');

        $msgQuery = DB::table('messages')
            ->whereBetween('created_at', [$start, $end]);

        if (is_string($direction) && $direction !== '') {
            $msgQuery->where('direction', $direction);
        }
        if (is_string($type) && $type !== '') {
            $msgQuery->where('type', $type);
        }
        if (is_string($statusFilter) && $statusFilter !== '') {
            $msgQuery->where('status', $statusFilter);
        }

        $m = $msgQuery->selectRaw("
            SUM(CASE WHEN direction = 'inbound' THEN 1 ELSE 0 END) as inbound_total,
            SUM(CASE WHEN direction = 'outbound' THEN 1 ELSE 0 END) as outbound_total,
            SUM(CASE WHEN direction = 'outbound' AND type = 'template' THEN 1 ELSE 0 END) as outbound_template_total,
            SUM(CASE WHEN direction = 'outbound' AND type = 'text' THEN 1 ELSE 0 END) as outbound_text_total,
            SUM(CASE WHEN direction = 'outbound' AND status = 'queued' THEN 1 ELSE 0 END) as ob_queued,
            SUM(CASE WHEN direction = 'outbound' AND status = 'sent' THEN 1 ELSE 0 END) as ob_sent,
            SUM(CASE WHEN direction = 'outbound' AND delivered_at IS NOT NULL THEN 1 ELSE 0 END) as ob_delivered,
            SUM(CASE WHEN direction = 'outbound' AND read_at IS NOT NULL THEN 1 ELSE 0 END) as ob_read,
            SUM(CASE WHEN direction = 'outbound' AND status = 'failed' THEN 1 ELSE 0 END) as ob_failed
        ")->first();

        $w = DB::table('webhook_logs')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw("
                SUM(CASE WHEN direction = 'inbound' THEN 1 ELSE 0 END) as incoming_total,
                SUM(CASE WHEN event_type = 'message_status' THEN 1 ELSE 0 END) as status_updates_total,
                SUM(CASE WHEN event_type = 'message_received' THEN 1 ELSE 0 END) as received_messages_total
            ")->first();

        return response()->json([
            'period' => $period,
            'range' => [
                'start_utc' => $start->toIso8601String(),
                'end_utc' => $end->toIso8601String(),
            ],
            'messages' => [
                'inbound_total' => (int) ($m->inbound_total ?? 0),
                'outbound_total' => (int) ($m->outbound_total ?? 0),
                'outbound_template_total' => (int) ($m->outbound_template_total ?? 0),
                'outbound_text_total' => (int) ($m->outbound_text_total ?? 0),
                'outbound_status' => [
                    'queued' => (int) ($m->ob_queued ?? 0),
                    'sent' => (int) ($m->ob_sent ?? 0),
                    'delivered' => (int) ($m->ob_delivered ?? 0),
                    'read' => (int) ($m->ob_read ?? 0),
                    'failed' => (int) ($m->ob_failed ?? 0),
                ],
            ],
            'webhooks' => [
                'incoming_total' => (int) ($w->incoming_total ?? 0),
                'status_updates_total' => (int) ($w->status_updates_total ?? 0),
                'received_messages_total' => (int) ($w->received_messages_total ?? 0),
            ],
        ]);
    }
}
