<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExternalEmailConsentDocumentBatch;
use App\Models\ExternalEmailConsentDocumentItem;
use App\Models\ExternalEmailConsentReview;
use App\Models\ExternalEmailConsentReviewEvent;
use App\Services\Marketing\EmailMarketingPreferences;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Associates a shared document pointer with pending reviews, without deciding or granting consent. */
class EmailEvidenceDocumentController extends Controller
{
    private const LIMIT = 1000;

    public function store(Request $request, EmailMarketingPreferences $preferences)
    {
        abort_unless(Auth::guard('web')->user()?->isAdmin(), 403);
        abort_unless(config('email-marketing.enabled') && config('email-marketing.evidence_review_enabled'), 404);
        abort_unless($preferences->ready() && Schema::hasTable('external_email_consent_reviews')
            && Schema::hasTable('external_email_consent_review_events')
            && Schema::hasTable('external_email_consent_document_batches')
            && Schema::hasTable('external_email_consent_document_items'), 503);

        $selection = $request->input('selection');
        $rules = [
            'selection' => ['required', 'string', Rule::in(['selected', 'all_pending'])],
            'document_reference' => ['required', 'string', 'max:160', 'regex:~\A[\p{L}\p{N}][\p{L}\p{N}._:/# -]{0,159}\z~u'],
            'acknowledge' => ['required', 'accepted'],
        ];
        if ($selection === 'selected') {
            $rules['review_ids'] = ['required', 'array', 'min:1', 'max:'.self::LIMIT];
            $rules['review_ids.*'] = ['required', 'integer', 'min:1', 'distinct'];
        } elseif ($selection === 'all_pending') {
            $rules['expected_count'] = ['required', 'integer', 'min:1', 'max:'.self::LIMIT];
        }
        // Never flash the document pointer or request inputs into the session.
        $validator = Validator::make($request->only(['selection', 'document_reference', 'acknowledge', 'review_ids', 'expected_count']), $rules);
        if ($validator->fails()) {
            return $this->result('document_error', 'document_error_invalid');
        }
        $input = $validator->validated();
        $adminId = (int) Auth::guard('web')->id();

        $linked = DB::transaction(function () use ($input, $adminId) {
            if ($input['selection'] === 'all_pending') {
                $ids = ExternalEmailConsentReview::query()->doesntHave('decisions')->doesntHave('sharedDocumentItem')
                    ->orderBy('id')->limit(self::LIMIT + 1)->pluck('id')->map(fn ($id) => (int) $id)->all();
                if (count($ids) !== (int) $input['expected_count'] || count($ids) > self::LIMIT) {
                    return null;
                }
            } else {
                $ids = array_map('intval', array_values($input['review_ids']));
                if (count($ids) !== count(array_unique($ids))) {
                    return null;
                }
            }
            // Locks use the same parent records as individual review decisions. Recheck all
            // evidence states after acquiring them, so stale or overlapping groups never partially link.
            $locked = ExternalEmailConsentReview::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);
            if ($locked->count() !== count($ids)
                || ExternalEmailConsentReviewEvent::query()->whereIn('review_id', $ids)->lockForUpdate()->first(['id']) !== null
                || ExternalEmailConsentDocumentItem::query()->whereIn('review_id', $ids)->lockForUpdate()->first(['id']) !== null) {
                return null;
            }

            $batch = ExternalEmailConsentDocumentBatch::query()->create([
                'document_reference' => $input['document_reference'],
                'record_count' => count($ids),
                'created_by' => $adminId,
                'created_at' => now(),
            ]);
            foreach (array_chunk($ids, 200) as $chunk) {
                ExternalEmailConsentDocumentItem::query()->insert(array_map(
                    fn (int $id) => ['batch_id' => $batch->id, 'review_id' => $id],
                    $chunk
                ));
            }
            // No evidence decisions, recipient consent, preferences, eligibility, or mail are changed.
            return count($ids);
        }, 3);

        return $linked === null
            ? $this->result('document_error', 'document_error_stale')
            : $this->result('document_success', 'document_success', ['count' => $linked]);
    }

    private function result(string $flash, string $translation, array $values = [])
    {
        return redirect()->to(route('admin.email-marketing.index').'#evidence')
            ->withHeaders(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow'])
            ->with($flash, __('email-evidence-review.'.$translation, $values));
    }
}
