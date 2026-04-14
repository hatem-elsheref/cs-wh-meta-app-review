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
    private const DEFAULT_TRACKING_BASE = 'https://portal.isnaad.sa/api/order-tracking';
    private const DEFAULT_ORDER_MISSED_BASE = 'https://portal.isnaad.sa/api/order-missed';

    /**
     * Short fallback shown when the order-issue API is unavailable.
     *
     * @return array{res_ar: string, res_en: string}
     */
    private function issueServiceFallbackCopy(): array
    {
        return [
            'res_ar' => "خدمة البلاغات قيد التطوير حالياً.\nنعمل على تحسينها لعملائنا.\nيرجى المحاولة لاحقاً.",
            'res_en' => "This service is currently under development.\nWe are enhancing it for our clients.\nPlease try again later.",
        ];
    }

    /**
     * Primary tracking by order number (digits only, per API path).
     *
     * @return array{ok: bool, res_ar: string, res_en: string, tracking_url?: string|null, tracking_status?: string, store?: string|null, order_status?: string|null, carrier?: string|null, tracking_number?: string|null}
     */
    public function trackOrder(string $orderNumber, string $waPhone): array
    {
        unset($waPhone);

        $orderNumber = trim($orderNumber);
        if ($orderNumber === '' || ! ctype_digit($orderNumber)) {
            return [
                'ok' => false,
                'res_ar' => 'رقم الطلب غير صالح. يرجى إرسال أرقام فقط.',
                'res_en' => 'Invalid order number. Please send digits only.',
            ];
        }

        $base = rtrim((string) config('services.isnaad.order_tracking_base_url', ''), '/');
        if ($base === '') {
            $base = rtrim(self::DEFAULT_TRACKING_BASE, '/');
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

            return [
                'ok' => false,
                'res_ar' => 'تعذر الوصول إلى خدمة التتبع حالياً. يرجى المحاولة لاحقاً.',
                'res_en' => 'Tracking service is currently unavailable. Please try again later.',
                'tracking_url' => null,
                'tracking_status' => 'request_failed',
            ];
        }

        if (! $response->successful()) {
            Log::warning('OrderTrackingService: HTTP error', [
                'order' => $orderNumber,
                'status' => $response->status(),
            ]);

            return [
                'ok' => false,
                'res_ar' => 'تعذر الوصول إلى خدمة التتبع حالياً. يرجى المحاولة لاحقاً.',
                'res_en' => 'Tracking service is currently unavailable. Please try again later.',
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
        $store = isset($data['store']) && is_string($data['store']) && $data['store'] !== '' ? $data['store'] : null;
        $orderStatus = isset($data['order_status']) && is_string($data['order_status']) && $data['order_status'] !== '' ? $data['order_status'] : null;
        $carrier = isset($data['carrier']) && is_string($data['carrier']) && $data['carrier'] !== '' ? $data['carrier'] : null;
        $trackingNumber = isset($data['tracking_number']) && is_string($data['tracking_number']) && $data['tracking_number'] !== '' ? $data['tracking_number'] : null;

        return $this->messagesForStatus($status, $orderNumber, $url, $store, $orderStatus, $carrier, $trackingNumber);
    }

    /**
     * @return array{ok: bool, res_ar: string, res_en: string, tracking_url?: string|null, tracking_status?: string, store?: string|null, order_status?: string|null, carrier?: string|null, tracking_number?: string|null}
     */
    private function messagesForStatus(
        string $status,
        string $orderNumber,
        ?string $url,
        ?string $store = null,
        ?string $orderStatus = null,
        ?string $carrier = null,
        ?string $trackingNumber = null
    ): array
    {
        $n = $orderNumber;

        return match ($status) {
            'ready' => $url !== null
                ? [
                    'ok' => true,
                    'res_ar' => "تفاصيل الطلب *#{$n}*:\nالمتجر: ".($store ?? 'غير محدد')."\nحالة الطلب: ".($orderStatus ?? 'غير محدد')."\nشركة الشحن: ".($carrier ?? 'غير محدد')."\nرقم التتبع: ".($trackingNumber ?? 'غير متوفر')."\nرابط التتبع: {$url}",
                    'res_en' => "Order details *#{$n}*:\nStore: ".($store ?? 'N/A')."\nOrder status: ".($orderStatus ?? 'N/A')."\nCarrier: ".($carrier ?? 'N/A')."\nTracking number: ".($trackingNumber ?? 'N/A')."\nTracking URL: {$url}",
                    'tracking_url' => $url,
                    'tracking_status' => 'ready',
                    'store' => $store,
                    'order_status' => $orderStatus,
                    'carrier' => $carrier,
                    'tracking_number' => $trackingNumber,
                ]
                : [
                    'ok' => true,
                    'res_ar' => "تفاصيل الطلب *#{$n}*:\nالمتجر: ".($store ?? 'غير محدد')."\nحالة الطلب: ".($orderStatus ?? 'غير محدد')."\nشركة الشحن: ".($carrier ?? 'غير محدد')."\nرقم التتبع: ".($trackingNumber ?? 'غير متوفر')."\nرابط التتبع غير متوفر حالياً.",
                    'res_en' => "Order details *#{$n}*:\nStore: ".($store ?? 'N/A')."\nOrder status: ".($orderStatus ?? 'N/A')."\nCarrier: ".($carrier ?? 'N/A')."\nTracking number: ".($trackingNumber ?? 'N/A')."\nTracking URL is not available yet.",
                    'tracking_url' => null,
                    'tracking_status' => 'ready',
                    'store' => $store,
                    'order_status' => $orderStatus,
                    'carrier' => $carrier,
                    'tracking_number' => $trackingNumber,
                ],
            'preparing' => [
                'ok' => true,
                'res_ar' => "تم استلام الطلب *#{$n}*، وجارٍ العمل عليه حالياً. سيتم شحنه في الوقت المحدد.",
                'res_en' => "We have received order *#{$n}* and are now working on it. It will be shipped within the estimated time.",
                'tracking_url' => null,
                'tracking_status' => 'preparing',
            ],
            'out_of_stock' => [
                'ok' => false,
                'res_ar' => "تم استلام الطلب *#{$n}*، ولكن لا يمكن البدء بالتجهيز أو الشحن حالياً لعدم توفر كمية كافية من أحد الأصناف. سنبدأ فور توفر الكمية من التاجر.",
                'res_en' => "We received order *#{$n}*, but we cannot start processing, packing, or shipping yet because one item does not have enough quantity. We will start once enough quantity is received from the merchant.",
                'tracking_url' => null,
                'tracking_status' => 'out_of_stock',
            ],
            'not_found' => [
                'ok' => false,
                'res_ar' => "تعذر العثور على الطلب *#{$n}*. يرجى التحقق من إدخال رقم الطلب أو رقم التتبع بشكل صحيح.",
                'res_en' => "We cannot find this order *#{$n}*. Please check and ensure you entered the correct order number or tracking number.",
                'tracking_url' => null,
                'tracking_status' => 'not_found',
            ],
            default => [
                'ok' => true,
                'res_ar' => "حالة الطلب *#{$n}*: {$status}.",
                'res_en' => "Order *#{$n}* status: {$status}.",
                'tracking_url' => $url,
                'tracking_status' => $status !== '' ? $status : 'unknown',
            ],
        };
    }

    /**
     * @return array{ok: bool, res_ar: string, res_en: string, tracking_url: null, tracking_status: string}
     */
    private function unexpectedResponse(): array
    {
        return [
            'ok' => false,
            'res_ar' => 'تم استلام استجابة غير متوقعة من خدمة التتبع.',
            'res_en' => 'Unexpected response from the tracking service.',
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
                'res_ar' => 'تعذر التحقق. يرجى إرسال رقم الطلب بأرقام فقط.',
                'res_en' => 'Could not verify. Please send the order number as digits only.',
            ];
        }

        $accountId = 'ACC-'.substr(hash('sha256', $waPhone.'|'.$orderNumber), 0, 10);

        if (str_ends_with($orderNumber, '404')) {
            return [
                'ok' => false,
                'res_ar' => 'لا يوجد طلب مطابق في حسابك.',
                'res_en' => 'No matching order was found in your account.',
            ];
        }

        return [
            'ok' => true,
            'res_ar' => "تم ربط محادثتك بالحساب *{$accountId}*. تم تسجيل الطلب *{$orderNumber}*.",
            'res_en' => "Your chat is linked to account *{$accountId}*. Order *{$orderNumber}* has been registered.",
            'account_id' => $accountId,
        ];
    }

    /**
     * Report an order issue (missed order) by order id + store id.
     *
     * Endpoint: GET https://portal.isnaad.sa/api/order-missed/{order_id}/{store_id}
     *
     * @return array{
     *   ok: bool,
     *   res_ar: string,
     *   res_en: string,
     *   issue_status?: string,
     *   shipping_number?: string|int|null,
     *   result?: string|null,
     *   order_number?: string,
     *   store_id?: string
     * }
     */
    public function orderMissed(string $orderNumber, string $storeId, string $waPhone): array
    {

//        return [
//            'status'            => 'added|already_exists|not_found|failed_to_add',
//            'shipping_number'   => 123 or null if not_found,
//    'result'            => 'we will put message here may be in en that identity the satte if added or already eists or failed to add wew ill mention th erason'
//];

        unset($waPhone);

        $orderNumber = trim($orderNumber);
        $storeId = trim($storeId);

        if ($orderNumber === '' || ! ctype_digit($orderNumber)) {
            return [
                'ok' => false,
                'res_ar' => 'رقم الطلب غير صالح. يرجى إرسال أرقام فقط.',
                'res_en' => 'Invalid order number. Please send digits only.',
                'order_number' => $orderNumber,
                'store_id' => $storeId,
            ];
        }

        if ($storeId === '' || ! ctype_digit($storeId)) {
            return [
                'ok' => false,
                'res_ar' => 'معرّف المتجر غير صالح. يرجى إرسال أرقام فقط.',
                'res_en' => 'Invalid store ID. Please send digits only.',
                'order_number' => $orderNumber,
                'store_id' => $storeId,
            ];
        }

        $base = rtrim((string) config('services.isnaad.order_missed_base_url', ''), '/');
        if ($base === '') {
            $base = rtrim(self::DEFAULT_ORDER_MISSED_BASE, '/');
        }

        $requestUrl = $base.'/'.$orderNumber.'/'.$storeId;

        try {
            $response = Http::timeout(20)->acceptJson()->get($requestUrl);
        } catch (\Throwable $e) {
            Log::warning('OrderTrackingService: orderMissed request failed', [
                'order' => $orderNumber,
                'store_id' => $storeId,
                'error' => $e->getMessage(),
            ]);

            $fallback = $this->issueServiceFallbackCopy();
            return [
                'ok' => false,
                'res_ar' => $fallback['res_ar'],
                'res_en' => $fallback['res_en'],
                'issue_status' => 'request_failed',
                'shipping_number' => null,
                'result' => null,
                'order_number' => $orderNumber,
                'store_id' => $storeId,
            ];
        }

        if (! $response->successful()) {
            $fallback = $this->issueServiceFallbackCopy();
            return [
                'ok' => false,
                'res_ar' => $fallback['res_ar'],
                'res_en' => $fallback['res_en'],
                'issue_status' => 'http_error',
                'shipping_number' => null,
                'result' => null,
                'order_number' => $orderNumber,
                'store_id' => $storeId,
            ];
        }

        $json = $response->json();
        if (! is_array($json)) {
            $fallback = $this->issueServiceFallbackCopy();
            return [
                'ok' => false,
                'res_ar' => $fallback['res_ar'],
                'res_en' => $fallback['res_en'],
                'issue_status' => 'invalid_response',
                'shipping_number' => null,
                'result' => null,
                'order_number' => $orderNumber,
                'store_id' => $storeId,
            ];
        }

        $data = $json['data'] ?? null;
        if (! is_array($data)) {
            $fallback = $this->issueServiceFallbackCopy();
            return [
                'ok' => false,
                'res_ar' => $fallback['res_ar'],
                'res_en' => $fallback['res_en'],
                'issue_status' => 'invalid_response',
                'shipping_number' => null,
                'result' => null,
                'order_number' => $orderNumber,
                'store_id' => $storeId,
            ];
        }

        $status = (string) ($data['status'] ?? '');
        $status = strtolower($status);
        $shippingNumber = $data['shipping_number'] ?? null;
        $result = isset($data['result']) && is_string($data['result']) ? $data['result'] : null;

        // Keep messages short and formal; no extra links.
        [$resAr, $resEn, $ok] = match ($status) {
            'added' => [
                "تم تسجيل البلاغ. رقم الشحن: ".($shippingNumber ?? '-').".",
                "Your request has been recorded. Shipping number: ".($shippingNumber ?? '-').".",
                true,
            ],
            'already_exists' => [
                "البلاغ مسجل مسبقاً. رقم الشحن: ".($shippingNumber ?? '-').".",
                "This request already exists. Shipping number: ".($shippingNumber ?? '-').".",
                true,
            ],
            'not_found' => [
                'لم يتم العثور على الطلب. يرجى التحقق من رقم الطلب ومعرّف المتجر.',
                'Order not found. Please verify the order number and store ID.',
                false,
            ],
            'failed_to_add' => [
                'تعذر تسجيل البلاغ حالياً.',
                'We could not record this request at the moment.',
                false,
            ],
            default => [
                'تعذر معالجة الطلب.',
                'We could not process this request.',
                false,
            ],
        };

        return [
            'ok' => $ok,
            'res_ar' => $resAr,
            'res_en' => $resEn,
            'issue_status' => $status !== '' ? $status : 'unknown',
            'shipping_number' => $shippingNumber,
            'result' => $result,
            'order_number' => $orderNumber,
            'store_id' => $storeId,
        ];
    }
}
