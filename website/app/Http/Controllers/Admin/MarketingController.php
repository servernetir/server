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
class MarketingController extends Controller
{
    public function index(Request $request, OutreachMailer $mailer): View
    {
        if (! Schema::hasTable('crm_leads')) {
            return view('admin.marketing.funnel', ['notReady' => true]);
        }

        $counts = CrmLead::selectRaw('stage, count(*) as c')->groupBy('stage')->pluck('c', 'stage')->all();
        $stage = (string) $request->query('stage', '');
        $tab = (string) $request->query('tab', 'funnel');

        $leads = CrmLead::query()
            ->when($stage !== '', fn ($q) => $q->where('stage', $stage))
            ->orderByRaw("case when stage = 'replied' then 0 else 1 end")
            ->orderByDesc('id')
            ->limit(120)
            ->get();

        $pending = CrmMessage::with('lead')
            ->where('direction', 'out')->where('status', 'queued')
            ->orderBy('id')->limit(40)->get();

        $closed = ['won', 'lost'];

        return view('admin.marketing.funnel', [
            'notReady'  => false,
            'counts'    => $counts,
            'leads'     => $leads,
            'stage'     => $stage,
            'tab'       => in_array($tab, ['funnel', 'queue', 'add'], true) ? $tab : 'funnel',
            'pending'   => $pending,
            'sentToday' => $mailer->sentToday(),
            'dailyCap'  => $mailer->dailyCap(),
            'inWindow'  => $mailer->inSendWindow(),
            'autopilot' => (bool) config('crm.autopilot'),
            'health'    => $this->health(),
            'stats'     => [
                'active'   => CrmLead::whereNotIn('stage', $closed)->count(),
                'new'      => CrmLead::where('stage', 'new')->count(),
                'enriched' => CrmLead::whereNotNull('observation')->count(),
                'pending'  => $pending->count(),
                'replied'  => CrmLead::where('stage', 'replied')->count(),
                'sent'     => CrmMessage::where('direction', 'out')->where('status', 'sent')->count(),
                'won'      => CrmLead::where('stage', 'won')->count(),
                'value'    => (int) CrmLead::whereNotIn('stage', $closed)->sum('value_eur'),
            ],
        ]);
    }

    /**
     * رشد و دیده‌شدن — سئوی خودمان و جاهایی که باید در آن‌ها باشیم.
     *
     * 🔴 اینجا هیچ‌چیزی به‌صورت خودکار در سایتِ کسِ دیگری چیزی نمی‌نویسد.
     * کامنت‌گذاریِ خودکار برای بک‌لینک، در سیاستِ رسمیِ گوگل «لینک اسپم» است
     * و نتیجه‌اش پایین رفتنِ رتبه یا حذف از نتایج است — یعنی دقیقاً برعکسِ
     * چیزی که می‌خواهیم. بک‌لینک از راهِ درست: پیدا کردنِ صفحه‌های واقعاً
     * مرتبط و نوشتنِ درخواست، که انسان می‌فرستد.
     */
    public function growth(): View
    {
        $audit = cache()->remember('marketing.self_audit', 3600, function () {
            $report = (new \App\Services\SiteAudit)->run(config('app.url'));

            return ($report['ok'] ?? false) ? $report : null;
        });

        return view('admin.marketing.growth', [
            'audit'       => $audit,
            'directories' => (array) config('crm.directories', []),
        ]);
    }

    public function show(CrmLead $lead): View
    {
        return view('admin.marketing.lead', [
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

    /**
     * ساختِ پیش‌نویسِ لینکدین یا اینستاگرام.
     *
     * ⚠️ اینجا دکمهٔ «ارسال» وجود ندارد و نباید ساخته شود. ارسالِ خودکار روی
     * این دو پلتفرم نقضِ شرایطشان است؛ متن ساخته می‌شود، تو کپی می‌کنی و
     * خودت می‌فرستی، بعد «فرستادم» می‌زنی تا ثبت شود.
     */
    public function social(Request $request, CrmLead $lead, OutreachComposer $composer): RedirectResponse
    {
        $data = $request->validate([
            'channel' => ['required', 'in:linkedin,instagram'],
            'kind'    => ['required', 'in:note,dm'],
        ]);

        $message = $composer->draftSocial($lead, $data['channel'], $data['kind']);

        return back()->with('ok', $message
            ? 'پیش‌نویس ساخته شد — کپی کن و خودت بفرست.'
            : 'پیش‌نویسی ساخته نشد. اگر مشاهده‌ای ثبت نشده، اول «بررسیِ سایت» را بزن.');
    }

    /** «فرستادم» — ثبتِ دستیِ پیامی که خودت در لینکدین یا اینستاگرام فرستادی */
    public function markSent(CrmMessage $message): RedirectResponse
    {
        if ($message->channel === 'email') {
            return back()->withErrors(['message' => 'ایمیل از این مسیر ثبت نمی‌شود.']);
        }

        $message->update(['status' => 'sent', 'sent_at' => now()]);

        $lead = $message->lead;

        if ($lead) {
            $lead->last_contacted_at = now();

            // مرحله را فقط از «سرنخ جدید» جلو می‌برد. اگر قبلاً ایمیل رفته،
            // ریتمِ ایمیل صاحبِ مرحله است و پیامِ شبکهٔ اجتماعی نباید عقب یا
            // جلویش ببرد.
            if ($lead->stage === 'new') {
                $lead->stage = 'contacted';
                $lead->next_action_at = now()->addDays(CrmLead::CADENCE['contacted'])->toDateString();
            }

            $lead->save();
        }

        return back()->with('ok', 'ثبت شد.');
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
