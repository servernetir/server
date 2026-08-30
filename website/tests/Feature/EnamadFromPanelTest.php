<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * نمادِ اعتماد از **پنلِ مدیریت** وارد می‌شود، نه از `.env`.
 *
 * گرفتنِ نماد یک کارِ اداری است نه دیپلوی — کسی که کدش را از enamad.ir
 * می‌گیرد لزوماً به `.env` سرور دسترسی ندارد. پس جایش کنارِ مهرِ شرکت است.
 *
 * ⚠️ `.env` عمداً به‌عنوانِ راهِ دوم می‌مانَد تا روی نصبی که جدولِ `settings`
 * ندارد هم کار کند.
 */
class EnamadFromPanelTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** مقدارِ پنل باید بر `.env` بچربد و مهر را روی سایت بیاورد. */
    public function test_the_panel_value_wins_and_renders(): void
    {
        config(['company.enamad' => ['id' => 'FROM-ENV', 'code' => 'ENVCODE']]);

        Setting::put('enamad_id', '709775');
        Setting::put('enamad_code', 'abc123');

        $seals = trust_seals();

        $this->assertCount(1, $seals);
        $this->assertStringContainsString('id=709775', $seals[0]['href']);
        $this->assertStringNotContainsString('FROM-ENV', $seals[0]['href'],
            'مقدارِ .env بر مقدارِ پنل چربید');

        $this->assertStringContainsString('trustseal.enamad.ir',
            $this->get('/')->assertOk()->getContent());
    }

    /** بی‌مقدارِ پنل، `.env` می‌ماند — نصبِ بی‌جدول نباید مهرش را از دست بدهد. */
    public function test_env_still_works_as_the_fallback(): void
    {
        config(['company.enamad' => ['id' => '111', 'code' => 'zzz']]);

        $this->assertCount(1, trust_seals());
    }

    /**
     * 🔴 نیمه‌پر ⇒ هیچ مهری.
     *
     * آدرسِ تأییدِ نیمه‌ساخته به صفحهٔ نامعتبرِ نماد می‌رود، و خریدارِ ایرانی
     * این مهر را **کلیک می‌کند**.
     */
    public function test_half_filled_produces_no_seal(): void
    {
        config(['company.enamad' => ['id' => '', 'code' => '']]);
        Setting::put('enamad_id', '709775');
        Setting::put('enamad_code', '');

        $this->assertSame([], trust_seals());
    }

    /** فرمِ تنظیمات هر دو مقدار را ذخیره می‌کند و به فرم برمی‌گرداند. */
    public function test_the_settings_form_saves_and_shows_them_back(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/settings', ['tab' => 'general', 'enamad_id' => '709775', 'enamad_code' => 'abc123'])
            ->assertRedirect();

        $this->assertSame('709775', Setting::get('enamad_id'));

        // ⚠️ برخلافِ رازها، به فرم برمی‌گردد: روی هر صفحهٔ سایت چاپ می‌شود، پس
        //    راز نیست و مدیر باید بتواند با پنلِ enamad مقایسه‌اش کند.
        $html = $this->actingAs($this->admin())->get('/admin/settings?tab=general')->assertOk()->getContent();
        $this->assertStringContainsString('709775', $html);
    }

    /**
     * ⚠️ ورودیِ نامعتبر رد می‌شود.
     *
     * این دو مقدار مستقیم داخلِ `href` و `src` می‌نشینند؛ الگوی حروف‌وعدد
     * جلوی هر چیزِ دیگری را می‌گیرد.
     */
    public function test_a_hostile_value_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/settings', ['tab' => 'general', 'enamad_id' => '"><script>x', 'enamad_code' => 'ok123'])
            ->assertSessionHasErrors('enamad_id');

        $this->assertSame('', (string) Setting::get('enamad_id', ''));
    }
}
