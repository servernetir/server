<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BirthdayProductCost;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Birthday\BirthdayMessages;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BirthdayRewardsController extends Controller
{
    public function update(Request $request)
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $data = $request->validate(['enabled' => 'required|boolean', 'sms_enabled' => 'required|boolean']);
        abort_unless(app(\App\Services\Birthday\BirthdayProgram::class)->installed(), 503);
        // Pattern reference/approval are installed only after the owner's operator approval.
        if ($data['sms_enabled'] && (! $data['enabled'] || ! app(BirthdayMessages::class)->configured())) {
            throw ValidationException::withMessages(['sms_enabled' => __('birthday.pattern_pending')]);
        }
        DB::transaction(function () use ($data) {
            Setting::put('birthday_offer_enabled', $data['enabled'] ? '1' : '0');
            Setting::put('birthday_sms_enabled', $data['sms_enabled'] ? '1' : '0');
            if (! $data['enabled']) {
                \App\Models\BirthdayReward::whereNull('revoked_at')->where('expires_at', '>', now())
                    ->update(['revoked_at' => now(), 'updated_at' => now()]);
            }
        });

        return redirect('/admin/settings?tab=accounts#birthday-rewards')->with('ok', __('birthday.saved'));
    }

    public function cost(Request $request)
    {
        abort_unless($request->user()?->isAdmin(), 403);
        abort_unless(Schema::hasTable('birthday_product_costs'), 503);
        $data = $request->validate(['product_id' => 'required|integer|exists:products,id',
            'server_id' => 'nullable|integer|exists:servers,id',
            'cycle' => ['required', Rule::in(Service::cycles())], 'component' => 'required|in:service,setup',
            'currency_code' => 'required|in:IRT,USD,EUR,TRY', 'amount' => ['required', 'string', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/'],
            'reference' => 'required|string|max:255', 'valid_days' => 'required|integer|min:1|max:365']);
        $scale = $data['currency_code'] === 'IRT' ? 1 : 100;
        try {
            $amount = BigDecimal::of($data['amount'])->multipliedBy($scale)->toScale(0, RoundingMode::Unnecessary)->toInt();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['amount' => __('birthday.invalid_cost')]);
        }
        if ($amount < 1 || $amount > 1000000000000 || trim($data['reference']) === '') {
            throw ValidationException::withMessages(['amount' => __('birthday.invalid_cost')]);
        }
        $product = Product::findOrFail($data['product_id']);
        $serverId = $data['server_id'] ?? $product->server_id;
        $ruleKey = $product->id.':'.($serverId ?: 0).':'.$data['cycle'].':'.$data['component'];
        BirthdayProductCost::updateOrCreate(['rule_key' => $ruleKey],
            ['product_id' => $product->id, 'server_id' => $serverId, 'cycle' => $data['cycle'], 'component' => $data['component'],
            'currency_code' => $data['currency_code'], 'amount' => $amount, 'reference' => $data['reference'],
                'verified_by' => $request->user()->id, 'verified_at' => now(), 'valid_until' => now()->addDays($data['valid_days'])]);

        return redirect('/admin/settings?tab=accounts#birthday-costs')->with('ok', __('birthday.saved'));
    }
}
