<?php

namespace App\Services\Integrations\Whatsapp;

use App\Models\MetaSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetaSender implements WhatsappSender
{
    private const GRAPH_API_BASE = 'https://graph.facebook.com/v21.0';

    public function sendTemplate(string $phoneNumber, string $template, array $params = []): array
    {
        try {
            $credentials = $this->credentials();

            if (empty($credentials['access_token']) || empty($credentials['phone_number_id'])) {
                return [
                    'status' => false,
                    'error' => 'WhatsApp Meta credentials are not configured',
                ];
            }

            $url = sprintf('%s/%s/messages', self::GRAPH_API_BASE, $credentials['phone_number_id']);

            $response = Http::timeout(60)
                ->acceptJson()
                ->withToken($credentials['access_token'])
                ->post($url, $this->buildPayload($phoneNumber, $template, $params));

            if ($response->successful()) {
                return [
                    'status' => true,
                    'response' => (array) $response->json(),
                ];
            }

            return [
                'status' => false,
                'error' => $response->json()['error']['message'] ?? ('Meta API error: '.$response->status()),
            ];
        } catch (\Throwable $e) {
            Log::error('MetaSender: failed to send template', [
                'template' => $template,
                'phone' => $phoneNumber,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function sendTemplateMultipleRecipients(array $phoneNumbers, string $template, array $params = []): array
    {
        $results = [];
        $hasError = false;

        foreach ($phoneNumbers as $phoneNumber) {
            $result = $this->sendTemplate((string) $phoneNumber, $template, $params);
            $results[(string) $phoneNumber] = $result;
            if (! ($result['status'] ?? false)) {
                $hasError = true;
            }
        }

        return [
            'status' => ! $hasError,
            'results' => $results,
        ];
    }

    // -------------------------------------------------------------------------

    private function buildPayload(string $phoneNumber, string $template, array $params): array
    {
        $metaParams = $this->resolveParams($params);

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $this->normalizePhone($phoneNumber),
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => ['code' => $this->resolveLanguageCode($template)],
            ],
        ];

        if (! empty($metaParams)) {
            $payload['template']['components'] = [
                [
                    'type' => 'body',
                    'parameters' => $metaParams,
                ],
            ];
        }

        return $payload;
    }

    /**
     * Handles both param styles:
     *
     * Numeric (positional by 'name' value):
     *   [['name' => '1', 'value' => 'Hatem'], ['name' => '2', 'value' => 'SKU-123']]
     *   → sorted by name int value → {{1}}, {{2}}, ...
     *
     * Named (positional by insertion order):
     *   [['name' => 'shop_name', 'value' => 'Rose'], ['name' => 'order_number', 'value' => '99']]
     *   → kept in order → {{1}}, {{2}}, ...
     *
     * @param  array<int, array{name?:string|int, value?:mixed}>  $params
     * @return array<int, array{type:string,text:string}>
     */
    private function resolveParams(array $params): array
    {
        if (empty($params)) {
            return [];
        }

        $firstName = $params[0]['name'] ?? null;
        $isNumeric = is_numeric($firstName);

        if ($isNumeric) {
            usort($params, fn ($a, $b) => (int) ($a['name'] ?? 0) <=> (int) ($b['name'] ?? 0));
        }

        return array_map(fn (array $param) => [
            'type' => 'text',
            'text' => (string) ($param['value'] ?? ''),
        ], $params);
    }

    private function resolveLanguageCode(string $templateName): string
    {
        $map = [
            'low_stock_alert_notification' => 'en_US',
            'low_stock_notification' => 'en_US',
            'order_inventory_skus_not_exists_alerts' => 'en_US',
            'ar7b' => 'en_US',
            'onboarding_signoff' => 'en',
            'new_chat_v1' => 'en',
            'ribal' => 'ar',
            'welcome' => 'ar',
            'failed_to_create_order_in_shipedge' => 'ar',
            'is_out' => 'ar',
            'madi_altaib' => 'ar',
            'shipped_order_notification3_ar' => 'ar',
            'late_answer' => 'ar',
            'processing_order_notification_ar' => 'ar',
            'delivered_order_notification_ar' => 'ar',
            'isnaad_order_shipped_ar' => 'ar',
            'isnaad_order_delivered_feedback_ar' => 'ar',
        ];

        return $map[$templateName] ?? 'en_US';
    }

    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[\s\-\(\)]/', '', $phone) ?? $phone;

        if (str_starts_with($phone, '+')) {
            return ltrim($phone, '+');
        }

        if (str_starts_with($phone, '00')) {
            return substr($phone, 2);
        }

        return $phone;
    }

    private function credentials(): array
    {
        $settings = MetaSetting::first();
        if ($settings && $settings->access_token && $settings->phone_number_id) {
            return [
                'access_token' => $settings->access_token,
                'phone_number_id' => $settings->phone_number_id,
            ];
        }

        return [];
    }
}

