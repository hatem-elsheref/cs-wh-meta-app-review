<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiSetting;
use Illuminate\Http\Request;

class AiSettingsController extends Controller
{
    public function index()
    {
        $s = AiSetting::query()->first();

        return response()->json([
            'data' => $s ? [
                'id' => $s->id,
                'provider' => $s->provider,
                'model' => $s->model,
                'api_key' => $s->api_key ? '***masked***' : null,
                'base_url' => $s->base_url,
                'default_language' => $s->default_language,
                'default_tone' => $s->default_tone,
                'system_prompt' => $s->system_prompt,
            ] : null,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'provider' => 'required|string|in:openai,anthropic,groq,gemini,custom',
            'model' => 'required|string',
            'api_key' => 'nullable|string',
            'base_url' => 'nullable|string',
            'default_language' => 'required|string',
            'default_tone' => 'required|string',
            'system_prompt' => 'nullable|string',
        ]);

        $s = AiSetting::query()->first() ?: new AiSetting();
        $s->fill($data);
        $s->save();

        return response()->json([
            'message' => 'AI settings saved',
            'data' => [
                'id' => $s->id,
            ],
        ]);
    }
}

