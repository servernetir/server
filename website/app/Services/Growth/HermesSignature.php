<?php

namespace App\Services\Growth;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final class HermesSignature
{
    public function verify(Request $request): void
    {
        abort_if(filled($request->getQueryString()), 401);
        $key = Setting::getSecret('growth_hermes_key');
        $ts = $request->header('X-Growth-Time', '');
        $nonce = $request->header('X-Growth-Nonce', '');
        $sig = $request->header('X-Growth-Signature', '');
        abort_unless($key && strlen($key) >= 32 && preg_match('/^\d{10}$/D', $ts) && abs(now()->timestamp - (int) $ts) <= 120
            && preg_match('/^[a-zA-Z0-9_-]{24,80}$/D', $nonce) && preg_match('/^[a-f0-9]{64}$/D', $sig), 401);
        $message = $ts."\n".$nonce."\n".$request->method()."\n".$request->getPathInfo()."\n".hash('sha256', $request->getContent());
        abort_unless(hash_equals(hash_hmac('sha256', $message, $key), $sig), 401);
        abort_unless(Cache::add('growth-hermes-nonce:'.hash('sha256', $nonce), true, 300), 409, 'Request already accepted.');
    }
}
