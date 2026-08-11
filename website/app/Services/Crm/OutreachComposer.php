<?php

namespace App\Services\Crm;

use App\Models\CrmLead;
use App\Models\CrmMessage;
use App\Models\CrmSuppression;
use App\Services\SiteAudit;
use Illuminate\Support\Facades\Log;

/**
 * از یک سرنخِ خام تا یک پیامِ آمادهٔ صف.
 *
 *   ۱. SiteAudit  → سایتش را واقعاً باز کن و بررسی کن   (کدِ خودمان، از قبل)
 *   ۲. OutreachWriter → یک مشاهدهٔ راست بیرون بکش
 *   ۳. OutreachWriter → ایمیل بنویس
 *   ۴. RedLine    → اگر عدد یا تضمین یا فوریت داشت، دور بینداز
 *   ۵. CrmMessage → در صف بنشان (ارسال کارِ این کلاس نیست)
 *
 * ⚠️ هیچ‌کدام از این مرحله‌ها «تقریباً درست» را قبول نمی‌کند. هر جا داده نبود،
 * سرنخ رد می‌شود و دلیلش لاگ می‌گیرد. سرنخِ ردشده هزینه‌ای ندارد؛ ایمیلِ بی‌ربط
 * دامنه‌ی فرستنده را می‌سوزاند و آن گران است.
 */
class OutreachComposer
{
    public function __construct(
        protected SiteAudit $audit = new SiteAudit,
        protected OutreachWriter $writer = new OutreachWriter,
        protected RedLine $redline = new RedLine,
        protected ContactFinder $contacts = new ContactFinder,
    ) {}

    /**
     * سرنخ را غنی می‌کند: ممیزی سایت + نشانیِ تماس + مشاهده.
     */
    public function enrich(CrmLead $lead): bool
    {
        if (blank($lead->website)) {
            return false;
        }

        $report = $this->audit->run($lead->website);

        if (! ($report['ok'] ?? false)) {
            Log::info('crm.enrich.unreachable', ['lead' => $lead->id, 'url' => $lead->website]);
            return false;
        }

        $lead->audit       = $report;
        $lead->audit_score = (int) ($report['overall'] ?? 0);

        // نشانیِ تماس فقط از روی سایتِ خودشان — هرگز حدس‌زده نمی‌شود.
        if (blank($lead->email)) {
            $c = $this->contacts->find($lead->website);

            if (filled($c['email'])) {
                $lead->email = $c['email'];
            } else {
                Log::info('crm.enrich.no_email', ['lead' => $lead->id, 'url' => $lead->website]);
            }
        }

        $obs = $this->writer->observe($lead->only(['company', 'website']), $report);

        if (blank($obs)) {
            // مدل چیزِ مشخصی پیدا نکرد → پیامی هم نباید برود.
            $lead->save();
            Log::info('crm.enrich.no_observation', ['lead' => $lead->id]);
            return false;
        }

        $lead->observation = $obs;
        $lead->save();

        return true;
    }

    /**
     * پیامِ بعدیِ این سرنخ را می‌سازد و در صف می‌گذارد.
     * اگر شرایط جور نباشد `null` برمی‌گرداند — و **بی‌صدا** نه؛ همیشه لاگ.
     */
    public function compose(CrmLead $lead): ?CrmMessage
    {
        if (! $lead->isContactable()) {
            Log::info('crm.compose.skip.not_contactable', ['lead' => $lead->id]);
            return null;
        }

        if (CrmSuppression::blocks($lead->email)) {
            Log::info('crm.compose.skip.suppressed', ['lead' => $lead->id]);
            return null;
        }

        // سقفِ سختِ دنباله: پیامِ اول + دو فالوآپ. پیامِ چهارم وجود ندارد.
        $sequence = $lead->outbound()->count();
        if ($sequence > CrmMessage::MAX_SEQUENCE) {
            Log::info('crm.compose.skip.sequence_exhausted', ['lead' => $lead->id]);
            return null;
        }

        $draft = $this->writer->email(
            $lead->only(['company', 'city', 'country', 'website', 'observation']),
            $sequence,
        );

        if (! $draft) {
            Log::warning('crm.compose.model_failed', ['lead' => $lead->id]);
            return null;
        }

        $body = $this->withSignature($draft['body']);

        // آخرین سد. اگر آلوده بود، هیچ چیزی در صف نمی‌نشیند.
        if (! $this->redline->allow($draft['subject']."\n".$body, ['lead' => $lead->id])) {
            return null;
        }

        return CrmMessage::create([
            'lead_id'   => $lead->id,
            'channel'   => 'email',
            'direction' => 'out',
            'subject'   => $draft['subject'],
            'body'      => $body,
            'status'    => 'queued',
            'sequence'  => $sequence,
        ]);
    }

