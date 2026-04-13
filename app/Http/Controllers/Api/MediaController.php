<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Services\MetaWhatsAppService;

class MediaController extends Controller
{
    public function __construct(private MetaWhatsAppService $metaService) {}

    public function download(int $id)
    {
        $message = Message::findOrFail($id);

        if (! $message->media_id) {
            return response()->json(['error' => 'No media for this message'], 404);
        }

        $result = $this->metaService->downloadMedia($message->media_id);
        if (! ($result['success'] ?? false)) {
            return response()->json(['error' => $result['error'] ?? 'Failed to download media'], 422);
        }

        return response($result['content'], 200)
            ->header('Content-Type', $result['content_type'] ?? 'application/octet-stream');
    }
}

