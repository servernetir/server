<?php

namespace Tests\Feature;

use App\Models\GoogleCalendarToken;
use App\Models\Setting;
use App\Models\User;
use App\Services\Calendar\CalendarService;
use App\Services\Calendar\Google\GoogleCalendarClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * اتصالِ تقویمِ گوگل.
 *
 * ⚠️ همهٔ تماس‌ها با `Http::fake()` است — گوگل سندباکس ندارد و تماسِ واقعی در
 * تست یعنی وابستگی به شبکه و به حسابِ یک نفرِ خاص.
 *
 * ⚠️ در هر تست فقط **یک بار** fake ثبت می‌شود: `Http::fake()` استاب‌ها را به
 * ترتیبِ ثبت می‌سنجد و اولین تطبیق برنده است، پس یک `fake` بعدی بی‌اثر
 * می‌مانَد و تست بی‌صدا هیچ‌چیز نمی‌سنجد (تلهٔ ثبت‌شدهٔ همین پروژه).
 */
class AdminCalendarGoogleTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::create([
            'name' => 'مدیر', 'email' => 'g'.random_int(1, 999999).'@x.com',
            'password' => bcrypt('secret1234'), 'role' => 'admin',
        ]);
    }

    private function configureApp(): void
    {
        Setting::put('google_client_id', '123.apps.googleusercontent.com');
        Setting::putSecret('google_client_secret', 'GOCSPX-test');
    }

    /**
     * ⚠️ `array_merge` و نه `+`.
     *
     * عملگرِ `+` روی آرایهٔ انجمنی، کلیدِ **سمتِ چپ** را نگه می‌دارد. با آن،
     * `$overrides['expires_at']` بی‌صدا نادیده گرفته می‌شد و توکن هیچ‌وقت
     * منقضی نمی‌شد — یعنی دو تستِ «تازه‌سازی» چیزی را می‌سنجیدند که اصلاً
     * اجرا نمی‌شد. خودِ همین تله یک بار این‌جا گرفت.
     */
    private function connect(User $user, array $overrides = []): GoogleCalendarToken
    {
        return GoogleCalendarToken::create(array_merge([
            'user_id'       => $user->id,
            'google_email'  => 'me@gmail.com',
            'calendar_id'   => 'primary',
            'access_token'  => 'at-1',
            'refresh_token' => 'rt-1',
            'expires_at'    => Carbon::now()->addHour(),
        ], $overrides));
    }

    /* ═════════════════════ پیکربندی و دیده‌شدن ═════════════════════ */

    public function test_the_google_layer_is_hidden_until_the_app_credentials_exist(): void
    {
        $html = $this->actingAs($this->staff(), 'web')->get('/admin/calendar')->assertOk()->getContent();

        // نه چیپِ گوگل، نه نوارِ اتصال — کنترلی که کاری نمی‌کند از نبودش بدتر است
        $this->assertStringNotContainsString('data-layer="google"', $html);
        $this->assertStringNotContainsString('اتصال به گوگل', $html);
    }

    /**
     * ⚠️ دکمهٔ اتصال در **تنظیمات** است، نه روی تقویم.
     *
     * صفحهٔ تقویم باید فضایش را به سررسیدها بدهد؛ اتصال خبری است که یک بار
     * لازم می‌شود. کاربرِ وصل‌نشده هم چیپی نمی‌بیند که هیچ‌وقت چیزی نیاورد.
     */
    public function test_the_connect_button_lives_in_settings_not_on_the_calendar(): void
    {
        $this->configureApp();
        $staff = $this->staff();

        $settings = $this->actingAs($staff, 'web')->get('/admin/settings')->assertOk()->getContent();
        $this->assertStringContainsString('اتصال به گوگل', $settings);

        $calendar = $this->actingAs($staff, 'web')->get('/admin/calendar')->assertOk()->getContent();
        $this->assertStringNotContainsString('اتصال به گوگل', $calendar);
        $this->assertStringNotContainsString('data-layer="google"', $calendar);
    }

    /* ═════════════════════ جریانِ OAuth ═════════════════════ */

    public function test_connect_redirects_to_google_with_offline_access(): void
    {
        $this->configureApp();

        $res = $this->actingAs($this->staff(), 'web')->get('/admin/calendar/google/connect');

        $res->assertRedirect();
        $to = $res->headers->get('Location');

        $this->assertStringContainsString('accounts.google.com', $to);
        // 🔴 بی‌این دو، refresh token نمی‌آید و اتصال فردا می‌میرد
        $this->assertStringContainsString('access_type=offline', $to);
        $this->assertStringContainsString('prompt=consent', $to);
        $this->assertStringContainsString('calendar.events', $to);
        $this->assertStringContainsString('state=', $to);
    }

    /**
     * 🔴 بدونِ تطبیقِ `state`، هر کسی می‌تواند کاربرِ واردشده را به یک callback
     * با کدِ **حسابِ خودش** بفرستد و تقویمِ خودش را به حسابِ او بچسباند.
     */
    public function test_a_callback_without_a_matching_state_is_refused(): void
    {
        $this->configureApp();
        Http::fake();

        $this->actingAs($this->staff(), 'web')
            ->get('/admin/calendar/google/callback?code=abc&state=forged')
            ->assertRedirect('/admin/calendar');

        $this->assertSame(0, GoogleCalendarToken::count());
        Http::assertNothingSent();
    }

    public function test_a_successful_callback_stores_an_encrypted_token(): void
    {
        $this->configureApp();

        $idToken = 'x.'.rtrim(strtr(base64_encode(json_encode(['email' => 'me@gmail.com'])), '+/', '-_'), '=').'.y';

        Http::fake(['oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'at-new', 'refresh_token' => 'rt-new',
            'expires_in' => 3600, 'id_token' => $idToken,
        ])]);

        $staff = $this->staff();

        $this->actingAs($staff, 'web')
            ->withSession(['google_calendar_state' => 'st-1'])
            ->get('/admin/calendar/google/callback?code=abc&state=st-1')
            ->assertRedirect('/admin/calendar')
            ->assertSessionHas('ok');

        $token = GoogleCalendarToken::firstWhere('user_id', $staff->id);
        $this->assertNotNull($token);
        $this->assertSame('me@gmail.com', $token->google_email);
        $this->assertSame('rt-new', $token->refresh_token);

        // ⚠️ رمزنگاری‌شده روی دیسک: مقدارِ خام نباید در ستون باشد
        $raw = \DB::table('google_calendar_tokens')->where('id', $token->id)->value('refresh_token');
        $this->assertNotSame('rt-new', $raw);
    }

    /**
     * 🔴 نبودِ refresh_token یعنی اتصالِ **ناقص**، نه موفق: دسترسی یک ساعت بعد
     * می‌میرد و راهی برای تازه‌کردنش نیست. ذخیره‌کردنش یعنی کاربر یک اتصالِ
     * سبز می‌بیند که فردا بی‌صدا از کار می‌افتد.
     */
    public function test_a_token_response_without_a_refresh_token_is_rejected(): void
    {
        $this->configureApp();

        Http::fake(['oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'at-new', 'expires_in' => 3600,
        ])]);

        $this->actingAs($this->staff(), 'web')
            ->withSession(['google_calendar_state' => 'st-1'])
            ->get('/admin/calendar/google/callback?code=abc&state=st-1')
            ->assertRedirect('/admin/calendar')
            ->assertSessionHas('err');

        $this->assertSame(0, GoogleCalendarToken::count());
    }

    public function test_disconnect_only_removes_the_current_users_token(): void
    {
        $this->configureApp();
        $mine = $this->staff();
        $other = $this->staff();
        $this->connect($mine);
        $this->connect($other);

        $this->actingAs($mine, 'web')->post('/admin/calendar/google/disconnect')->assertRedirect();

        $this->assertNull(GoogleCalendarToken::forUser($mine->id));
        $this->assertNotNull(GoogleCalendarToken::forUser($other->id), 'اتصالِ کاربرِ دیگر نباید دست بخورد');
    }

    /* ═════════════════════ خواندنِ رویداد ═════════════════════ */

    public function test_google_events_appear_in_the_calendar_for_the_connected_user_only(): void
    {
        $this->configureApp();
        $mine = $this->staff();
        $other = $this->staff();
        $this->connect($mine);

        Http::fake(['www.googleapis.com/*' => Http::response(['items' => [
            ['id' => 'g1', 'summary' => 'جلسه با وکیل', 'status' => 'confirmed',
             'start' => ['dateTime' => '2026-08-03T11:00:00+03:30'], 'htmlLink' => 'https://cal/g1'],
            ['id' => 'g2', 'summary' => 'سفر', 'status' => 'confirmed',
             'start' => ['date' => '2026-08-05']],
        ]])]);

        $res = $this->actingAs($mine, 'web')
            ->getJson('/admin/calendar/events?y=1405&m=5&layers[]=google')->assertOk()->json();

        $titles = array_column($res['events'], 'title');
        $this->assertContains('جلسه با وکیل', $titles);
        $this->assertContains('سفر', $titles, 'رویدادِ تمام‌روز هم باید بیاید');

        // کاربرِ بی‌اتصال هیچ‌چیز نمی‌بیند
        $none = $this->actingAs($other, 'web')
            ->getJson('/admin/calendar/events?y=1405&m=5&layers[]=google')->assertOk()->json();
        $this->assertSame([], $none['events']);
    }

    public function test_a_cancelled_google_event_is_dropped(): void
    {
        $this->configureApp();
        $staff = $this->staff();
        $this->connect($staff);

        Http::fake(['www.googleapis.com/*' => Http::response(['items' => [
            ['id' => 'g1', 'summary' => 'لغوشده', 'status' => 'cancelled',
             'start' => ['date' => '2026-08-05']],
        ]])]);

        $res = $this->actingAs($staff, 'web')
            ->getJson('/admin/calendar/events?y=1405&m=5&layers[]=google')->assertOk()->json();

        $this->assertSame([], $res['events']);
    }

    /**
     * 🔴 خرابیِ گوگل نباید تقویم را بکشد — بقیهٔ لایه‌ها باید بیایند.
     */
    public function test_a_google_outage_does_not_break_the_rest_of_the_calendar(): void
    {
        $this->configureApp();
        $staff = $this->staff();
        $this->connect($staff);

        \App\Models\CalendarEvent::create([
            'type' => 'task', 'title' => 'کارِ داخلی',
            'event_date' => '2026-08-03', 'status' => 'pending',
        ]);

        Http::fake(['www.googleapis.com/*' => Http::response(['error' => ['message' => 'boom']], 500)]);

        $res = $this->actingAs($staff, 'web')
            ->getJson('/admin/calendar/events?y=1405&m=5')->assertOk()->json();

        $this->assertTrue($res['ok']);
        $this->assertContains('کارِ داخلی', array_column($res['events'], 'title'));
    }

    /**
     * 🔴 پاسخِ **خراب** نباید کش شود.
     *
     * نسخهٔ اول روی شکست آرایهٔ خالی را پنج دقیقه کش می‌کرد. پیامدش دقیقاً در
     * بدترین لحظه ظاهر می‌شد: کاربر خطا را می‌بیند، علتش را رفع می‌کند (مثلاً
     * Calendar API را در کنسولِ گوگل فعال می‌کند)، رفرش می‌کند و **باز هم هیچ
     * رویدادی نمی‌بیند** — چون پاسخِ خرابِ قبلی هنوز در کش است. آن‌وقت دنبالِ
     * مشکلی می‌گردد که دیگر وجود ندارد. این دقیقاً روی نصبِ زنده رخ داد.
     */
    public function test_a_failed_fetch_is_not_cached_so_the_fix_shows_up_immediately(): void
    {
        $this->configureApp();
        $staff = $this->staff();
        $this->connect($staff);

        $calls = 0;

        // اول خراب (API فعال نیست)، بعد سالم — دقیقاً سناریوی واقعی
        Http::fake(['www.googleapis.com/*' => function () use (&$calls) {
            $calls++;

            return $calls === 1
                ? Http::response(['error' => ['message' => 'Calendar API has not been used']], 403)
                : Http::response(['items' => [
                    ['id' => 'g1', 'summary' => 'جلسه', 'status' => 'confirmed',
                     'start' => ['date' => '2026-08-05']],
                ]]);
        }]);

        $first = $this->actingAs($staff, 'web')
            ->getJson('/admin/calendar/events?y=1405&m=5&layers[]=google')->assertOk()->json();
        $this->assertSame([], $first['events']);

        // همان درخواست، بلافاصله — نباید از کشِ خالی جواب بگیرد
        $second = $this->actingAs($staff, 'web')
            ->getJson('/admin/calendar/events?y=1405&m=5&layers[]=google')->assertOk()->json();

        $this->assertSame(['جلسه'], array_column($second['events'], 'title'),
            'بعد از رفعِ علت، همان رفرشِ بعدی باید جواب بدهد');
        $this->assertSame(2, $calls, 'تلاشِ دوم باید واقعاً به گوگل زده باشد');
    }

    /** و برعکس: پاسخِ موفق **باید** کش شود، وگرنه هر رندر یک تماسِ شبکه است */
    public function test_a_successful_fetch_is_cached(): void
    {
        $this->configureApp();
        $staff = $this->staff();
        $this->connect($staff);

        $calls = 0;
        Http::fake(['www.googleapis.com/*' => function () use (&$calls) {
            $calls++;

            return Http::response(['items' => []]);
        }]);

        $this->actingAs($staff, 'web')->getJson('/admin/calendar/events?y=1405&m=5&layers[]=google')->assertOk();
        $this->actingAs($staff, 'web')->getJson('/admin/calendar/events?y=1405&m=5&layers[]=google')->assertOk();

        $this->assertSame(1, $calls, 'دومی باید از کش بیاید');
    }

    /* ═════════════════════ نوشتنِ رویداد ═════════════════════ */

    public function test_an_event_can_be_created_straight_into_google(): void
    {
        $this->configureApp();
        $staff = $this->staff();
        $this->connect($staff);

        Http::fake(['www.googleapis.com/*' => Http::response([
            'id' => 'gnew', 'htmlLink' => 'https://cal/gnew',
        ])]);

        $res = $this->actingAs($staff, 'web')->postJson('/admin/calendar/events', [
            'type' => 'task', 'title' => 'قرار با مشتری',
            'event_date' => '1405-05-12', 'target' => 'google',
        ])->assertCreated()->json();

        $this->assertTrue($res['ok']);
        $this->assertSame('google', $res['event']['type']);

        // ⚠️ هیچ ردیفِ محلی ساخته نمی‌شود، وگرنه رویداد دو بار دیده می‌شد
        $this->assertSame(0, \App\Models\CalendarEvent::count());

        Http::assertSent(function ($request) {
            $body = $request->data();

            // 🔴 `end.date` در گوگل انحصاری است: یک روزِ کامل یعنی فردا
            return str_contains($request->url(), '/events')
                && ($body['start']['date'] ?? null) === '2026-08-03'
                && ($body['end']['date'] ?? null) === '2026-08-04';
        });
    }

    public function test_a_failed_google_insert_saves_nothing_and_says_so(): void
    {
        $this->configureApp();
        $staff = $this->staff();
        $this->connect($staff);

        Http::fake(['www.googleapis.com/*' => Http::response(['error' => ['message' => 'quota']], 403)]);

        $this->actingAs($staff, 'web')->postJson('/admin/calendar/events', [
            'type' => 'task', 'title' => 'قرار', 'event_date' => '1405-05-12', 'target' => 'google',
        ])->assertStatus(422)->assertJsonPath('error', 'google_insert_failed');

        $this->assertSame(0, \App\Models\CalendarEvent::count());
    }

    public function test_targeting_google_without_a_connection_is_refused(): void
    {
        $this->configureApp();
        Http::fake();

        $this->actingAs($this->staff(), 'web')->postJson('/admin/calendar/events', [
            'type' => 'task', 'title' => 'قرار', 'event_date' => '1405-05-12', 'target' => 'google',
        ])->assertStatus(422)->assertJsonPath('error', 'google_not_connected');

        Http::assertNothingSent();
    }

    /* ═════════════════════ حذفِ رویدادِ گوگل ═════════════════════ */

    public function test_a_google_event_can_be_deleted_from_the_panel(): void
    {
        $this->configureApp();
        $staff = $this->staff();
        $this->connect($staff);

        Http::fake(['www.googleapis.com/*' => Http::response(null, 204)]);

        $this->actingAs($staff, 'web')
            ->deleteJson('/admin/calendar/google/events/abc123')
            ->assertOk()->assertJsonPath('deleted', 'google:abc123');

        Http::assertSent(fn ($r) => $r->method() === 'DELETE'
            && str_contains($r->url(), '/events/abc123'));
    }

    /**
     * ⚠️ رویدادی که از قبل نیست، «موفق» است.
     *
     * مقصد همان چیزی است که کاربر خواسته («این نباشد»); خطادادن بابتِ کاری که
     * لازم نبوده فقط سردرگمش می‌کند. همان قاعدهٔ `releaseServer()` که حذفِ
     * سرورِ ازقبل‌نبود را موفق می‌شمارد.
     */
    public function test_deleting_an_already_gone_event_counts_as_success(): void
    {
        $this->configureApp();
        $staff = $this->staff();
        $this->connect($staff);

        Http::fake(['www.googleapis.com/*' => Http::response(['error' => ['message' => 'Not Found']], 404)]);

        $this->actingAs($staff, 'web')
            ->deleteJson('/admin/calendar/google/events/gone')
            ->assertOk()->assertJsonPath('ok', true);
    }

    public function test_a_refused_google_delete_is_reported(): void
    {
        $this->configureApp();
        $staff = $this->staff();
        $this->connect($staff);

        Http::fake(['www.googleapis.com/*' => Http::response(['error' => ['message' => 'forbidden']], 403)]);

        $this->actingAs($staff, 'web')
            ->deleteJson('/admin/calendar/google/events/abc')
            ->assertStatus(422)->assertJsonPath('error', 'google_delete_failed');
    }

    public function test_deleting_without_a_connection_is_refused(): void
    {
        $this->configureApp();
        Http::fake();

        $this->actingAs($this->staff(), 'web')
            ->deleteJson('/admin/calendar/google/events/abc')
            ->assertStatus(422)->assertJsonPath('error', 'google_not_connected');

        Http::assertNothingSent();
    }

    /* ═════════════════════ جای وضعیتِ اتصال ═════════════════════ */

    /**
     * 🔴 نوارِ «وصل است» از صفحهٔ تقویم برداشته شد — خبری که یک بار لازم است
     * نباید هر روز فضا بگیرد. ولی **خرابی** می‌مانَد، وگرنه لایهٔ گوگل بی‌صدا
     * خالی می‌شود و مدیر فکر می‌کند آن هفته قراری نداشته.
     */
    public function test_a_healthy_connection_shows_no_bar_on_the_calendar(): void
    {
        $this->configureApp();
        $staff = $this->staff();
        $this->connect($staff);
        Http::fake(['www.googleapis.com/*' => Http::response(['items' => []])]);

        $html = $this->actingAs($staff, 'web')->get('/admin/calendar')->assertOk()->getContent();

        $this->assertStringNotContainsString('تقویم گوگل وصل است', $html);
        $this->assertStringNotContainsString('cal-gbar', $html);
        // ولی چیپِ لایه باید باشد، چون وصل است
        $this->assertStringContainsString('data-layer="google"', $html);
    }

    /**
     * ⚠️ فِیک باید **خراب** باشد، نه سالم.
     *
     * یک واکشیِ موفق `markSynced()` می‌زند و `last_error` را پاک می‌کند — که
     * رفتارِ درستی است. نسخهٔ اولِ این تست خطا را ست می‌کرد و بعد اجازه می‌داد
     * یک واکشیِ سالم پاکش کند، پس چیزی را می‌سنجید که خودش خنثی کرده بود.
     */
    public function test_a_broken_connection_still_shouts_on_the_calendar(): void
    {
        $this->configureApp();
        $staff = $this->staff();
        $this->connect($staff);

        Http::fake(['www.googleapis.com/*' => Http::response(
            ['error' => ['message' => 'Calendar API has not been used']], 403,
        )]);

        $html = $this->actingAs($staff, 'web')->get('/admin/calendar')->assertOk()->getContent();

        $this->assertStringContainsString('cal-gbar', $html);
        $this->assertStringContainsString('خواندن رویدادها ناموفق', $html);
    }

    /**
     * چیپِ گوگل برای کاربرِ **وصل‌نشده** ساخته نمی‌شود — کنترلی که هیچ‌وقت
     * چیزی نمی‌آورد، کاربر را دنبالِ خرابیِ ناموجود می‌فرستد.
     */
    public function test_an_unconnected_user_gets_no_google_chip(): void
    {
        $this->configureApp();

        $html = $this->actingAs($this->staff(), 'web')->get('/admin/calendar')->assertOk()->getContent();

        $this->assertStringNotContainsString('data-layer="google"', $html);
    }

    public function test_the_settings_page_carries_the_connection_controls(): void
    {
        $this->configureApp();
        $staff = $this->staff();
        $this->connect($staff);

        $html = $this->actingAs($staff, 'web')->get('/admin/settings')->assertOk()->getContent();

        $this->assertStringContainsString('حسابِ شما وصل است', $html);
        $this->assertStringContainsString('me@gmail.com', $html);
        $this->assertStringContainsString('/admin/calendar/google/disconnect', $html);
    }

    /* ═════════════════════ تازه‌سازیِ توکن ═════════════════════ */

    public function test_an_expired_token_is_refreshed_before_use(): void
    {
        $this->configureApp();
        $staff = $this->staff();
        $token = $this->connect($staff, ['expires_at' => Carbon::now()->subMinute()]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at-2', 'expires_in' => 3600]),
            'www.googleapis.com/*'        => Http::response(['items' => []]),
        ]);

        $this->actingAs($staff, 'web')
            ->getJson('/admin/calendar/events?y=1405&m=5&layers[]=google')->assertOk();

        $this->assertSame('at-2', $token->fresh()->access_token);
    }

    /**
     * `invalid_grant` یعنی کاربر دسترسی را پس گرفته یا توکنِ حالتِ Testing
     * منقضی شده — و پیامش باید با «گوگل الان در دسترس نیست» فرق کند، وگرنه
     * کاربر منتظرِ رفعِ خودبه‌خودی می‌مانَد که هرگز نمی‌آید.
     */
    public function test_a_revoked_grant_tells_the_user_to_reconnect(): void
    {
        $this->configureApp();
        $staff = $this->staff();
        $token = $this->connect($staff, ['expires_at' => Carbon::now()->subMinute()]);

        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->actingAs($staff, 'web')
            ->getJson('/admin/calendar/events?y=1405&m=5&layers[]=google')->assertOk();

        $this->assertStringContainsString('دوباره وصل', (string) $token->fresh()->last_error);
    }

    public function test_the_scopes_stay_least_privilege(): void
    {
        // نگهبانِ عمدی: اگر روزی کسی `auth/calendar` کامل بخواهد، این می‌شکند
        $this->assertStringContainsString('calendar.events', GoogleCalendarClient::SCOPES);
        $this->assertStringNotContainsString('auth/calendar ', GoogleCalendarClient::SCOPES.' ');
    }
}
