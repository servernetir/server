<?php

namespace Tests\Feature;

use App\Mail\AuditReportMail;
use App\Models\AuditReport;
use App\Models\OutreachContact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * گزارشِ ماندگارِ بررسیِ سایت — لینکِ اشتراکی، صفحهٔ عمومی، و کمپینِ ایمیل.
 *
 * دو چیز این‌جا سنجیده می‌شود که خرابی‌شان **خاموش** است:
 *  • صفحهٔ گزارش کدِ ۲۰۰ می‌دهد ولی خالی است (تلهٔ §۸ پروژه)
 *  • ایمیل به کسی می‌رود که گفته بود نفرست
 */
class AuditReportShareTest extends TestCase
{
    use RefreshDatabase;

    /** خروجیِ کمینه ولی **واقعی‌شکل** از `SiteAudit::run()`. */
    private function audit(string $host = 'example.com', int $score = 62): array
    {
        return [
            'ok' => true, 'url' => 'https://'.$host, 'host' => $host,
            'overall' => $score, 'grade' => 'C',
            'scores' => ['seo' => 60], 'weights' => ['seo' => 1],
            'meta' => ['title' => 'Example'],
            'checks' => ['seo' => [['key' => 'title', 'status' => 'fail']]],
            'plan' => [], 'counts' => ['fail' => 3, 'warn' => 4, 'pass' => 20],
            'vitals' => null,
        ];
    }

    private function report(string $host = 'example.com'): AuditReport
    {
        return AuditReport::fromAudit($this->audit($host), 'tool');
    }

    // ═══════════════ ۱) مدل ═══════════════

    public function test_a_failed_audit_is_never_stored_as_a_report(): void
    {
        $this->assertNull(AuditReport::fromAudit(['ok' => false, 'error' => 'unreachable']));
        $this->assertSame(0, AuditReport::count());
    }

    public function test_each_report_gets_its_own_unguessable_token(): void
    {
        $a = $this->report('a.com');
        $b = $this->report('b.com');

        $this->assertNotSame($a->token, $b->token);
        $this->assertGreaterThanOrEqual(24, strlen($a->token));
    }

    /**
     * 🔴 توکن **رمزِ** گزارش است. اگر در سریالایز بیاید، هر جایی که مدل به JSON
     * برود (پاسخِ API، لاگ، ردیابِ خطا) نشانیِ گزارشِ یک نفر به دستِ دیگری
     * می‌افتد. همان قاعدهٔ `CloudPlan::$hidden`.
     */
    public function test_the_token_never_leaks_through_serialization(): void
    {
        $json = $this->report()->toJson();

        $this->assertStringNotContainsString('token', $json);
        $this->assertStringNotContainsString($this->report()->token, $json);
    }

    public function test_the_report_url_carries_the_locale_it_was_made_in(): void
    {
        $fa = AuditReport::fromAudit($this->audit(), 'tool', null, 'fa');
        $en = AuditReport::fromAudit($this->audit(), 'tool', null, 'en');

        $this->assertStringContainsString('/report/', $fa->url());
        $this->assertStringNotContainsString('/en/report/', $fa->url());
        $this->assertStringContainsString('/en/report/', $en->url());
    }

    // ═══════════════ ۲) صفحهٔ عمومی ═══════════════

    /**
     * ⚠️ «۲۰۰ یعنی هیچ». صفحه باید **دادهٔ گزارش** را هم داشته باشد، وگرنه
     * جاوااسکریپت چیزی برای رندر ندارد و گیرنده صفحهٔ خالی می‌بیند.
     */
    public function test_the_public_report_page_carries_the_data_the_script_renders(): void
    {
        $r = $this->report('shop.example');

        $html = $this->get('/report/'.$r->token)->assertOk()->getContent();

        $this->assertStringContainsString('window.AUDIT_DATA', $html);
        $this->assertStringContainsString('window.SEO_META', $html);
        $this->assertStringContainsString('shop.example', $html);
        $this->assertStringContainsString('seo-results', $html);
        // بی این فایل، صفحه برای همیشه خالی می‌مانَد
        $this->assertStringContainsString('assets/js/tools.js', $html);
    }

