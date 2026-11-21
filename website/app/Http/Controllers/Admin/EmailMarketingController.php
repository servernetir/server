<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\EmailMarketingPreference;
use App\Models\ExternalEmailConsentReview;
use App\Services\Marketing\EmailMarketingPreferences;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmailMarketingController extends Controller
{
    public function index(Request $request, EmailMarketingPreferences $preferences)
    {
        $this->guard($preferences);
        $filter = $request->validate(['state' => ['nullable', Rule::in(['all', 'pending', 'granted', 'withdrawn', 'suppressed'])]]);
        $state = $filter['state'] ?? 'all';
        $query = EmailMarketingPreference::query()->with('customer:id,code,email');
        if ($state === 'suppressed') {
            $query->whereNotNull('suppressed_at');
        } elseif ($state !== 'all') {
            $query->where('state', $state)->whereNull('suppressed_at');
        }
        $records = $query->latest('id')->paginate(35)->withQueryString();
        $eligible = $preferences->eligibilityMap($records->getCollection());
        $counts = [
            'all' => EmailMarketingPreference::count(),
            'pending' => EmailMarketingPreference::where('state', 'pending')->whereNull('suppressed_at')->count(),
            'granted' => EmailMarketingPreference::where('state', 'granted')->whereNull('suppressed_at')->count(),
            'blocked' => EmailMarketingPreference::where(function ($q) {
                $q->where('state', 'withdrawn')->orWhereNotNull('suppressed_at');
            })->count(),
        ];
        $masked = [];
        foreach ($records as $record) {
            $email = (string) ($record->customer?->email ?? '');
            $masked[$record->id] = '—';
            try {
                if ($email !== '' && (($eligible[$record->id] ?? false)
                    || hash_equals($record->email_digest, $preferences->emailDigest($email)))) {
                    $masked[$record->id] = $this->maskedEmail($email);
                }
            } catch (DomainException) {
                // A changed or unsupported address is not displayed as the
                // address attached to an older consent record.
            }
        }

        // Show review-only evidence alongside existing customer preferences.
        // The evidence table is optional and never contributes to eligibility.
        $evidenceRecords = null;
        $evidenceReviewReady = false;
        $evidenceDocumentReady = false;
        $evidenceDocumentAvailable = 0;
        $evidenceCounts = ['pending' => 0, 'verified' => 0, 'rejected' => 0, 'revoked' => 0];
        if (config('email-marketing.evidence_review_enabled') && Schema::hasTable('external_email_consent_reviews')) {
            $evidenceReviewReady = Schema::hasTable('external_email_consent_review_events');
            $evidenceQuery = ExternalEmailConsentReview::query()->select(['id', 'consent_date', 'created_at'])->latest('id');
            if ($evidenceReviewReady) {
                $evidenceQuery->with('latestDecision');
                $evidenceCounts['pending'] = ExternalEmailConsentReview::query()->doesntHave('decisions')->count();
                foreach (['verified', 'rejected', 'revoked'] as $decision) {
                    $evidenceCounts[$decision] = ExternalEmailConsentReview::query()
                        ->whereHas('latestDecision', fn ($q) => $q->where('decision', $decision))->count();
                }
                $evidenceDocumentReady = Schema::hasTable('external_email_consent_document_batches')
                    && Schema::hasTable('external_email_consent_document_items');
                if ($evidenceDocumentReady) {
                    $evidenceQuery->with('sharedDocumentItem');
                    $evidenceDocumentAvailable = ExternalEmailConsentReview::query()
                        ->doesntHave('decisions')->doesntHave('sharedDocumentItem')->count();
                }
            }
            $evidenceRecords = $evidenceQuery->paginate(35, ['*'], 'evidence_page')->withQueryString()->fragment('evidence');
            if (! $evidenceReviewReady) {
                $evidenceCounts['pending'] = $evidenceRecords->total();
            }
        }

        return response()->view('admin.email-marketing', compact('records', 'eligible', 'counts', 'masked', 'state', 'evidenceRecords', 'evidenceReviewReady', 'evidenceCounts', 'evidenceDocumentReady', 'evidenceDocumentAvailable'))
            ->withHeaders(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow']);
    }

    public function sample(EmailMarketingPreferences $preferences)
    {
        $this->guard($preferences);

        return response("\xEF\xBB\xBFname,email\nExample Contact,subscriber@example.test\n", 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="email-preferences-example.csv"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function stage(Request $request, EmailMarketingPreferences $preferences)
    {
        $this->guard($preferences);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:1024'],
            'acknowledge' => ['required', 'accepted'],
        ]);
        $file = $data['file'];
        if (strtolower($file->getClientOriginalExtension()) !== 'csv') {
            throw $this->fileError('email-marketing.csv_required');
        }
        $path = $file->getRealPath();
        if ($path === false) {
            throw $this->fileError('email-marketing.csv_invalid');
        }
        [$emails, $stats] = $this->readCsv($path);
        $stats += ['matched' => 0, 'pending_added' => 0, 'unchanged' => 0, 'blocked' => 0, 'unmatched' => 0];
        $adminId = (int) Auth::guard('web')->id();

        // Only existing verified customer accounts may be considered. Uploaded
        // names are ignored, addresses not matched to an account are discarded,
        // and an import never grants consent or initiates a message.
        foreach (array_chunk($emails, 100) as $chunk) {
            $matches = Customer::query()->whereNotNull('email_verified_at')
                ->whereIn(DB::raw('LOWER(TRIM(email))'), $chunk)->get(['id', 'email'])
                ->groupBy(fn (Customer $c) => strtolower(trim((string) $c->email)));
            foreach ($chunk as $email) {
                $owners = $matches->get($email);
                if ($owners === null || $owners->isEmpty()) {
                    $stats['unmatched']++;
                    continue;
                }
                if ($owners->count() !== 1) {
                    $stats['blocked']++;
                    continue;
                }
                $customer = $owners->first();
                $stats['matched']++;
                try {
                    $record = $preferences->stage($customer, $adminId);
                    if ($record->wasRecentlyCreated) {
                        $stats['pending_added']++;
                    } else {
                        $stats['unchanged']++;
                    }
                } catch (DomainException) {
                    $stats['blocked']++;
                }
            }
        }

        // Only aggregate counters enter the session. No raw uploaded contacts
        // are retained or copied outside the existing customer database.
        return redirect()->route('admin.email-marketing.index')
            ->with('stage_result', $stats);
    }

    public function suppress(string $preference, EmailMarketingPreferences $preferences)
    {
        $this->guard($preferences);
        $record = EmailMarketingPreference::query()->findOrFail($preference);
        $preferences->suppress($record, (int) Auth::guard('web')->id());

        return redirect()->route('admin.email-marketing.index')->with('ok', __('email-marketing.suppressed_saved'));
    }

    private function guard(EmailMarketingPreferences $preferences): void
    {
        abort_unless(Auth::guard('web')->user()?->isAdmin(), 403);
        abort_unless(config('email-marketing.enabled'), 404);
        abort_unless($preferences->ready(), 503);
    }

    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw $this->fileError('email-marketing.csv_invalid');
        }
        $seen = [];
        $stats = ['rows' => 0, 'invalid' => 0, 'duplicates' => 0];
        try {
            $header = fgetcsv($handle, 0, ',', '"', '');
            $header = is_array($header) ? array_map(
                fn ($value) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $value))),
                $header
            ) : [];
            if ($header !== ['name', 'email'] && $header !== ['email']) {
                throw $this->fileError('email-marketing.csv_header');
            }
            $column = array_search('email', $header, true);
            while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if ($row === [null]) {
                    continue;
                }
                if (++$stats['rows'] > 1000) {
                    throw $this->fileError('email-marketing.csv_limit');
                }
                $address = strtolower(trim((string) ($row[$column] ?? '')));
                if (count($row) !== count($header) || strlen($address) > 190
                    || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                    $stats['invalid']++;
                    continue;
                }
                if (isset($seen[$address])) {
                    $stats['duplicates']++;
                    continue;
                }
                $seen[$address] = true;
            }
        } finally {
            fclose($handle);
        }

        return [array_keys($seen), $stats];
    }

    private function maskedEmail(string $email): string
    {
        $parts = explode('@', $email, 2);
        return count($parts) === 2 ? substr($parts[0], 0, 1).'***@'.$parts[1] : '—';
    }

    private function fileError(string $key): ValidationException
    {
        return ValidationException::withMessages(['file' => __($key)]);
    }
}
