<?php

namespace App\Services\Marketing;

use App\Http\Controllers\Admin\ImpersonateController;
use App\Models\Customer;
use App\Models\EmailMarketingPreference;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Consent and suppression only. This service has no sending or scheduling capability. */
final class EmailMarketingPreferences
{
    public const CONSENT_VERSION = 'servernet-email-marketing-v1';

    private const DIGEST_DOMAIN = 'servernet:email-preferences:address:v1|';

    private const TOKEN_DOMAIN = 'servernet:email-preferences:unsubscribe:v1|';

    private const KEY_DOMAIN = 'servernet:email-preferences:key-id:v1';

    public function ready(): bool
    {
        if (! Schema::hasTable('email_marketing_preferences') || ! Schema::hasTable('email_marketing_preference_events')) {
            return false;
        }
        try {
            $this->keys();
        } catch (DomainException) {
            return false;
        }

        return true;
    }

    /** Address canonicalisation matches LOWER(TRIM(email)) in the ownership check. */
    public function normalizeEmail(string $email): string
    {
        $normalized = strtolower(trim($email, ' '));
        if (strlen($normalized) > 190 || ! filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('Email address is not supported.');
        }

        return $normalized;
    }

    public function emailDigest(string $email): string
    {
        return $this->digests($this->normalizeEmail($email))[0];
    }

    public function preferenceFor(Customer $customer): ?EmailMarketingPreference
    {
        if (! $this->ready()) {
            return null;
        }

        try {
            $fresh = Customer::query()->find($customer->getKey());
            if (! $fresh || ! $fresh->isActive() || ! $fresh->email_verified_at) {
                return null;
            }
            $email = $this->normalizeEmail((string) $fresh->email);
            if (! $this->uniqueOwner($fresh, $email)) {
                return null;
            }

            return $this->ownPreference($fresh, $this->digests($email));
        } catch (DomainException) {
            return null;
        }
    }

    /** Imports only create pending records for eligible existing accounts. */
    public function stage(Customer $customer, ?int $adminId): EmailMarketingPreference
    {
        $this->assertReady();
        $this->assertKeyCoverage();
        if ($adminId !== null) {
            $this->assertAdmin($adminId);
        }

        return DB::transaction(function () use ($customer, $adminId) {
            $fresh = $this->lockCustomer($customer);
            [, $digests] = $this->verifiedIdentity($fresh, true);
            $existing = $this->ownPreference($fresh, $digests, true);
            if ($existing) {
                // Staging is idempotent and can never erase a prior choice or suppression.
                return $existing;
            }
            if ($this->anyPreferenceFor($digests)) {
                throw new DomainException('Address history requires separate recipient review.');
            }

            return $this->createPending($fresh, $digests[0], $adminId === null ? 'system' : 'admin', $adminId, true);
        }, 3);
    }

    /** Only the authenticated recipient may opt in or withdraw. No staff consent. */
    public function setConsent(Customer $customer, bool $grant): EmailMarketingPreference
    {
        $this->assertReady();
        if (! Auth::guard('customer')->check() || (int) Auth::guard('customer')->id() !== (int) $customer->getKey()) {
            throw new DomainException('Email preferences belong to the authenticated recipient.');
        }
        if (Auth::guard('web')->check()
            || (request()->hasSession() && request()->session()->has(ImpersonateController::SESSION_KEY))) {
            throw new DomainException('Staff sessions cannot record a recipient consent action.');
        }

        return DB::transaction(function () use ($customer, $grant) {
            $fresh = $this->lockCustomer($customer);
            $email = $this->normalizeEmail((string) $fresh->email);
            $digests = $this->digests($email);
            $preference = $this->ownPreference($fresh, $digests, true);

            // An existing verified preference may be withdrawn after verification
            // lapses. Creating a new record or granting always needs verified ownership.
            if ($grant || ! $preference) {
                $this->assertKeyCoverage();
                $this->verifiedIdentity($fresh, true);
            }
            if (! $preference) {
                if ($this->anyPreferenceFor($digests)) {
                    throw new DomainException('Address history requires separate recipient review.');
                }
                $preference = $this->createPending($fresh, $digests[0], 'customer', (int) $fresh->getKey(), false);
            }

            if (! $grant) {
                $this->withdraw($preference, 'customer', (int) $fresh->getKey());

                return $preference;
            }

            if ($preference->state === EmailMarketingPreference::STATE_WITHDRAWN || $preference->withdrawn_at !== null
                || $preference->suppressed_at !== null || $this->anyPreferenceFor($digests, $preference->getKey())
                || $this->legacyBlocked($email)) {
                throw new DomainException('Email consent is blocked by withdrawal or address history.');
            }
            if ($this->hasCurrentConsent($preference, $fresh)) {
                return $preference;
            }

            // Positive consent is explicit. A fresh verified recipient action may
            // renew stale consent; the prior opt-in event remains in append-only history.
            $locale = app()->getLocale();
            $preference->forceFill([
                'state' => EmailMarketingPreference::STATE_GRANTED,
                'consented_at' => now(),
                'consent_version' => self::CONSENT_VERSION,
                'consent_locale' => in_array($locale, ['fa', 'en', 'tr'], true) ? $locale : 'fa',
            ])->save();
            $this->audit($preference, 'opt_in', 'customer', (int) $fresh->getKey(), self::CONSENT_VERSION);

            return $preference;
        }, 3);
    }

