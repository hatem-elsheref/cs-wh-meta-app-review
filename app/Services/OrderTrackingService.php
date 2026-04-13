<?php

namespace App\Services;

/**
 * Order / shipment lookups for WhatsApp flows.
 * Replace internal stubs with HTTP clients or domain services as needed.
 */
class OrderTrackingService
{
    /**
     * Primary tracking by order number (digits only).
     *
     * @return array{ok: bool, res_ar: string, res_en: string}
     */
    public function trackOrder(string $orderNumber, string $waPhone): array
    {
        $orderNumber = trim($orderNumber);
        if ($orderNumber === '' || ! ctype_digit($orderNumber)) {
            return [
                'ok' => false,
                'res_ar' => 'رقم الطلب غير صالح. يرجى إرسال أرقام فقط بدون مسافات أو رموز.',
                'res_en' => 'Invalid order number. Please send digits only (no spaces or symbols).',
            ];
        }

        // Stub: treat numbers starting with 99 or equal to 0 as "not found" so the flow can run check_order.
        if ($orderNumber === '0' || str_starts_with($orderNumber, '99')) {
            return [
                'ok' => false,
                'res_ar' => 'لم نعثر على نتيجة مباشرة لهذا الرقم. جاري البحث بطريقة بديلة.',
                'res_en' => 'No direct match for this number. Trying an alternate lookup.',
            ];
        }

        // TODO: call warehouse / carrier API here; build status text for AR/EN.
        return [
            'ok' => true,
            'res_ar' => "تفاصيل الطلبية *{$orderNumber}*: قيد المعالجة والتتبع. آخر تحديث: في الطريق للتوصيل. للاستفسار: +966 8001111905",
            'res_en' => "Order *{$orderNumber}*: tracked — last update: out for delivery. Questions? +966 8001111905",
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

        // Stub: order numbers ending in 404 still not found.
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
