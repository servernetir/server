<?php

namespace App\Services\Cloud;

use Illuminate\Http\Request;

/** A short-lived configurator selection, never an order or authorization. */
class CloudStoreDraft
{
    public const SESSION = 'cloud_store_public_draft';
    public const FIELDS = ['location', 'plan', 'image', 'cycle', 'billing_mode', 'label', 'extra_ipv4', 'on_credit_out'];

    public function keep(Request $request, array $data): void
    {
        $request->session()->put(self::SESSION, [
            'expires_at' => now()->addMinutes(30)->timestamp,
            'data' => array_intersect_key($data, array_flip(self::FIELDS)),
        ]);
    }

    public function take(Request $request): ?array
    {
        $draft = $request->session()->pull(self::SESSION);
        if (! is_array($draft) || ($draft['expires_at'] ?? 0) < now()->timestamp || ! is_array($draft['data'] ?? null)) {
            return null;
        }

        return array_intersect_key($draft['data'], array_flip(self::FIELDS));
    }
}
