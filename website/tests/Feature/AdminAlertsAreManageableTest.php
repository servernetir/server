<?php

namespace Tests\Feature;

use App\Models\NotificationTemplate;
use App\Models\User;
use App\Services\Notify\AdminNotifier;
use App\Support\AdminAlerts;
use Database\Seeders\AdminNotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 🔴 اعلان‌هایی که به خودِ مدیر می‌روند باید قابلِ خاموش‌کردن و ویرایش باشند.
 *
 * ═══ خرابی‌ای که این می‌بندد ═══
 *
 * ۲۵ فراخوانِ `AdminNotifier::event()` عنوان و متنِ سخت‌کد داشتند و هیچ کلیدِ
 * خاموشی. اعلانِ پرتکرارِ کم‌ارزش دقیقاً همان چیزی است که باعث می‌شود اعلانِ
 * **مهم** هم دیده نشود — همان «آژیرِ همیشه‌روشن» که این پروژه بارها خورده.
 */
class AdminAlertsAreManageableTest extends TestCase
{
    use RefreshDatabase;

    /** ⚠️ نامش `seed` نیست: آن متدِ خودِ `TestCase` است و بازتعریفش fatal می‌دهد. */
    private function seedAlerts(): void
    {
        (new AdminNotificationTemplateSeeder)->run();
    }

    private function notifier(): AdminNotifier
    {
        return app(AdminNotifier::class);
    }

    // ═══════════════ ۱) خاموشی واقعاً خاموش کند ═══════════════

    /**
     * 🔴 خاموش یعنی **هیچ کانالی** — نه بله، نه ایمیل.
     *
     * ⚠️ ادعا روی `BaleNotifier` است چون تنها نقطه‌ای است که می‌شود دیدش؛ اگر
     * روزی کانالِ سومی اضافه شود و از این گیت رد نشود، همین تست کافی نیست —
     * ولی گیت **پیش از** ساختِ متن است، پس کانالِ تازه هم خودبه‌خود پوشش دارد.
     */
    public function test_a_switched_off_alert_sends_nothing(): void
    {
        $this->seedAlerts();

        NotificationTemplate::where('key', 'admin.payment_ok')
            ->update(['is_active' => false]);

        $bale = \Mockery::mock(\App\Services\Bale\BaleNotifier::class);
        $bale->shouldNotReceive('toAdmin');
        $bale->shouldNotReceive('toAdminButtons');
        $this->app->instance(\App\Services\Bale\BaleNotifier::class, $bale);

        $this->notifier()->event('پرداختِ موفق', ['مشتری' => 'الف']);

        $this->assertTrue(true); // ادعا در shouldNotReceive است
    }

    /** و روشن بودن، رفتارِ امروز را عوض نمی‌کند. */
    public function test_an_active_alert_still_sends(): void
    {
        $this->seedAlerts();

        $seen = null;
        $bale = \Mockery::mock(\App\Services\Bale\BaleNotifier::class);
        $bale->shouldReceive('toAdminButtons')->andReturn(false);
        $bale->shouldReceive('toAdmin')->andReturnUsing(function ($p, $t) use (&$seen) {
            $seen = $t;

            return true;
        });
        $this->app->instance(\App\Services\Bale\BaleNotifier::class, $bale);

        $this->notifier()->event('پرداختِ موفق', ['مشتری' => 'الف', 'مبلغ' => '۱۰۰']);

        $this->assertStringContainsString('پرداختِ موفق', (string) $seen);
        $this->assertStringContainsString('الف', (string) $seen);
    }

    /**
     * ⚠️ رویدادی که هنوز seed نشده باید **بیاید**، نه اینکه ساکت شود.
     *
     * وگرنه یک مهاجرتِ نزده روی سرور، همهٔ اعلان‌ها را بی‌صدا خاموش می‌کرد.
     * سکوت باید تصمیمِ صریحِ مدیر باشد، نه پیش‌فرضِ یک نصبِ ناقص.
     */
    public function test_an_unseeded_event_still_sends(): void
    {
        // عمداً بی‌seed
        $seen = null;
        $bale = \Mockery::mock(\App\Services\Bale\BaleNotifier::class);
        $bale->shouldReceive('toAdminButtons')->andReturn(false);
        $bale->shouldReceive('toAdmin')->andReturnUsing(function ($p, $t) use (&$seen) {
            $seen = $t;

            return true;
        });
        $this->app->instance(\App\Services\Bale\BaleNotifier::class, $bale);

        $this->notifier()->event('پرداختِ موفق', ['مشتری' => 'ب']);

        $this->assertStringContainsString('ب', (string) $seen);
    }

    // ═══════════════ ۲) متنِ دلخواه ═══════════════