    /** Administrator power is monotone: suppress only, never clear or grant. */
    public function suppress(EmailMarketingPreference $preference, int $adminId): void
    {
        $this->assertReady();
        $this->assertAdmin($adminId);
        DB::transaction(function () use ($preference, $adminId) {
            $fresh = EmailMarketingPreference::query()->whereKey($preference->getKey())->lockForUpdate()->firstOrFail();
            if ($fresh->suppressed_at !== null) {
                return;
            }
            $fresh->forceFill(['suppressed_at' => now(), 'suppression_reason' => 'admin_suppression'])->save();
            $this->audit($fresh, 'suppress', 'admin', $adminId);
        }, 3);
    }

    public function eligible(Customer $customer): bool
    {
        $preference = $this->preferenceFor($customer);

        return $preference !== null && $this->eligiblePreference($preference, $customer);
    }

    public function eligiblePreference(EmailMarketingPreference $preference, ?Customer $customer): bool
    {
        if (! $customer || (int) $preference->customer_id !== (int) $customer->getKey()) {
            return false;
        }

        return $this->eligibilityMap([$preference])[(int) $preference->getKey()] ?? false;
    }

    /** Read-only, fresh bulk eligibility for the paginated admin summary. No N+1. */
    public function eligibilityMap(iterable $preferences): array
    {
        $result = [];
        foreach ($preferences as $preference) {
            $result[(int) $preference->getKey()] = false;
        }
        if (! $result || ! $this->ready() || ! $this->keyCoverage() || ! Schema::hasTable('crm_suppression') || ! Schema::hasTable('outreach_contacts')) {
            return $result;
        }

        foreach (array_chunk(array_keys($result), 250) as $ids) {
            $rows = EmailMarketingPreference::query()->whereIn('id', $ids)->get();
            $customers = Customer::query()->whereIn('id', $rows->pluck('customer_id')->filter()->unique())->get()->keyBy('id');
            $candidates = [];
            foreach ($rows as $row) {
                $customer = $customers->get($row->customer_id);
                if (! $customer || ! $customer->isActive() || ! $customer->email_verified_at || ! $this->hasCurrentConsent($row, $customer)) {
                    continue;
                }
                try {
                    $email = $this->normalizeEmail((string) $customer->email);
                    $digests = $this->digests($email);
                } catch (DomainException) {
                    continue;
                }
                if (! in_array($row->email_digest, $digests, true)) {
                    continue;
                }
                $candidates[(int) $row->getKey()] = ['customer' => (int) $customer->getKey(), 'email' => $email, 'digests' => $digests];
            }
            if (! $candidates) {
                continue;
            }

            $emails = array_values(array_unique(array_column($candidates, 'email')));
            $domains = array_values(array_unique(array_map(fn (string $email) => substr(strrchr($email, '@'), 1), $emails)));
            $allDigests = array_values(array_unique(array_merge(...array_column($candidates, 'digests'))));
            $owners = Customer::query()->whereRaw('LOWER(TRIM(email)) IN ('.implode(',', array_fill(0, count($emails), '?')).')', $emails)
                ->get(['id', 'email'])->groupBy(fn (Customer $customer) => strtolower(trim((string) $customer->email, ' ')));

            $history = collect();
            foreach (array_chunk($allDigests, 500) as $digestChunk) {
                $history = $history->concat(EmailMarketingPreference::query()->whereIn('email_digest', $digestChunk)
                    ->get(['id', 'email_digest', 'state', 'withdrawn_at', 'suppressed_at']));
            }
            $history = $history->groupBy('email_digest');
            $crm = DB::table('crm_suppression')->where(function ($query) use ($emails, $domains) {
                $query->whereRaw('LOWER(TRIM(email)) IN ('.implode(',', array_fill(0, count($emails), '?')).')', $emails)
                    ->orWhereRaw('LOWER(TRIM(domain)) IN ('.implode(',', array_fill(0, count($domains), '?')).')', $domains);
            })->get(['email', 'domain']);
            $crmEmails = $crm->pluck('email')->map(fn ($email) => strtolower(trim((string) $email, ' ')))->flip();
            $crmDomains = $crm->pluck('domain')->map(fn ($domain) => strtolower(trim((string) $domain, ' ')))->flip();
            $oldOptOut = DB::table('outreach_contacts')->whereRaw('LOWER(TRIM(email)) IN ('.implode(',', array_fill(0, count($emails), '?')).')', $emails)
                ->whereNotNull('unsubscribed_at')->pluck('email')->map(fn ($email) => strtolower(trim((string) $email, ' ')))->flip();

            foreach ($candidates as $id => $candidate) {
                $email = $candidate['email'];
                $addressOwners = $owners->get($email, collect());
                if ($addressOwners->count() !== 1 || (int) $addressOwners->first()->getKey() !== $candidate['customer']
                    || $crmEmails->has($email) || $crmDomains->has(substr(strrchr($email, '@'), 1)) || $oldOptOut->has($email)) {
                    continue;
                }
                $addressHistory = collect();
                foreach ($candidate['digests'] as $digest) {
                    $addressHistory = $addressHistory->concat($history->get($digest, collect()));
                }
                // Reassignments, duplicate records, and any prior suppression fail closed.
                if ($addressHistory->count() !== 1) {
                    continue;
                }
                $only = $addressHistory->first();
                $result[$id] = (int) $only->getKey() === $id && $only->state === EmailMarketingPreference::STATE_GRANTED
                    && $only->withdrawn_at === null && $only->suppressed_at === null;
            }
        }

        return $result;
    }

