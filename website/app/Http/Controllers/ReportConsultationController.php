<?php

namespace App\Http\Controllers;

use App\Models\AuditReport;
use App\Services\Crm\ChatLeadCapture;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/** An inbound consultation request, never permission for a marketing sequence. */
class ReportConsultationController extends Controller
{
    public function store(Request $request, string $token, ChatLeadCapture $capture): RedirectResponse
    {
        foreach (['audit_reports', 'crm_leads', 'crm_messages'] as $table) {
            abort_unless(Schema::hasTable($table), 503);
        }
        $report = AuditReport::where('token', $token)->firstOrFail();
        $destination = $report->url().'#consultation';
        if ($report->isStale()) {
            return redirect($destination)->withErrors(['consultation' => __('conversion.report_stale')], 'reportConsultation');
        }
        $input = $request->only(['consultation_name', 'consultation_email', 'consultation_phone',
            'consultation_message', 'consultation_consent', 'consultation_website']);
        if (is_string($input['consultation_email'] ?? null)) {
            $input['consultation_email'] = mb_strtolower(trim($input['consultation_email']));
        }
        foreach (['consultation_name', 'consultation_phone', 'consultation_message'] as $key) {
            if (is_string($input[$key] ?? null)) {
                $input[$key] = trim($input[$key]);
            }
        }
        $validator = Validator::make($input, [
            'consultation_name' => ['required', 'string', 'max:120'],
            'consultation_email' => ['required', 'email', 'max:190'],
            'consultation_phone' => ['nullable', 'string', 'max:40'],
            'consultation_message' => ['required', 'string', 'max:1500'],
            'consultation_consent' => ['required', 'accepted'],
            'consultation_website' => ['nullable', 'string', 'max:0'],
        ]);
        if ($validator->fails()) {
            return redirect($destination)->withErrors($validator, 'reportConsultation')
                ->withInput(array_intersect_key($input, array_flip(['consultation_name', 'consultation_email', 'consultation_phone', 'consultation_message'])));
        }
        $data = $validator->validated();
        $capture->capture([
            'external_id' => 'seo-report:'.$report->id.':'.hash('sha256', $data['consultation_email']),
            // The lead identity stays report + email. Only distinct validated
            // content creates another inbound message; identical retries do not.
            'message_key' => json_encode([
                'name' => $data['consultation_name'], 'phone' => $data['consultation_phone'] ?? null,
                'message' => $data['consultation_message'], 'consent_version' => 'consultation-reply-v1',
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'ticket' => 'SEO-'.$report->id,
            'name' => $data['consultation_name'],
            'email' => $data['consultation_email'],
            'phone' => $data['consultation_phone'] ?? null,
            'locale' => app()->getLocale(),
            // Only persisted report facts are authoritative. Client score, host,
            // status, source and campaign fields cannot become CRM instructions.
            'summary' => 'SEO consultation | report_id='.$report->id.' | host='.$report->host
                .' | score='.$report->score.' | report_source='.$report->source
                ."\n".$data['consultation_message']
                ."\nConsent: consultation-reply-v1; locale=".app()->getLocale().'; scope=this request only',
            'occurred_at' => now()->utc()->toIso8601String(),
        ]);

        return redirect($destination)->with('report_consultation_success', true);
    }
}
