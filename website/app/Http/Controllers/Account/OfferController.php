<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\MarketingGrant;
use App\Models\MarketingPreference;
use App\Models\MarketingRedemption;
use App\Models\Payment;
use App\Services\Marketing\DiscountEngine;
use App\Services\Payment\PaymentService;
use App\Support\Jalali;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class OfferController extends Controller
{
    public function index()
    {
        $ready = Schema::hasTable('marketing_campaigns');
        $customer = Auth::guard('customer')->user();

        return view('account.offers', AccountController::shell('offers') + [
            'ready' => $ready, 'offers' => $ready ? MarketingGrant::where('customer_id', $customer->id)->latest()->paginate(20) : collect(),
            'preference' => $ready ? MarketingPreference::firstOrNew(['customer_id' => $customer->id]) : null,
            'redemptions' => $ready ? MarketingRedemption::where('customer_id', $customer->id)->get()->keyBy('grant_id') : collect(),
        ]);
    }

    public function preferences(Request $request)
    {
        abort_unless(Schema::hasTable('marketing_preferences'), 503);
        $data = $request->validate(['consent' => 'required|in:granted,withdrawn,unknown',
            'birth_calendar' => 'nullable|in:jalali,gregorian', 'birth_year' => 'nullable|integer|min:1200|max:2200',
            'birth_month' => 'nullable|integer|min:1|max:12', 'birth_day' => 'nullable|integer|min:1|max:31']);
        $calendar = $data['birth_calendar'] ?? null;
        $month = $data['birth_month'] ?? null;
        $day = $data['birth_day'] ?? null;
        if ($calendar || $month || $day) {
            $max = $calendar === 'jalali' ? ($month <= 6 ? 31 : 30) : ([2 => 29, 4 => 30, 6 => 30, 9 => 30, 11 => 30][$month] ?? 31);
            if (! $calendar || ! $month || ! $day || $day > $max ||
                (! empty($data['birth_year']) && ($calendar === 'jalali'
                    ? $day > Jalali::daysInMonth($data['birth_year'], $month)
                    : ! checkdate($month, $day, $data['birth_year'])))) {
                throw ValidationException::withMessages(['birth_day' => __('marketing.birth_invalid')]);
            }
        }
        $data['customer_id'] = Auth::guard('customer')->id();
        $data['consent_at'] = now();
        DB::transaction(function () use ($data) {
            $old = MarketingPreference::where('customer_id', $data['customer_id'])->lockForUpdate()->first();
            $before = $old?->consent ?: 'unknown';
            MarketingPreference::updateOrCreate(['customer_id' => $data['customer_id']], $data);
            DB::table('marketing_audits')->insert(['action' => 'consent', 'reason' => 'Customer preference update',
                'details' => json_encode(['customer_id' => $data['customer_id'], 'before' => $before, 'after' => $data['consent']]),
                'created_at' => now()]);
            if ($data['consent'] !== 'granted') {
                DB::table('marketing_deliveries')->where('customer_id', $data['customer_id'])
                    ->where('status', 'scheduled')->update(['status' => 'blocked', 'reason' => 'marketing_consent', 'updated_at' => now()]);
            }
        });

        return back()->with('ok', __('marketing.saved'));
    }

    public function track(string $token)
    {
        abort_unless(Schema::hasTable('marketing_grants'), 404);
        $g = MarketingGrant::where('track_token', $token)->firstOrFail();
        $path = '/account/offers/'.$g->id.'/open';
        if (! Auth::guard('customer')->check()) {
            session(['url.intended' => 'https://console.servernet.cloud'.$path]);

            return redirect()->to(lroute('login'));
        }

        return redirect()->to($path);
    }

    public function open(MarketingGrant $grant)
    {
        $id = Auth::guard('customer')->id();
        abort_unless($grant->customer_id === null || (int) $grant->customer_id === (int) $id, 404);
        DB::table('marketing_events')->insertOrIgnore(['campaign_id' => $grant->campaign_id,
            'grant_id' => $grant->id, 'kind' => 'click', 'created_at' => now(),
            'event_key' => hash('sha256', 'click:'.$grant->id.':'.$id.':'.now()->toDateString())]);
        session(['marketing_coupon' => $grant->code]);

        return redirect()->to($grant->snapshot['message']['landing_path']);
    }

    public function apply(Request $request, Invoice $invoice, DiscountEngine $engine)
    {
        $data = $request->validate(['code' => 'required|string|max:40']);
        $engine->reserve($invoice, Auth::guard('customer')->user(), $data['code']);

        return redirect()->to(lroute('account.invoice', $invoice))->with('ok', __('marketing.reserved_ok'));
    }

    public function free(Request $request, Invoice $invoice, DiscountEngine $engine)
    {
        abort_unless((int) $invoice->customer_id === (int) Auth::guard('customer')->id(), 404);
        DB::transaction(function () use ($invoice, $engine) {
            $i = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($i->status === 'paid' && $i->total === 0) {
                return;
            }
            $engine->assertPayable($i);
            $r = MarketingRedemption::where('invoice_id', $i->id)->where('status', 'reserved')->first();
            abort_unless($r && $r->expires_at->isFuture() && $i->total === 0 && $i->paid === 0, 422);
            // Existing PaymentService provisions the order even with zero actual cash.
            $i->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
            $p = Payment::firstOrCreate(['external_ref' => 'campaign-free-'.$i->id], [
                'invoice_id' => $i->id, 'customer_id' => $i->customer_id, 'gateway' => 'gift',
                'currency_code' => $i->currency_code, 'amount' => 0, 'status' => 'redirected']);
            $result = app(PaymentService::class)->settleConfirmed($p, 'campaign-free-'.$i->id);
            if (! $result->ok) {
                throw new \RuntimeException('Free order settlement failed');
            }
        }, 5);

        return redirect()->to(lroute('account.invoice', $invoice));
    }
}
