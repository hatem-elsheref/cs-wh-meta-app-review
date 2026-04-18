<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AdminAudit
{
    /**
     * @param  int|null  $actorUserId  Use when the acting user is not yet on the request (e.g. login).
     */
    public static function log(Request $request, string $action, ?Model $subject = null, array $properties = [], ?int $actorUserId = null): void
    {
        AuditLog::query()->create([
            'user_id' => $actorUserId ?? $request->user()?->id,
            'action' => $action,
            'subject_type' => $subject ? $subject->getMorphClass() : null,
            'subject_id' => $subject?->getKey(),
            'properties' => $properties === [] ? null : $properties,
            'ip' => $request->ip(),
            'user_agent' => self::truncateAgent($request->userAgent()),
        ]);
    }

    private static function truncateAgent(?string $agent): ?string
    {
        if ($agent === null || $agent === '') {
            return null;
        }

        return mb_substr($agent, 0, 512);
    }
}