    /** Opaque address-bound withdrawal capability; contains no address or customer ID. */
    public function unsubscribeToken(EmailMarketingPreference $preference): string
    {
        $this->assertReady();
        $fresh = EmailMarketingPreference::query()->findOrFail($preference->getKey());

        return $fresh->public_id.'.'.$this->mac($fresh, $this->keys()[0]);
    }

    /** Invalid token is side-effect-free. Valid token can only withdraw, idempotently. */
    public function unsubscribeByToken(string $token): bool
    {
        if (! $this->ready() || ! preg_match('/\A([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\.([0-9a-f]{64})\z/', $token, $matches)) {
            return false;
        }

        return DB::transaction(function () use ($matches) {
            $preference = EmailMarketingPreference::query()->where('public_id', $matches[1])->lockForUpdate()->first();
            if (! $preference) {
                return false;
            }
            $valid = false;
            foreach ($this->keys() as $key) {
                $valid = hash_equals($this->mac($preference, $key), $matches[2]) || $valid;
            }
            if (! $valid) {
                return false;
            }
            $this->withdraw($preference, 'public', null);

            return true;
        }, 3);
    }

    private function assertReady(): void
    {
        if (! $this->ready()) {
            throw new DomainException('Email preference storage is unavailable.');
        }
    }

    private function assertAdmin(int $adminId): void
    {
        $admin = Auth::guard('web')->user();
        if (! $admin instanceof User || (int) $admin->getKey() !== $adminId
            || ! User::query()->find($adminId)?->isAdmin()) {
            throw new DomainException('Administrator authorization required.');
        }
    }

    private function lockCustomer(Customer $customer): Customer
    {
        $fresh = Customer::query()->whereKey($customer->getKey())->lockForUpdate()->first();
        if (! $fresh) {
            throw new DomainException('Customer account is no longer available.');
        }

        return $fresh;
    }

    private function verifiedIdentity(Customer $customer, bool $lock): array
    {
        $email = $this->normalizeEmail((string) $customer->email);
        if (! $customer->isActive() || ! $customer->email_verified_at || ! $this->uniqueOwner($customer, $email, $lock)) {
            throw new DomainException('Verified unique email ownership is required.');
        }

        return [$email, $this->digests($email)];
    }

    private function uniqueOwner(Customer $customer, string $email, bool $lock = false): bool
    {
        $query = Customer::query()->whereRaw('LOWER(TRIM(email)) = ?', [$email])->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $ids = $query->limit(2)->pluck('id');

        return $ids->count() === 1 && (int) $ids->first() === (int) $customer->getKey();
    }

    private function ownPreference(Customer $customer, array $digests, bool $lock = false): ?EmailMarketingPreference
    {
        $query = EmailMarketingPreference::query()->where('customer_id', $customer->getKey())->whereIn('email_digest', $digests);
        if ($lock) {
            $query->lockForUpdate();
        }
        $rows = $query->limit(2)->get();
        if ($rows->count() > 1) {
            throw new DomainException('Ambiguous email preference history.');
        }

        return $rows->first();
    }

