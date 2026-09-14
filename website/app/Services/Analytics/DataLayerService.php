<?php

namespace App\Services\Analytics;

use App\Models\Customer;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;

/**
 * سرویس یکپارچه‌سازی و مدیریت لایه دیتا (GA4 & GTM DataLayer) سرورنت.
 *
 * پوشش کامل کاتالوگ رویدادهای استاندارد تجارت الکترونیک، چرخه عمر مشتری و خدمات ابری.
 */
class DataLayerService
{
    public const SESSION_PURCHASE_KEY = 'servernet_analytics_purchase';
    public const SESSION_AUTH_KEY     = 'servernet_analytics_auth_event';
    public const SESSION_GENERIC_KEY  = 'servernet_analytics_generic_event';

    /**
     * ثبت و آماده‌سازی رویداد خرید (purchase) استاندارد GA4 E-Commerce.
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
                        'item_category' => (string) ($item->type ?? 'hosting_cloud'),
                    ];
                }
            }

            if (empty($items)) {
                $items[] = [
                    'item_id'       => (string) ($payment->invoice_id ?? $payment->id),
                    'item_name'     => 'خدمات میزبانی و سرور ابری سرورنت',
                    'price'         => (float) $payment->amount,
                    'quantity'      => 1,
                    'item_category' => 'cloud_service',
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

    /**
     * رویداد ثبت‌نام موفق کاربر (sign_up)
     */
    public static function flashSignUp($customer, string $method = 'mobile_otp'): void
    {
        try {
            session()->flash(self::SESSION_AUTH_KEY, [
                'event'   => 'sign_up',
                'method'  => $method,
                'user_id' => (string) ($customer->id ?? ''),
            ]);
        } catch (\Throwable) {
            // ایمن در برابر خطا
        }
    }

    /**
     * رویداد ورود موفق کاربر (login)
     */
    public static function flashLogin($customer, string $method = 'mobile_otp'): void
    {
        try {
            session()->flash(self::SESSION_AUTH_KEY, [
                'event'   => 'login',
                'method'  => $method,
                'user_id' => (string) ($customer->id ?? ''),
            ]);
        } catch (\Throwable) {
            // ایمن در برابر خطا
        }
    }

    /**
     * ثبت رویداد عمومی در سشن
     */
    public static function flashEvent(string $eventName, array $params = []): void
    {
        try {
            session()->flash(self::SESSION_GENERIC_KEY, array_merge([
                'event' => $eventName,
            ], $params));
        } catch (\Throwable) {
            // ایمن در برابر خطا
        }
    }
}