    /** گزارشِ سایتِ کسِ دیگر نباید ایندکس شود. */
    public function test_the_public_report_page_is_noindex(): void
    {
        $html = $this->get('/report/'.$this->report()->token)->assertOk()->getContent();

        $this->assertStringContainsString('noindex', $html);
    }

    public function test_an_unknown_token_is_a_404(): void
    {
        $this->get('/report/'.str_repeat('a', 32))->assertNotFound();
    }

    // ═══════════════ ۳) ابزارِ عمومی ═══════════════

    /**
     * 🔴 صفحهٔ عمومی **فیلدِ ایمیل ندارد** — تصمیمِ آگاهانه، نه فراموشی.
     *
     * فیلدِ ایمیل روی یک صفحهٔ بی‌احراز یعنی سرورِ ما به دستورِ هر ناشناسی به هر
     * نشانی‌ای ایمیل می‌فرستد؛ یک ابزارِ اسپم و فیشینگ با نامِ دامنهٔ خودمان.
     * اگر روزی کسی چنین فیلدی اضافه کند، این تست قرمز می‌شود.
     */
    public function test_the_public_tool_shares_a_link_but_never_sends_mail(): void
    {
        $html = $this->get('/tools/seo')->assertOk()->getContent();

        $this->assertStringContainsString('au-share', $html, 'بخشِ لینکِ اشتراک باید باشد');
        $this->assertStringNotContainsString('type="email"', $html,
            'صفحهٔ عمومیِ ابزار نباید فیلدِ ایمیل داشته باشد');
    }

    // ═══════════════ ۴) کمپین ═══════════════

    public function test_the_list_parser_keeps_only_rows_that_carry_a_real_email(): void
    {
        Mail::fake();
        $this->actingAs($this->admin());

        $this->postJson('/admin/seo/list', ['list' => implode("\n", [
            'https://good.com/path, owner@good.com',
            'noemail.com',                 // بی‌ایمیل ⇒ رد
            'bad.com, not-an-email',       // ایمیلِ نامعتبر ⇒ رد
            'other.ir; hello@other.ir',    // سمی‌کالن هم جداکننده است
        ])])->assertOk();

        $this->assertSame(2, OutreachContact::count());
        $this->assertDatabaseHas('outreach_contacts', ['host' => 'good.com', 'email' => 'owner@good.com']);
        $this->assertDatabaseHas('outreach_contacts', ['host' => 'other.ir', 'email' => 'hello@other.ir']);
        // 🔴 هرگز `info@bad.com` حدس زده نشود
        $this->assertDatabaseMissing('outreach_contacts', ['host' => 'noemail.com']);
    }

    /**
     * 🔴 قلبِ ماجرا: کسی که «نفرست» را زده، دیگر هیچ ایمیلی نمی‌گیرد —
     * حتی برای **دامنهٔ دیگری** و حتی در کمپینِ بعدی.
     */
    public function test_an_unsubscribe_blocks_that_address_everywhere_afterwards(): void
    {
        Mail::fake();
        $c = OutreachContact::create([
            'host' => 'first.com', 'email' => 'owner@x.com',
            'audit_report_id' => $this->report('first.com')->id,
        ]);

        $this->get('/report/unsubscribe/'.$c->unsubscribe_token)->assertOk();

        $this->assertTrue(OutreachContact::isSuppressed('owner@x.com'));

        // دامنهٔ دیگر، همان شخص ⇒ وارد فهرست هم نمی‌شود
        $this->actingAs($this->admin())
            ->postJson('/admin/seo/list', ['list' => "second.com, owner@x.com"])
            ->assertOk()
            ->assertJsonPath('added', 0);

        $this->assertDatabaseMissing('outreach_contacts', ['host' => 'second.com']);
    }

    public function test_sending_a_campaign_mail_carries_identity_and_a_working_optout(): void
    {
        Mail::fake();
        $c = OutreachContact::create([
            'host' => 'shop.ir', 'email' => 'owner@shop.ir',
            'audit_report_id' => $this->report('shop.ir')->id,
        ]);

        $this->actingAs($this->admin())
            ->postJson('/admin/seo/send-next', ['ids' => [$c->id]])
            ->assertOk()
            ->assertJsonPath('status', 'sent');

        Mail::assertSent(AuditReportMail::class, function ($m) use ($c) {
            return $m->outreach === true
                && $m->unsubscribeUrl === $c->unsubscribeUrl()
                && $m->hasTo('owner@shop.ir');
        });

        $this->assertSame('sent', $c->fresh()->status);
    }

