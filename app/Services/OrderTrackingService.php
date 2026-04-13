<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Order / shipment lookups for WhatsApp flows via Isnaad portal API.
 *
 * @see https://portal.isnaad.sa/api/order-tracking/{order_id}
 */
class OrderTrackingService
{
    /**
     * Primary tracking by order number (digits only, per API path).
     *
     * @return array{ok: bool, res_ar: string, res_en: string, tracking_url?: string|null, tracking_status?: string}
     */
    public function trackOrder(string $orderNumber, string $waPhone): array
    {
        unset($waPhone);

        $orderNumber = trim($orderNumber);
        if ($orderNumber === '' || ! ctype_digit($orderNumber)) {
            return [
                'ok' => false,
                'res_ar' => 'رقم الطلب غير صالح. يرجى إرسال أرقام فقط بدون مسافات أو رموز.',
                'res_en' => 'Invalid order number. Please send digits only (no spaces or symbols).',
            ];
        }

        $base = (string) config('services.isnaad.order_tracking_base_url', '');
        if ($base === '') {
            return $this->serviceUnavailable();
        }

        $requestUrl = $base.'/'.$orderNumber;

        try {
            $response = Http::timeout(20)
                ->acceptJson()
                ->get($requestUrl);
        } catch (\Throwable $e) {
            Log::warning('OrderTrackingService: request failed', [
                'order' => $orderNumber,
                'error' => $e->getMessage(),
            ]);

            return $this->serviceUnavailable();
        }

        if (! $response->successful()) {
            Log::warning('OrderTrackingService: HTTP error', [
                'order' => $orderNumber,
                'status' => $response->status(),
            ]);

            return [
                'ok' => false,
                'res_ar' => 'تعذر الاتصال بخدمة التتبع حالياً. حاول لاحقاً أو تواصل مع الدعم.',
                'res_en' => 'We could not reach the tracking service. Please try again later or contact support.',
                'tracking_url' => null,
                'tracking_status' => 'http_error',
            ];
        }

        $json = $response->json();
        if (! is_array($json)) {
            return $this->unexpectedResponse();
        }

        $apiOk = $json['status'] ?? null;
        $data = $json['data'] ?? null;
        if (! is_array($data)) {
            if ($apiOk === false) {
                return $this->messagesForStatus('not_found', $orderNumber, null);
            }

            return $this->unexpectedResponse();
        }

        $status = strtolower((string) ($data['status'] ?? ''));
        $url = isset($data['url']) && is_string($data['url']) && $data['url'] !== '' ? $data['url'] : null;

        return $this->messagesForStatus($status, $orderNumber, $url);
    }

