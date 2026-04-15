<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\WebhookLog;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

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

        // If no explicit range was provided, fall back to period.
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

        $messagesQ = Message::query()->whereBetween('created_at', [$start, $end]);
        $webhooksQ = WebhookLog::query()->whereBetween('created_at', [$start, $end]);

        $direction = $request->query('direction'); // inbound|outbound
        $type = $request->query('type'); // text|template|image|...
        $statusFilter = $request->query('status'); // queued|sent|failed|...

        if (is_string($direction) && $direction !== '') {
            $messagesQ->where('direction', $direction);
        }
        if (is_string($type) && $type !== '') {
            $messagesQ->where('type', $type);
        }
        if (is_string($statusFilter) && $statusFilter !== '') {
            $messagesQ->where('status', $statusFilter);
        }

        // For “overview” numbers we still break down inbound/outbound from the (filtered) base query.
        $outbound = (clone $messagesQ)->where('direction', 'outbound');
        $inbound = (clone $messagesQ)->where('direction', 'inbound');

        $sentTotal = (clone $outbound)->count();
        $templateTotal = (clone $outbound)->where('type', 'template')->count();
        $freeTextTotal = (clone $outbound)->where('type', 'text')->count();

        $status = [
            'queued' => (clone $outbound)->where('status', 'queued')->count(),
            'sent' => (clone $outbound)->where('status', 'sent')->count(),
            'delivered' => (clone $outbound)->whereNotNull('delivered_at')->count(),
            'read' => (clone $outbound)->whereNotNull('read_at')->count(),
            'failed' => (clone $outbound)->where('status', 'failed')->count(),
        ];

        return response()->json([
            'period' => $period,
            'range' => [
                'start_utc' => $start->toIso8601String(),
                'end_utc' => $end->toIso8601String(),
            ],
            'messages' => [
                'inbound_total' => $inbound->count(),
                'outbound_total' => $sentTotal,
                'outbound_template_total' => $templateTotal,
                'outbound_text_total' => $freeTextTotal,
                'outbound_status' => $status,
            ],
            'webhooks' => [
                'incoming_total' => (clone $webhooksQ)->where('direction', 'inbound')->count(),
                'status_updates_total' => (clone $webhooksQ)->where('event_type', 'message_status')->count(),
                'received_messages_total' => (clone $webhooksQ)->where('event_type', 'message_received')->count(),
            ],
        ]);
    }
}

