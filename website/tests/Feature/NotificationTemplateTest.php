<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Services\Notify\CustomerNotifier;
use App\Services\Sms\SmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * مدیریتِ متنِ پیام‌ها از پنل.
 *
 * قاعدهٔ حاکم بر این لایه: **هیچ اعلانی نباید بشکند.** الگو یک روکش است روی
 * متنِ کد؛ هر جا کم بیاورد، متنِ کد می‌رود. تست‌های زیر همین را می‌پایند، چون
 * یک اعلانِ نرفته یعنی مشتری از تحویلِ سرور یا سررسیدِ فاکتورش بی‌خبر می‌مانَد.
 */
class NotificationTemplateTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string,string> */
    public array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();

        $this->app->instance(SmsSender::class, new class($this) implements SmsSender {
            public function __construct(private NotificationTemplateTest $t) {}
            public function enabled(): bool { return true; }
            public function name(): string { return 'fake'; }
            public function send(string $m, string $text): bool { $this->t->sent[$m] = $text; return true; }
            public function sendOtp(string $m, string $code): bool { return true; }
        });
    }

    private function customer(): Customer
    {
        return Customer::create(['email' => 'c'.random_int(1, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa']);
    }

    private function admin(): User
    {
        return User::create(['name' => 'مدیر', 'email' => 'a'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('x'), 'role' => 'admin']);
    }

    private function tpl(array $over = []): NotificationTemplate
    {
        return NotificationTemplate::create(array_merge([
            'key' => 'paid', 'title' => 'تأیید پرداخت', 'group' => 'billing',
            'bale_body' => 'پرداخت {amount} تومانی شما ثبت شد.',
            'variables' => [['name' => 'amount', 'desc' => 'مبلغ']],
            'is_active' => true,
        ], $over));
    }

    // ═══════════════════ منطقِ جایگزینی ═══════════════════

    public function test_template_text_replaces_the_hardcoded_one(): void
    {
        $this->tpl();

        $out = NotificationTemplate::body('paid', ['amount' => '۲۵۰٬۰۰۰'], 'متن قدیمی');

        $this->assertSame('پرداخت ۲۵۰٬۰۰۰ تومانی شما ثبت شد.', $out);
    }

    /** 🔴 متغیرِ نفرستاده نباید به‌صورت خام به مشتری برسد */
    public function test_missing_variable_falls_back_to_the_code_text(): void
    {
        $this->tpl();

        $out = NotificationTemplate::body('paid', [], 'متن قدیمی');

        $this->assertSame('متن قدیمی', $out, 'با متغیرِ خالی باید متنِ کد برود');
        $this->assertStringNotContainsString('{amount}', $out);
    }

    public function test_inactive_template_is_ignored(): void
    {
        $this->tpl(['is_active' => false]);

        $this->assertSame('متن قدیمی', NotificationTemplate::body('paid', ['amount' => '۱'], 'متن قدیمی'));
    }

    public function test_unknown_key_falls_back(): void
    {
        $this->assertSame('متن قدیمی', NotificationTemplate::body('no_such_event', [], 'متن قدیمی'));
    }

    /** 🔴 سرتاسری: پیامی که واقعاً به مشتری می‌رود باید متنِ الگو باشد */
    public function test_the_notifier_actually_sends_the_template_text(): void
    {
        $this->tpl();
        $c = $this->customer();

        app(CustomerNotifier::class)->event($c, 'paid', ['amount' => '۹۹'], 'متن قدیمی');

        $this->assertNotEmpty($this->sent);
        $this->assertStringContainsString('پرداخت ۹۹ تومانی شما ثبت شد.', implode(' ', $this->sent));
    }

    /** بی‌الگو، رفتار دقیقاً مثلِ قبل می‌مانَد */
    public function test_without_a_template_the_old_text_still_goes_out(): void
    {
        $c = $this->customer();

        app(CustomerNotifier::class)->event($c, 'paid', [], 'متن قدیمی');

        $this->assertStringContainsString('متن قدیمی', implode(' ', $this->sent));
    }

    // ═══════════════════ ایمیل ═══════════════════

    /**
     * 🔴 متنِ ایمیلِ الگو باید واقعاً به مشتری برسد.
     *
     * وقتی این صفحه ساخته شد، `body()` تنها خوانندهٔ الگو بود و فقط `bale_body`
     * را می‌خواند. یعنی مدیر متنِ ایمیل را ویرایش می‌کرد، «ارسال آزمایشی» را
     * می‌زد، تغییر را می‌دید و نتیجه می‌گرفت که زنده است — درحالی‌که مشتری
     * برای همیشه متنِ قدیمیِ داخلِ کد را می‌گرفت.
     */
    public function test_template_email_is_actually_delivered(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        $this->tpl([
            'key' => 'suspended',
            'email_subject' => 'سرویس {service} معلق شد',
            'email_body' => '<p>سرویس <b>{service}</b> موقتاً غیرفعال شد.</p>',
            'bale_body' => 'سرویس {service} معلق شد.',
            'variables' => [['name' => 'service', 'desc' => 'نام سرویس']],
        ]);

        $c = $this->customer();
        app(CustomerNotifier::class)->templated($c, 'suspended', ['service' => 'هاست من'], 'متن قدیمی');

        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\TemplateMail::class,
            fn ($m) => $m->title === 'سرویس هاست من معلق شد'
                && str_contains($m->bodyHtml, 'هاست من'));
    }

    /** 🔴 متغیرِ نفرستاده = ایمیلِ نافرستاده، نه ایمیلی با «{service}» در متنش */
    public function test_an_incomplete_email_template_is_not_sent(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        $this->tpl(['key' => 'suspended', 'email_body' => '<p>سرویس {service} معلق شد.</p>']);

        app(CustomerNotifier::class)->templated($this->customer(), 'suspended', [], 'متن قدیمی');

        \Illuminate\Support\Facades\Mail::assertNothingSent();
    }

    /** الگوی بی‌متنِ ایمیل نباید ایمیلِ خالی بفرستد */
    public function test_no_email_body_means_no_email(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        $this->tpl(['key' => 'suspended', 'email_body' => null]);

        $sent = app(CustomerNotifier::class)
            ->templated($this->customer(), 'suspended', ['amount' => '۱'], 'متن قدیمی');

        $this->assertFalse($sent, 'فراخوان باید بفهمد ایمیلی نرفته تا خودش پشتیبان بفرستد');
        \Illuminate\Support\Facades\Mail::assertNothingSent();
    }

    /**
     * 🔴 `templated()` باید کلیدِ **واقعی** و متغیرها را به لایهٔ پیامک بدهد.
     *
     * ═══ این تست قبلاً دقیقاً برعکس بود، و باگ را قفل کرده بود ═══
     *
     * نسخهٔ قبلی می‌گفت «کلیدِ رویداد نباید به لایهٔ پیامک برود» و ادعا
     * می‌کرد `'__none__'` درست است. استدلالش این بود: «اگر الگو پیدا شود و
     * متغیرهایش با اپراتور نخوانَد، `SmsDispatcher` متنِ آزاد را جایگزین
     * نمی‌فرستد، پس یادآوری بی‌صدا گم می‌شود.»
     *
     * آن استدلال یک **فرضِ نانوشته** داشت: «متنِ آزاد می‌رود». وقتی درایور از
     * `IppanelSender` به رلهٔ n8n عوض شد، `send()` عمداً `false` شد — و آن
     * فرض بی‌سروصدا باطل شد. نتیجه: هیچ‌کدام از ۲۵ رویدادِ کاتالوگ پیامک
     * نمی‌فرستاد، و **این تست سبز می‌مانْد** چون دقیقاً همان رفتار را تضمین
     * می‌کرد.
     *
     * ⚠️ درسِ ماندگار: تستی که یک **تصمیمِ طراحی** را قفل می‌کند، باید فرضِ
     * زیرینِ آن تصمیم را هم بسنجد. وگرنه روزی که فرض باطل شود، تست از
     * محافظ به نگهبانِ باگ تبدیل می‌شود.
     *
     * فرضِ زیرین این‌جا در `NotificationSilenceTest` سنجیده می‌شود: اگر پیامک
     * نرود، **باید دیده شود**. با آن محافظ، دادنِ کلیدِ واقعی اکیداً بهتر است:
     * الگو که باشد پیامک می‌رود، و نباشد شکست ثبت می‌شود.
     */
    public function test_templated_passes_the_real_event_key_to_the_sms_layer(): void
    {
        $seen = [];
        $this->app->instance(\App\Services\Sms\SmsDispatcher::class,
            new class($seen) extends \App\Services\Sms\SmsDispatcher {
                public function __construct(public array &$seen) {}
                public function event(string $m, string $e, array $v, string $f): bool
                {
                    $this->seen[] = $e;

                    return true;
                }
            });

        $this->tpl(['key' => 'expiring', 'bale_body' => 'یادآوری']);

        app(CustomerNotifier::class)
            ->templated($this->customer(), 'expiring', ['service' => 'LX-2', 'days' => '۳'], 'متن قدیمی');

        $this->assertSame(['expiring'], $seen,
            'کلیدِ رویداد به لایهٔ پیامک نرسید ⇒ هیچ الگویی فعال نمی‌شود و پیامک بی‌صدا نمی‌رود');
    }
    // ═══════════════════ صفحهٔ مدیریت ═══════════════════

    public function test_admin_sees_the_catalogue(): void
    {
        $this->tpl();

        $html = $this->actingAs($this->admin())->get('/admin/templates')->assertOk()->getContent();

        $this->assertStringContainsString('تأیید پرداخت', $html);
        $this->assertStringContainsString('الگوی پیام‌ها', $html);
        // محدودیتِ پیامک باید صریح گفته شود، نه اینکه مدیر دنبالِ فیلدِ نبود بگردد
        $this->assertStringContainsString('اپراتور', $html);
    }

    public function test_admin_can_edit_and_it_takes_effect(): void
    {
        $t = $this->tpl();

        $this->actingAs($this->admin())->post('/admin/templates/'.$t->id, [
            'bale_body' => 'مبلغ {amount} تومان دریافت شد. سپاس!',
            'email_subject' => 'رسید پرداخت',
            'email_body' => '<p>مبلغ <b>{amount}</b> تومان دریافت شد.</p>',
            'is_active' => 1,
        ])->assertRedirect();

        $this->assertSame('مبلغ ۵۰ تومان دریافت شد. سپاس!',
            NotificationTemplate::body('paid', ['amount' => '۵۰'], 'متن قدیمی'));
    }

    /** 🔴 HTMLِ خطرناک نباید از پنل وارد ایمیلِ مشتری شود */
    public function test_email_html_is_sanitised(): void
    {
        $t = $this->tpl();

        $this->actingAs($this->admin())->post('/admin/templates/'.$t->id, [
            'bale_body' => 'x {amount}',
            'email_body' => '<p>سلام</p><script>alert(1)</script>',
            'is_active' => 1,
        ]);

        $this->assertStringNotContainsString('<script', (string) $t->fresh()->email_body);
    }

    public function test_editor_page_uses_the_self_hosted_wysiwyg(): void
    {
        $t = $this->tpl();

        $html = $this->actingAs($this->admin())->get('/admin/templates/'.$t->id)->assertOk()->getContent();

        // ویرایشگرِ خودمیزبان، نه CDN — CSP هر منبعِ بیرونی را بی‌صدا بلاک می‌کند
        $this->assertStringContainsString('class="wysiwyg"', $html);
        $this->assertStringContainsString('wysiwyg-tb', $html);
        $this->assertDoesNotMatchRegularExpression('~<script[^>]+src=["\']https?://~', $html,
            'هیچ اسکریپتِ بیرونی نباید بارگذاری شود');

        // چیپِ متغیر باید باشد وگرنه مدیر باید نام‌ها را حفظ کند
        $this->assertStringContainsString('{amount}', $html);
    }

    /** ارسال آزمایشیِ الگوی خالی باید خطا بدهد نه ایمیلِ سفید */
    public function test_test_send_refuses_an_empty_template(): void
    {
        $t = $this->tpl(['bale_body' => null, 'email_body' => null]);

        $this->actingAs($this->admin())->post('/admin/templates/'.$t->id.'/test')
            ->assertRedirect()->assertSessionHasErrors();
    }

    /** غیرِ مدیر نباید به این صفحه برسد */
    public function test_guests_are_blocked(): void
    {
        $t = $this->tpl();

        $this->get('/admin/templates')->assertRedirect();
        $this->post('/admin/templates/'.$t->id, ['bale_body' => 'x'])->assertRedirect();

        $this->assertSame('پرداخت {amount} تومانی شما ثبت شد.', $t->fresh()->bale_body);
    }
}
