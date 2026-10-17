<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IdentityApplication;
use App\Models\IdentityRequest;
use App\Services\IdentityApi\Catalog;
use App\Services\IdentityApi\Controls;
use App\Services\IdentityApi\Engine;
use App\Services\IdentityApi\IdentityError;
use Illuminate\Http\Request;

class IdentityController extends Controller
{
    private function application(Request $request): IdentityApplication
    {
        if (! app(Controls::class)->ready()) {
            throw new IdentityError('service_unavailable', 503);
        }
        $token = $request->attributes->get('api_token');
        $a = IdentityApplication::where('customer_id', $token->customer_id)->find($token->identity_application_id);
        if (! $a || $a->status !== 'active') {
            throw new IdentityError('product_not_active', 403);
        }

        return $a;
    }

    public function services(Request $request, Catalog $catalog)
    {
        $this->application($request);

        return response()->json(['services' => $catalog->publicList()]);
    }

    public function inquire(Request $request, string $service, Engine $engine)
    {
        $data = $request->validate(['input' => ['required', 'array'], 'max_charge_irt' => ['required', 'integer', 'min:0', 'max:9000000000000'], 'price_version' => ['nullable', 'integer', 'min:1']]);
        $r = $engine->accept($this->application($request), $request->attributes->get('api_token'), $service, $data['input'], (string) $request->header('Idempotency-Key'), (int) $data['max_charge_irt'], isset($data['price_version']) ? (int) $data['price_version'] : null);
        $r = $engine->dispatch($r);

        return response()->json($r->receipt(), in_array($r->processing_status, ['accepted', 'processing', 'unknown'], true) ? 202 : 200);
    }

    public function show(Request $request, string $id)
    {
        $a = $this->application($request);
        $r = IdentityRequest::where('customer_id', $a->customer_id)->where('application_id', $a->id)->where('public_id', $id)->first();
        if (! $r) {
            throw new IdentityError('request_not_found', 404);
        }

        return response()->json($r->receipt());
    }
}
