<?php

namespace App\Services\Crm;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Shared, read-only stop gate for CRM and SEO outreach. Never clears a stop. */
final class OutboundSuppression
{
    public static function blocks(?string $email): bool
    {
        $email = mb_strtolower(trim((string) $email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return true;
        }
        // A partially installed application cannot prove a destination is safe.
        foreach (['crm_suppression', 'outreach_contacts'] as $table) {
            if (! Schema::hasTable($table)) {
                return true;
            }
        }
        $domain = substr(strrchr($email, '@'), 1);
        if (DB::table('crm_suppression')->where(function ($query) use ($email, $domain) {
            $query->where('email', $email)->orWhere('domain', $domain);
        })->exists()) {
            return true;
        }

        return DB::table('outreach_contacts')->where('email', $email)
            ->whereNotNull('unsubscribed_at')->exists();
    }
}
