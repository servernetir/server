<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\{Domain, DomainQuote};
use App\Services\Domain\DomainSearch;
use Illuminate\Http\{JsonResponse, Request};

/** Authenticated, exact-name pricing; neither invoices nor paid registrar requests. */
class HostingDomainQuoteController extends Controller
{
    public function __invoke(Request $request, DomainSearch $search): JsonResponse
    {
        $data = $request->validate(['domain' => ['required', 'string', 'max:190', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i']]);
        $fqdn = strtolower(trim($data['domain']));
        [$sld, $tld] = Domain::splitFqdn($fqdn);
        $row = collect($search->search($sld, [$tld], (int) auth('customer')->id()))
            ->first(fn ($row) => ($row['domain'] ?? '') === $fqdn);
        if (! $row || ! ($row['orderable'] ?? false) || ! ($row['available'] ?? false)) {
            return response()->json(['ok' => false, 'message' => __('hosting-domains.unavailable')], 422);
        }
        $quote = DomainQuote::find((int) ($row['quote_id'] ?? 0));
        if (! $quote || ! $quote->claimFor((int) auth('customer')->id()) || ! $quote->supplier_account_id) {
            return response()->json(['ok' => false, 'message' => __('hosting-domains.unavailable')], 422);
        }
        $taxPct = CloudStoreController::taxPercent();
        $tax = (int) round($quote->sell_toman * $taxPct / 100);
        return response()->json([
            'ok' => true, 'domain' => $fqdn, 'quote_id' => $quote->id,
            'price_toman' => (int) $quote->sell_toman, 'tax_toman' => $tax,
            'total_toman' => (int) $quote->sell_toman + $tax,
            'period_years' => intdiv((int) $quote->period_months, 12),
            'expires_at' => $quote->honour_until?->toIso8601String(),
        ]);
    }
}
