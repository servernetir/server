<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CloudInstance;
use App\Models\Customer;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
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
 * نمایش صریح باید قابل تکرار باشد؛ قطع پاسخ یا رفرش نباید مشتری را از رمز محروم کند.
 */
class CloudRootPasswordRevealTest extends TestCase
{
    use RefreshDatabase;

    private const PW = 'Sup3r-S3cret-Root-Pw';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

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
            'status' => 'running']);
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

    /** درخواست صریح رمز را فقط برای همان نمونه در نشست می‌گذارد. */
    public function test_an_explicit_action_reveals_the_stored_password_without_changing_it(): void
    {
        [$c, $s, $i] = $this->setup1();

        $ciphertext = $i->root_password_enc;
        $response = $this->actingAs($c, 'customer')
            ->post('/account/cloud/'.$s->id.'/reveal-password')
            ->assertRedirect(route('account.cloud.show', $s))
            ->assertSessionHas('revealed_root_password_'.$i->id, self::PW)
            ->assertSessionMissing('revealed_root_password');

        $this->assertTrue((bool) $i->refresh()->password_seen);
        $this->assertSame($ciphertext, $i->root_password_enc);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    public function test_previously_seen_password_can_be_revealed_repeatedly(): void
    {
        [$c, $s, $i] = $this->setup1();
        $i->update(['password_seen' => true]);
        $ciphertext = $i->root_password_enc;

        $this->actingAs($c, 'customer')->get('/account/cloud/'.$s->id)
            ->assertOk()->assertViewHas('canReveal', true)->assertViewHas('password', fn ($value) => $value === null);

        foreach ([1, 2] as $attempt) {
            $this->post('/account/cloud/'.$s->id.'/reveal-password')
                ->assertRedirect()->assertSessionHasNoErrors()
                ->assertSessionHas('revealed_root_password_'.$i->id, self::PW);
            $this->get('/account/cloud/'.$s->id)
                ->assertOk()->assertViewHas('password', self::PW);
            $this->get('/account/cloud/'.$s->id)
                ->assertOk()->assertViewHas('password', fn ($value) => $value === null)->assertViewHas('canReveal', true);
        }
        $this->assertSame($ciphertext, $i->fresh()->root_password_enc);
    }

    public function test_password_flash_is_consumed_only_by_its_instance_and_get_is_not_cacheable(): void
    {
        [$c, $s, $i] = $this->setup1();
        [, $otherService] = $this->setup1();
        $otherService->update(['customer_id' => $c->id]);
        $key = 'revealed_root_password_'.$i->id;

        $this->actingAs($c, 'customer')->withSession([$key => self::PW])
            ->get('/account/cloud/'.$otherService->id)
            ->assertOk()->assertViewHas('password', fn ($value) => $value === null)->assertDontSee(self::PW)
            ->assertSessionHas($key, self::PW);
        $response = $this->get('/account/cloud/'.$s->id)
            ->assertOk()->assertViewHas('password', self::PW)->assertSessionMissing($key);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->get('/account/cloud/'.$s->id)->assertOk()->assertViewHas('password', fn ($value) => $value === null)->assertDontSee(self::PW);
    }

    public function test_a_lost_flash_can_be_recovered_without_resetting_the_server_password(): void
    {
        [$c, $s, $i] = $this->setup1();
        $this->actingAs($c, 'customer')->post('/account/cloud/'.$s->id.'/reveal-password');
        session()->forget('revealed_root_password_'.$i->id);
        $this->post('/account/cloud/'.$s->id.'/reveal-password')
            ->assertSessionHasNoErrors()->assertSessionHas('revealed_root_password_'.$i->id, self::PW);
        $this->assertSame(self::PW, $i->fresh()->password());
    }

    public function test_missing_or_undecryptable_password_is_not_recorded_as_revealed(): void
    {
        foreach ([null, 'invalid-ciphertext', Crypt::encryptString('')] as $ciphertext) {
            [$c, $s, $i] = $this->setup1();
            $i->update(['root_password_enc' => $ciphertext]);
            $this->actingAs($c, 'customer')->post('/account/cloud/'.$s->id.'/reveal-password')
                ->assertSessionHasErrors()->assertSessionMissing('revealed_root_password_'.$i->id);
            $this->assertFalse($i->fresh()->password_seen);
            $this->assertSame($ciphertext, $i->fresh()->root_password_enc);
            $this->assertFalse(ActivityLog::where('service_id', $s->id)->where('action', 'password_reveal')->exists());
        }
    }

    public function test_reveal_audit_distinguishes_customer_and_impersonating_staff_without_secrets(): void
    {
        [$c, $s] = $this->setup1();
        $url = '/account/cloud/'.$s->id.'/reveal-password';
        $this->actingAs($c, 'customer')->post($url)->assertSessionHasNoErrors();
        $customerLog = ActivityLog::where('service_id', $s->id)->where('action', 'password_reveal')->firstOrFail();
        $this->assertSame('customer', $customerLog->actor);
        $this->assertStringNotContainsString(self::PW, json_encode($customerLog->getAttributes(), JSON_THROW_ON_ERROR));

        $this->withSession(['impersonator_id' => 501])->post($url)->assertSessionHasNoErrors();
        $staffLog = ActivityLog::where('service_id', $s->id)->where('action', 'impersonate')->firstOrFail();
        $this->assertSame('staff', $staffLog->actor);
        $this->assertStringNotContainsString(self::PW, json_encode($staffLog->getAttributes(), JSON_THROW_ON_ERROR));
        $this->assertFalse(ActivityLog::visibleToCustomer()->whereKey($staffLog->id)->exists());
    }

    public function test_lightnode_delivery_gate_remains_in_effect_for_previously_seen_passwords(): void
    {
        [$c, $s, $i] = $this->setup1();
        $i->update(['provider' => 'lightnode', 'status' => 'building', 'password_seen' => true]);
        $url = '/account/cloud/'.$s->id.'/reveal-password';
        $this->actingAs($c, 'customer')->post($url)
            ->assertSessionHasErrors()->assertSessionMissing('revealed_root_password_'.$i->id);
        $i->update(['status' => 'running']);
        $this->post($url)->assertSessionHasErrors()->assertSessionMissing('revealed_root_password_'.$i->id);
        $i->update(['ready_notified_at' => now()]);
        session()->forget('errors');
        $this->post($url)->assertSessionHasNoErrors()->assertSessionHas('revealed_root_password_'.$i->id, self::PW);
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
