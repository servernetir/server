<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\NotificationTemplate;
use App\Models\Service;
use App\Services\Cloud\CloudFraudGuard;
use App\Services\Cloud\CloudProvisioner;
use App\Services\Notify\AdminNotifier;
use App\Services\Notify\CustomerNotifier;
use App\Services\Notify\NotifyEvent;
use App\Support\ErrorTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🔴 شکستنِ سکوتِ صفِ بازبینی.
 *
 * ═══ آنچه واقعاً رخ داد ═══
 *
 * پنج سفارش پارک شدند و **هیچ‌کس خبردار نشد**. مشتری منتظرِ سروری ماند که
 * هرگز نمی‌آمد و پنل هم‌زمان به او وعده می‌داد «کمتر از دو دقیقه… ایمیل هم
 * برایتان می‌رود». کارفرما فقط چون خودش ردیابِ خطا را باز کرد فهمید.
 *
 * ⚠️ دقتِ لازم: سکوت **یک‌طرفه** بود، نه کامل. مدیر از قبل یک اعلانِ بله/ایمیل
 * می‌گرفت — ولی بی‌لینک، بی‌ایموجیِ متمایز، و بی‌هیچ ردیفی در تاریخچهٔ سرویس
 * (تنها جایی که مدیر دنبالِ علت می‌گردد). و مشتری هیچ‌چیز.
 *
 * ═══ چه چیزی این‌جا قفل می‌شود ═══
 *
 * ۱) مشتری خبردار می‌شود — از راهِ کاتالوگِ رویداد، نه یک فراخوانِ دستیِ دیگر.
 * ۲) 🔴 علتِ محافظ **به مشتری نمی‌رسد** (عددِ سقف نباید درز کند).
 * ۳) دقیقاً **یک بار**، حتی با چند تلاشِ دوباره.
 * ۴) الگوی `/admin/templates` واقعاً فعال است: متغیر پاس داده می‌شود، ردیفِ
 *    سیدر هست، و هیچ جای‌نگهدارِ پرنشده‌ای در متن نمی‌مانَد.
 * ۵) تاریخچهٔ سرویس دیگر خالی نیست.
 */
class CloudHoldNotifyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        ErrorTracker::clear();
    }

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'h'.random_int(1, 999999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ])->fresh();
    }

    private function service(Customer $c, array $over = []): Service
    {
        return Service::create(array_merge([
            'customer_id' => $c->id, 'name' => 'سرورِ ابری CV-2-4', 'currency_code' => 'IRT',
            'price' => 570000, 'tax_percent' => 0, 'cycle' => 'monthly',
            'status' => 'awaiting_provision', 'provision_status' => 'pending',
            'cloud_plan_id' => 1,
        ], $over));
    }

    /** مشتری‌ای که سقفِ روزانه را رد کرده */
    private function bursting(): Customer
    {
        $c = $this->customer();

        foreach (range(1, CloudFraudGuard::DAILY_MAX) as $i) {
            $this->service($c, ['provision_status' => 'manual']);
        }

        return $c->fresh();
    }

    /**
     * هر دو ناقل را می‌گیرد و **پارامترها** را هم نگه می‌دارد — نه فقط کلید را.
     *
     * ⚠️ نگه‌داشتنِ پارامترها لازم است: ادعای «علت به مشتری نمی‌رسد» فقط با
     * دیدنِ آرایهٔ متغیرها و متنِ پشتیبان قابلِ اثبات است، نه با نامِ رویداد.
     *
     * @return array{customer:array<int,array>, admin:array<int,array>}
     */
    private function capture(callable $run): array
    {
        $customer = [];
        $admin = [];

        $this->mock(CustomerNotifier::class, function ($m) use (&$customer) {
            $m->shouldReceive('templated')->andReturnUsing(
                function ($c, $key, $vars = [], $text = '') use (&$customer) {
                    $customer[] = ['key' => $key, 'vars' => $vars, 'text' => $text];

                    return true;
                });
            $m->shouldReceive('event')->andReturnNull();
        });

        $this->mock(AdminNotifier::class, function ($m) use (&$admin) {
            $m->shouldReceive('event')->andReturnUsing(
                function ($title, $rows = [], $url = null, $emoji = '🔔') use (&$admin) {
                    $admin[] = ['title' => $title, 'rows' => $rows, 'url' => $url, 'emoji' => $emoji];
                });
        });

        $run();

        return ['customer' => $customer, 'admin' => $admin];
    }

    // ═══════════════ ۱ و ۲) هر دو طرف، با متنِ درست ═══════════════

    /** 🔴 مشتری دیگر در سکوت نمی‌مانَد — و علتِ فنی به او نمی‌رسد */
    public function test_a_parked_order_notifies_the_customer_without_leaking_the_threshold(): void
    {
        $c = $this->bursting();
        $s = $this->service($c);

        $seen = $this->capture(fn () => app(CloudProvisioner::class)->provision($s->fresh()));

        $this->assertSame('manual', $s->fresh()->provision_status);
        $this->assertCount(1, $seen['customer'], 'مشتری باید دقیقاً یک پیام بگیرد');
        $this->assertSame('service_hold', $seen['customer'][0]['key']);

        // متغیرها **پاس داده شده‌اند** — وگرنه الگوی پنل هرگز فعال نمی‌شود
        $this->assertSame(['service' => $s->name], $seen['customer'][0]['vars'],
            'متغیرهای الگو باید دقیقاً همان چیزی باشد که کاتالوگ اعلام کرده');

        // 🔴 عددِ سقف نباید درز کند
        $customerFacing = $seen['customer'][0]['text'].json_encode($seen['customer'][0]['vars'], JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('۲۴ ساعت', $customerFacing,
            'علتِ محافظ به مشتری رسید — یعنی به مهاجم گفته‌ایم یکی زیرِ سقف بماند');
        $this->assertStringNotContainsString((string) CloudFraudGuard::DAILY_MAX, $customerFacing);
        $this->assertStringNotContainsString('تقلب', $customerFacing,
            'مشتریِ واقعی نباید متهم شود؛ بازبینی روتین است');

        Http::assertNothingSent();
    }

    /** و مدیر **علت** و **لینکِ مستقیم** می‌گیرد، نه یک 🔔 بی‌نشان */
    public function test_the_admin_alert_carries_the_reason_and_a_deep_link(): void
    {
        $c = $this->bursting();
        $s = $this->service($c);

        $seen = $this->capture(fn () => app(CloudProvisioner::class)->provision($s->fresh()));

        $this->assertCount(1, $seen['admin']);
        $alert = $seen['admin'][0];

        $rows = json_encode($alert['rows'], JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('۲۴ ساعت', (string) $rows,
            'مدیر باید علتِ محافظ را ببیند، وگرنه نمی‌داند چه چیزی را تأیید می‌کند');
        $this->assertStringContainsString('/admin/services/'.$s->id, (string) $alert['url'],
            'اعلانی که لینک ندارد یعنی مدیر باید خودش دنبالش بگردد — و نمی‌گردد');
        $this->assertNotSame('🔔', $alert['emoji'],
            'اعلانِ بی‌نشان بینِ بقیهٔ اعلان‌ها گم می‌شود');
    }

    // ═══════════════ ۳) دقیقاً یک بار ═══════════════

    /**
     * 🔴 «هرگز دو بار» — قاعدهٔ صریحِ کارفرما.
     *
     * `provision:run` و دکمهٔ «تلاشِ دوباره»ی مدیر هر دو دوباره به همین شاخه
     * می‌رسند. بی‌قفل، مشتری به‌ازای هر تلاش یک پیامِ یکسان می‌گرفت.
     */
    public function test_the_customer_is_notified_once_no_matter_how_many_retries(): void
    {
        $c = $this->bursting();
        $s = $this->service($c);

        $seen = $this->capture(function () use ($s) {
            foreach (range(1, 4) as $i) {
                $s->forceFill(['provision_status' => 'pending'])->save();
                app(CloudProvisioner::class)->provision($s->fresh());
            }
        });

        $this->assertCount(1, $seen['customer'],
            'مشتری '.count($seen['customer']).' پیام گرفت — سیلِ پیامِ تکراری اعتماد را می‌برد');
        $this->assertSame('manual', $s->fresh()->provision_status);
    }

    // ═══════════════ ۴) الگوی پنل واقعاً زنده است ═══════════════

    /** کاتالوگ باید کلید را **وصل** بداند، وگرنه /admin/templates دروغ می‌گوید */
    public function test_the_catalogue_marks_the_hold_event_as_live_for_both_audiences(): void
    {
        $this->assertTrue(NotifyEvent::has('service_hold'));
        $this->assertTrue(NotifyEvent::notifiesCustomer('service_hold'));
        $this->assertTrue(NotifyEvent::notifiesAdmin('service_hold'));
        $this->assertSame(['service'], NotifyEvent::vars('service_hold'),
            'متغیرِ اضافه در کاتالوگ، الگو را برای همیشه بی‌اثر می‌کند');
        $this->assertNotContains('service_hold', NotifyEvent::unwired());
    }

    /**
     * 🔴 و الگو پس از جایگزینی هیچ جای‌نگهدارِ پرنشده‌ای ندارد.
     *
     * این دقیقاً همان تلهٔ مستندشدهٔ پروژه است: `NotificationTemplate::body()`
     * اگر بعد از جایگزینی هنوز `{چیزی}` ببیند عمداً متنِ الگو را دور می‌ریزد و
     * `email()` اصلاً چیزی نمی‌فرستد. یعنی مدیر متن را ویرایش می‌کند، «ذخیره
     * شد» می‌گیرد، و هیچ‌وقت هیچ ایمیلی نمی‌رود.
     */
    public function test_the_seeded_template_actually_renders_with_the_variables_the_caller_sends(): void
    {
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);

        $row = NotificationTemplate::where('key', 'service_hold')->first();
        $this->assertNotNull($row, 'بی‌ردیفِ سیدر، /admin/templates این کلید را ندارد و ایمیلی نمی‌رود');

        $vars = ['service' => 'سرورِ ابری CV-2-4'];

        $body = NotificationTemplate::body('service_hold', $vars, 'متنِ پشتیبان');
        $this->assertNotSame('متنِ پشتیبان', $body, 'الگو فعال نشد — یعنی جای‌نگهدارِ پرنشده دارد');
        $this->assertStringContainsString('CV-2-4', $body);
        $this->assertDoesNotMatchRegularExpression('~\{[a-z_]+\}~i', $body);

        $mail = NotificationTemplate::email('service_hold', $vars);
        $this->assertNotNull($mail, 'ایمیلِ الگو ساخته نشد — مشتری هیچ ایمیلی نمی‌گیرد');
        $this->assertDoesNotMatchRegularExpression('~\{[a-z_]+\}~i', $mail['subject'].$mail['html']);
    }

    // ═══════════════ ۵) تاریخچه دیگر خالی نیست ═══════════════

    /**
     * تنها جایی که مدیر دنبالِ علت می‌گردد، تا امروز برای یک سفارشِ پارک‌شده
     * کاملاً خالی بود — چون `needsReview()` بر خلافِ `fail()` هیچ
     * `ActivityLog`ای نمی‌نوشت.
     */
    public function test_the_service_history_records_why_the_order_was_parked(): void
    {
        $c = $this->bursting();
        $s = $this->service($c);

        app(CloudProvisioner::class)->provision($s->fresh());

        $log = ActivityLog::where('service_id', $s->id)->get()
            ->map(fn ($r) => (string) $r->description)->implode("\n");

        $this->assertStringContainsString('بازبینیِ دستی', $log);
        $this->assertStringContainsString('۲۴ ساعت', $log,
            'علتِ محافظ باید در تاریخچه باشد — همان‌جایی که مدیر نگاه می‌کند');
    }
}