    /**
     * پیش‌نویسِ لینکدین یا اینستاگرام — برای کپی کردن، نه برای فرستادن.
     *
     * 🔴 `status = 'draft'` و نه `'queued'`. این تفاوت، کلِ مرزِ ایمنیِ این
     * ماژول است: `OutreachMailer::drain()` فقط `queued` را برمی‌دارد، پس هیچ
     * مسیرِ خودکاری — حتی اگر روزی کسی کانالِ لینکدین را به آن اضافه کند —
     * نمی‌تواند این‌ها را بفرستد. اتوماتیکِ لینکدین یعنی اکانتِ سوخته.
     *
     * @param  'linkedin'|'instagram'  $channel
     * @param  'note'|'dm'  $kind
     */
    public function draftSocial(CrmLead $lead, string $channel, string $kind = 'dm'): ?CrmMessage
    {
        if (! in_array($channel, ['linkedin', 'instagram'], true)) {
            return null;
        }

        // اینجا عمداً `isContactable()` صدا زده نمی‌شود: آن شرط نشانیِ ایمیل
        // می‌خواهد و برای پیامِ لینکدین ایمیل لازم نیست. ولی «مشاهده» لازم
        // است — همان قانونِ ۶۰ ثانیه، روی هر کانالی.
        if (blank($lead->observation)) {
            Log::info('crm.social.skip.no_observation', ['lead' => $lead->id, 'channel' => $channel]);

            return null;
        }

        if (in_array($lead->stage, ['won', 'lost'], true)) {
            Log::info('crm.social.skip.closed', ['lead' => $lead->id]);

            return null;
        }

        $draft = $this->writer->social(
            $lead->only(['company', 'city', 'country', 'website', 'observation']),
            $channel,
            $kind,
        );

        if (! $draft) {
            Log::warning('crm.social.model_failed', ['lead' => $lead->id, 'channel' => $channel]);

            return null;
        }

        if (! $this->redline->allow($draft['body'], ['lead' => $lead->id, 'channel' => $channel])) {
            return null;
        }

        return CrmMessage::create([
            'lead_id'   => $lead->id,
            'channel'   => $channel,
            'direction' => 'out',
            'subject'   => $kind === 'note' ? 'یادداشتِ درخواستِ ارتباط' : 'پیامِ مستقیم',
            'body'      => $draft['body'],
            'status'    => 'draft',
            'sequence'  => $lead->messages()->where('channel', $channel)->count(),
        ]);
    }

    /**
     * امضا + نشانیِ پستی + راهِ لغو.
     *
     * 🔴 نشانیِ فیزیکی و راهِ لغو **الزامِ قانونی** است (CAN-SPAM، CASL)، نه
     * تعارف. بدونِ این‌ها ایمیل نباید برود.
     */
    protected function withSignature(string $body): string
    {
        $f = config('crm.from');

        return rtrim($body)."\n\n"
            ."--\n"
            ."{$f['name']}\n"
            ."{$f['title']}\n"
            ."{$f['email']} · {$f['site']}\n"
            ."{$f['address']}\n\n"
            ."You received this because your business contact address is published online. "
            ."Reply \"no\" and I will not write again.";
    }
}
