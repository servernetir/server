<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Services\Cloud\CloudAddons;
use App\Services\Cloud\CloudStoreDraft;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CloudStoreGuestController extends Controller
{
    public function store(Request $request, CloudStoreDraft $draft): RedirectResponse
    {
        $data = $request->validate([
            'location' => ['required', 'string', 'max:32'],
            'plan' => ['required', 'string', 'max:96'],
            'image' => ['required', 'string', 'max:64'],
            'cycle' => ['required', 'string', Rule::in(CloudStoreController::cycles())],
            'billing_mode' => ['nullable', 'string', Rule::in(['cycle', 'hourly'])],
            'label' => ['nullable', 'string', 'max:64'],
            'extra_ipv4' => ['nullable', 'integer', 'min:0', 'max:'.CloudAddons::MAX_EXTRA_IP],
            'on_credit_out' => ['nullable', 'string', Rule::in(['suspend', 'convert', 'terminate'])],
        ]);
        $draft->keep($request, $data);
        $target = lroute('account.cloud.store', ['resume' => 1]);
        if (auth('customer')->check()) {
            return redirect($target)->header('Cache-Control', 'private, no-store');
        }
        $request->session()->put('url.intended', $target);

        return redirect(lroute('login'))->header('Cache-Control', 'private, no-store');
    }
}
