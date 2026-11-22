<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExternalEmailConsentReview;
use App\Services\Marketing\EmailMarketingPreferences;
use App\Services\Marketing\EmailMarketingCampaigns;
use DateTimeImmutable;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Imports evidence and encrypted delivery addresses; approval is a separate administrator action. */
class EmailEvidenceReviewController extends Controller
{
    public function index(Request $request, EmailMarketingPreferences $preferences)
    {
        $this->guard($preferences);
        // Keep old bookmarks and pagination working without a second dashboard.
        $page = $request->integer('page', 1);
        $parameters = $page > 1 ? ['evidence_page' => $page] : [];

        return redirect()->to(route('admin.email-marketing.index', $parameters).'#evidence')
            ->withHeaders($this->headers());
    }

    public function sample(EmailMarketingPreferences $preferences)
    {
        $this->guard($preferences);

        // Blank evidence/date cells are deliberate, never sample fabricated consent.
        return response("\xEF\xBB\xBFemail,consent_date,consent_reference\n", 200, $this->headers() + [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="email-evidence-review-example.csv"',
        ]);
    }

    public function stage(Request $request, EmailMarketingPreferences $preferences)
    {
        $this->guard($preferences);
        $data = $request->validate(['file' => ['required', 'file', 'max:1024'], 'acknowledge' => ['required', 'accepted']]);
        $file = $data['file'];
        if (strtolower($file->getClientOriginalExtension()) !== 'csv' || $file->getRealPath() === false) {
            throw $this->fileError('csv_required');
        }

        // Fully validate before writing. Addresses are encrypted separately; no messages are sent.
        [$entries, $stats] = $this->readCsv($file->getRealPath(), $preferences);
        $admin = (int) Auth::guard('web')->id();
        try {
            $stats = DB::transaction(function () use ($entries, $stats, $admin) {
                $knownKeyIds = $entries[0]['identity']['known_key_ids'];
                if (ExternalEmailConsentReview::query()->whereNotIn('digest_key_id', $knownKeyIds)->exists()) {
                    throw new DomainException('Historical review identity unavailable.');
                }
                foreach ($entries as $entry) {
                    $identity = $entry['identity'];
                    // Preserve evidence and all previous review/withdrawal decisions.
                    if (ExternalEmailConsentReview::query()->whereIn('email_digest', $identity['digests'])->exists()) {
                        $stats['duplicates']++;
                        if (app(EmailMarketingCampaigns::class)->ready()) {
                            app(EmailMarketingCampaigns::class)->restoreAddress($entry['email'], $admin);
                        }
                        continue;
                    }
                    $row = ExternalEmailConsentReview::query()->firstOrCreate([
                        'email_digest' => $identity['digests'][0],
                    ], [
                        'digest_key_id' => $identity['key_id'],
                        'consent_date' => $entry['date'],
                        'evidence_reference' => $entry['reference'],
                        'imported_by' => $admin,
                    ]);
                    $stats[$row->wasRecentlyCreated ? 'added' : 'duplicates']++;
                    if (app(EmailMarketingCampaigns::class)->ready()) {
                        app(EmailMarketingCampaigns::class)->restoreAddress($entry['email'], $admin);
                    }
                }

                return $stats;
            }, 3);
        } catch (DomainException) {
            throw $this->fileError('csv_invalid');
        }

        return redirect()->to(route('admin.email-marketing.index').'#evidence')->with('evidence_result', $stats);
    }

    private function guard(EmailMarketingPreferences $preferences): void
    {
        abort_unless(Auth::guard('web')->user()?->isAdmin(), 403);
        abort_unless(config('email-marketing.enabled') && config('email-marketing.evidence_review_enabled'), 404);
        abort_unless($preferences->ready() && Schema::hasTable('external_email_consent_reviews'), 503);
    }

    private function readCsv(string $path, EmailMarketingPreferences $preferences): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw $this->fileError('csv_invalid');
        }
        $entries = [];
        $stats = ['rows' => 0, 'added' => 0, 'duplicates' => 0];
        try {
            $header = fgetcsv($handle, 0, ',', '"', '');
            $header = is_array($header) ? array_map(
                fn ($value) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $value))),
                $header
            ) : [];
            if ($header !== ['email', 'consent_date', 'consent_reference']) {
                throw $this->fileError('csv_header');
            }
            while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if ($row === [null]) {
                    continue;
                }
                if (++$stats['rows'] > 1000) {
                    throw $this->fileError('csv_limit');
                }
                if (count($row) !== 3) {
                    throw $this->fileError('csv_invalid');
                }
                [$email, $date, $reference] = array_map(fn ($value) => trim((string) $value), $row);
                $parsed = preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $date)
                    ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
                if (! $parsed || $parsed->format('Y-m-d') !== $date || $date < '1900-01-01' || $date > now()->format('Y-m-d')
                    || ! preg_match('~\A[\p{L}\p{N}][\p{L}\p{N}._:/@# -]{0,159}\z~u', $reference)) {
                    throw $this->fileError('csv_invalid');
                }
                try {
                    $identity = $preferences->evidenceReviewIdentity($email);
                } catch (DomainException) {
                    throw $this->fileError('csv_invalid');
                }
                $digest = $identity['digests'][0];
                if (isset($entries[$digest])) {
                    $stats['duplicates']++;
                    continue;
                }
                $entries[$digest] = ['identity' => $identity, 'email' => $preferences->normalizeEmail($email), 'date' => $date, 'reference' => $reference];
            }
        } finally {
            fclose($handle);
        }
        if (! $entries) {
            throw $this->fileError('csv_invalid');
        }

        return [array_values($entries), $stats];
    }

    private function headers(): array
    {
        return ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow'];
    }

    private function fileError(string $key): ValidationException
    {
        return ValidationException::withMessages(['file' => __('email-evidence-review.'.$key)]);
    }
}
