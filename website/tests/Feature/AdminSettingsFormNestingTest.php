<?php

namespace Tests\Feature;

use App\Models\GoogleCalendarToken;
use App\Models\NotificationTemplate;
use App\Models\ServiceCost;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * صفحهٔ تنظیمات نباید `<form>`ِ تودرتو داشته باشد.
 *
 * ═══ باگی که این تست از آن آمد ═══
 *
 * کارفرما: «۵ را ۱۰۰ کردم، روی ذخیره کلیک می‌کنم هیچ اتفاقی نمی‌افتد.»
 *
 * علت: یک `<form>`ِ «قطعِ اتصالِ تقویمِ گوگل» **وسطِ** فرمِ اصلی نشسته بود. HTML
 * فرمِ تودرتو را نمی‌پذیرد؛ مرورگر تا `<form>`ِ داخلی را ببیند، فرمِ **بیرونی
 * را همان‌جا می‌بندد**. پس دکمهٔ «ذخیره» — که پایین‌تر بود — به هیچ فرمی وصل
 * نبود و کلیک روی آن واقعاً هیچ کاری نمی‌کرد: نه درخواستی، نه خطایی.
 *
 * یعنی **هیچ‌کدام** از تنظیماتِ آن صفحه ذخیره نمی‌شد: نه توکنِ زیرساخت‌ها، نه
 * سقفِ محافظِ سرور، هیچ‌کدام.
 *
 * ⚠️ چرا هیچ‌کدام از ۱۸۸۵ تست نگرفتش: همه `POST /admin/settings` را **مستقیم**
 * می‌زنند و کنترلر کاملاً سالم است. آنچه شکسته بود رابطهٔ دکمه با فرم در
 * **مرورگر** بود. همان قاعدهٔ ثبت‌شدهٔ پروژه: «کدِ ۲۰۰ یعنی هیچ» — و این بار
 * حتی ۲۰۰ هم نبود، چون اصلاً درخواستی نمی‌رفت.
 *
 * ⚠️ درسِ عمومی‌تر: تستی که فقط نقطهٔ پایانی را می‌زند، **هرگز** نمی‌فهمد که
 * کاربر راهی برای رسیدن به آن نقطه ندارد.
 */
class AdminSettingsFormNestingTest extends TestCase
{
    use RefreshDatabase;

    private function page(string $tab = 'general', ?User $admin = null): string
    {
        Http::preventStrayRequests();
        $admin ??= User::create([
            'name' => 'مدیر', 'email' => 'st'.random_int(1000, 9999).'@example.test',
            'password' => bcrypt('secret-for-test'), 'role' => 'admin',
        ]);

        return $this->actingAs($admin)->get('/admin/settings?tab='.$tab)->assertOk()->getContent();
    }

    /**
     * ⚠️ از وقتی صفحه تب‌بندی شد، «صفحهٔ تنظیمات» یک HTML نیست بلکه هفت‌تاست.
     * ادعای تودرتویی باید روی **همه**شان برود، وگرنه فردا یک فرمِ تودرتو در
     * تبی که این تست نمی‌بیند اضافه می‌شود و همان باگ از درِ دیگر برمی‌گردد.
     */
    public static function tabs(): array
    {
        return array_map(fn ($t) => [$t], array_keys(
            \App\Http\Controllers\Admin\SettingsController::TABS
        ));
    }

