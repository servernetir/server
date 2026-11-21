<?php

namespace App\Support;

use Illuminate\Http\Request;

/** The preference module handles private account data and withdrawal capabilities. */
final class EmailMarketingPrivacy
{
    public static function sensitive(?Request $request): bool
    {
        return $request !== null && $request->is(
            'admin/email-marketing', 'admin/email-marketing/*',
            'account/email-preferences', 'en/account/email-preferences', 'tr/account/email-preferences',
            'email-marketing/unsubscribe/*', 'en/email-marketing/unsubscribe/*', 'tr/email-marketing/unsubscribe/*'
        );
    }

    public static function sensitiveUrl(string $url): bool
    {
        return (bool) preg_match('~/(?:email-marketing/unsubscribe|account/email-preferences|admin/email-marketing)(?:[/?#]|$)~i', $url);
    }
}
