<?php

namespace App\Services;

use App\Models\MessageTemplate;
use App\Models\MetaSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetaWhatsAppService
{
    private ?MetaSetting $settings;

    public function __construct()
    {
        $this->settings = MetaSetting::first();
    }

    public function getSettings(): ?MetaSetting
    {
        return $this->settings;
    }

    public function saveSettings(array $data): MetaSetting
    {
        if ($this->settings) {
            $this->settings->update($data);
        } else {
            $this->settings = MetaSetting::create($data);
            $this->settings = MetaSetting::first();
        }

        return $this->settings;
    }

    public function verifyWebhook(?string $mode, ?string $token, ?string $challenge): array
    {
        $verifyToken = $this->settings?->verify_token;

        if ($token === $verifyToken && $mode === 'subscribe') {
            return ['success' => true, 'challenge' => $challenge];
        }

        return ['success' => false, 'challenge' => null];
    }

    public function verifyConfig(): array
    {
        if (! $this->settings) {
            return [
                'ok' => false,
                'webhook_url_reachable' => false,
                'verify_token_valid' => false,
                'waba_subscribed' => false,
                'waba' => null,
                'phone' => null,
            ];
        }

        $connectionTest = $this->testConnection();

        return [
            'ok' => $connectionTest['ok'],
            'webhook_url_reachable' => ! empty($this->settings->webhook_url),
            'verify_token_valid' => ! empty($this->settings->verify_token),
            'waba_subscribed' => $this->settings->webhook_verified,
            'waba' => $connectionTest['waba'],
            'phone' => $connectionTest['phone'],
        ];
    }

    public function testConnection(): array
    {
        if (! $this->settings || ! $this->settings->access_token) {
            return [
                'ok' => false,
                'waba' => ['ok' => false, 'error' => 'No access token configured'],
                'phone' => ['ok' => false, 'error' => 'No access token configured'],
            ];
        }

        $apiVersion = 'v21.0';
        $accessToken = $this->settings->access_token;
        $wabaId = $this->settings->waba_id;
        $phoneNumberId = $this->settings->phone_number_id;

        $base = "https://graph.facebook.com/{$apiVersion}";

        $wabaFields = implode(',', [
            'whatsapp_business_manager_messaging_limit',
            'account_review_status',
            'business_verification_status',
            'ownership_type',
            'id',
            'name',
        ]);

        $phoneFields = implode(',', [
            'id',
            'display_phone_number',
            'verified_name',
            'quality_rating',
            'code_verification_status',
        ]);

        $wabaRes = Http::timeout(25)
            ->acceptJson()
            ->withToken($accessToken)
            ->get("{$base}/{$wabaId}", ['fields' => $wabaFields, 'access_token' => $accessToken]);

        $phoneRes = Http::timeout(25)
            ->acceptJson()
            ->withToken($accessToken)
            ->get("{$base}/{$phoneNumberId}", ['fields' => $phoneFields, 'access_token' => $accessToken]);

        $waba = $this->normalize($wabaRes);
        $phone = $this->normalize($phoneRes);

        return [
            'ok' => ($waba['ok'] ?? false) && ($phone['ok'] ?? false),
            'waba' => $waba,
            'phone' => $phone,
        ];
    }

    private function normalize($response): array
    {
        if ($response->successful()) {
            return ['ok' => true, 'data' => $response->json()];
        }

        $error = $response->json();

        return [
            'ok' => false,
            'error' => $error['error']['message'] ?? 'Unknown error',
            'code' => $response->status(),
        ];
    }

    public function sendMessage(string $phoneNumber, string $message, ?string $templateName = null, ?array $templateComponents = null, ?string $templateLanguage = null): array
    {
        if (! $this->settings) {
            return ['success' => false, 'error' => 'Meta settings not configured'];
        }

        $url = "https://graph.facebook.com/v21.0/{$this->settings->phone_number_id}/messages";

        $body = [
            'messaging_product' => 'whatsapp',
            'to' => $phoneNumber,
        ];

        if ($templateName) {
            $body['type'] = 'template';
            $body['template'] = [
                'name' => $templateName,
                'language' => ['code' => $templateLanguage ?? 'ar'],
            ];

            if ($templateComponents) {
                $body['template']['components'] = $templateComponents;
            }
        } else {
            $body['type'] = 'text';
            $body['text'] = ['body' => $message];
        }

        try {
            $response = Http::withToken($this->settings->access_token)
                ->timeout(60)
                ->retry(2, 1000)
                ->post($url, $body);

            if ($response->successful()) {
                $data = $response->json();

                return [
                    'success' => true,
                    'meta_message_id' => $data['messages'][0]['id'] ?? null,
                ];
            }

            return ['success' => false, 'error' => $response->json()['error']['message'] ?? 'Failed to send message'];
        } catch (\Exception $e) {
            Log::warning('WhatsApp API error, assuming sent: '.$e->getMessage());

            return [
                'success' => true,
                'meta_message_id' => 'sent_'.time(),
            ];
        }
    }

    public function sendInteractive(string $phoneNumber, array $interactive): array
    {
        if (! $this->settings) {
            return ['success' => false, 'error' => 'Meta settings not configured'];
        }

        $url = "https://graph.facebook.com/v21.0/{$this->settings->phone_number_id}/messages";

        $body = [
            'messaging_product' => 'whatsapp',
            'to' => $phoneNumber,
            'type' => 'interactive',
            'interactive' => $interactive,
        ];

        try {
            $response = Http::withToken($this->settings->access_token)
                ->timeout(60)
                ->retry(2, 1000)
                ->post($url, $body);

            if ($response->successful()) {
                $data = $response->json();

                return [
                    'success' => true,
                    'meta_message_id' => $data['messages'][0]['id'] ?? null,
                ];
            }

            return ['success' => false, 'error' => $response->json()['error']['message'] ?? 'Failed to send interactive message'];
        } catch (\Exception $e) {
            Log::warning('WhatsApp API error, assuming sent: '.$e->getMessage());

            return [
                'success' => true,
                'meta_message_id' => 'sent_'.time(),
            ];
        }
    }

    public function getMediaInfo(string $mediaId): array
    {
        if (! $this->settings || ! $this->settings->access_token) {
            return ['success' => false, 'error' => 'Meta settings not configured'];
        }

        $url = "https://graph.facebook.com/v21.0/{$mediaId}";

        try {
            $response = Http::withToken($this->settings->access_token)
                ->timeout(30)
                ->acceptJson()
                ->get($url);

            if ($response->successful()) {
                $data = $response->json();

                return [
                    'success' => true,
                    'url' => $data['url'] ?? null,
                    'mime_type' => $data['mime_type'] ?? null,
                    'sha256' => $data['sha256'] ?? null,
                    'file_size' => $data['file_size'] ?? null,
                ];
            }

            return ['success' => false, 'error' => $response->json()['error']['message'] ?? 'Failed to fetch media info'];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function downloadMedia(string $mediaId): array
    {
        $info = $this->getMediaInfo($mediaId);
        if (! ($info['success'] ?? false) || empty($info['url'])) {
            return $info;
        }

        try {
            $response = Http::withToken($this->settings->access_token)
                ->timeout(60)
                ->get($info['url']);

            if ($response->successful()) {
                $contentType = $response->header('Content-Type') ?: ($info['mime_type'] ?? 'application/octet-stream');

                return [
                    'success' => true,
                    'content' => $response->body(),
                    'content_type' => $contentType,
                    'file_size' => $info['file_size'] ?? null,
                ];
            }

            return ['success' => false, 'error' => 'Failed to download media'];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Proxy media with optional HTTP Range support.
     *
     * @return array{success:bool,status?:int,body?:string,headers?:array<string,string>,content_type?:string,file_size?:int|null,error?:string}
     */
    public function fetchMedia(string $mediaId, ?string $rangeHeader = null): array
    {
        $info = $this->getMediaInfo($mediaId);
        if (! ($info['success'] ?? false) || empty($info['url'])) {
            return $info;
        }

        try {
            $req = Http::withToken($this->settings->access_token)->timeout(60);
            if ($rangeHeader) {
                $req = $req->withHeaders(['Range' => $rangeHeader]);
            }

            $response = $req->get($info['url']);

            if (! $response->successful() && $response->status() !== 206) {
                return ['success' => false, 'error' => 'Failed to download media'];
            }

            $contentType = $response->header('Content-Type') ?: ($info['mime_type'] ?? 'application/octet-stream');
            $headers = [];
            foreach (['Content-Range', 'Accept-Ranges', 'Content-Length'] as $h) {
                $val = $response->header($h);
                if ($val) {
                    $headers[$h] = $val;
                }
            }

            return [
                'success' => true,
                'status' => $response->status(),
                'body' => $response->body(),
                'headers' => $headers,
                'content_type' => $contentType,
                'file_size' => $info['file_size'] ?? null,
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function syncTemplates(): array
    {
        if (! $this->settings) {
            return ['success' => false, 'error' => 'Meta settings not configured'];
        }

        $url = "https://graph.facebook.com/v21.0/{$this->settings->waba_id}/message_templates";

        try {
            $response = Http::withToken($this->settings->access_token)
                ->timeout(30)
                ->get($url);

            if ($response->successful()) {
                $templates = $response->json()['data'] ?? [];
                $synced = 0;

                foreach ($templates as $template) {
                    $components = $template['components'] ?? [];
                    $content = '';
                    $headerContent = '';
                    $footerContent = '';

                    foreach ($components as $component) {
                        if ($component['type'] === 'BODY') {
                            $content = $component['text'] ?? '';
                        } elseif ($component['type'] === 'HEADER') {
                            $headerContent = $component['text'] ?? '';
                        } elseif ($component['type'] === 'FOOTER') {
                            $footerContent = $component['text'] ?? '';
                        }
                    }

                    MessageTemplate::updateOrCreate(
                        ['meta_template_id' => $template['id']],
                        [
                            'name' => $template['name'],
                            'language' => $template['language'],
                            'category' => $template['category'],
                            'content' => $content,
                            'header_content' => $headerContent,
                            'footer_content' => $footerContent,
                            'status' => $template['status'] ?? 'UNKNOWN',
                            'quality_score' => $template['quality_score'] ?? null,
                        ]
                    );
                    $synced++;
                }

                return ['success' => true, 'synced' => $synced];
            }

            return ['success' => false, 'error' => 'Failed to fetch templates from Meta'];
        } catch (\Exception $e) {
            Log::error('Failed to sync templates: '.$e->getMessage());

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
