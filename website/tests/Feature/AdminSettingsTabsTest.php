<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\SettingsController as S;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تنظیماتِ تب‌بندی‌شده — و قفلِ «راهنمای الگو».
 *
 * ═══ چرا این کلاس وجود دارد ═══
 *
 * تب‌بندی یک خطرِ تازه ساخت که در نسخهٔ تک‌فرمی وجود نداشت: فیلدهای تب‌های
 * دیگر در درخواست **نیستند**. الگوی قبلیِ نوشتن آن‌ها را «خالی» می‌دید و
 * `null` می‌نوشت — یعنی ذخیرهٔ تبِ «حساب‌ها» بی‌صدا نرخِ یورو و توکنِ زیرساخت
 * را پاک می‌کرد. هیچ خطایی، هیچ لاگی، و کشفش فقط وقتی که تحویلِ خودکار بخوابد.
 *
 * بخشِ دومِ این کلاس همان «راهنمای الگو»یی است که کارفرما خواست، ولی به‌شکلِ
 * **اجراشدنی**: یک سند می‌گوید تنظیمِ تازه کجا برود؛ این تست‌ها **نمی‌گذارند**
 * جای دیگری برود.
 */
class AdminSettingsTabsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'مدیر', 'email' => 'tabs'.random_int(1000, 9999).'@example.test',
            'password' => bcrypt('secret-for-test'), 'role' => 'admin',
        ]);
    }

    private function html(string $tab): string
    {
        return $this->actingAs($this->admin())->get('/admin/settings?tab='.$tab)->assertOk()->getContent();
    }

    /** بدنهٔ فرمِ اصلیِ تنظیمات در یک تب (بی‌فرم‌های دیگرِ همان صفحه). */
    private function settingsForm(string $tab): string
    {
        preg_match('~<form[^>]*action="/admin/settings"[^>]*>(.*?)</form>~is', $this->html($tab), $m);

        return $m[1] ?? '';
    }

    // ═══════════════════ ۱) خودِ تب‌ها ═══════════════════

    public function test_every_tab_renders(): void
    {
        foreach (array_keys(S::TABS) as $tab) {
            $html = $this->html($tab);
            $this->assertStringContainsString(S::TABS[$tab]['t'], $html);
        }
    }

    public function test_an_unknown_tab_falls_back_instead_of_exploding(): void
    {
        $this->actingAs($this->admin())->get('/admin/settings?tab=../../etc/passwd')->assertOk();
        $this->actingAs($this->admin())->get('/admin/settings?tab=nope')->assertOk();
    }

    /**
     * 🔴 هستهٔ ادعا: تبِ ناشناخته در **ذخیره** نباید «همه» تعبیر شود.
     *
     * اگر `update()` تبِ نامعتبر را نادیده بگیرد و همهٔ کلیدها را بنویسد، یک
     * درخواستِ بی‌فیلد کلِ تنظیمات را پاک می‌کند.
     */
    public function test_saving_with_an_unknown_tab_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/settings', ['tab' => 'whatever'])
            ->assertStatus(422);

        $this->actingAs($this->admin())
            ->post('/admin/settings', [])
            ->assertStatus(422);
    }

    // ═══════════════ ۲) از دست نرفتنِ دادهٔ تب‌های دیگر ═══════════════

    /**
     * 🔴 گران‌ترین ادعای این فایل.
     *
     * ذخیرهٔ یک تب نباید هیچ کلیدی از تب‌های دیگر را لمس کند. سناریو دقیقاً
     * همان چیزی است که کاربر انجام می‌دهد: نرخ و درصد را در تبِ نرخ‌گذاری ست
     * می‌کند، بعد می‌رود شمارهٔ کارت را در تبِ حساب‌ها عوض کند.
     */
    public function test_saving_one_tab_never_wipes_another_tabs_settings(): void
    {
        Setting::put('pricing_baseline_rate', '95000');
        Setting::put('price_margin_pct', '12');
        Setting::put('domain_nameservers', 'ns1.servernet.cloud,ns2.servernet.cloud');
        Setting::putSecret('hetzner_api_token', 'tok-live-123');

        $this->actingAs($this->admin())->post('/admin/settings', [
            'tab'         => 'accounts',
            'bank_holder' => 'اطمینان داده‌پردازان دانش',
            'bank_card'   => '6104111122223333',
        ])->assertRedirect();

        $this->assertSame('اطمینان داده‌پردازان دانش', Setting::get('bank_holder'));
        $this->assertSame('95000', Setting::get('pricing_baseline_rate'), 'نرخِ مبنا پاک شد');
        $this->assertSame('12', Setting::get('price_margin_pct'), 'حاشیهٔ سود پاک شد');
        $this->assertSame('ns1.servernet.cloud,ns2.servernet.cloud', Setting::get('domain_nameservers'));
        $this->assertSame('tok-live-123', Setting::getSecret('hetzner_api_token'), 'توکنِ زیرساخت پاک شد');
    }

    /** …و در جهتِ عکس: ذخیرهٔ زیرساخت نباید حسابِ بانکی را پاک کند. */
    public function test_saving_infra_leaves_the_bank_account_alone(): void
    {
        Setting::put('bank_sheba', '123456789012345678901234');
        Setting::put('pricing_baseline_rate', '95000');

        $this->actingAs($this->admin())->post('/admin/settings', [
            'tab'                   => 'infra',
            'cloud_guard_daily_max' => '7',
        ])->assertRedirect();

        $this->assertSame('7', Setting::get('cloud_guard_daily_max'));
        $this->assertSame('123456789012345678901234', Setting::get('bank_sheba'));
        $this->assertSame('95000', Setting::get('pricing_baseline_rate'));
    }

    /**
     * ⚠️ تیکِ خاموش هنوز باید داخلِ **تبِ خودش** خاموش شود.
     *
     * چک‌باکسِ تیک‌نخورده اصلاً فرستاده نمی‌شود، پس نمی‌شود همهٔ کلیدهای غایب
     * را «دست نزن» گرفت. تفکیک باید در سطحِ تب باشد، نه در سطحِ فیلد.
     */
    public function test_an_unchecked_box_still_turns_off_within_its_own_tab(): void
    {
        Setting::put('cloud_traffic_unlimited', '1');

        $this->actingAs($this->admin())->post('/admin/settings', [
            'tab' => 'infra',
        ])->assertRedirect();

        $this->assertNull(Setting::get('cloud_traffic_unlimited'));
    }

    // ═══════════════ ۳) راهنمای الگو، به‌شکلِ اجراشدنی ═══════════════

    public function test_no_setting_key_belongs_to_two_tabs(): void
    {
        $seen = [];
        foreach (array_keys(S::TABS) as $tab) {
            foreach (array_keys(S::fieldsFor($tab)) as $key) {
                $this->assertArrayNotHasKey($key, $seen,
                    "کلیدِ «{$key}» هم در تبِ «".($seen[$key] ?? '?')."» است هم «{$tab}». "
                    .'کلیدِ دوتابعیتی یعنی یک تب دیگری را پاک می‌کند.');
                $seen[$key] = $tab;
            }
        }

        $this->assertNotEmpty($seen);
    }

    /**
     * 🔴 هر فیلدی که در فرمِ تنظیمات رندر می‌شود باید در `FIELDS` همان تب باشد.
     *
     * فیلدی که در ویو هست و در `FIELDS` نیست، اعتبارسنجی نمی‌شود، نوشته نمی‌شود
     * و **بی‌هیچ خطایی ذخیره نمی‌شود** — مدیر عدد را عوض می‌کند، «ذخیره شد»
     * می‌بیند، و هیچ اتفاقی نمی‌افتد. دقیقاً همان تجربه‌ای که یک بار از این
     * صفحه گزارش شد، این بار از یک علتِ دیگر.
     */
    public function test_every_rendered_field_is_declared_in_its_tab(): void
    {
        $ignored = ['_token', 'tab'];

        foreach (array_keys(S::TABS) as $tab) {
            $declared = array_keys(S::fieldsFor($tab));
            if ($declared === []) {
                continue;
            }

            preg_match_all('~\bname="([^"\[\]]+)"~', $this->settingsForm($tab), $m);

            foreach (array_unique($m[1]) as $name) {
                if (in_array($name, $ignored, true)) {
                    continue;
                }
                $this->assertContains($name, $declared,
                    "فیلدِ «{$name}» در تبِ «{$tab}» رندر می‌شود ولی در FIELDS نیست — بی‌صدا ذخیره نمی‌شود.");
            }
        }
    }

    /**
     * و نیمهٔ دوم: کلیدی که اعلام شده ولی هیچ‌جا رندر نمی‌شود.
     *
     * ⚠️ ضعیف‌تر از ادعای بالاست و عمداً: بعضی فیلدها فقط **مشروط** رندر
     * می‌شوند (تیکِ «فراموش کن» فقط وقتی توکنی ذخیره شده باشد). پس فقط
     * فیلدهای بی‌قیدوشرط سنجیده می‌شوند.
     */
    public function test_unconditional_fields_actually_reach_the_page(): void
    {
        $conditional = ['stamp', 'remove_stamp'];

        foreach (['accounts', 'pricing'] as $tab) {
            $form = $this->settingsForm($tab);

            foreach (array_keys(S::fieldsFor($tab)) as $key) {
                if (in_array($key, $conditional, true) || str_ends_with($key, '_forget')) {
                    continue;
                }
                $this->assertStringContainsString('name="'.$key.'"', $form,
                    "کلیدِ «{$key}» در FIELDS تبِ «{$tab}» هست ولی در صفحه رندر نمی‌شود.");
            }
        }
    }

    // ═══════════════ ۴) صفحاتی که به تب منتقل شدند ═══════════════

    public function test_the_old_pages_redirect_into_their_tab(): void
    {
        $admin = $this->admin();

        foreach ([
            '/admin/payment-accounts' => 'accounts',
            '/admin/crypto-wallets'   => 'accounts',
            '/admin/costs'            => 'costs',
            '/admin/templates'        => 'messages',
        ] as $old => $tab) {
            $this->actingAs($admin)->get($old)->assertRedirect('/admin/settings?tab='.$tab);
        }
    }

    /**
     * ⚠️ ریدایرکت کافی نیست: محتوا هم باید واقعاً آن‌جا باشد.
     *
     * بی‌این ادعا، یک ریدایرکتِ درست می‌توانست به تبی برود که آن بخش را اصلاً
     * رندر نمی‌کند — یعنی قابلیت بی‌صدا از دسترس خارج شود.
     */
    public function test_the_moved_sections_really_render_inside_settings(): void
    {
        $accounts = $this->html('accounts');
        $this->assertStringContainsString('حساب بانکی شرکت', $accounts);
        $this->assertStringContainsString('حساب‌های ارزی و رمزارز', $accounts);
        $this->assertStringContainsString('استخر آدرس‌های دریافت رمزارز', $accounts);
        // فرم‌های هر بخش باید به مسیرِ خودشان POST کنند، نه به /admin/settings
        $this->assertStringContainsString('action="/admin/payment-accounts"', $accounts);
        $this->assertStringContainsString('action="/admin/crypto-wallets"', $accounts);

        $this->assertStringContainsString('هزینه‌های ثابت سرویس‌ها', $this->html('costs'));
        $this->assertStringContainsString('الگوی پیام‌ها', $this->html('messages'));
    }

    /**
     * 🔴 هر کلاسِ CSSای که این تب‌ها استفاده می‌کنند باید در `admin.css` تعریف
     * شده باشد.
     *
     * ⚠️ این تست از یک باگِ واقعیِ همین بازچینی آمد: استایلِ `.pa-form` در یک
     * `<style>`ِ **درون‌خطیِ** `payment-accounts.blade.php` بود، و وقتی آن ویو
     * حذف شد و مارک‌آپش به تبِ حساب‌ها رفت، استایلش جا مانْد. فرم بی‌هیچ خطایی
     * و با کدِ ۲۰۰ بی‌استایل رندر می‌شد.
     *
     * ✅ استثنای `btn-glass` برداشته شد. آن کلاس در ۱۴ ویوِ مدیریت استفاده
     * می‌شد و هیچ‌جای `admin.css` تعریف نشده بود؛ حالا هست، پس این تست دیگر
     * هیچ معافیتی ندارد. **همین‌طور نگهش دار** — فهرستِ استثناء که بماند،
     * به‌مرور تست را به یک لیستِ سفیدِ بی‌اثر تبدیل می‌کند.
     */
    public function test_every_css_class_the_tabs_use_is_defined(): void
    {
        $css = (string) file_get_contents(public_path('assets/css/admin.css'));
        $views = array_merge(
            glob(resource_path('views/admin/settings/*.blade.php')) ?: [],
            [resource_path('views/admin/settings.blade.php')]
        );

        $missing = [];
        foreach ($views as $view) {
            preg_match_all('~class="([^"{}]+)"~', (string) file_get_contents($view), $m);
            foreach ($m[1] as $chunk) {
                foreach (preg_split('~\s+~', trim($chunk)) as $class) {
                    if ($class === '') {
                        continue;
                    }
                    if (! str_contains($css, '.'.$class)) {
                        $missing[$class] = basename($view);
                    }
                }
            }
        }

        $this->assertSame([], $missing,
            'کلاسِ تعریف‌نشده (بی‌خطا، بی‌استایل): '
            .implode(', ', array_map(fn ($v, $k) => "{$k} در {$v}", $missing, array_keys($missing))));
    }

    /** منوی کناری دیگر نباید آن سه/چهار آیتم را داشته باشد. */
    public function test_the_sidebar_no_longer_lists_the_merged_pages(): void
    {
        $html = $this->html('general');

        foreach ([
            'href="/admin/payment-accounts"',
            'href="/admin/crypto-wallets"',
            'href="/admin/costs"',
            'href="/admin/templates"',
        ] as $link) {
            $this->assertStringNotContainsString($link, $html,
                'این آیتم داخلِ تنظیمات رفته و نباید در منو تکرار شود: '.$link);
        }

        $this->assertStringContainsString('href="/admin/settings"', $html);
    }
}