    /** تگ‌ها از همان `$rows` می‌آیند — هیچ فراخوانی چیزی اضافه پاس نمی‌دهد. */
    public function test_the_admin_text_is_rendered_with_the_row_tags(): void
    {
        $this->seedAlerts();

        NotificationTemplate::where('key', 'admin.payment_ok')
            ->update(['bale_body' => 'پول رسید از {مشتری} به مبلغ {مبلغ}']);

        $seen = null;
        $bale = \Mockery::mock(\App\Services\Bale\BaleNotifier::class);
        $bale->shouldReceive('toAdminButtons')->andReturn(false);
        $bale->shouldReceive('toAdmin')->andReturnUsing(function ($p, $t) use (&$seen) {
            $seen = $t;

            return true;
        });
        $this->app->instance(\App\Services\Bale\BaleNotifier::class, $bale);

        $this->notifier()->event('پرداختِ موفق', ['مشتری' => 'رضا', 'مبلغ' => '۵۰۰']);

        $this->assertSame('پول رسید از رضا به مبلغ ۵۰۰', (string) $seen);
    }

    /**
     * 🔴 تگی که آن رویداد نمی‌دهد ⇒ برگشت به پیش‌فرض، نه چاپِ `{تگ}`.
     *
     * پیامی که در آن «{مبلغ}» چاپ شده، از پیامِ پیش‌فرض بدتر است.
     */
    public function test_an_unknown_tag_falls_back_to_the_default_text(): void
    {
        $this->seedAlerts();

        NotificationTemplate::where('key', 'admin.payment_ok')
            ->update(['bale_body' => 'پول رسید — {چیزی_که_نیست}']);

        $seen = null;
        $bale = \Mockery::mock(\App\Services\Bale\BaleNotifier::class);
        $bale->shouldReceive('toAdminButtons')->andReturn(false);
        $bale->shouldReceive('toAdmin')->andReturnUsing(function ($p, $t) use (&$seen) {
            $seen = $t;

            return true;
        });
        $this->app->instance(\App\Services\Bale\BaleNotifier::class, $bale);

        $this->notifier()->event('پرداختِ موفق', ['مشتری' => 'رضا']);

        $this->assertStringNotContainsString('{چیزی_که_نیست}', (string) $seen);
        $this->assertStringContainsString('پرداختِ موفق', (string) $seen);
    }

    /**
     * 🔴 و محافظِ جای‌نگهدار باید **فارسی** را هم ببیند.
     *
     * الگویش `[a-z_]` بود؛ یعنی برای تگ‌های انگلیسی کار می‌کرد و برای فارسی
     * ساکت بود — و تگ‌های این پروژه همه فارسی‌اند.
     */
    public function test_the_placeholder_guard_sees_persian_tags(): void
    {
        NotificationTemplate::create([
            'key' => 'zz.fa', 'title' => 'ت', 'group' => 'account', 'audience' => 'admin',
            'bale_body' => 'سلام {مشتری}', 'is_active' => true,
        ]);

        $this->assertSame('پیش‌فرض',
            NotificationTemplate::body('zz.fa', [], 'پیش‌فرض'),
            'تگِ فارسیِ پرنشده از محافظ رد شد');

        $this->assertSame('سلام رضا',
            NotificationTemplate::body('zz.fa', ['مشتری' => 'رضا'], 'پیش‌فرض'));
    }

    /**
     * ⚠️ ولی CSSِ داخلِ متنِ ایمیل نباید «تگِ پرنشده» خوانده شود.
     *
     * وگرنه محافظ ایمیل‌های سالم را هم جلو می‌گیرد و آن‌ها بی‌صدا نمی‌روند —
     * خرابی‌ای که از خودِ خرابیِ اصلی بی‌سروصداتر است.
     */
    public function test_css_braces_are_not_mistaken_for_a_tag(): void
    {
        NotificationTemplate::create([
            'key' => 'zz.css', 'title' => 'ت', 'group' => 'account', 'audience' => 'admin',
            'bale_body' => 'سلام <style>a{color:red}</style>', 'is_active' => true,
        ]);

        $this->assertStringContainsString('color:red',
            NotificationTemplate::body('zz.css', [], 'پیش‌فرض'));
    }

    // ═══════════════ ۳) گاردِ drift ═══════════════

