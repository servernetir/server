<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CrmLead;
use App\Models\CrmMessage;
use App\Models\CrmSuppression;
use App\Services\Crm\OutreachComposer;
use App\Services\Crm\OutreachMailer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * قیفِ جذبِ مشتری در پنلِ مدیریت.
 *
 * ⚠️ این صفحه عمداً «داشبوردِ زیبا» نیست، **میزِ تأیید** است. تا وقتی خلبانِ
 * خودکار خاموش است، هیچ ایمیلی بدونِ عبور از دکمهٔ همین صفحه بیرون نمی‌رود.
 *
 * 🔴 نگهبانِ `hasTable` روی همه‌جا: روی سروری که هنوز مهاجرت نخورده، این صفحه
 * باید بگوید «آماده نیست»، نه اینکه کلِ پنل را ۵۰۰ کند.
 */
class CrmController extends Controller
{
    public function index(Request $request, OutreachMailer $mailer): View
    {
        if (! Schema::hasTable('crm_leads')) {
            return view('admin.crm', ['notReady' => true]);
        }

        $counts = CrmLead::selectRaw('stage, count(*) as c')
            ->groupBy('stage')
            ->pluck('c', 'stage')
            ->all();

        $stage = (string) $request->query('stage', '');

        $leads = CrmLead::query()
            ->when($stage !== '', fn ($q) => $q->where('stage', $stage))
            ->orderByRaw("case when stage = 'replied' then 0 else 1 end")
            ->orderByDesc('id')
            ->limit(120)
            ->get();

        // صفِ تأیید — چیزی که همین حالا منتظرِ توست
        $pending = CrmMessage::with('lead')
            ->where('direction', 'out')
            ->where('status', 'queued')
            ->orderBy('id')
            ->limit(40)
            ->get();

        return view('admin.crm', [
            'notReady'    => false,
            'counts'      => $counts,
            'leads'       => $leads,
            'stage'       => $stage,
            'pending'     => $pending,
            'sentToday'   => $mailer->sentToday(),
            'dailyCap'    => $mailer->dailyCap(),
            'inWindow'    => $mailer->inSendWindow(),
            'autopilot'   => (bool) config('crm.autopilot'),
            'suppressed'  => CrmSuppression::count(),
            'health'      => $this->health(),
        ]);
    }

    public function show(CrmLead $lead): View
    {
        return view('admin.crm-lead', [
            'lead'     => $lead,
            'messages' => $lead->messages()->orderBy('id')->get(),
            'blocked'  => CrmSuppression::blocks($lead->email),
        ]);
    }

    /** افزودنِ دستیِ سرنخ — مسیرِ بی‌کلید، وقتی Places فعال نیست. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'company'  => ['required', 'string', 'max:160'],
            'website'  => ['required', 'url', 'max:190'],
            'email'    => ['nullable', 'email', 'max:190'],
            'city'     => ['nullable', 'string', 'max:80'],
            'country'  => ['nullable', 'string', 'max:2'],
            'vertical' => ['nullable', 'string', 'max:40'],
        ]);

        $hash = CrmLead::hashFor($data['website']);

        if (CrmLead::where('domain_hash', $hash)->exists()) {
            return back()->withErrors(['website' => 'این دامنه از قبل در قیف هست.']);
        }

        if (filled($data['email'] ?? null) && CrmSuppression::blocks($data['email'])) {
            return back()->withErrors(['email' => 'این نشانی در فهرستِ سیاه است و افزوده نمی‌شود.']);
        }

        CrmLead::create($data + [
            'domain_hash' => $hash,
            'source'      => 'manual',
            'stage'       => 'new',
        ]);

        return back()->with('ok', 'سرنخ افزوده شد. در اجرای بعدیِ بررسی، سایتش خوانده می‌شود.');
    }

    /** بررسیِ همین‌حالای یک سرنخ (ممیزی + نشانی + مشاهده) */
    public function enrich(CrmLead $lead, OutreachComposer $composer): RedirectResponse
    {
        $ok = $composer->enrich($lead);

        return back()->with('ok', $ok
            ? 'بررسی شد و مشاهده ثبت گردید.'
            : 'بررسی انجام شد ولی مشاهدهٔ مشخصی پیدا نشد — پیامی ساخته نمی‌شود (عمدی).');
    }

