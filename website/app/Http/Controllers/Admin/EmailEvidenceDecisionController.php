<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExternalEmailConsentReview;
use App\Models\ExternalEmailConsentReviewEvent;
use App\Services\Marketing\EmailMarketingPreferences;
use DomainException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Manual review of individually inspected documentary evidence, never a consent grant. */
class EmailEvidenceDecisionController extends Controller
{
    private const TRANSITIONS = [
        'pending' => ['verified', 'rejected', 'revoked'],
        'verified' => ['rejected', 'revoked'],
        'rejected' => ['revoked'],
        'revoked' => [],
    ];

    private const SOURCE_KINDS = ['web_form', 'written_consent', 'account_opt_in', 'other_documented'];

    private const REJECTION_REASONS = ['missing_evidence', 'identity_mismatch', 'not_marketing_consent', 'ambiguous_evidence', 'invalidated'];

    private const REVOCATION_REASONS = ['recipient_withdrawal', 'administrative_prevent'];

    public function review(string $review, EmailMarketingPreferences $preferences)
    {
        $this->guard($preferences);
        $record = ExternalEmailConsentReview::query()->select(['id', 'consent_date', 'created_at'])->findOrFail($review);
        // Do not expose either original or subsequent evidence references or address digests.
        $events = $record->decisions()->select(['id', 'review_id', 'decision', 'reason', 'source_kind', 'reviewed_by', 'created_at'])
            ->with('reviewer:id,name')->latest('id')->get();
        $state = $events->first()?->decision ?? 'pending';
        $expectedEvent = $events->first()?->id ?? 0;

        return response()->view('admin.email-evidence-review-detail', compact('record', 'events', 'state', 'expectedEvent'))
            ->withHeaders($this->headers());
    }

    public function decide(Request $request, string $review, EmailMarketingPreferences $preferences)
    {
        $this->guard($preferences);
        // No validate()/withInput(): raw source addresses and references must never enter flash/session.
        $decision = $request->input('decision');
        $rules = [
            'decision' => ['required', 'string', Rule::in(['verified', 'rejected', 'revoked'])],
            'expected_event' => ['required', 'integer', 'min:0'],
            'acknowledge' => ['required', 'accepted'],
        ];
        $pointer = ['string', 'min:3', 'max:160', 'regex:~\A[\p{L}\p{N}][\p{L}\p{N}._:/# -]{2,159}\z~u'];
        if ($decision === 'verified') {
            $rules += [
                'source_email' => ['required', 'string', 'max:190'],
                'source_date' => ['required', 'date_format:Y-m-d'],
                'source_reference' => ['required', 'string', 'max:160'],
                'source_kind' => ['required', 'string', Rule::in(self::SOURCE_KINDS)],
                'review_reference' => array_merge(['required'], $pointer),
            ];
        } elseif ($decision === 'rejected') {
            $rules['reason'] = ['required', 'string', Rule::in(self::REJECTION_REASONS)];
        } elseif ($decision === 'revoked') {
            $rules['reason'] = ['required', 'string', Rule::in(self::REVOCATION_REASONS)];
            $rules['review_reference'] = array_merge(['required_if:reason,recipient_withdrawal', 'nullable'], $pointer);
        }
        $validator = Validator::make($request->only(array_keys($rules)), $rules);
        if ($validator->fails()) {
            return $this->result($review, 'review_error', 'review_error_invalid');
        }
        $input = $validator->validated();
        $adminId = (int) Auth::guard('web')->id();

        try {
            $outcome = DB::transaction(function () use ($review, $input, $adminId, $preferences) {
                // Serialize decisions on the same immutable record and reject stale forms/replays.
                $record = ExternalEmailConsentReview::query()->lockForUpdate()->findOrFail($review);
                $previous = $record->decisions()->latest('id')->lockForUpdate()->first();
                $state = $previous?->decision ?? 'pending';
                $decision = $input['decision'];
                if ((int) ($previous?->id ?? 0) !== (int) $input['expected_event']
                    || ! in_array($decision, self::TRANSITIONS[$state] ?? [], true)) {
                    return 'review_error_state';
                }

                if ($decision === 'verified') {
                    // The operator must use the independent original source, not just the intake CSV.
                    // Match the supplied evidence to this specific record using the original key identity.
                    $identity = $preferences->evidenceReviewIdentity($input['source_email']);
                    $keyIndex = array_search($record->digest_key_id, $identity['known_key_ids'], true);
                    $sourceReference = (string) $record->evidence_reference;
                    $reviewReference = (string) $input['review_reference'];
                    if ($keyIndex === false || ! isset($identity['digests'][$keyIndex])
                        || ! hash_equals($record->email_digest, $identity['digests'][$keyIndex])
                        || $record->consent_date->format('Y-m-d') !== $input['source_date']
                        || ! hash_equals($sourceReference, $input['source_reference'])
                        || strcasecmp($reviewReference, $sourceReference) === 0
                        || in_array($reviewReference, [(string) $record->id, (string) $record->contact_id], true)) {
                        return 'review_error_mismatch';
                    }
                }

                ExternalEmailConsentReviewEvent::query()->create([
                    'review_id' => $record->id,
                    'decision' => $decision,
                    'reason' => $decision === 'verified' ? 'evidence_checked' : $input['reason'],
                    'source_kind' => $decision === 'verified' ? $input['source_kind'] : null,
                    'review_reference' => $input['review_reference'] ?? null,
                    'reviewed_by' => $adminId,
                    'created_at' => now(),
                ]);

                return 'review_success';
            }, 3);
        } catch (DomainException|DecryptException) {
            return $this->result($review, 'review_error', 'review_error_mismatch');
        }

        return $this->result($review, $outcome === 'review_success' ? 'review_success' : 'review_error', $outcome);
    }

    private function guard(EmailMarketingPreferences $preferences): void
    {
        abort_unless(Auth::guard('web')->user()?->isAdmin(), 403);
        abort_unless(config('email-marketing.enabled') && config('email-marketing.evidence_review_enabled'), 404);
        abort_unless($preferences->ready() && Schema::hasTable('external_email_consent_reviews')
            && Schema::hasTable('external_email_consent_review_events'), 503);
    }

    private function result(string $review, string $flash, string $translation)
    {
        return redirect()->route('admin.email-marketing.evidence.review', ['review' => $review])
            ->withHeaders($this->headers())->with($flash, __('email-evidence-review.'.$translation));
    }

    private function headers(): array
    {
        return ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow'];
    }
}