    /**
     * 🔴 مهم‌ترین ادعا: هر عنوانِ سخت‌کدی که در کد هست باید در نقشه باشد.
     *
     * کلید از **عنوان** پیدا می‌شود. اگر کسی عنوانی را در کد عوض کند یا رویدادِ
     * تازه‌ای اضافه کند، آن اعلان بی‌صدا از مدیریت خارج می‌شود: می‌رود، ولی
     * مدیر دیگر نمی‌تواند خاموشش کند و در صفحه هم نمی‌بیندش.
     *
     * ⚠️ جهتِ خرابی امن است (اعلان می‌رود، گم نمی‌شود) ولی **ساکت** است — و
     * همین تست تنها چیزی است که ساکت‌بودنش را می‌شکند.
     */
    /**
     * 🔴 مهم‌ترین ادعا: هر فراخوانِ اعلانِ مدیر باید کلید داشته باشد.
     *
     * کلید از **عنوان** پیدا می‌شود. اگر کسی عنوانی را در کد عوض کند یا رخدادِ
     * تازه‌ای اضافه کند، آن اعلان بی‌صدا از مدیریت خارج می‌شود: می‌رود، ولی
     * مدیر دیگر نمی‌تواند خاموشش کند و در صفحهٔ تنظیمات هم نمی‌بیندش.
     *
     * ⚠️ جهتِ خرابی امن است (اعلان می‌رود، گم نمی‌شود) ولی **ساکت** است — و
     * همین تست تنها چیزی است که ساکت‌بودنش را می‌شکند. همین حالا هم سه رخداد
     * را گرفت که از قلم افتاده بودند، و یکی از آن‌ها عنوانش با آنچه من در
     * نقشه نوشته بودم فرق داشت.
     *
     * ⚠️ با **توکنایزر**، نه regex: نسخهٔ اولِ همین تست regex بود و فراخوانی
     * را که بینِ `event(` و عنوانش یک کامنت داشت اصلاً ندید — گاردی که خودش
     * یک سوراخ داشت و سبز هم بود.
     */
    public function test_every_admin_alert_call_is_manageable(): void
    {
        $calls = $this->alertCalls();

        $this->assertNotEmpty($calls, 'هیچ فراخوانِ اعلانی پیدا نشد — پیش‌فرضِ این تست عوض شده');

        $bad = [];

        foreach ($calls as [$title, $hasKey, $where]) {
            if ($hasKey) {
                continue; // کلیدِ صریح پاس داده — عنوانش هرچه باشد مدیریت‌پذیر است
            }

            if (in_array(explode(':', $where)[0], self::DELIBERATELY_UNMANAGED, true)) {
                continue;
            }

            if ($title === null) {
                $bad[] = 'عنوانِ ساخته‌شده بدونِ key: ('.$where.')';

                continue;
            }

            if (AdminAlerts::keyFor($title) === null) {
                $bad[] = $title.' ('.$where.')';
            }
        }

        $this->assertSame([], $bad,
            'این اعلان‌ها مدیریت‌پذیر نیستند — مدیر نه می‌بیندشان نه می‌تواند خاموششان کند: '
            .implode(' | ', $bad));
    }

    /**
     * فراخوان‌های `AdminNotifier::event()` در کلِ `app/`.
     *
     * @return list<array{0:?string,1:bool,2:string}>  [عنوانِ ثابت یا null، کلیدِ صریح دارد؟، کجا]
     */
    private function alertCalls(): array
    {
        $out = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $src = (string) file_get_contents($file->getPathname());

            if (! str_contains($src, 'AdminNotifier::class)->event(')) {
                continue;
            }

            $t = token_get_all($src);
            $n = count($t);

            for ($i = 0; $i < $n; $i++) {
                // ⚠️ گیرهٔ ما `AdminNotifier` است نه `event`: در همین پرونده‌ها
                //    `CustomerNotifier->event()` هم هست و ربطی به این‌جا ندارد.
                if (! is_array($t[$i]) || $t[$i][0] !== T_STRING || $t[$i][1] !== 'AdminNotifier') {
                    continue;
                }

                $open = $this->openParenOfEvent($t, $i, $n);

                if ($open === null) {
                    continue;
                }

                $out[] = [
                    $this->staticTitle($t, $open, $n),
                    $this->hasExplicitKey($t, $open, $n),
                    $file->getFilename().':'.$t[$i][2],
                ];
            }
        }

