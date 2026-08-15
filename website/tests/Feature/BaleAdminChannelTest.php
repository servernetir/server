<?php

namespace Tests\Feature;

use App\Models\BaleContact;
use App\Services\Bale\BaleNotifier;
use App\Services\Notify\AdminNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * مرزِ هزینه: **سفیر فقط برای مشتریان**.
 *
 * ═══ چرا این تست وجود دارد ═══
 *
 * سفیرِ بله به ازای هر پیام هزینه دارد و ارزشش این است که با **شماره** کار
 * می‌کند — یعنی به مشتری‌ای هم می‌رسد که هرگز وارد ربات نشده. مدیر آن مسئله را
 * ندارد: `chat_id`ِ او پایدار است و APIِ رباتِ خودمان رایگان به او می‌رسد.
 *
 * 🔴 این قاعده پیش از این در **سه** جای پروژه نوشته شده بود — docblockِ
 * `BaleSafirSender`، کامنتِ `config/services.bale_safir`، و کامنتِ
 * `AppServiceProvider` — و در **هیچ‌کدام اجرا نمی‌شد**: `AdminNotifier` متدِ
 * `notify()` را صدا می‌زد که خطِ اولش سفیر است. پس هر رویدادِ داخلی (تیکتِ تازه،
 * پرداخت، شکستِ تحویل، دامنهٔ منقضی) بی‌سروصدا از کانالِ پولی می‌رفت و کارفرما
 * بابتِ اعلانِ خودش به خودش پول می‌داد.
 *
 * کامنت این را نگرفت چون کامنت اجرا نمی‌شود. این تست می‌گیردش.
 *
 * ⚠️ عمداً از `AdminNotifier` **واقعی** رد می‌شود، نه از یک `BaleNotifier`ِ
 * دستی‌ساخته. اگر فقط متدِ پایین را می‌سنجید، فراخوانی که فردا به `notify()`
 * برگردد سبز رد می‌شد — دقیقاً همان تلهٔ ثبت‌شدهٔ پروژه: تستی که تصمیم را قفل
 * می‌کند باید از همان دری بگذرد که کدِ واقعی می‌گذرد.
 */
class BaleAdminChannelTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_PHONE = '09121110000';

    private const ADMIN_CHAT = '900900';

    protected function setUp(): void
    {
        parent::setUp();

        // هر دو کانال «فعال»اند تا تست واقعاً انتخاب را بسنجد، نه خاموشی را
        config()->set('services.bale.token', 'bot-token-for-test');
        config()->set('services.bale_safir.key', 'safir-key-for-test');
        config()->set('services.bale_safir.bot_id', 2017652664);
        config()->set('servernet.contact.notify_phone', self::ADMIN_PHONE);
        config()->set('servernet.contact.notify_chat_id', '');
        config()->set('servernet.contact.email', '');   // ایمیل اینجا موضوع نیست

        Http::swap(new Factory);
        Http::fake([
            '*safir*'      => Http::response(['message_id' => 'x'], 200),
            '*sendMessage' => Http::response(['ok' => true], 200),
            '*'            => Http::response(['ok' => true], 200),
        ]);
    }

    private function hostsHit(): array
    {
        $hosts = [];

        Http::assertSent(function ($r) use (&$hosts) {
            $hosts[] = parse_url($r->url(), PHP_URL_HOST) ?: '';

            return true;
        });

        return $hosts;
    }

    private function assertNoSafir(string $why): void
    {
        foreach ($this->hostsHit() as $host) {
            $this->assertStringNotContainsString('safir', $host, $why);
        }
    }

    // ═══════════════ مدیر: هرگز سفیر ═══════════════

    public function test_an_admin_event_never_reaches_the_paid_safir_channel(): void
    {
        BaleContact::create([
            'mobile'    => self::ADMIN_PHONE,
            'chat_id'   => self::ADMIN_CHAT,
            'linked_at' => now(),
        ]);

        app(AdminNotifier::class)->event('تیکت تازه', ['مشتری' => 'SN-1']);

        $this->assertNoSafir('اعلانِ مدیر از سفیرِ پولی رفت — سفیر فقط برای مشتریان است');
    }

    public function test_the_admin_event_does_go_out_over_the_bot_api(): void
    {
        BaleContact::create([
            'mobile'    => self::ADMIN_PHONE,
            'chat_id'   => self::ADMIN_CHAT,
            'linked_at' => now(),
        ]);

        app(AdminNotifier::class)->event('پرداختِ موفق', ['مبلغ' => '۱۲۰٬۰۰۰']);

        /*
        | ⚠️ «سفیر را نزد» به‌تنهایی بی‌ارزش است — با یک `return` زودهنگام هم
        | سبز می‌شود. پس اینجا می‌سنجیم که پیام **واقعاً** رفته، و به همان
        | chat_idِ درست.
        */
        $ok = false;

        Http::assertSent(function ($r) use (&$ok) {
            if (str_contains($r->url(), '/sendMessage')
                && ($r->data()['chat_id'] ?? null) === self::ADMIN_CHAT) {
                $ok = true;
            }

            return true;
        });

        $this->assertTrue($ok, 'اعلانِ مدیر به chat_idِ ربات نرفت');
    }

    /**
     * chat_idِ صریح، وابستگی به «مدیر ربات را استارت کرده باشد» را حذف می‌کند.
     *
     * بی‌این، رفعِ هزینه یک عارضه داشت: مدیری که هرگز شماره‌اش را با ربات share
     * نکرده، از فردای این تغییر **هیچ** اعلانِ بله‌ای نمی‌گرفت — یعنی هزینه را
     * با سکوت عوض می‌کردیم.
     */
    public function test_an_explicit_chat_id_works_with_no_contact_row_at_all(): void
    {
        config()->set('servernet.contact.notify_chat_id', '777001');
        config()->set('servernet.contact.notify_phone', '');

        $this->assertSame(0, BaleContact::count());

        app(AdminNotifier::class)->event('هشدارِ سلامت');

        $ok = false;

        Http::assertSent(function ($r) use (&$ok) {
            if (($r->data()['chat_id'] ?? null) === '777001') {
                $ok = true;
            }

            return true;
        });

        $this->assertTrue($ok, 'chat_idِ صریحِ مدیر نادیده گرفته شد');
        $this->assertNoSafir('chat_idِ صریح بود ولی باز سراغِ سفیر رفت');
    }

    /** مقصدِ نداشته باید **ثبت** شود، نه اینکه بی‌صدا رد شود */
    public function test_a_missing_admin_destination_is_recorded_not_swallowed(): void
    {
        config()->set('servernet.contact.notify_chat_id', '');
        config()->set('servernet.contact.notify_phone', '');

        \App\Support\ErrorTracker::clear();

        app(AdminNotifier::class)->event('رویدادی که مقصد ندارد');

        $found = collect(\App\Support\ErrorTracker::recent(50))
            ->contains(fn ($r) => str_contains(json_encode($r, JSON_UNESCAPED_UNICODE) ?: '', 'bale-admin'));

        $this->assertTrue($found, 'مقصدِ نداشتهٔ بلهٔ مدیر هیچ ردی نگذاشت');
    }

    // ═══════════════ مشتری: همچنان سفیر ═══════════════

    /**
     * نیمهٔ دومِ قاعده. بی‌این، «سفیر را خاموش کردم» هم سبز می‌شد — و آن‌وقت
     * مشتری‌ای که ربات را استارت نکرده، دیگر هیچ پیامی نمی‌گرفت.
     */
    public function test_the_customer_path_still_uses_safir(): void
    {
        app(BaleNotifier::class)->notify('09120001122', 'سرویس شما فعال شد');

        $hit = collect($this->hostsHit())->contains(fn ($h) => str_contains($h, 'safir'));

        $this->assertTrue($hit, 'مسیرِ مشتری دیگر از سفیر نمی‌رود — همان باگی که سفیر برای رفعش آمد');
    }
    // ═══════════════ گاردِ ساختاری: کانالِ پولی فقط برای مشتری ═══════════════

    /**
     * 🔴 این تست **سورس** را می‌پاید، نه رفتار را — و عمداً.
     *
     * قاعدهٔ «سفیر فقط برای مشتریان» سه بار در docblockها نوشته شده بود و سه بار
     * شکست، چون هر فراخوانِ تازه‌ای که `BaleNotifier` را تزریق می‌کند، به‌طور
     * طبیعی `notify()` را صدا می‌زند — نامش طبیعی‌تر است و امضایش هم همان.
     *
     * آخرین موردش را خودِ کارفرما دید: خلاصهٔ روزانهٔ صندوقِ ایمیل از کانالِ
     * پولی می‌رفت («از مسیر سفیر بله دارد می‌فرستد، هزینه دارد»).
     *
     * تستِ رفتاری این را نمی‌گیرد مگر برای هر فراخوان جداگانه نوشته شود — و
     * فراخوانِ بعدی که کسی فردا اضافه می‌کند، تستِ خودش را همراه ندارد. پس
     * ادعا روی **کلِ درخت** بسته می‌شود.
     */
    public function test_only_the_customer_notifier_may_use_the_paid_safir_path(): void
    {
        // تنها جایی که حق دارد `notify()` را صدا بزند
        $allowed = ['app/Services/Notify/CustomerNotifier.php'];

        $root = base_path('app');
        $bad  = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($it as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $rel = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(base_path()) + 1));

            if (in_array($rel, $allowed, true)) {
                continue;
            }

            $src = (string) file_get_contents($file->getPathname());

            // فقط فایل‌هایی که واقعاً BaleNotifier در دست دارند
            if (! str_contains($src, 'BaleNotifier')) {
                continue;
            }

            // خودِ BaleNotifier متدها را تعریف می‌کند
            if (str_ends_with($rel, 'Services/Bale/BaleNotifier.php')) {
                continue;
            }

            if (preg_match('~->notify\s*\(~', $src)) {
                $bad[] = $rel;
            }
        }

        $this->assertSame([], $bad,
            "
این فایل‌ها مسیرِ **پولیِ** سفیر را صدا می‌زنند. برای مدیر باید toAdmin() باشد:
"
            .implode("
", $bad));
    }
}
