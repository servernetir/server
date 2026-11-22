<?php

namespace App\Http\Controllers;

use App\Services\Marketing\EmailMarketingCampaigns;
use Illuminate\Http\Request;

class EmailCampaignUnsubscribeController extends Controller
{
    public function show(string $token, EmailMarketingCampaigns $campaigns)
    {
        abort_unless($campaigns->validToken($token), 404);
        return response()->view('email-marketing.campaign-unsubscribe', ['complete' => false])
            ->withHeaders($this->headers());
    }

    public function withdraw(Request $request, string $token, EmailMarketingCampaigns $campaigns)
    {
        abort_unless($campaigns->withdraw($token), 404);
        return response()->view('email-marketing.campaign-unsubscribe', ['complete' => true])
            ->withHeaders($this->headers());
    }

    private function headers(): array
    {
        return ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow'];
    }
}