    /**
     * @return array{ok: bool, res_ar: string, res_en: string, tracking_url?: string|null, tracking_status?: string}
     */
    private function messagesForStatus(string $status, string $orderNumber, ?string $url): array
    {
        $n = $orderNumber;

        return match ($status) {
            'ready' => $url !== null
                ? [
                    'ok' => true,
                    'res_ar' => "طلبيتك *#{$n}* جاهزة للتتبع.\n\nرابط التتبع:\n{$url}",
                    'res_en' => "Your order *#{$n}* is ready to track.\n\nTracking link:\n{$url}",
                    'tracking_url' => $url,
                    'tracking_status' => 'ready',
                ]
                : [
                    'ok' => true,
                    'res_ar' => "طلبيتك *#{$n}* جاهزة، لكن رابط التتبع غير متوفر حالياً. تواصل معنا: +966 8001111905",
                    'res_en' => "Order *#{$n}* is ready, but the tracking link is not available yet. Contact us: +966 8001111905",
                    'tracking_url' => null,
                    'tracking_status' => 'ready',
                ],
            'preparing' => [
                'ok' => true,
                'res_ar' => "طلبيتك *#{$n}* قيد التجهيز حالياً.\n\nسنرسل رابط التتبع فور تجهيز الشحنة للإرسال.",
                'res_en' => "Your order *#{$n}* is being prepared.\n\nWe will send a tracking link as soon as your shipment is dispatched.",
                'tracking_url' => null,
                'tracking_status' => 'preparing',
            ],
            'out_of_stock' => [
                'ok' => false,
                'res_ar' => "طلبيتك *#{$n}*: للأسف الصنف غير متوفر حالياً (*نفاد مخزون*).\n\nيرجى التواصل معنا لترتيب البديل أو الاسترداد.",
                'res_en' => "Order *#{$n}*: this item is currently *out of stock*.\n\nPlease contact us to arrange a substitute or a refund.",
                'tracking_url' => null,
                'tracking_status' => 'out_of_stock',
            ],
            'not_found' => [
                'ok' => false,
                'res_ar' => "لم نعثر على طلبية برقم *#{$n}*.\n\nتأكد من الرقم أو تواصل معنا عبر https://www.isnaad.ai/contact",
                'res_en' => "We could not find an order with number *#{$n}*.\n\nCheck the number or reach us at https://www.isnaad.ai/contact",
                'tracking_url' => null,
                'tracking_status' => 'not_found',
            ],
            default => [
                'ok' => true,
                'res_ar' => "حالة طلبيتك *#{$n}*: {$status}. للتفاصيل تواصل مع الدعم.",
                'res_en' => "Order *#{$n}* status: {$status}. Contact support for details.",
                'tracking_url' => $url,
                'tracking_status' => $status !== '' ? $status : 'unknown',
            ],
        };
    }

    /**
     * @return array{ok: bool, res_ar: string, res_en: string, tracking_url: null, tracking_status: string}
     */
    private function serviceUnavailable(): array
    {
        return [
            'ok' => false,
            'res_ar' => 'خدمة التتبع غير مهيأة على الخادم. يرجى التواصل مع الدعم.',
            'res_en' => 'Tracking is not configured on this server. Please contact support.',
            'tracking_url' => null,
            'tracking_status' => 'unconfigured',
        ];
    }

    /**
     * @return array{ok: bool, res_ar: string, res_en: string, tracking_url: null, tracking_status: string}
     */
    private function unexpectedResponse(): array
    {
        return [
            'ok' => false,
            'res_ar' => 'استجابة غير متوقعة من خدمة التتبع. حاول مرة أخرى لاحقاً.',
            'res_en' => 'Unexpected response from the tracking service. Please try again later.',
            'tracking_url' => null,
            'tracking_status' => 'invalid_response',
        ];
    }

    /**
     * Secondary lookup when primary track fails; associates WhatsApp phone with an account id stub.
     *
     * @return array{ok: bool, res_ar: string, res_en: string, account_id?: string}
     */
    public function checkOrder(string $orderNumber, string $waPhone): array
    {
        $orderNumber = trim($orderNumber);
        if ($orderNumber === '' || ! ctype_digit($orderNumber)) {
            return [
                'ok' => false,
                'res_ar' => 'تعذر التحقق. أرسل رقم الطلبية بأرقام فقط.',
                'res_en' => 'Could not verify. Send the order number as digits only.',
            ];
        }

        $accountId = 'ACC-'.substr(hash('sha256', $waPhone.'|'.$orderNumber), 0, 10);

        if (str_ends_with($orderNumber, '404')) {
            return [
                'ok' => false,
                'res_ar' => 'لا توجد طلبية مطابقة في حسابك. تواصل معنا: https://www.isnaad.ai/contact',
                'res_en' => 'No matching order on file. Contact us: https://www.isnaad.ai/contact',
            ];
        }

        return [
            'ok' => true,
            'res_ar' => "تم ربط رقمك بالحساب *{$accountId}*. الطلبية *{$orderNumber}* مسجّلة وسيتم إرسال التفاصيل عند توفرها.",
            'res_en' => "Your chat is linked to account *{$accountId}*. Order *{$orderNumber}* is on file; we will send details when available.",
            'account_id' => $accountId,
        ];
    }
}
