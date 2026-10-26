<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Setting;
use App\Services\Marketing\DiscountEntryVisibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

final class DiscountVisibilityController extends Controller
{
    public function updateDefault(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        Setting::put(DiscountEntryVisibility::SETTING, $data['enabled'] ? '1' : '0');

        return redirect('/admin/settings?tab=accounts#discount-entry')
            ->with('ok', __('marketing.visibility_saved'));
    }

    public function updateCustomer(Request $request, Customer $customer): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        abort_unless(Schema::hasColumn('customers', 'discount_entry_visibility'), 503);
        $data = $request->validate(['visibility' => ['required', Rule::in(DiscountEntryVisibility::MODES)]]);
        DB::transaction(function () use ($customer, $data) {
            Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail()
                ->forceFill(['discount_entry_visibility' => $data['visibility']])->save();
        });

        return redirect('/admin/customers/'.$customer->id.'#finance')
            ->with('ok', __('marketing.visibility_saved'));
    }
}