    /** یک ردیف دو بار فرستاده نشود — حلقهٔ مرورگر همان ids را دوباره می‌دهد. */
    public function test_a_contact_is_never_sent_twice(): void
    {
        Mail::fake();
        $c = OutreachContact::create([
            'host' => 'a.ir', 'email' => 'a@a.ir',
            'audit_report_id' => $this->report('a.ir')->id,
        ]);
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/admin/seo/send-next', ['ids' => [$c->id]])->assertOk();
        $this->actingAs($admin)->postJson('/admin/seo/send-next', ['ids' => [$c->id]])
            ->assertOk()->assertJsonPath('done', true);

        Mail::assertSentCount(1);
    }

    /** ردیفی که گزارش ندارد هرگز ایمیل نمی‌شود — «۰ از ۱۰۰» فرستادن توهین است. */
    public function test_a_contact_without_a_report_is_never_mailed(): void
    {
        Mail::fake();
        $c = OutreachContact::create(['host' => 'down.ir', 'email' => 'x@down.ir']);

        $this->actingAs($this->admin())
            ->postJson('/admin/seo/send-next', ['ids' => [$c->id]])
            ->assertOk()->assertJsonPath('done', true);

        Mail::assertNothingSent();
    }

    /** صفحهٔ پنل واقعاً رندر شود — خطای Blade یا کلیدِ نبود، ۵۰۰ می‌دهد. */
    public function test_the_admin_page_renders_with_its_controls(): void
    {
        OutreachContact::create([
            'host' => 'shop.ir', 'email' => 'owner@shop.ir',
            'audit_report_id' => $this->report('shop.ir')->id,
        ]);

        $html = $this->actingAs($this->admin())->get('/admin/seo')->assertOk()->getContent();

        $this->assertStringContainsString('sx-send-one', $html);   // ارسال به یک نفر
        $this->assertStringContainsString('sx-scan', $html);       // حلقهٔ بررسی
        $this->assertStringContainsString('sx-send', $html);       // حلقهٔ ارسال
        $this->assertStringContainsString('admin-seo.js', $html);
        $this->assertStringContainsString('shop.ir', $html);
        // هشدارِ کمپین باید دیده شود، نه اینکه فقط در کامنت باشد
        $this->assertStringContainsString('sx-warn', $html);
    }

    public function test_the_admin_pages_and_endpoints_require_a_logged_in_admin(): void
    {
        $this->get('/admin/seo')->assertRedirect();
        $this->postJson('/admin/seo/send-one', ['url' => 'x.com', 'email' => 'a@b.com'])
            ->assertStatus(302);
    }

    /**
     * 🔴 تلهٔ ثبت‌شدهٔ پروژه: `$request->validate()` روی `/admin/*` یک ۳۰۲ِ HTML
     * می‌دهد نه ۴۲۲، و `fetch` در مرورگر می‌ترکد. این تست همان را قفل می‌کند.
     */
    public function test_validation_errors_on_admin_json_routes_come_back_as_json(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/admin/seo/send-one', ['url' => '', 'email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonStructure(['messages']);
    }

