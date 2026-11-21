<?php

namespace App\Http\Controllers;

use App\Services\Marketing\EmailMarketingPreferences;
use Illuminate\Http\Request;

class EmailUnsubscribeController extends Controller
{
    public function show(string $token)
    {
        // GET is intentionally non-mutating: inbox scanners and prefetchers
        // must not withdraw a recipient's preference by opening a link.
        return $this->page($token, false);
    }

    public function store(Request $request, string $token, EmailMarketingPreferences $preferences)
    {
        // Only this route accepts a bearer capability instead of browser CSRF.
        // Its only possible effect is withdrawal, never enrollment or sending.
        if (! $preferences->ready()) {
            return response('', 503)->withHeaders($this->headers());
        }
        $valid = $preferences->unsubscribeByToken($token);
        if ($request->input('List-Unsubscribe') === 'One-Click') {
            return response('', $valid ? 200 : 404)->withHeaders($this->headers());
        }

        return $this->page($token, $valid, $valid ? 200 : 404);
    }

    private function page(string $token, bool $done, int $status = 200)
    {
        $invalid = $status >= 400;
        return response()->view('email-marketing.unsubscribe', compact('token', 'done', 'invalid'), $status)
            ->withHeaders($this->headers());
    }

    private function headers(): array
    {
        return [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }
}
