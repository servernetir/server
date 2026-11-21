<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Admin\ImpersonateController;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\EmailMarketingPreference;
use App\Services\Marketing\EmailMarketingPreferences;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmailPreferencesController extends Controller
{
    public function show(Request $request, EmailMarketingPreferences $preferences)
    {
        abort_unless($preferences->ready(), 503);
        /** @var Customer $customer */
        $customer = Auth::guard('customer')->user();
        $preference = $preferences->preferenceFor($customer);

        return response()->view('account.email-preferences', AccountController::shell('email-preferences') + [
            'preference' => $preference,
            'eligible' => $preferences->eligible($customer),
            'mayOptIn' => $this->mayOptIn($request, $customer)
                && $preference?->state !== EmailMarketingPreference::STATE_WITHDRAWN
                && $preference?->suppressed_at === null,
            'emailVerified' => $customer->email_verified_at !== null,
            'canWithdraw' => filled($customer->email) && $this->recipientSession($request),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, EmailMarketingPreferences $preferences)
    {
        abort_unless($preferences->ready(), 503);
        /** @var Customer $customer */
        $customer = Auth::guard('customer')->user();
        $data = $request->validate([
            'action' => ['required', Rule::in(['subscribe', 'unsubscribe'])],
            'consent' => ['required_if:action,subscribe', 'accepted_if:action,subscribe'],
        ]);
        $grant = $data['action'] === 'subscribe';
        if (! $this->recipientSession($request) || ($grant && ! $this->mayOptIn($request, $customer))) {
            throw ValidationException::withMessages(['action' => __('email-marketing.not_eligible')]);
        }

        try {
            $preferences->setConsent($customer, $grant);
        } catch (DomainException) {
            // Do not disclose ownership collisions or internal suppression reasons.
            throw ValidationException::withMessages(['action' => __('email-marketing.not_eligible')]);
        }

        return redirect()->to(lroute('account.email-preferences'))
            ->with('ok', __('email-marketing.preference_saved'));
    }

    private function mayOptIn(Request $request, Customer $customer): bool
    {
        return (bool) config('email-marketing.enabled')
            && $customer->isActive() && $customer->email_verified_at !== null
            && $this->recipientSession($request);
    }

    private function recipientSession(Request $request): bool
    {
        return ! $request->session()->has(ImpersonateController::SESSION_KEY)
            && ! Auth::guard('web')->check();
    }
}
