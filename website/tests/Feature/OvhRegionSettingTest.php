<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * منطقهٔ OVH در پنل — از فرم تا تنظیم.
 *
 * ═══ چرا این تست جدا از `OvhClientTest` است ═══
 *
 * آن‌جا ثابت می‌شود کلاینت به منطقهٔ ذخیره‌شده گوش می‌دهد. ولی تنظیمی که
 * **راهی برای ست‌کردن نداشته باشد** عملاً وجود ندارد؛ همان قاعدهٔ ثبت‌شدهٔ
 * پروژه: «تستی که فقط نقطهٔ پایانی را می‌زند، هرگز نمی‌فهمد کاربر راهی برای
 * رسیدن به آن ندارد.»
 *
 * و نشانیِ ساختِ کلید هم منطقه‌ای است: اگر صفحه نشانیِ اروپا را به حسابِ
 * آمریکایی نشان دهد، مدیر کلیدی می‌سازد که هرگز کار نمی‌کند و تنها بازخوردش
 * ۴۰۳ِ بی‌توضیحِ امضاست.
 */
class OvhRegionSettingTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'مدیر', 'email' => 'ovh'.random_int(1000, 9999).'@example.test',
            'password' => bcrypt('secret-for-test'), 'role' => 'admin',
        ]);
    }

    private function infraPage(): string
    {
        return $this->actingAs($this->admin())
            ->get('/admin/settings?tab=infra')->assertOk()->getContent();
    }

    public function test_the_region_can_be_chosen_from_the_panel(): void
    {
        $html = $this->infraPage();

        $this->assertStringContainsString('name="ovh_region"', $html);

        foreach (['value="eu"', 'value="ca"', 'value="us"'] as $option) {
            $this->assertStringContainsString($option, $html);
        }
    }

    public function test_saving_the_region_sticks(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/settings', ['tab' => 'infra', 'ovh_region' => 'us'])
            ->assertRedirect();

        $this->assertSame('us', Setting::get('ovh_region'));
    }

    /** ⚠️ نشانیِ createToken باید همان منطقه‌ای باشد که ذخیره شده */
    public function test_the_page_points_at_the_token_url_of_the_saved_region(): void
    {
        Setting::put('ovh_region', 'us');

        $html = $this->infraPage();

        $this->assertStringContainsString('api.us.ovhcloud.com/createToken', $html);
        $this->assertStringNotContainsString('eu.api.ovh.com/createToken', $html);
    }

    /** پیش‌فرضِ صفحه با پیش‌فرضِ کلاینت یکی است — وگرنه صفحه دربارهٔ خودش دروغ می‌گوید */
    public function test_an_unset_region_shows_europe(): void
    {
        $html = $this->infraPage();

        $this->assertStringContainsString('eu.api.ovh.com/createToken', $html);
    }

    /**
     * 🔴 منطقهٔ بی‌معنا نباید ذخیره شود. اگر بشود، `OvhClient::region()` به
     * `eu` برمی‌گردد ولی صفحه همان مقدارِ بی‌معنا را نشان می‌دهد — یعنی پنل و
     * کلاینت دو حرفِ متفاوت می‌زنند و عیب‌یابیِ ۴۰۳ غیرممکن می‌شود.
     */
    public function test_a_nonsense_region_is_rejected_by_validation(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/settings', ['tab' => 'infra', 'ovh_region' => 'atlantis'])
            ->assertSessionHasErrors('ovh_region');

        $this->assertNull(Setting::get('ovh_region'));
    }
}
