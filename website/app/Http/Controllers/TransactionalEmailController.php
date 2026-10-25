<?php

namespace App\Http\Controllers;

use App\Models\CrmLead;
use App\Models\CrmMessage;
use App\Services\ExchangeRate;
use App\Services\TransactionalEmailQuote;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class TransactionalEmailController extends Controller
{
    public function show(ExchangeRate $fx, TransactionalEmailQuote $quote): View
    {
        $usdRate = $fx->toToman('USD');
        $eurRate = $fx->toToman('EUR');
        $currency = app()->getLocale() === 'fa' && $usdRate ? 'IRT'
            : ($usdRate && $eurRate ? 'EUR' : 'USD');
        $plans = array_map(function (array $plan) use ($quote, $currency, $usdRate, $eurRate) {
            $plan['amount'] = $quote->displayAmount($plan['usd_cents'], $currency, $usdRate, $eurRate);
            return $plan;
        }, config('transactional_email.plans'));

        return view('pages.transactional-email', compact('plans', 'currency', 'usdRate', 'eurRate'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'company' => 'required|string|max:160',
            'email' => 'required|email:rfc|max:190',
            'phone' => ['required', 'string', 'max:40', 'regex:/^[0-9۰-۹٠-٩+().\s-]{7,40}$/u'],
            'domain' => ['nullable', 'string', 'max:253', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z0-9-]{2,63}$/i'],
            'volume' => 'required|integer|min:1000|max:1000000',
            'message' => 'required|string|max:1000',
            'request_id' => 'required|uuid',
            'consent' => 'accepted',
            'website_url' => 'prohibited', // honeypot, not the customer's domain
        ]);
        $email = mb_strtolower(trim($data['email']));
        [$reference, $created] = DB::transaction(function () use ($data, $email) {
            // A product+email namespace avoids overwriting discovered companies,
            // shared mailbox providers or an existing customer's contact details.
            $lead = CrmLead::firstOrCreate([
                'domain_hash' => hash('sha256', 'transactional-email|'.$email),
            ], [
                'company' => $data['company'], 'contact_name' => $data['name'],
                'email' => $email, 'phone' => $data['phone'],
                'website' => $data['domain'] ?? null,
                'source' => 'transactional-web', 'vertical' => 'transactional-email',
                'offer' => 'managed-email', 'stage' => 'replied',
                // No observation, next action or outbound message: no outreach.
            ]);
            $lead = CrmLead::whereKey($lead->id)->lockForUpdate()->firstOrFail();
            $body = implode("\n", [
                'Product: transactional-email', 'Locale: '.app()->getLocale(),
                'Company: '.$data['company'], 'Name: '.$data['name'],
                'Email: '.$email, 'Phone: '.$data['phone'],
                'Domain: '.($data['domain'] ?? ''), 'Monthly recipients: '.$data['volume'],
                'Consent: contact about this request only', '', $data['message'],
            ]);
            $message = CrmMessage::firstOrCreate([
                'lead_id' => $lead->id,
                'provider_id' => 'email-web-'.hash('sha256', $email.'|'.$data['request_id']),
            ], [
                'channel' => 'web', 'direction' => 'in', 'status' => 'received',
                'subject' => 'Transactional email quote request', 'body' => $body,
            ]);
            return ['TE-'.$message->id, $message->wasRecentlyCreated];
        });

        return redirect(lroute('email.transactional').'#request')
            ->with('email_quote_reference', $reference)
            ->with('email_quote_created', $created);
    }
}
