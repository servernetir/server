<?php

namespace Tests\Feature;

use App\Models\CrmLead;
use App\Models\CrmMessage;
use App\Models\MailboxMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تولیدکنندهٔ پیش‌نمایشِ پنل — تستِ واقعی نیست، ابزار است.
 *
 * صفحه‌های واقعی را با دادهٔ نمونه رندر می‌کند و در storage/app/panel-preview.html
 * می‌ریزد تا بشود بدونِ لاگین و بدونِ دیپلوی نگاهشان کرد. CSS داخلش تزریق
 * می‌شود چون فایلِ بیرونی روی مرورگرِ کسِ دیگر باز نمی‌شود.
 *
 * فقط وقتی `PANEL_PREVIEW=1` باشد اجرا می‌شود، وگرنه در سوئیتِ اصلی
 * رد می‌شود — این یک تست نیست و نباید مثلِ تست شکست بخورد.
 */
class PanelPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_render_preview(): void
    {
        if (env('PANEL_PREVIEW') !== '1') {
            $this->markTestSkipped('ابزارِ پیش‌نمایش — با PANEL_PREVIEW=1 اجرا می‌شود');
        }

        config(['mailboxes.accounts' => [
            ['key' => 'ceo', 'label' => 'مدیرعامل', 'user' => 'ceo@servernet.cloud', 'pass' => 'x'],
            ['key' => 'support', 'label' => 'پشتیبانی', 'user' => 'support@servernet.cloud', 'pass' => 'x'],
            ['key' => 'info', 'label' => 'اطلاعات', 'user' => 'info@servernet.cloud', 'pass' => 'x'],
        ]]);

        $admin = User::create([
            'name' => 'احسان ابراهیمی', 'email' => 'ceo@servernet.cloud',
            'password' => bcrypt('preview-only'), 'role' => 'admin',
        ]);

        $lead = $this->seedCrm();
        $this->seedMail();

        $pages = [
            'قیف جذب مشتری' => '/admin/crm',
            'پروندهٔ سرنخ'   => '/admin/crm/'.$lead->id,
            'صندوق‌های ایمیل' => '/admin/mail',
        ];

        $rendered = [];

        foreach ($pages as $title => $url) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();
            $rendered[$title] = $this->body($html);
        }

        file_put_contents(storage_path('app/panel-preview.html'), $this->wrap($rendered));

        $this->assertTrue(true);
    }

    private function seedCrm(): CrmLead
    {
        $audit = [
            'ok' => true, 'overall' => 71,
            'scores' => ['seo' => 78, 'performance' => 84, 'security' => 55, 'mobile' => 90, 'best' => 60],
            'checks' => [
                'security' => [
                    ['status' => 'fail', 'label' => 'هدرِ Content-Security-Policy ندارد'],
                    ['status' => 'fail', 'label' => 'HSTS تنظیم نشده'],
                ],
                'seo' => [['status' => 'warn', 'label' => 'صفحه توضیحاتِ متا ندارد']],
            ],
        ];

        $rows = [
            ['Jumeirah Dental Studio', 'https://jumeirah-dental-studio.ae', 'info@jumeirah-dental-studio.ae', 'dental', 'new', 71,
                'The booking page asks for a passport number before it shows any prices, and there are no practitioner bios anywhere on the site.'],
            ['Aurora Aesthetics Clinic', 'https://aurora-aesthetics.ae', 'hello@aurora-aesthetics.ae', 'aesthetic', 'contacted', 66,
                'There is no Arabic version of the site even though half of the reviews are written in Arabic.'],
            ['Marina Smile Center', 'https://marina-smile.ae', 'reception@marina-smile.ae', 'dental', 'replied', 58,
                'The treatment pages have no prices and no patient reviews, so every enquiry starts from zero trust.'],
            ['Palm Wellness Clinic', 'https://palm-wellness.ae', null, 'aesthetic', 'new', null, null],
        ];

        $first = null;

        foreach ($rows as $i => [$company, $site, $email, $vertical, $stage, $score, $observation]) {
            $lead = CrmLead::create([
                'domain_hash' => CrmLead::hashFor($site),
                'company' => $company, 'website' => $site, 'email' => $email,
                'city' => 'Dubai', 'country' => 'AE', 'vertical' => $vertical,
                'source' => 'places', 'stage' => $stage,
                'audit_score' => $score, 'audit' => $score ? $audit : null,
                'observation' => $observation,
                'phone' => '+971 4 000 00'.$i,
                'notes' => '4.6 · 180 نظر · Jumeirah, Dubai',
                'next_action_at' => now()->addDays($i + 1)->toDateString(),
                'last_contacted_at' => $stage === 'new' ? null : now()->subDays(3),
            ]);

            $first ??= $lead;
        }

        // یک ایمیل در صفِ تأیید
        CrmMessage::create([
            'lead_id' => $first->id, 'channel' => 'email', 'direction' => 'out',
            'subject' => 'A note about your Jumeirah booking page',
            'body' => "Hello,\n\nI was looking at your booking page and noticed it asks for a passport number "
                ."before showing any prices. For a first-time patient comparing three clinics, that is usually "
                ."where they leave.\n\nI build websites for clinics and run the hosting behind them myself, so "
                ."speed, uptime and backups are my responsibility rather than a vendor's.\n\nIf you're not happy "
                ."with the first design direction, you don't pay.\n\nWould it help if I sent you the two changes "
                ."I would make first?\n\n--\nEhsan Ebrahimi\nFounder & CEO, ServerNet Cloud\n"
                ."ceo@servernet.cloud · https://servernet.cloud/webdesign\n"
                ."Sefir-e Omid, No. 11, Kahrom Sahel St., Urmia, Iran\n\n"
                ."You received this because your business contact address is published online. "
                ."Reply \"no\" and I will not write again.",
            'status' => 'queued', 'sequence' => 0,
        ]);

        // یک پیش‌نویسِ لینکدین
        CrmMessage::create([
            'lead_id' => $first->id, 'channel' => 'linkedin', 'direction' => 'out',
            'subject' => 'یادداشتِ درخواستِ ارتباط',
            'body' => 'I was looking at the Jumeirah Dental booking flow and noticed it asks for a passport '
                .'number before it shows a price. I build clinic sites and run the hosting behind them, so '
                .'this is the kind of thing I look at.',
            'status' => 'draft', 'sequence' => 0,
        ]);

        // و یک جوابِ واقعی
        CrmMessage::create([
            'lead_id' => $first->id, 'channel' => 'email', 'direction' => 'in',
            'subject' => 'Re: A note about your Jumeirah booking page',
            'body' => "Interesting. We know the booking form is heavy. What would this cost and how long does it take?",
            'status' => 'reply', 'sequence' => 0, 'sent_at' => now()->subHours(4),
        ]);

        return $first;
    }

    private function seedMail(): void
    {
        $rows = [
            ['ceo', 'Dr Salem Al Marri', 'salem@marina-smile.ae', 'Re: your note about our booking page', 'sales', true, 5,
                'قیمت و زمانِ بازطراحی را پرسیده'],
            ['support', 'Reza Karimi', 'reza@kianpet.com', 'سرور از ۳ بامداد بالا نمی‌آید', 'support', true, 5,
                'سرورش از سه بامداد قطع است و منتظر جواب'],
            ['support', 'Sara N.', 'sara@example.ae', 'فاکتور تمدید هاست', 'billing', true, 4,
                'فاکتورِ تمدید را می‌خواهد'],
            ['ceo', 'ServerNet', 'noreply@servernet.cloud', '[سرورنت] پرداختِ موفق — فاکتور ۱۸۴۲', 'billing', false, 2, null, true],
            ['ceo', 'ServerNet', 'noreply@servernet.cloud', '[سرورنت] تیکت تازه از مشتری', 'support', false, 2, null, true],
            ['info', 'AWS Marketing', 'news@bulk-sender.example', 'Weekly cloud deals you should not miss', 'bulk', false, 1, 'تبلیغات'],
            ['info', 'Hetzner', 'billing@hetzner.example', 'Your invoice is available', 'vendor', false, 3, 'فاکتورِ ماهانهٔ تأمین‌کننده'],
        ];

        foreach ($rows as $i => $r) {
            MailboxMessage::create([
                'account' => $r[0],
                'uid_hash' => MailboxMessage::hashFor($r[0], 'demo-'.$i),
                'message_id' => 'demo-'.$i.'@servernet.cloud',
                'from_name' => $r[1], 'from_email' => $r[2], 'subject' => $r[3],
                'snippet' => 'نمونهٔ متنِ نامه برای پیش‌نمایش…',
                'received_at' => now()->subHours($i * 3 + 1),
                'category' => $r[4], 'needs_reply' => $r[5], 'importance' => $r[6],
                'summary' => $r[7] ?? null,
                'is_system' => $r[8] ?? false,
            ]);
        }
    }

    /** بدنهٔ صفحه بدونِ <html> و <head> */
    private function body(string $html): string
    {
        if (preg_match('~<body[^>]*>(.*)</body>~si', $html, $m)) {
            return $m[1];
        }

        return $html;
    }

    /** @param  array<string, string>  $pages */
    private function wrap(array $pages): string
    {
        $css = @file_get_contents(public_path('assets/css/admin.css')) ?: '';
        $tabs = '';
        $panes = '';
        $i = 0;

        foreach ($pages as $title => $body) {
            $on = $i === 0 ? ' on' : '';
            $tabs .= '<button class="pv-tab'.$on.'" data-pane="p'.$i.'">'.$title.'</button>';
            $panes .= '<div class="pv-pane'.$on.'" id="p'.$i.'">'.$body.'</div>';
            $i++;
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>پیش‌نمایشِ پنل — سرورنت</title>
<style>{$css}</style>
<style>
  .pv-bar{position:sticky;top:0;z-index:99;display:flex;gap:8px;flex-wrap:wrap;
    padding:12px 16px;background:#0b1020;border-bottom:1px solid rgba(148,163,184,.2)}
  .pv-tab{background:rgba(148,163,184,.14);color:#94a3b8;border:0;border-radius:999px;
    padding:8px 16px;font:inherit;cursor:pointer}
  .pv-tab.on{background:rgba(34,211,238,.18);color:#22d3ee}
  .pv-pane{display:none}.pv-pane.on{display:block}
  .pv-note{padding:10px 16px;color:#fbbf24;font-size:13px;
    border-bottom:1px solid rgba(148,163,184,.2);background:#0b1020;line-height:1.9}
  .ad-side{display:none}.ad-shell{display:block}.ad-main{margin:0}
</style>
</head>
<body>
<div class="pv-bar">{$tabs}</div>
<div class="pv-note">
  پیش‌نمایشِ ایستا با دادهٔ نمونه — همان قالب‌های واقعیِ پنل، رندرشده. دکمه‌ها اینجا کار نمی‌کنند.
  ستونِ کناریِ منو عمداً پنهان شده تا صفحه در موبایل جا شود.
</div>
{$panes}
<script>
document.querySelectorAll('.pv-tab').forEach(function (t) {
  t.addEventListener('click', function () {
    document.querySelectorAll('.pv-tab').forEach(function (x) { x.classList.remove('on'); });
    document.querySelectorAll('.pv-pane').forEach(function (x) { x.classList.remove('on'); });
    t.classList.add('on');
    document.getElementById(t.dataset.pane).classList.add('on');
    window.scrollTo(0, 0);
  });
});
</script>
</body>
</html>
HTML;
    }
}
