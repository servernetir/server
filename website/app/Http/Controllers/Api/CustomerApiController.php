<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * APIِ فقط‌خواندنیِ مشتری — نسخهٔ ۱.
 *
 * مشتری با توکن (هدرِ Authorization: Bearer sn_…) وضعیت حساب، سرویس‌ها،
 * فاکتورها و اعتبارش را می‌خواند. زیرساخت طوری است که «ساختِ سرویس/دامنه»
 * بعداً به‌صورتِ روت‌های نوشتنی (با ability جدا) اضافه شود.
 */
class CustomerApiController extends Controller
{
    private function customer(Request $request): Customer
    {
        return $request->attributes->get('api_customer');
    }

    public function me(Request $request): JsonResponse
    {
        $c = $this->customer($request);

        return response()->json(['ok' => true, 'data' => [
            'code'   => $c->code,
            'name'   => $c->displayName(),
            'email'  => $c->email,
            'phone'  => $c->phone,
            'status' => $c->status,
            'credit' => ['IRT' => $c->creditBalance('IRT')],
        ]]);
    }

    public function services(Request $request): JsonResponse
    {
        $c = $this->customer($request);

        $services = Schema::hasTable('services')
            ? $c->services()->orderByDesc('id')->get()->map(fn ($s) => [
                'id'       => $s->id,
                'name'     => $s->name,
                'status'   => $s->status,
                'price'    => $s->hasUnverifiedBillingTerms() ? null : (int) $s->price,
                'currency' => $s->currency_code,
                'cycle'    => $s->hasUnverifiedBillingTerms() ? null : $s->cycle,
                'billing_terms' => $s->hasUnverifiedBillingTerms() ? 'unverified' : 'recorded',
            ])
            : collect();

        return response()->json(['ok' => true, 'data' => $services->values()]);
    }

    public function invoices(Request $request): JsonResponse
    {
        $c = $this->customer($request);

        $invoices = $c->invoices()->orderByDesc('id')->limit(100)->get()->map(fn ($i) => [
            'number'    => $i->number,
            'kind'      => $i->kind,
            'total'     => (int) $i->total,
            'paid'      => (int) $i->paid,
            'status'    => $i->status,
            'currency'  => $i->currency_code,
            'issued_at' => optional($i->issued_at)->toIso8601String(),
        ]);

        return response()->json(['ok' => true, 'data' => $invoices->values()]);
    }

    public function credit(Request $request): JsonResponse
    {
        $c = $this->customer($request);

        return response()->json(['ok' => true, 'data' => [
            'balance' => ['IRT' => $c->creditBalance('IRT')],
        ]]);
    }
    /** Every product appears through its owned service; private provisioning data is excluded. */
    public function service(Request $request, string $service): JsonResponse
    {
        $s = $this->customer($request)->services()->findOrFail($service);
        return response()->json(['ok' => true, 'data' => [
            'id' => $s->id, 'name' => $s->name, 'product' => $s->kind(), 'status' => $s->status,
            'billing_mode' => $s->billing_mode, 'currency' => $s->currency_code,
            'price' => $s->hasUnverifiedBillingTerms() ? null : (int) $s->price,
            'tax_percent' => $s->hasUnverifiedBillingTerms() ? null : (int) $s->tax_percent,
            'cycle' => $s->hasUnverifiedBillingTerms() ? null : $s->cycle,
            'next_due_at' => $s->next_due_at?->toDateString(),
        ]]);
    }

    public function invoice(Request $request, string $invoice): JsonResponse
    {
        $i = $this->customer($request)->invoices()->with('items')->findOrFail($invoice);
        return response()->json(['ok' => true, 'data' => [
            'id' => $i->id, 'number' => $i->number, 'kind' => $i->kind, 'status' => $i->status,
            'currency' => $i->currency_code, 'subtotal' => (int) $i->subtotal, 'discount' => (int) $i->discount,
            'tax' => (int) $i->tax, 'total' => (int) $i->total, 'paid' => (int) $i->paid, 'due' => $i->due(),
            'issued_at' => $i->issued_at?->toIso8601String(), 'due_at' => $i->due_at?->toIso8601String(),
            'amount_exponent' => \App\Models\Currency::find($i->currency_code)?->exponent,
            'items' => $i->items->map(fn ($item) => ['title' => $item->title, 'description' => $item->description,
                'quantity' => $item->quantity, 'unit_price' => (int) $item->unit_price,
                'discount' => (int) $item->discount, 'tax_amount' => (int) $item->tax_amount, 'line_total' => (int) $item->line_total]),
        ]]);
    }

    public function wallet(Request $request): JsonResponse
    {
        $c = $this->customer($request);
        $wallet = app(\App\Services\Finance\Wallet::class);
        $currencies = \App\Models\CreditEntry::where('customer_id', $c->id)->distinct()->pluck('currency_code')->push('IRT')->unique();
        $balances = \Illuminate\Support\Facades\DB::transaction(function () use ($c, $wallet, $currencies) {
            Customer::whereKey($c->id)->lockForUpdate()->firstOrFail();
            return $currencies->mapWithKeys(function ($currency) use ($c, $wallet) {
                $balance = $wallet->balanceOf($c->id, $currency, currentRead: true);
                $reserved = $wallet->reservedOf($c->id, $currency, currentRead: true);
                return [$currency => ['amount_exponent' => \App\Models\Currency::find($currency)?->exponent,
                    'balance' => $balance, 'reserved' => $reserved, 'available' => $balance - $reserved]];
            });
        });
        return response()->json(['ok' => true, 'data' => $balances]);
    }

}
