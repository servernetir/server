<?php

namespace App\Http\Middleware;

use App\Services\Birthday\BirthdayProgram;
use Closure;
use Illuminate\Http\Request;

class PrepareBirthdayReward
{
    public function handle(Request $request, Closure $next)
    {
        if ($customer = auth('customer')->user()) {
            app(BirthdayProgram::class)->ensure($customer);
        }
        $invoice = $request->route('invoice');
        if ($request->isMethod('POST') && $invoice instanceof \App\Models\Invoice
            && ! str_ends_with((string) $request->route()?->getName(), '.cancel')) {
            // Authenticate ownership before reading any customer-specific quote state.
            abort_unless((int) $invoice->customer_id === (int) auth('customer')->id(), 404);
            app(\App\Services\Birthday\BirthdayInvoices::class)
                ->assertPresented($invoice, (string) $request->input('birthday_price_token', ''));
        }
        $response = $next($request);
        // Customer-specific rewards must never enter the shared public page cache.
        if (auth('customer')->check()) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        return $response;
    }
}
