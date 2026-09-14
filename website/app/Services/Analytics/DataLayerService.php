<?php

namespace App\Services\Analytics;

use App\Models\Invoice;
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
            session()->flash(self::SESSION_PURCHASE_KEY, self::purchasePayload($payment));
        } catch (\Throwable $e) {
            // Analytics must never break settlement. Customer, gateway and
            // card data deliberately stay out of this diagnostic record.
            Log::warning('analytics.purchase_payload_failed', [
                'payment_id' => $payment->id,
                'invoice_id' => $payment->invoice_id,
                'exception'  => $e::class,
            ]);
        }
    }

    /** @return array{event:string,ecommerce:array<string,mixed>} */
    public static function purchasePayload(Payment $payment): array
    {
        $invoice = $payment->relationLoaded('invoice')
            ? $payment->invoice
            : $payment->invoice()->with('items')->first();

        [$currency, $multiplier] = self::normaliseCurrency(
            (string) ($payment->currency_code ?: $invoice?->currency_code ?: 'IRR')
        );
        $items = $invoice ? self::invoiceItems($invoice, $multiplier) : [];

        if ($items === []) {
            $items[] = [
                'item_id'       => 'invoice:'.(string) ($payment->invoice_id ?: 'unknown'),
                'item_name'     => 'ServerNet service',
                'item_category' => (string) ($invoice?->kind ?: 'service'),
                'price'         => (int) $payment->amount * $multiplier,
                'quantity'      => 1,
            ];
        }

        return [
            'event' => 'purchase',
            'ecommerce' => [
                // Internal payment ID is stable and idempotent; a gateway
                // reference may be recycled or reveal provider details.
                'transaction_id' => 'payment:'.(string) $payment->id,
                'value'          => (int) $payment->amount * $multiplier,
                'currency'       => $currency,
                'tax'            => (int) ($invoice?->tax ?? 0) * $multiplier,
                'items'          => $items,
            ],
        ];
    }

    /** Build a PII-free invoice-stage event using the purchase contract. */
    public static function invoicePayload(Invoice $invoice, string $event = 'view_cart'): array
    {
        [$currency, $multiplier] = self::normaliseCurrency((string) ($invoice->currency_code ?: 'IRR'));

        return [
            'event' => preg_match('/^[a-z][a-z0-9_]{1,39}$/', $event) ? $event : 'view_cart',
            'funnel_stage' => 'invoice',
            'ecommerce' => [
                'currency' => $currency,
                'value' => (int) $invoice->total * $multiplier,
                'items' => self::invoiceItems($invoice, $multiplier),
            ],
        ];
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

    /** @return array{0:string,1:int} */
    private static function normaliseCurrency(string $currency): array
    {
        $currency = strtoupper($currency);

        return $currency === 'IRT' ? ['IRR', 10] : [$currency ?: 'IRR', 1];
    }

    /** @return array<int,array<string,int|string>> */
    private static function invoiceItems(Invoice $invoice, int $multiplier): array
    {
        $items = [];
        $invoiceItems = $invoice->relationLoaded('items') ? $invoice->items : $invoice->items()->get();

        foreach ($invoiceItems as $item) {
            $quantity = max(1, (int) ($item->quantity ?: 1));
            $unitPrice = (int) ($item->unit_price ?: intdiv((int) $item->line_total, $quantity));
            $items[] = array_filter([
                'item_id'       => 'invoice_item:'.(string) $item->id,
                'item_name'     => mb_substr((string) $item->title, 0, 100),
                'item_category' => (string) ($invoice->kind ?: 'service'),
                'price'         => $unitPrice * $multiplier,
                'quantity'      => $quantity,
            ], static fn ($value) => $value !== '');
        }

        if ($items === []) {
            $items[] = [
                'item_id'       => 'invoice:'.(string) $invoice->id,
                'item_name'     => 'ServerNet service',
                'item_category' => (string) ($invoice->kind ?: 'service'),
                'price'         => (int) $invoice->total * $multiplier,
                'quantity'      => 1,
            ];
        }

        return $items;
    }
}
