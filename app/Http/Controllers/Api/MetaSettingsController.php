<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MetaWhatsAppService;
use App\Support\AdminAudit;
use Illuminate\Http\Request;

class MetaSettingsController extends Controller
{
    public function __construct(private MetaWhatsAppService $metaService) {}

    public function index()
    {
        $settings = $this->metaService->getSettings();

        return response()->json([
            'data' => $settings ? [
                'id' => $settings->id,
                'phone_number_id' => $settings->phone_number_id,
                'waba_id' => $settings->waba_id,
                'app_id' => $settings->app_id,
                'app_secret' => $settings->app_secret,
                'access_token' => $settings->access_token,
                'webhook_url' => $settings->webhook_url,
                'verify_token' => $settings->verify_token,
                'webhook_verified' => $settings->webhook_verified,
                'webhook_subscriptions' => $settings->webhook_subscriptions,
            ] : null,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'phone_number_id' => 'required|string',
            'waba_id' => 'required|string',
            'app_id' => 'required|string',
            'app_secret' => 'nullable|string',
            'access_token' => 'nullable|string',
            'webhook_url' => 'nullable|string',
            'verify_token' => 'nullable|string',
        ]);

        $settings = $this->metaService->saveSettings($data);

        AdminAudit::log($request, 'meta_settings.saved', $settings, [
            'phone_number_id' => $settings->phone_number_id,
            'waba_id' => $settings->waba_id,
        ]);

        return response()->json([
            'message' => 'Settings saved successfully',
            'data' => [
                'id' => $settings->id,
                'phone_number_id' => $settings->phone_number_id,
                'waba_id' => $settings->waba_id,
                'app_id' => $settings->app_id,
                'app_secret' => $settings->app_secret ? '***masked***' : null,
                'access_token' => $settings->access_token ? '***masked***' : null,
                'webhook_url' => $settings->webhook_url,
                'verify_token' => $settings->verify_token ? '***masked***' : null,
                'webhook_verified' => $settings->webhook_verified,
            ],
        ]);
    }

    public function verifyConfig()
    {
        $result = $this->metaService->verifyConfig();

        return response()->json([
            'data' => $result,
        ]);
    }

    public function getWebhookUrl()
    {
        $settings = $this->metaService->getSettings();
        $url = $settings?->webhook_url;

        return response()->json([
            'webhook_url' => $url,
        ]);
    }
}