    /** ساختِ پیشنویسِ پیامِ بعدی برای همین سرنخ */
    public function compose(CrmLead $lead, OutreachComposer $composer): RedirectResponse
    {
        $message = $composer->compose($lead);

        return back()->with('ok', $message
            ? 'پیش‌نویس ساخته شد و در صفِ تأیید است.'
            : 'پیامی ساخته نشد. دلیلش در ردیابِ خطا/لاگ ثبت شده است.');
    }

    /**
     * تأیید و ارسالِ یک پیام — از همان مسیرِ کرون، نه میان‌بر.
     *
     * `sendOne` خودش دوباره فهرستِ سیاه را چک می‌کند، مرحله را جلو می‌برد و
     * تاریخِ فالوآپ را می‌گذارد. اگر اینجا مستقیم `Mail::` می‌زدیم، تأییدِ دستی
     * قیف را از حرکت می‌انداخت.
     */
    public function approve(CrmMessage $message, OutreachMailer $mailer): RedirectResponse
    {
        if ($message->status !== 'queued') {
            return back()->withErrors(['message' => 'این پیام دیگر در صف نیست.']);
        }

        $message->update(['status' => 'sending']);
        $ok = $mailer->sendOne($message->refresh());

        return back()->with('ok', $ok
            ? 'ارسال شد.'
            : 'ارسال نشد — دلیل روی همان پیام ثبت شد.');
    }

    public function reject(CrmMessage $message): RedirectResponse
    {
        $message->update(['status' => 'cancelled', 'error' => 'rejected in panel']);

        return back()->with('ok', 'پیام رد شد و فرستاده نمی‌شود.');
    }

    /** تغییرِ دستیِ مرحله — مثلاً وقتی جواب تلفنی آمده */
    public function stage(Request $request, CrmLead $lead): RedirectResponse
    {
        $data = $request->validate([
            'stage'  => ['required', 'string', 'in:'.implode(',', array_keys(CrmLead::STAGES))],
            'offer'  => ['nullable', 'string', 'max:60'],
            'value'  => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'reason' => ['nullable', 'string', 'max:190'],
        ]);

        $lead->stage = $data['stage'];
        $lead->offer = $data['offer'] ?? $lead->offer;
        $lead->value_eur = $data['value'] ?? $lead->value_eur;

        if ($data['stage'] === 'won') {
            $lead->won_at = now();
            $lead->next_action_at = null;
        } elseif ($data['stage'] === 'lost') {
            $lead->lost_at = now();
            $lead->lost_reason = $data['reason'] ?? $lead->lost_reason;
            $lead->next_action_at = null;
        } else {
            $lead->next_action_at = now()->addDays(CrmLead::CADENCE[$data['stage']] ?? 3)->toDateString();
        }

        $lead->save();

        return back()->with('ok', 'مرحله به «'.$lead->stageLabel().'» تغییر کرد.');
    }

    /**
     * افزودن به فهرستِ سیاه.
     *
     * 🔴 دکمهٔ برعکسی وجود ندارد و نباید ساخته شود: «no» یعنی هرگزِ دائمی.
     */
    public function suppress(CrmLead $lead): RedirectResponse
    {
        if (blank($lead->email)) {
            return back()->withErrors(['email' => 'این سرنخ نشانیِ ایمیل ندارد.']);
        }

        CrmSuppression::add($lead->email, 'manual', 'از پنل');

        CrmMessage::where('lead_id', $lead->id)
            ->where('direction', 'out')
            ->whereIn('status', ['queued', 'draft'])
            ->update(['status' => 'cancelled', 'error' => 'suppressed in panel']);

        $lead->update([
            'stage'          => 'lost',
            'lost_at'        => now(),
            'lost_reason'    => 'فهرستِ سیاه (دستی)',
            'next_action_at' => null,
        ]);

        return back()->with('ok', 'به فهرستِ سیاه اضافه شد. دیگر هیچ پیامی به این نشانی نمی‌رود.');
    }

    /**
     * سلامتِ پیکربندی — «چرا هیچ اتفاقی نمی‌افتد؟» باید یک نگاه جواب بگیرد.
     *
     * الگویِ تکرارشوندهٔ این پروژه «شکست نمی‌خورد، فقط اتفاق نمی‌افتد» است.
     */
    private function health(): array
    {
        return [
            'places' => filled(config('crm.discovery.places_key')),
            'model'  => filled(config('services.'.config('services.ai_routing.outreach', 'deepseek').'.key')),
            'imap'   => filled(config('crm.inbox.host')) && filled(config('crm.inbox.pass')),
            'mailer' => filled(config('mail.mailers.'.config('crm.mailer').'.host')),
        ];
    }
}