    /**
     * 🔴 هستهٔ ادعا: هیچ `<form>`ای پیش از بسته‌شدنِ فرمِ قبلی باز نشود.
     *
     * ⚠️ عمداً روی **همهٔ** فرم‌های صفحه ادعا می‌کند، نه فقط آن یکی که شکسته
     * بود. اگر فردا کسی فرمِ تازه‌ای وسطِ فرمِ اصلی بگذارد، همین تست می‌گیردش —
     * وگرنه باگ از درِ دیگر برمی‌گردد و باز هم بی‌صدا.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('tabs')]
    public function test_no_form_is_nested_inside_another(string $tab): void
    {
        $html = $this->page($tab);

        preg_match_all('~<form\b|</form>~i', $html, $m, PREG_OFFSET_CAPTURE);

        $depth = 0;

        foreach ($m[0] as [$tag, $pos]) {
            if (stripos($tag, '</') === 0) {
                $depth--;

                continue;
            }

            $depth++;

            $this->assertLessThanOrEqual(1, $depth,
                'یک <form> داخلِ <form>ِ دیگر باز شد (نویسه '.$pos.'). '
                .'مرورگر فرمِ بیرونی را همان‌جا می‌بندد و دکمهٔ ذخیره بی‌اثر می‌شود.');
        }

        $this->assertSame(0, $depth, 'تعدادِ <form> و </form> نمی‌خواند');
    }

    /**
     * ⚠️ نیمهٔ دوم: دکمهٔ ذخیره باید واقعاً **به فرمِ تنظیمات وصل** باشد.
     *
     * بی‌این، کسی می‌توانست فرمِ تودرتو را با بردنِ دکمه به بیرون «حل» کند و
     * دکمه‌ای بسازد که باز هم هیچ‌جا پست نمی‌کند.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('tabs')]
    public function test_the_save_button_belongs_to_the_settings_form(string $tab): void
    {
        $cost = null;
        if ($tab === 'costs') {
            $cost = ServiceCost::create([
                'key' => 'form-test', 'label' => 'Test cost', 'amount' => 100,
                'currency_code' => 'IRT', 'is_system' => false,
            ]);
        }
        if ($tab === 'messages') {
            NotificationTemplate::create([
                'key' => 'form-test-admin', 'title' => 'Test notification',
                'group' => 'billing', 'audience' => 'admin', 'is_active' => true,
            ]);
        }

        $html = $this->page($tab);
        $xpath = $this->document($html);

        // Each tab owns different actions; the guide deliberately has no form.
        if ($tab === 'guide') {
            $this->assertSame(0, $xpath->query('//main//form')->length);
            $this->assertSame(0, $xpath->query('//main//button[@type="submit"]')->length);
            $this->assertStringContainsString('هر تنظیم کجا می‌نشیند', $html);

            return;
        }

        $buttons = $xpath->query('//main//button[@type="submit"]');
        $this->assertGreaterThan(0, $buttons->length, 'No submit button for '.$tab);
        $actions = [];
        foreach ($buttons as $button) {
            $owner = $button->hasAttribute('form')
                ? $xpath->query('//form[@id="'.$button->getAttribute('form').'"]')
                : $xpath->query('ancestor::form[1]', $button);
            $this->assertSame(1, $owner->length, 'Submit button has no unique form owner: '.$tab);
            $form = $owner->item(0);
            $this->assertSame('post', strtolower($form->getAttribute('method')));
            $this->assertSame(1, $xpath->query('.//input[@name="_token"]', $form)->length);
            $actions[] = $form->getAttribute('action');
        }

        $expected = match ($tab) {
            'costs' => ['/admin/costs', '/admin/costs/add', '/admin/costs/'.$cost->id.'/delete'],
            'menus' => ['/admin/menus/add', '/admin/menus/save', '/admin/menus/hide'],
            'bale' => ['/admin/bale/pair'],
            'messages' => [],
            default => ['/admin/settings'],
        };
        foreach ($expected as $action) {
            $this->assertContains($action, $actions, 'Missing usable form: '.$action);
        }
        if ($tab === 'messages') {
            foreach ($actions as $action) {
                $this->assertMatchesRegularExpression('~^/admin/templates/\d+/toggle$~', $action);
            }
        }
    }

    /** دکمهٔ قطعِ اتصال باید با `form=` به فرمِ بیرونیِ خودش وصل باشد */
    public function test_the_disconnect_button_targets_its_own_outer_form(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Setting::put('google_client_id', 'forms.apps.googleusercontent.com');
        Setting::putSecret('google_client_secret', 'test-only');
        GoogleCalendarToken::create([
            'user_id' => $admin->id, 'google_email' => 'calendar@example.test',
            'calendar_id' => 'primary', 'access_token' => 'test-only-access',
            'refresh_token' => 'test-only-refresh', 'expires_at' => now()->addHour(),
        ]);
        $html = $this->page('general', $admin);
        $xpath = $this->document($html);

        $this->assertSame(1, $xpath->query('//button[@type="submit" and @form="gcal-disconnect"]')->length);
        $this->assertSame(1, $xpath->query('//form[@id="gcal-disconnect" and @method="post" and @action="/admin/calendar/google/disconnect"]')->length);
        $this->assertSame(0, $xpath->query('//form[@id="gcal-disconnect"]/ancestor::form')->length);
        $this->assertSame(1, preg_match('~<form[^>]*action="/admin/settings"[^>]*>(.*?)</form>~is', $html, $matches));
        $this->assertStringNotContainsString('<form', $matches[1]);
        $this->assertStringContainsString('type="submit"', $matches[1]);
        Http::assertNothingSent();
    }

    private function document(string $html): \DOMXPath
    {
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new \DOMXPath($dom);
    }
}
