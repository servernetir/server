<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Auth\GoogleIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GoogleController extends Controller
{
    public function start(Request $request, GoogleIdentity $google)
    {
        abort_unless(GoogleIdentity::configured(), 404);
        $request->session()->forget(['login_otp', 'login_2fa', 'google_registration', 'reg']);
        $request->session()->regenerate();
        $phoneSignup = $request->boolean('phone') && config('google-auth.phone_enabled')
            && in_array(app()->getLocale(), ['en', 'tr'], true);
        $ctx = ['state' => bin2hex(random_bytes(32)), 'verifier' => bin2hex(random_bytes(32)),
            'redirect' => route($this->rp().'google.callback'), 'locale' => app()->getLocale(), 'created' => time()];
        $ctx['phone_signup'] = $phoneSignup;
        $request->session()->put('google_oauth', $ctx);
        return redirect()->away($google->authorizationUrl($ctx['state'], $ctx['verifier'], $ctx['redirect'], $phoneSignup));
    }

    public function phone(Request $request, GoogleIdentity $google)
    {
        abort_unless(GoogleIdentity::configured() && config('google-auth.phone_enabled')
            && in_array(app()->getLocale(), ['en', 'tr'], true), 404);
        $registration = $request->session()->get('google_registration');
        if (! is_array($registration) || ($registration['expires'] ?? 0) < time()
            || ($registration['locale'] ?? null) !== app()->getLocale()) {
            return redirect()->route($this->rp().'register');
        }
        $request->session()->regenerate();
        $ctx = ['state' => bin2hex(random_bytes(32)), 'verifier' => bin2hex(random_bytes(32)),
            'redirect' => route($this->rp().'google.callback'), 'locale' => app()->getLocale(),
            'created' => time(), 'phone' => true, 'subject' => $registration['sub']];
        $request->session()->put('google_oauth', $ctx);
        return redirect()->away($google->authorizationUrl($ctx['state'], $ctx['verifier'], $ctx['redirect'], true));
    }

    public function callback(Request $request, GoogleIdentity $google)
    {
        abort_unless(GoogleIdentity::configured(), 404);
        $ctx = $request->session()->pull('google_oauth');
        if (! is_array($ctx) || ! is_string($request->query('state'))
            || ! hash_equals($ctx['state'], $request->query('state'))
            || time() - $ctx['created'] > 600 || $ctx['locale'] !== app()->getLocale()
            || $request->has('error') || ! is_string($request->query('code')) || $request->query('code') === '') {
            return ! empty($ctx['phone']) ? $this->phoneResult($request) : $this->failed();
        }
        try {
            $identity = $google->exchange($request->query('code'), $ctx['verifier'], $ctx['redirect'],
                ! empty($ctx['phone']) || ! empty($ctx['phone_signup']));
            if (! empty($ctx['phone'])) {
                $registration = $request->session()->get('google_registration');
                if (! config('google-auth.phone_enabled') || ! is_array($registration)
                    || ($registration['expires'] ?? 0) < time() || ($registration['locale'] ?? null) !== app()->getLocale()
                    || ! in_array(app()->getLocale(), ['en', 'tr'], true)
                    || $registration['sub'] !== $ctx['subject'] || $identity['sub'] !== $registration['sub']
                    || $identity['email'] !== $registration['email']) {
                    return $this->phoneResult($request);
                }
                if (filled($identity['phone'] ?? null)) {
                    $registration['phone'] = $identity['phone'];
                    $request->session()->put('google_registration', $registration);
                    return $this->phoneResult($request, true);
                }
                return $this->phoneResult($request, false, $identity['phone_status'] ?? 'unavailable');
            }
            $customer = Customer::where('google_subject', $identity['sub'])->first();
            if ($customer === null) {
                $customer = Customer::where('email', $identity['email'])->first();
            }
            if ($customer !== null && $customer->status !== 'pending') {
                if (! $customer->isActive()) {
                    return redirect()->route($this->rp().'login')->withErrors(['identifier' => __('ui.auth_account_blocked')]);
                }
                if ($customer->ipBlocks($request->ip())) {
                    return redirect()->route($this->rp().'login')->withErrors(['identifier' => __('ui.auth_ip_blocked')]);
                }
                if (filled($customer->google_subject) && $customer->google_subject !== $identity['sub']) {
                    return $this->failed();
                }
                DB::transaction(function () use ($customer, $identity) {
                    $locked = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
                    if (! $locked->isActive() || (filled($locked->google_subject) && $locked->google_subject !== $identity['sub'])) {
                        throw new \RuntimeException('Customer changed during Google login');
                    }
                    $locked->forceFill(['google_subject' => $identity['sub'],
                        'email_verified_at' => $locked->email === $identity['email'] ? ($locked->email_verified_at ?? now()) : $locked->email_verified_at])->save();
                });
                return app(LoginController::class)->googleLogin($request, $customer->fresh());
            }
            $phoneStatus = $identity['phone_status'] ?? null;
            unset($identity['phone_status']);
            $identity['expires'] = time() + 1800;
            $identity['locale'] = app()->getLocale();
            $request->session()->put('google_registration', $identity);
            return redirect()->route($this->rp().'register')
                ->withInput(['email' => $identity['email'], 'first_name' => $identity['first_name'], 'last_name' => $identity['last_name']])
                ->with('reg_notice', __('google.verified').($phoneStatus !== null ? ' '.$this->phoneNotice($phoneStatus) : ''));
        } catch (\Throwable) {
            // Never log a provider exception containing codes, tokens or client credentials.
            return ! empty($ctx['phone']) ? $this->phoneResult($request) : $this->failed();
        }
    }

    private function phoneNotice(string $status): string
    {
        $status = in_array($status, ['received', 'not_granted', 'no_number', 'unavailable'], true) ? $status : 'unavailable';
        // Diagnostic outcome only: no customer identifiers, phone numbers, codes or tokens.
        Log::info('Google phone import result', ['outcome' => $status]);
        return __('google.phone_'.$status);
    }

    private function phoneResult(Request $request, bool $received = false, string $status = 'unavailable')
    {
        $registration = $request->session()->get('google_registration', []);
        return redirect()->route($this->rp().'register')->withInput([
            'first_name' => $registration['first_name'] ?? '', 'last_name' => $registration['last_name'] ?? '',
            'phone' => $registration['phone'] ?? '',
        ])->with('reg_notice', $this->phoneNotice($received ? 'received' : $status));
    }

    private function failed()
    {
        return redirect()->route($this->rp().'login')->withErrors(['identifier' => __('google.failed')]);
    }

    private function rp(): string
    {
        return \App\Providers\AppServiceProvider::LOCALES[app()->getLocale()] ?? '';
    }
}
