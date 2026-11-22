<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Marketing\EmailMarketingCampaigns;
use App\Services\Marketing\MissingEmailRecipientNames;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class EmailMarketingCampaignController extends Controller
{
    public function approve(Request $request, EmailMarketingCampaigns $campaigns)
    {
        $this->guard($campaigns);
        abort_unless(config('email-marketing.evidence_review_enabled'), 404);
        $rules = [
            'selection' => ['required', Rule::in(['selected', 'all_pending'])],
            'document_reference' => ['required', 'string', 'max:160', 'regex:~\A[\p{L}\p{N}][\p{L}\p{N}._:/# -]{0,159}\z~u'],
            'acknowledge' => ['required', 'accepted'],
            'review_ids' => ['required_if:selection,selected', 'array', 'min:1', 'max:1000'],
            'review_ids.*' => ['integer', 'min:1', 'distinct'],
            'expected_count' => ['required_if:selection,all_pending', 'integer', 'min:1', 'max:1000'],
        ];
        $validator = Validator::make($request->only(array_keys($rules)), $rules);
        if ($validator->fails()) {
            return $this->result('error');
        }
        $data = $validator->validated();
        try {
            $stats = $campaigns->approve($data['selection'] === 'all_pending' ? null : $data['review_ids'],
                $data['document_reference'], (int) Auth::guard('web')->id(),
                $data['selection'] === 'all_pending' ? (int) $data['expected_count'] : null);
            return $this->result('approved', $stats + ['count' => $stats['approved']]);
        } catch (DomainException) {
            return $this->result('error');
        }
    }

    public function import(Request $request, EmailMarketingCampaigns $campaigns)
    {
        $this->guard($campaigns);
        abort_unless(config('email-marketing.evidence_review_enabled'), 404);
        $validator = Validator::make($request->only(['file', 'acknowledge']), [
            'file' => ['required', 'file', 'max:1024'], 'acknowledge' => ['required', 'accepted'],
        ]);
        if ($validator->fails()) {
            return $this->result('error');
        }
        $file = $request->file('file');
        if (strtolower($file->getClientOriginalExtension()) !== 'csv' || ! $file->getRealPath()) {
            return $this->result('error');
        }
        try {
            $stats = $campaigns->importCsv($file->getRealPath(), (int) Auth::guard('web')->id());
            return $this->result('imported', $stats + ['count' => $stats['restored']]);
        } catch (DomainException) {
            return $this->result('error');
        }
    }

    public function store(Request $request, EmailMarketingCampaigns $campaigns)
    {
        $this->guard($campaigns);
        $validator = Validator::make($request->only(['subject', 'body', 'audience']), [
            'subject' => ['required', 'string', 'max:180', 'not_regex:/[\r\n]/'],
            'body' => ['required', 'string', 'max:20000'],
            'audience' => ['required', Rule::in(['all', 'customers', 'external'])],
        ]);
        if ($validator->fails()) {
            return $this->result('error');
        }
        $data = $validator->validated();
        try {
            $campaigns->createDraft($data['subject'], $data['body'], $data['audience'], (int) Auth::guard('web')->id());
            return $this->result('saved');
        } catch (DomainException) {
            return $this->result('error');
        }
    }

    public function send(Request $request, string $campaign, EmailMarketingCampaigns $campaigns)
    {
        $this->guard($campaigns);
        if (! $request->boolean('acknowledge')) {
            return $this->result('error');
        }
        try {
            $count = $campaigns->queue((int) $campaign, (int) Auth::guard('web')->id());
            return $this->result('queued', ['count' => $count]);
        } catch (MissingEmailRecipientNames $e) {
            return $this->result('missing_names', ['count' => $e->count]);
        } catch (DomainException) {
            return $this->result('error');
        }
    }

    private function guard(EmailMarketingCampaigns $campaigns): void
    {
        abort_unless(Auth::guard('web')->user()?->isAdmin(), 403);
        abort_unless(config('email-marketing.enabled'), 404);
        abort_unless($campaigns->ready(), 503);
    }

    private function result(string $key, array $values = [])
    {
        return redirect()->to(route('admin.email-marketing.index').'#campaigns')
            ->withHeaders(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow'])
            ->with(in_array($key, ['error', 'missing_names'], true) ? 'campaign_error' : 'campaign_success', __('email-campaigns.'.$key, $values));
    }
}