        return $out;
    }

    /** پرانتزِ بازِ `->event(` بعد از `AdminNotifier`، یا null. */
    private function openParenOfEvent(array $t, int $i, int $n): ?int
    {
        for ($j = $i + 1; $j < min($i + 12, $n); $j++) {
            // ⚠️ خطِ `use ...\AdminNotifier;` هم همان T_STRING را دارد؛ `;` مرزِ
            //    عبارت است و بی‌آن، نگاهِ رو به جلو وارد کدِ بی‌ربط می‌شود.
            if ($t[$j] === ';') {
                return null;
            }

            if (! is_array($t[$j]) || $t[$j][0] !== T_STRING || $t[$j][1] !== 'event') {
                continue;
            }

            $k = $this->nextMeaningful($t, $j + 1, $n);

            return ($k !== null && $t[$k] === '(') ? $k : null;
        }

        return null;
    }

    /**
     * عنوان، فقط اگر یک رشتهٔ **کاملِ** ثابت باشد.
     *
     * `'🔴 '.$what` عنوانِ ثابت نیست: آن نقطهٔ بعدش یعنی رشته فقط پیشوند است.
     * نسخهٔ اولِ این تست همان پیشوند را عنوان می‌خواند و دنبالِ `🔴 ` در نقشه
     * می‌گشت — گزارشی که هم غلط بود هم گیج‌کننده.
     */
    private function staticTitle(array $t, int $open, int $n): ?string
    {
        $a = $this->nextMeaningful($t, $open + 1, $n);

        if ($a === null || ! is_array($t[$a]) || $t[$a][0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        $b = $this->nextMeaningful($t, $a + 1, $n);

        if ($b === null || $t[$b] !== ',') {
            return null; // به چیزی چسبیده ⇒ عنوان ساخته می‌شود
        }

        return substr($t[$a][1], 1, -1);
    }

    /** آیا در آرگومان‌های همین فراخوانی `key:` هست؟ */
    private function hasExplicitKey(array $t, int $open, int $n): bool
    {
        $depth = 0;

        for ($j = $open; $j < $n; $j++) {
            if ($t[$j] === '(') {
                $depth++;
            } elseif ($t[$j] === ')') {
                if (--$depth === 0) {
                    return false;
                }
            } elseif ($depth === 1 && is_array($t[$j]) && $t[$j][0] === T_STRING && $t[$j][1] === 'key') {
                $k = $this->nextMeaningful($t, $j + 1, $n);

                if ($k !== null && $t[$k] === ':') {
                    return true;
                }
            }
        }

        return false;
    }

    private function nextMeaningful(array $t, int $j, int $n): ?int
    {
        for (; $j < $n; $j++) {
            if (is_array($t[$j]) && in_array($t[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $j;
        }

        return null;
    }

    /**
     * 🔴 عمداً مدیریت‌ناپذیر — و دلیلش این‌جا نوشته شده.
     *
     * افزودن به این فهرست باید یک تصمیم باشد، نه راهِ فرار از تستِ بالا.
     */
    private const DELIBERATELY_UNMANAGED = [
        // کدِ تأییدِ اتصالِ کنسولِ مدیر. خاموش‌کردنش یعنی مدیر خودش را از کنسول
        // بیرون بیندازد بی‌آنکه راهی برای برگشت بماند. اعلانی که تنها راهِ
        // دسترسی است نباید سوییچِ خاموشی داشته باشد.
        'AdminBaleGate.php',
    ];
    /** ⚠️ و هر کلیدِ نقشه باید واقعاً seed شود، وگرنه در صفحه نیست. */
    public function test_every_mapped_event_becomes_a_row(): void
    {
        $this->seedAlerts();

        $keys = NotificationTemplate::where('audience', 'admin')->pluck('key')->all();

        $this->assertSame(
            array_values(array_diff(array_keys(AdminAlerts::EVENTS), $keys)),
            [],
            'این رخدادها در نقشه هستند ولی ردیف نگرفتند'
        );
    }

    // ═══════════════ ۴) صفحه ═══════════════

    public function test_the_settings_page_lists_them_apart_from_customer_messages(): void
    {
        $this->seedAlerts();
        $admin = User::factory()->create(['role' => 'admin']);

        $html = $this->actingAs($admin)->get('/admin/settings?tab=messages')
            ->assertOk()->getContent();

        $this->assertStringContainsString('اعلان‌های من', $html);
        $this->assertStringContainsString('پرداختِ موفق', $html);
    }

    /** سوییچ یک کلیک است، نه بازکردنِ یک صفحه. */
    public function test_the_admin_can_switch_one_off_from_the_list(): void
    {
        $this->seedAlerts();
        $admin = User::factory()->create(['role' => 'admin']);
        $t = NotificationTemplate::where('key', 'admin.payment_ok')->firstOrFail();

        $this->actingAs($admin)->post('/admin/templates/'.$t->id.'/toggle')->assertRedirect();

        $this->assertFalse((bool) $t->fresh()->is_active);
    }

    /**
     * 🔴 و الگوی **مشتری** از این‌جا خاموش نمی‌شود.
     *
     * آن‌جا خاموشی یعنی مشتری پیامِ ضروری (کدِ ورود، تحویلِ سرویس) را نگیرد —
     * تصمیمی که نباید پشتِ یک دکمهٔ کوچک در فهرست باشد.
     */
    public function test_a_customer_template_cannot_be_switched_off_here(): void
    {
        $t = NotificationTemplate::create([
            'key' => 'zz.test', 'title' => 'آزمون', 'group' => 'account',
            'audience' => 'customer', 'is_active' => true,
        ]);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post('/admin/templates/'.$t->id.'/toggle')
            ->assertSessionHasErrors();

        $this->assertTrue((bool) $t->fresh()->is_active);
    }
}
