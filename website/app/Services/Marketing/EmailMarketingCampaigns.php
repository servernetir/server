<?php

namespace App\Services\Marketing;

use App\Models\Customer;
use App\Models\EmailMarketingCampaign;
use App\Models\EmailMarketingDelivery;
use App\Models\EmailMarketingPreference;
use App\Models\EmailMarketingRecipient;
use App\Models\ExternalEmailConsentReview;
use App\Models\ExternalEmailConsentReviewEvent;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/** Registered-account policy and documented external consent are separate sending bases. */
final class EmailMarketingCampaigns
{
    public function __construct(private EmailMarketingPreferences $preferences, private EmailCampaignTemplate $templates) {}

    public function ready(): bool
    {
        if (! $this->preferences->ready() || ! Schema::hasTable('email_marketing_recipients')
            || ! Schema::hasTable('email_marketing_campaigns') || ! Schema::hasTable('email_marketing_deliveries')
            || ! Schema::hasTable('external_email_consent_reviews') || ! Schema::hasTable('external_email_consent_review_events')
            || ! Schema::hasColumn('email_marketing_recipients', 'full_name') || ! Schema::hasColumn('email_marketing_campaigns', 'sender_email')) {
            return false;
        }
        try {
            $this->assertKeyCoverage($this->preferences->evidenceReviewIdentity('key-coverage@example.invalid')['known_key_ids']);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function summary(): array
    {
        $stats = ['ready' => $this->ready(), 'registered' => 0, 'external_ready' => 0, 'external_missing' => 0, 'external_missing_names' => 0, 'blocked' => 0, 'mailer_ready' => false];
        if (! $stats['ready']) {
            return $stats;
        }
        try {
            $this->assertMailer();
            $stats['mailer_ready'] = true;
        } catch (DomainException) {
            // The page remains available to compose drafts while delivery is unconfigured.
        }
        $this->syncRegistered();
        foreach (EmailMarketingRecipient::query()->cursor() as $recipient) {
            if (! $this->eligible($recipient)) {
                $stats['blocked']++;
            } elseif ($recipient->basis === 'registration') {
                $stats['registered']++;
            } else {
                $stats['external_ready']++;
                if (! filled($recipient->full_name)) { $stats['external_missing_names']++; }
            }
        }
        $stats['external_missing'] = ExternalEmailConsentReview::query()
            ->whereNotIn('id', EmailMarketingRecipient::query()->whereNotNull('review_id')->select('review_id'))->count();

        return $stats;
    }

    public function recent(): Collection
    {
        return $this->ready() ? EmailMarketingCampaign::query()->withCount('deliveries')
            ->withCount(['deliveries as sent_count' => fn ($q) => $q->where('status', 'sent'),
                'deliveries as skipped_count' => fn ($q) => $q->where('status', 'skipped'),
                'deliveries as unknown_count' => fn ($q) => $q->where('status', 'unknown')])
            ->latest('id')->limit(10)->get() : collect();
    }

    /** Repeated reads never re-enable an address that has withdrawn or been suppressed. */
    public function syncRegistered(): int
    {
        $this->assertReady();
        $count = 0;
        foreach (Customer::query()->where('status', 'active')->cursor() as $customer) {
            try {
                $email = $this->preferences->normalizeEmail((string) $customer->email);
                if (Customer::query()->whereRaw('LOWER(TRIM(email)) = ?', [$email])->count() !== 1) {
                    continue;
                }
                $review = $this->reviewFor($email);
                $this->upsertRecipient($email, 'registration', (int) $customer->id, $review?->id, null);
                $count++;
            } catch (DomainException) {
                // Invalid addresses are not sendable.
            }
        }

        return $count;
    }

    /** The upload restores an address only when its HMAC matches an existing evidence record. */
    public function restoreAddress(string $email, int $adminId, ?string $fullName = null): bool
    {
        $this->assertAdmin($adminId);
        $this->assertReady();
        $email = $this->preferences->normalizeEmail($email);
        $review = $this->reviewFor($email);
        if (! $review) {
            return false;
        }
        $fullName = filled($fullName) ? $this->templates->normalizeName($fullName) : null;
        $this->upsertRecipient($email, 'documented', null, (int) $review->id, $adminId, $fullName);

        return true;
    }

    public function importCsv(string $path, int $adminId): array
    {
        $this->assertAdmin($adminId);
        $this->assertReady();
        $handle = fopen($path, 'rb');
        if (! $handle) {
            throw new DomainException('CSV unavailable.');
        }
        $stats = ['restored' => 0, 'unmatched' => 0, 'invalid' => 0];
        $entries = [];
        try {
            $header = fgetcsv($handle, 0, ',', '"', '');
            $header = is_array($header) ? array_map(fn ($v) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $v))), $header) : [];
            $column = array_search('email', $header, true);
            if ($column === false || count($header) !== count(array_unique($header))) {
                throw new DomainException('CSV requires email column.');
            }
            $nameColumn = false;
            foreach (['full_name', 'name', 'نام و نام خانوادگی'] as $nameHeader) {
                if (($index = array_search($nameHeader, $header, true)) !== false) { $nameColumn = $index; break; }
            }
            $rows = 0;
            while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if ($row === [null]) {
                    continue;
                }
                if (++$rows > 1000) {
                    throw new DomainException('CSV exceeds 1000 rows.');
                }
                if (count($row) !== count($header)) { $stats['invalid']++; continue; }
                try {
                    $email = $this->preferences->normalizeEmail((string) ($row[$column] ?? ''));
                    $fullName = $nameColumn !== false && filled($row[$nameColumn] ?? null)
                        ? $this->templates->normalizeName((string) $row[$nameColumn]) : null;
                    if (isset($entries[$email]) && $entries[$email]['name'] !== null && $fullName !== null
                        && $entries[$email]['name'] !== $fullName) {
                        throw new \UnexpectedValueException('Conflicting names for one address.');
                    }
                    $entries[$email] = ['name' => $fullName ?? ($entries[$email]['name'] ?? null)];
                } catch (DomainException) {
                    $stats['invalid']++;
                }
            }
        } catch (\UnexpectedValueException) {
            throw new DomainException('Conflicting recipient names in CSV.');
        } finally {
            fclose($handle);
        }
        // Parse the entire bounded file before making changes.
        return DB::transaction(function () use ($entries, $adminId, $stats) {
            foreach ($entries as $email => $entry) {
                $stats[$this->restoreAddress($email, $adminId, $entry['name']) ? 'restored' : 'unmatched']++;
            }

            return $stats;
        }, 3);
    }

    /** null selects every pending record, across all pages, including document-linked records. */
    public function approve(?array $ids, string $documentRef, int $adminId, ?int $expectedCount = null): array
    {
        $this->assertReady();
        $this->assertAdmin($adminId);
        if (! preg_match('~\A[\p{L}\p{N}][\p{L}\p{N}._:/# -]{0,159}\z~u', $documentRef)) {
            throw new DomainException('Document reference invalid.');
        }
        return DB::transaction(function () use ($ids, $documentRef, $adminId, $expectedCount) {
            $query = ExternalEmailConsentReview::query()->doesntHave('decisions')->orderBy('id');
            if ($ids !== null) {
                $query->whereIn('id', $ids);
            }
            $records = $query->limit(1001)->lockForUpdate()->get();
            if ($records->isEmpty() || $records->count() > 1000
                || ($expectedCount !== null && $records->count() !== $expectedCount)
                || ($ids !== null && $records->count() !== count(array_unique($ids)))) {
                throw new DomainException('Pending selection changed.');
            }
            $stats = ['approved' => 0, 'ready' => 0, 'missing_address' => 0, 'blocked' => 0];
            foreach ($records as $record) {
                // Individual and bulk decisions both serialize on the parent evidence row.
                if ($record->decisions()->lockForUpdate()->exists()) {
                    throw new DomainException('Pending selection changed.');
                }
                ExternalEmailConsentReviewEvent::query()->create([
                    'review_id' => $record->id, 'decision' => 'verified', 'reason' => 'evidence_checked',
                    'source_kind' => 'written_consent', 'review_reference' => $documentRef,
                    'reviewed_by' => $adminId, 'created_at' => now(),
                ]);
                $stats['approved']++;
                $recipient = EmailMarketingRecipient::query()->where('review_id', $record->id)->first();
                $stats[! $recipient ? 'missing_address' : ($this->eligible($recipient) ? 'ready' : 'blocked')]++;
            }

            return $stats;
        }, 3);
    }

    public function createDraft(string $subject, string $body, string $audience, int $adminId): EmailMarketingCampaign
    {
        $this->assertReady();
        $this->assertAdmin($adminId);
        if (! in_array($audience, ['all', 'customers', 'external'], true) || trim($subject) === '' || trim($body) === ''
            || mb_strlen($subject) > 180 || mb_strlen($body) > 20000 || preg_match('/[\r\n]/', $subject)) {
            throw new DomainException('Campaign content invalid.');
        }

        $this->templates->validate($body);
        return EmailMarketingCampaign::query()->create(['sender_email' => 'info@servernet.cloud',
            'sender_name' => 'ServerNet', 'reply_to' => 'info@servernet.cloud', 'subject' => trim($subject), 'body' => trim($body),
            'audience' => $audience, 'status' => 'draft', 'created_by' => $adminId]);
    }

    public function queue(int $campaignId, int $adminId): int
    {
        $this->assertReady();
        $this->assertAdmin($adminId);
        $this->assertMailer();
        $this->syncRegistered();
        return DB::transaction(function () use ($campaignId, $adminId) {
            $campaign = EmailMarketingCampaign::query()->lockForUpdate()->findOrFail($campaignId);
            if ($campaign->status !== 'draft') {
                return $campaign->deliveries()->count();
            }
            $recipients = EmailMarketingRecipient::query()->orderBy('id');
            if ($campaign->audience === 'customers') {
                $recipients->where('basis', 'registration');
            } elseif ($campaign->audience === 'external') {
                $recipients->where('basis', 'documented');
            }
            $this->templates->validate($campaign->body);
            $requiresName = $this->templates->requiresName($campaign->body);
            $selected = [];
            $missingNames = 0;
            foreach ($recipients->cursor() as $recipient) {
                if (! $this->eligible($recipient)) { continue; }
                $selected[] = $recipient;
                if ($requiresName) {
                    try { $this->templates->normalizeName($recipient->full_name); }
                    catch (DomainException) { $missingNames++; }
                }
            }
            if ($missingNames > 0) { throw new MissingEmailRecipientNames($missingNames); }
            $count = count($selected);
            foreach ($selected as $recipient) {
                EmailMarketingDelivery::query()->firstOrCreate(['campaign_id' => $campaign->id, 'recipient_id' => $recipient->id], ['status' => 'queued']);
            }
            if ($count === 0) {
                throw new DomainException('No eligible recipients.');
            }
            $campaign->update(['status' => 'queued', 'queued_at' => now(), 'queued_by' => $adminId]);

            return $count;
        }, 3);
    }

    public function tick(int $limit = 25): array
    {
        $this->assertReady();
        $mailer = $this->assertMailer();
        $stats = ['sent' => 0, 'skipped' => 0, 'unknown' => 0];
        if (! config('email-marketing.enabled')) {
            return $stats;
        }
        // A lost process may already have reached the provider. Never automatically resend it.
        EmailMarketingDelivery::query()->where('status', 'processing')->where('attempted_at', '<', now()->subMinutes(15))
            ->update(['status' => 'unknown', 'reason' => 'interrupted_worker', 'updated_at' => now()]);
        $ids = EmailMarketingDelivery::query()->where('status', 'queued')->orderBy('id')->limit(max(1, min(100, $limit)))->pluck('id');
        foreach ($ids as $id) {
            $delivery = DB::transaction(function () use ($id) {
                $row = EmailMarketingDelivery::query()->lockForUpdate()->find($id);
                if (! $row || $row->status !== 'queued') {
                    return null;
                }
                $row->update(['status' => 'processing', 'attempted_at' => now()]);
                return $row;
            }, 3);
            if (! $delivery) {
                continue;
            }
            $recipient = EmailMarketingRecipient::query()->find($delivery->recipient_id);
            $campaign = EmailMarketingCampaign::query()->find($delivery->campaign_id);
            try {
                if (! $recipient || ! $campaign || $campaign->status !== 'queued' || ! $this->eligible($recipient)) {
                    $delivery->update(['status' => 'skipped', 'reason' => 'no_longer_eligible']);
                    $stats['skipped']++;
                    continue;
                }
                $unsubscribe = rtrim((string) config('app.url'), '/').'/email-marketing/unsubscribe/campaign/'.$this->token($recipient);
                try {
                    $text = $this->templates->render($campaign->body, $recipient->full_name, $unsubscribe);
                } catch (DomainException) {
                    $delivery->update(['status' => 'skipped', 'reason' => 'personalization_unavailable']);
                    $stats['skipped']++;
                    continue;
                }
                if (! $this->templates->containsUnsubscribeToken($campaign->body)) {
                    $text .= "\n\nServerNet\n".(string) config('crm.from.address', '')."\n".__('email-campaigns.unsubscribe_label').': '.$unsubscribe;
                }
                $sent = Mail::mailer($mailer)->raw($text, function ($message) use ($recipient, $campaign, $unsubscribe) {
                    $message->to($recipient->email)->subject($campaign->subject)
                        ->from($campaign->sender_email ?? (string) config('crm.from.email', config('mail.from.address')), $campaign->sender_name ?? 'ServerNet')
                        ->replyTo($campaign->reply_to ?? $campaign->sender_email ?? (string) config('crm.from.email'));
                    $headers = $message->getSymfonyMessage()->getHeaders();
                    $headers->addTextHeader('List-Unsubscribe', '<'.$unsubscribe.'>');
                    $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
                });
                if ($sent === null) {
                    throw new DomainException('Mail transport did not acknowledge a message.');
                }
                $delivery->update(['status' => 'sent', 'sent_at' => now(), 'reason' => 'provider_accepted']);
                $stats['sent']++;
            } catch (Throwable) {
                // No address, body, credential, exception or provider response goes to the public log.
                $delivery->update(['status' => 'unknown', 'reason' => 'delivery_unconfirmed']);
                $stats['unknown']++;
            }
        }
        foreach (EmailMarketingCampaign::query()->where('status', 'queued')->get() as $campaign) {
            if (! $campaign->deliveries()->whereIn('status', ['queued', 'processing'])->exists()) {
                $campaign->update(['status' => 'complete']);
            }
        }

        return $stats;
    }

    public function eligible(EmailMarketingRecipient $recipient): bool
    {
        try {
            if ($recipient->withdrawn_at || $recipient->suppressed_at) {
                return false;
            }
            $email = $this->preferences->normalizeEmail((string) $recipient->email);
            $identity = $this->preferences->evidenceReviewIdentity($email);
            $this->assertKeyCoverage($identity['known_key_ids']);
            if (! in_array($recipient->email_digest, $identity['digests'], true)) {
                return false;
            }
            // Preserve address-specific choices even if registration or another source still exists.
            if (EmailMarketingPreference::query()->whereIn('email_digest', $identity['digests'])
                ->where(fn ($q) => $q->where('state', 'withdrawn')->orWhereNotNull('withdrawn_at')->orWhereNotNull('suppressed_at'))->exists()
                || $this->legacyBlocked($email)) {
                return false;
            }
            if ($recipient->review_id) {
                $decision = ExternalEmailConsentReviewEvent::query()->where('review_id', $recipient->review_id)->latest('id')->value('decision');
                if (in_array($decision, ['rejected', 'revoked'], true)) {
                    return false;
                }
            }
            if ($recipient->basis === 'registration') {
                $customer = Customer::query()->find($recipient->customer_id);
                return $customer && $customer->isActive()
                    && $email === $this->preferences->normalizeEmail((string) $customer->email)
                    && Customer::query()->whereRaw('LOWER(TRIM(email)) = ?', [$email])->count() === 1;
            }

            return $recipient->review_id && ExternalEmailConsentReviewEvent::query()
                ->where('review_id', $recipient->review_id)->latest('id')->value('decision') === 'verified';
        } catch (Throwable) {
            return false;
        }
    }

    public function token(EmailMarketingRecipient $recipient): string
    {
        return $recipient->public_id.'.'.$this->tokenMac($recipient, $this->keys()[0]);
    }

    public function validToken(string $token): bool
    {
        return $this->recipientByToken($token) !== null;
    }

    public function withdraw(string $token): bool
    {
        $recipient = $this->recipientByToken($token);
        if (! $recipient) {
            return false;
        }
        DB::transaction(function () use ($recipient) {
            $row = EmailMarketingRecipient::query()->lockForUpdate()->findOrFail($recipient->id);
            if (! $row->withdrawn_at) {
                $row->update(['withdrawn_at' => now()]);
            }
            if (Schema::hasTable('crm_suppression')) {
                DB::table('crm_suppression')->insertOrIgnore([
                    'email' => $this->preferences->normalizeEmail((string) $row->email),
                    'domain' => null, 'reason' => 'unsubscribe', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            EmailMarketingDelivery::query()->where('recipient_id', $row->id)->where('status', 'queued')
                ->update(['status' => 'skipped', 'reason' => 'recipient_withdrawal', 'updated_at' => now()]);
        }, 3);

        return true;
    }

    private function upsertRecipient(string $email, string $basis, ?int $customerId, ?int $reviewId, ?int $adminId, ?string $fullName = null): EmailMarketingRecipient
    {
        $identity = $this->preferences->evidenceReviewIdentity($email);
        return DB::transaction(function () use ($email, $basis, $customerId, $reviewId, $adminId, $identity, $fullName) {
            $recipient = EmailMarketingRecipient::query()->whereIn('email_digest', $identity['digests'])->lockForUpdate()->first();
            if ($recipient) {
                $values = [];
                if ($fullName !== null && $recipient->full_name !== $fullName) { $values['full_name'] = $fullName; }
                if ((string) $recipient->email !== $email) {
                    $values['email'] = $email;
                }
                if ($basis === 'registration' && ($recipient->basis !== $basis || (int) $recipient->customer_id !== $customerId)) {
                    $values += ['basis' => $basis, 'customer_id' => $customerId];
                }
                if ($reviewId !== null && (int) $recipient->review_id !== $reviewId) {
                    $values['review_id'] = $reviewId;
                }
                if ($values !== []) {
                    $recipient->update($values);
                }
                return $recipient;
            }

            return EmailMarketingRecipient::query()->firstOrCreate(['email_digest' => $identity['digests'][0]], ['public_id' => (string) Str::uuid(),
                'digest_key_id' => $identity['key_id'],
                'email' => $email, 'full_name' => $fullName, 'basis' => $basis, 'customer_id' => $customerId,
                'review_id' => $reviewId, 'created_by' => $adminId]);
        }, 3);
    }

    private function reviewFor(string $email): ?ExternalEmailConsentReview
    {
        $identity = $this->preferences->evidenceReviewIdentity($email);
        return ExternalEmailConsentReview::query()->whereIn('email_digest', $identity['digests'])->first();
    }

    private function legacyBlocked(string $email): bool
    {
        if (! Schema::hasTable('crm_suppression') || ! Schema::hasTable('outreach_contacts')) {
            return true;
        }
        $domain = substr(strrchr($email, '@'), 1);
        return DB::table('crm_suppression')->where(fn ($q) => $q->whereRaw('LOWER(TRIM(email)) = ?', [$email])
            ->orWhereRaw('LOWER(TRIM(domain)) = ?', [$domain]))->exists()
            || DB::table('outreach_contacts')->whereRaw('LOWER(TRIM(email)) = ?', [$email])->whereNotNull('unsubscribed_at')->exists();
    }

    private function assertKeyCoverage(array $knownIds): void
    {
        foreach (['email_marketing_preferences', 'external_email_consent_reviews', 'email_marketing_recipients'] as $table) {
            if (DB::table($table)->whereNotIn('digest_key_id', $knownIds)->exists()) {
                throw new DomainException('Historical address key unavailable.');
            }
        }
    }

    private function assertReady(): void
    {
        if (! $this->ready()) {
            throw new DomainException('Email campaign storage unavailable.');
        }
    }

    private function assertAdmin(int $id): void
    {
        if ((int) Auth::guard('web')->id() !== $id || ! User::query()->find($id)?->isAdmin()) {
            throw new DomainException('Administrator required.');
        }
    }

    private function assertMailer(): string
    {
        $mailer = config('email-marketing.mailer');
        if (! filled($mailer)) {
            // The marketing sender uses the already configured company mailbox; CRM remains separate.
            $account = collect(config('mailboxes.accounts', []))->firstWhere('key', 'info');
            if (! $account || strtolower((string) ($account['user'] ?? '')) !== 'info@servernet.cloud' || ! filled($account['pass'] ?? null)) {
                throw new DomainException('Company sender mailbox unavailable.');
            }
            $transport = (array) config('mail.mailers.'.config('crm.mailer', 'crm'));
            if (($transport['transport'] ?? null) !== 'smtp') { throw new DomainException('Company SMTP transport unavailable.'); }
            $transport['username'] = $account['user'];
            $transport['password'] = $account['pass'];
            $transport['timeout'] = 20;
            unset($transport['url']);
            config(['mail.mailers.email-marketing-info' => $transport]);
            $mailer = 'email-marketing-info';
        }
        $mailer = (string) $mailer;
        if (! in_array(config('mail.mailers.'.$mailer.'.transport'), ['smtp', 'sendmail', 'ses', 'ses-v2', 'mailgun', 'postmark', 'resend'], true)) {
            throw new DomainException('Real mail transport unavailable.');
        }
        return $mailer;
    }

    private function recipientByToken(string $token): ?EmailMarketingRecipient
    {
        if (! Schema::hasTable('email_marketing_recipients') || ! Schema::hasTable('email_marketing_deliveries')
            || ! preg_match('/\A([0-9a-f-]{36})\.([0-9a-f]{64})\z/', $token, $match)) {
            return null;
        }
        $recipient = EmailMarketingRecipient::query()->where('public_id', $match[1])->first();
        if ($recipient) {
            foreach ($this->keys() as $key) {
                if (hash_equals($this->tokenMac($recipient, $key), $match[2])) {
                    return $recipient;
                }
            }
        }
        return null;
    }

    private function tokenMac(EmailMarketingRecipient $recipient, string $key): string
    {
        return hash_hmac('sha256', 'servernet:email-campaign:unsubscribe:v1|'.$recipient->public_id.'|'.$recipient->email_digest, $key);
    }

    private function keys(): array
    {
        return array_map(function ($key) {
            $decoded = str_starts_with((string) $key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
            if (! is_string($decoded) || strlen($decoded) < 32) {
                throw new DomainException('Signing key unavailable.');
            }
            return $decoded;
        }, [config('app.key'), ...(array) config('app.previous_keys', [])]);
    }
}
