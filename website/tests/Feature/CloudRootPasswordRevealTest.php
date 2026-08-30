<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\Customer;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * رمزِ root نباید پیش از دیده‌شدن بسوزد.
 *
 * ═══ رخدادِ واقعی ═══
 *
 * مشتری سرورِ مجازی خرید و در پنل **هیچ رمزی ندید** — یعنی اصلاً نمی‌توانست
 * به سرورش وصل شود.
 *
 * علت: صفحهٔ سرور یک **GET** بود که پرچمِ `password_seen` را می‌زد. هر
 * بارگذاریِ صفحه رمز را می‌سوزاند — یک رفرش، یک prefetchِ مرورگر، یا ورودِ
 * مدیر به پنلِ مشتری برای عیب‌یابی. کاربر چیزی ندید و پرچم روشن شد.
 *
 * ⚠️ قاعدهٔ عمومی که این باگ یادآوری کرد: **GET نباید حالت را عوض کند.**
 * مرورگرها GET را آزادانه تکرار و پیش‌بارگذاری می‌کنند؛ هر چیزِ یک‌بارمصرف
 * باید پشتِ کنشِ صریح باشد.
 *
 * خودِ قاعدهٔ «یک بار» درست است و می‌مانَد — فقط لحظه‌اش را کاربر انتخاب می‌کند.
 */
class CloudRootPasswordRevealTest extends TestCase
{
    use RefreshDatabase;

    private const PW = 'Sup3r-S3cret-Root-Pw';

    private function setup1(): array
    {
        $c = Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'pw'.random_int(1000, 9999).'@example.test',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('secret-for-test'), 'status' => 'active',
        ]);

        $s = Service::create([
            'customer_id' => $c->id, 'name' => 'سرور مجازی', 'currency_code' => 'IRT',
            'price' => 900000, 'cycle' => 'monthly', 'status' => 'active',
            'cloud_plan_id' => null, 'provision_status' => 'done',
        ]);

        $i = new CloudInstance(['service_id' => $s->id, 'provider' => 'hetzner',
            'provider_ref' => '123', 'hostname' => 'sn-svc-'.$s->id, 'ipv4' => '203.0.113.9',
            'status' => 'active']);
        $i->setPassword(self::PW);           // password_seen → false
        $i->service_id = $s->id;
        $i->save();

        return [$c, $s, $i];
    }

    /** 🔴 قلبِ باگ: باز کردنِ صفحه نباید رمز را بسوزاند */
    public function test_merely_viewing_the_page_never_burns_the_password(): void
    {
        [$c, $s, $i] = $this->setup1();

        $this->actingAs($c, 'customer')->get('/account/cloud/'.$s->id)->assertOk();
        $this->actingAs($c, 'customer')->get('/account/cloud/'.$s->id)->assertOk();

        $this->assertFalse((bool) $i->refresh()->password_seen,
            'بارگذاریِ صفحه رمز را سوزاند — مشتری هرگز آن را نمی‌بیند و به سرورش وصل نمی‌شود');
    }

    /** و صفحه باید راهِ دیدنش را نشان دهد */
    public function test_the_page_offers_a_way_to_reveal_it(): void
    {
        [$c, $s] = $this->setup1();

        /*
         * ⚠️ ادعا روی **دادهٔ ویو** است نه روی HTML.
         *
         * بلوکِ رمز داخلِ شرط‌های دیگری از صفحه است (وضعیتِ تحویل، قابلیت‌های
         * درایور) و فیکسچرِ این تست همهٔ آن‌ها را نمی‌سازد. ادعای HTMLمحور
         * این‌جا چیزی را می‌سنجید که موضوعِ این تست نیست و با هر تغییرِ
         * بی‌ربطِ چیدمان قرمز می‌شد.
         *
         * چیزی که واقعاً باید درست باشد این است: کنترلر بگوید «این کاربر
         * حق دارد رمز را ببیند». رندرِ دکمه از همین یک پرچم می‌آید.
         */
        $res = $this->actingAs($c, 'customer')->get('/account/cloud/'.$s->id)->assertOk();

        $this->assertTrue($res->viewData('canReveal'),
            'کنترلر اجازهٔ نمایشِ رمز را نمی‌دهد، پس دکمه هرگز رندر نمی‌شود');
    }

    /** کنشِ صریح رمز را می‌دهد و همان‌جا می‌سوزاند */
    public function test_an_explicit_action_reveals_it_once(): void
    {
        [$c, $s, $i] = $this->setup1();

        $this->actingAs($c, 'customer')
            ->post('/account/cloud/'.$s->id.'/reveal-password')
            ->assertRedirect()
            ->assertSessionHas('revealed_root_password', self::PW);

        $this->assertTrue((bool) $i->refresh()->password_seen);
    }

    /** بارِ دوم دیگر نمی‌دهد — قاعدهٔ «یک بار» سرِ جایش است */
    public function test_a_second_reveal_is_refused(): void
    {
        [$c, $s] = $this->setup1();

        $this->actingAs($c, 'customer')->post('/account/cloud/'.$s->id.'/reveal-password');

        $this->actingAs($c, 'customer')
            ->post('/account/cloud/'.$s->id.'/reveal-password')
            ->assertSessionHasErrors();
    }

    /** ⚠️ رمز هرگز نباید در URL برود — لاگِ سرور و کلادفلر و تاریخچهٔ مرورگر */
    public function test_the_password_never_travels_in_the_url(): void
    {
        [$c, $s] = $this->setup1();

        $res = $this->actingAs($c, 'customer')
            ->post('/account/cloud/'.$s->id.'/reveal-password');

        $this->assertStringNotContainsString(self::PW, (string) $res->headers->get('Location'));
    }

    /** مالکیت: مشتریِ دیگری نباید بتواند رمز را باز کند */
    public function test_another_customer_cannot_reveal_it(): void
    {
        [, $s] = $this->setup1();

        $other = Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'ot'.random_int(1000, 9999).'@example.test',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('secret-for-test'), 'status' => 'active',
        ]);

        $this->actingAs($other, 'customer')
            ->post('/account/cloud/'.$s->id.'/reveal-password')
            ->assertNotFound();
    }
}
