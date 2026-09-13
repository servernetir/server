<?php

namespace App\Services\Analytics;

use App\Models\Payment;
use Illuminate\Support\Facades\Log;

/**
 * سرویس یکپارچه‌سازی و آماده‌سازی داده‌های لایه دیتا (DataLayer) برای GTM و GA4.
 *
 * قابلیت‌ها:
 *  - استخراج خودکار اقلام فاکتور (Invoice Items) مطابق استاندارد E-Commerce گوگل آنالیتیکس ۴
 *  - جلوگیری از ارسال رویدادهای تکراری خرید (Anti-duplicate tracking)
 *  - ثبت امن در نشست فلش (Session Flash) جهت خواندن در Layout اصلی Blade
 */
class DataLayerService
{
    public const SESSION_PURCHASE_KEY = 'servernet_analytics_purchase';

    /**
     * ثبت و آماده‌سازی رویداد خرید در سشن برای ارسال به دیتالایر.
     */
    public static function flashPurchase(Payment $payment): void
    {
        try {
            $invoice = $payment->relationLoaded('invoice') ? $payment->invoice : $payment->invoice()->first();
            $items = [];

            if ($invoice) {
                $invoiceItems = $invoice->relationLoaded('items') ? $invoice->items : $invoice->items()->get();
                foreach ($invoiceItems as $item) {
                    $items[] = [
                        'item_id'   => (string) ($item->id ?? $item->service_id ?? $item->title),
                        'item_name' => (string) $item->title,
                        'price'     => (float) ($item->unit_price ?? $item->line_total),
                        'quantity'  => (int) ($item->quantity ?? 1),
                    ];
                }
            }

            if (empty($items)) {
                $items[] = [
                    'item_id'   => (string) ($payment->invoice_id ?? $payment->id),
                    'item_name' => 'خدمات میزبانی و سرور ابری سرورنت',
                    'price'     => (float) $payment->amount,
                    'quantity'  => 1,
                ];
            }

            $payload = [
                'event'     => 'purchase',
                'ecommerce' => [
                    'transaction_id' => (string) ($payment->ref_id ?: ('PAY-'.$payment->id)),
                    'value'          => (float) $payment->amount,
                    'currency'       => (string) ($payment->currency_code ?: 'IRT'),
                    'tax'            => (float) ($invoice?->tax ?? 0),
                    'items'          => $items,
                ],
            ];

            session()->flash(self::SESSION_PURCHASE_KEY, $payload);
        } catch (\Throwable $e) {
            Log::warning('خطا در آماده‌سازی دیتالایر خرید GA4', [
                'payment_id' => $payment->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