    /**
     * 🔴 جایگاهِ فراخوانِ رندر در `tools.js` — باگی که هیچ تستی و حتی کنسول نگرفت.
     *
     * `render()` تابعِ اعلان‌شده است و hoist می‌شود، ولی داخلش `order` و `icon`
     * را صدا می‌زند که با `const` تعریف شده‌اند. اولین نسخهٔ این کد فراخوان را
     * **بالاتر** از آن دو گذاشت؛ نتیجه یک ReferenceErrorِ منطقهٔ مردهٔ زمانی
     * وسطِ رندر بود: نمره و دسته‌ها و برنامهٔ اقدام می‌آمدند، ولی فهرستِ چک‌ها و
     * فیلتر هرگز ساخته نمی‌شدند. صفحه ۲۰۰ بود، تست‌ها سبز بودند (چون فقط وجودِ
     * `window.AUDIT_DATA` در HTML را می‌سنجیدند)، و گزارشی که برای مشتری
     * می‌رفت نصفه باز می‌شد. فقط با **نگاه‌کردن به صفحه** پیدا شد.
     *
     * این تست ترتیب را قفل می‌کند — تنها چیزی که بدونِ اجرای مرورگر سنجیدنی است.
     */
    public function test_the_report_bootstrap_runs_after_the_consts_it_depends_on(): void
    {
        $js = (string) file_get_contents(public_path('assets/js/tools.js'));

        $boot = strpos($js, 'window.AUDIT_DATA');
        $this->assertNotFalse($boot, 'قلّابِ رندرِ صفحهٔ گزارش در tools.js نیست');

        foreach (['const order =', 'const icon =', 'const gradeClass ='] as $decl) {
            $at = strpos($js, $decl);
            $this->assertNotFalse($at, "«{$decl}» پیدا نشد — تست کهنه شده");
            $this->assertGreaterThan(
                $at,
                $boot,
                "فراخوانِ رندر پیش از «{$decl}» است ⇒ TDZ و گزارشِ نیمه‌رندرشده"
            );
        }
    }

    /**
     * 🔴 چاپ باید **توکن‌ها** را عوض کند، نه فقط زمینه را.
     *
     * نسخهٔ اول فقط `background:#fff` می‌گذاشت. ولی کلِ طراحی روی توکن‌های تیره
     * سوار است (`--text:#E6EAF3`)، پس متن روی کاغذِ سفید خاکستریِ روشن — عملاً
     * نامرئی — چاپ می‌شد. خرابی کاملاً خاموش بود: صفحه سالم، و فقط کسی که
     * واقعاً چاپ می‌گرفت می‌فهمید.
     *
     * ⚠️ سربرگِ چاپ هم باید بیرونِ @media print صریحاً پنهان باشد؛ `hidden` یا
     * نبودِ قاعده کافی نیست (تلهٔ ثبت‌شدهٔ `[hidden]` در این پروژه).
     */
    public function test_the_print_stylesheet_remaps_the_theme_tokens(): void
    {
        $css = (string) file_get_contents(public_path('assets/css/site.css'));

        $this->assertSame(1, substr_count($css, '@media print{'),
            'قواعدِ چاپ باید یک‌جا باشد؛ دو بلوک یعنی دو تعریف که روزی از هم فاصله می‌گیرند');

        $start = strpos($css, '@media print{');
        $print = substr($css, $start);

        foreach (['--text:', '--bg:', '--surface:', '--line:'] as $token) {
            $this->assertStringContainsString($token, $print,
                "توکنِ {$token} برای چاپ بازتعریف نشده ⇒ متنِ تیره‌طرح روی کاغذِ سفید");
        }

        $this->assertStringContainsString('@page', $print, 'اندازه و حاشیهٔ کاغذ تعریف نشده');
        $this->assertStringContainsString('break-inside:avoid', $print, 'کنترلِ شکستِ صفحه نیست');

        // سربرگ/پابرگِ چاپ نباید روی صفحه دیده شوند
        $this->assertMatchesRegularExpression(
            '/\.au-print-head\s*,\s*\.au-print-foot\s*\{\s*display:\s*none/',
            $css,
            'سربرگِ چاپ بیرونِ @media print پنهان نشده ⇒ روی خودِ سایت دیده می‌شود'
        );
    }

    /** سربرگِ چاپ باید در صفحهٔ گزارش با تاریخ و نشانیِ واقعی پر شده باشد. */
    public function test_the_printed_report_identifies_itself(): void
    {
        $r = $this->report('shop.example');

        $html = $this->get('/report/'.$r->token)->assertOk()->getContent();

        $this->assertStringContainsString('au-print-head', $html);
        $this->assertStringContainsString('au-print-foot', $html);
        $this->assertStringContainsString($r->url(), $html, 'نشانیِ گزارش باید روی کاغذ بیاید');
        $this->assertStringContainsString(sdate($r->created_at), $html, 'تاریخِ بررسی باید روی کاغذ بیاید');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }
}
