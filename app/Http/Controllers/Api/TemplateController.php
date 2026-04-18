<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MessageTemplate;
use App\Services\MetaWhatsAppService;
use App\Support\AdminAudit;
use Illuminate\Http\Request;

class TemplateController extends Controller
{
    public function __construct(private MetaWhatsAppService $metaService) {}

    public function index()
    {
        $templates = MessageTemplate::orderBy('name')->get();

        return response()->json([
            'data' => $templates->map(fn($t) => array_merge($t->toArray(), [
                'parameters' => $t->parameters,
            ])),
        ]);
    }

    public function sync(Request $request)
    {
        $result = $this->metaService->syncTemplates();

        if ($result['success']) {
            AdminAudit::log($request, 'templates.synced', null, [
                'synced' => $result['synced'] ?? 0,
            ]);

            return response()->json([
                'message' => "Synced {$result['synced']} templates successfully",
            ]);
        }

        return response()->json([
            'error' => $result['error'],
        ], 422);
    }

    public function show(int $id)
    {
        $template = MessageTemplate::findOrFail($id);

        return response()->json([
            'data' => array_merge($template->toArray(), [
                'parameters' => $template->parameters,
            ]),
        ]);
    }
}
