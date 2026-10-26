<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\MarketingGrant;
use App\Models\MarketingPreference;
use App\Models\MarketingRedemption;
use App\Models\Payment;
use App\Services\Marketing\DiscountEngine;
use App\Services\Marketing\DiscountEntryVisibility;
use App\Services\Payment\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OfferController extends Controller
{
    public function index()
    {
        // Legacy SMS opt-out links must still reach an unsubscribe action.
        return redirect()->to(lroute('account.marketing.unsubscribe'));
    }

    public function preferences(Request $request)
    {
        // Retired form: neither birthdays nor positive marketing consent are accepted here.
        abort(410);
    }

    public function unsubscribePage()
    {
        return view('account.marketing-unsubscribe', AccountController::shell(''));
    }

    public function unsubscribe()
    {
        abort_unless(Schema::hasTable('marketing_preferences'), 503);
        $id = Auth::guard('customer')->id();
        DB::transaction(function () use ($id) {
            // Serialize concurrent opt-out requests even when no preference row exists yet.
            Customer::whereKey($id)->lockForUpdate()->firstOrFail();
            $old = MarketingPreference::where('customer_id', $id)->lockForUpdate()->first();
            $before = $old?->consent ?: 'unknown';
            MarketingPreference::updateOrCreate(['customer_id' => $id], ['consent' => 'withdrawn', 'consent_at' => now()]);
            if ($before !== 'withdrawn') {
                DB::table('marketing_audits')->insert(['action' => 'consent', 'reason' => 'Customer marketing opt-out',
                    'details' => json_encode(['customer_id' => $id, 'before' => $before, 'after' => 'withdrawn']),
                    'created_at' => now()]);
            }
            DB::table('marketing_deliveries')->where('customer_id', $id)
                ->where('status', 'scheduled')->update(['status' => 'blocked', 'reason' => 'marketing_consent', 'updated_at' => now()]);
        });

        return redirect()->to(lroute('account.marketing.unsubscribe'))->with('ok', __('marketing.unsubscribe_saved'));
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
        abort_unless(app(DiscountEntryVisibility::class)->enabled(Auth::guard('customer')->user()), 403);
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
