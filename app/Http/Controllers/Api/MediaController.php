<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Services\MetaWhatsAppService;
use Illuminate\Support\Str;

class MediaController extends Controller
{
    public function __construct(private MetaWhatsAppService $metaService) {}

    public function download(int $id)
    {
        $message = Message::findOrFail($id);

        if (! $message->media_id) {
            return response()->json(['error' => 'No media for this message'], 404);
        }

        $range = request()->header('Range');
        $result = $this->metaService->fetchMedia($message->media_id, $range);
        if (! ($result['success'] ?? false)) {
            return response()->json(['error' => $result['error'] ?? 'Failed to download media'], 422);
        }

        $contentType = $result['content_type'] ?? 'application/octet-stream';
        $ext = match (true) {
            str_contains($contentType, 'image/jpeg') => 'jpg',
            str_contains($contentType, 'image/png') => 'png',
            str_contains($contentType, 'image/webp') => 'webp',
            str_contains($contentType, 'image/gif') => 'gif',
            str_contains($contentType, 'video/mp4') => 'mp4',
            str_contains($contentType, 'audio/ogg') => 'ogg',
            str_contains($contentType, 'audio/mpeg') => 'mp3',
            str_contains($contentType, 'application/pdf') => 'pdf',
            default => 'bin',
        };

        $base = $message->type === 'document'
            ? ($message->content ?: "document-{$message->id}")
            : "{$message->type}-{$message->id}";

        $safeBase = Str::slug(pathinfo($base, PATHINFO_FILENAME)) ?: "media-{$message->id}";
        $filename = "{$safeBase}.{$ext}";

        $status = (int) ($result['status'] ?? 200);
        $resp = response($result['body'] ?? '', $status)
            ->header('Content-Type', $contentType)
            ->header('Content-Disposition', 'inline; filename="'.$filename.'"')
            ->header('Cache-Control', 'private, max-age=3600')
            ->header('Accept-Ranges', 'bytes');

        foreach (($result['headers'] ?? []) as $k => $v) {
            $resp->header($k, $v);
        }

        return $resp;
    }
}