    private function anyPreferenceFor(array $digests, ?int $exceptId = null): bool
    {
        return EmailMarketingPreference::query()->whereIn('email_digest', $digests)
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))->exists();
    }

    private function createPending(Customer $customer, string $digest, string $actor, ?int $actorId, bool $stage): EmailMarketingPreference
    {
        $row = new EmailMarketingPreference;
        $row->forceFill([
            'public_id' => strtolower((string) Str::uuid()),
            'customer_id' => $customer->getKey(),
            'email_digest' => $digest,
            'digest_key_id' => $this->keyId($this->keys()[0]),
            'state' => EmailMarketingPreference::STATE_PENDING,
        ])->save();
        $this->audit($row, 'create', $actor, $actorId);
        if ($stage) {
            $this->audit($row, 'stage', $actor, $actorId);
        }

        return $row;
    }

    private function hasCurrentConsent(EmailMarketingPreference $preference, Customer $customer): bool
    {
        return $preference->state === EmailMarketingPreference::STATE_GRANTED
            && $preference->suppressed_at === null && $preference->withdrawn_at === null
            && $preference->consented_at !== null && $customer->email_verified_at !== null
            && $preference->consent_version === self::CONSENT_VERSION
            && $preference->consented_at->greaterThanOrEqualTo($customer->email_verified_at);
    }

    private function withdraw(EmailMarketingPreference $preference, string $actor, ?int $actorId): void
    {
        if ($preference->state === EmailMarketingPreference::STATE_WITHDRAWN) {
            return;
        }
        $preference->forceFill(['state' => EmailMarketingPreference::STATE_WITHDRAWN, 'withdrawn_at' => now()])->save();
        $this->audit($preference, 'opt_out', $actor, $actorId);
    }

    private function audit(EmailMarketingPreference $preference, string $event, string $actor, ?int $actorId, ?string $version = null): void
    {
        DB::table('email_marketing_preference_events')->insert([
            'email_marketing_preference_id' => $preference->getKey(),
            'event' => $event,
            'actor_type' => $actor,
            'actor_id' => $actorId,
            'consent_version' => $version,
            'created_at' => now(),
        ]);
    }

    private function digests(string $normalized): array
    {
        return array_map(fn (string $key) => hash_hmac('sha256', self::DIGEST_DOMAIN.$normalized, $key), $this->keys());
    }

    private function mac(EmailMarketingPreference $preference, string $key): string
    {
        return hash_hmac('sha256', self::TOKEN_DOMAIN.$preference->public_id.'|'.$preference->email_digest, $key);
    }

    private function legacyBlocked(string $email): bool
    {
        // Preserve the established domain/address stop gate even though the old
        // CRM is not a valid source of email opt-in evidence.
        if (! Schema::hasTable('crm_suppression') || ! Schema::hasTable('outreach_contacts')) {
            return true;
        }
        $domain = substr(strrchr($email, '@'), 1);

        return DB::table('crm_suppression')->where(function ($query) use ($email, $domain) {
            $query->whereRaw('LOWER(TRIM(email)) = ?', [$email])->orWhereRaw('LOWER(TRIM(domain)) = ?', [$domain]);
        })->exists() || DB::table('outreach_contacts')->whereRaw('LOWER(TRIM(email)) = ?', [$email])
            ->whereNotNull('unsubscribed_at')->exists();
    }

    private function keyId(string $key): string
    {
        return hash_hmac('sha256', self::KEY_DOMAIN, $key);
    }

    private function keyCoverage(): bool
    {
        $ids = array_map(fn (string $key) => $this->keyId($key), $this->keys());

        return ! EmailMarketingPreference::query()->whereNotIn('digest_key_id', $ids)->exists();
    }

    private function assertKeyCoverage(): void
    {
        if (! $this->keyCoverage()) {
            throw new DomainException('Historical email preference key is unavailable.');
        }
    }

    /** Keep prior APP_KEY values while preferences exist; rotation must preserve the key ring. */
    private function keys(): array
    {
        $current = config('app.key');
        if (! is_string($current) || $current === '') {
            throw new DomainException('Preference signing key is unavailable.');
        }
        $keys = [];
        foreach ([$current, ...array_filter((array) config('app.previous_keys', []))] as $value) {
            if (! is_string($value)) {
                throw new DomainException('Preference signing key is invalid.');
            }
            $key = str_starts_with($value, 'base64:') ? base64_decode(substr($value, 7), true) : $value;
            if ($key === false || strlen($key) < 32) {
                throw new DomainException('Preference signing key is invalid.');
            }
            if (! in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
