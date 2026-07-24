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
                'price'    => (int) $s->price,
                'currency' => $s->currency_code,
                'cycle'    => $s->cycle,
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
}
