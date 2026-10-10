<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Setting;
use App\Services\Security\Totp;
use App\Services\Sms\SmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    private array $codes = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['google-auth.enabled' => true, 'google-auth.client_id' => 'test-client', 'google-auth.client_secret' => 'test-secret']);
        Http::preventStrayRequests();
        $this->app->instance(SmsSender::class, new class($this) implements SmsSender {
            public function __construct(private GoogleAuthTest $t) {}
            public function enabled(): bool { return true; }
            public function name(): string { return 'fake'; }
            public function send(string $m, string $text): bool { return true; }
            public function sendOtp(string $m, string $code): bool { $this->t->recordCode($m, $code); return true; }
        });
    }

    public function recordCode(string $m, string $code): void { $this->codes[$m] = $code; }

    private function fakeGoogle(array $changes = []): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'test-only-token']),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response(array_merge([
                'sub' => '100200300', 'email' => 'google-test@gmail.com', 'email_verified' => true,
                'given_name' => 'Test', 'family_name' => 'User',
            ], $changes)),
        ]);
    }

    private function runCallback(string $prefix = '')
    {
        $this->get($prefix.'/login/google')->assertRedirect();
        $state = session('google_oauth.state');
        return $this->get($prefix.'/login/google/callback?'.http_build_query(['state' => $state, 'code' => 'test-code']));
    }

    private function customer(array $attrs = []): Customer
    {
        $c = Customer::create(['email' => 'google-test@gmail.com', 'phone' => '09121234567',
            'status' => 'active', 'password' => bcrypt('test-password'), 'locale' => 'fa']);
        $c->forceFill($attrs)->save();
        return $c;
    }

    private function legalDocuments(string $locale): void
    {
        foreach (['terms', 'privacy'] as $kind) {
            DB::table('legal_documents')->insert(['kind'=>$kind, 'version'=>'test-v1',
                'locale'=>$locale, 'body'=>'Test document', 'sha256'=>hash('sha256','Test document'),
                'published_at'=>now(), 'created_at'=>now(), 'updated_at'=>now()]);
        }
    }

    private function expectIranianIdentity(bool $matched = true): void
    {
        $provider = \Mockery::mock(\App\Services\Identity\IdentityProvider::class);
        $provider->shouldReceive('shahkar')->once()->with('0084575948','09121234567')
            ->andReturn(new \App\Services\Identity\ShahkarResult($matched, $matched ? null : 'عدم تطابق'));
        if ($matched) {
            $provider->shouldReceive('identity')->once()->with('0084575948','1370-05-12')
                ->andReturn(new \App\Services\Identity\IdentityResult(true,'علی','محمدی','رضا'));
        } else {
            $provider->shouldNotReceive('identity');
        }
        $this->app->instance(\App\Services\Identity\IdentityProvider::class,$provider);
    }

    private function finishAndRelogin(string $prefix, Customer $customer): void
    {
        $this->assertAuthenticatedAs($customer,'customer');
        $this->assertGuest('web');
        $this->get($prefix.'/account')->assertOk();
        $this->assertNull(session('reg'));
        $this->assertNull(session('google_registration'));
        $this->post($prefix.'/logout')->assertRedirect();
        $this->assertGuest('customer');
        $this->get($prefix.'/account')->assertRedirect($prefix.'/login');
        $this->runCallback($prefix)->assertRedirect($prefix.'/account');
        $this->assertAuthenticatedAs($customer,'customer');
        $this->assertGuest('web');
        $this->get($prefix.'/account')->assertOk();
        $this->assertSame(1,Customer::count());
        $this->assertNotNull($customer->fresh()->last_login_at);
        $this->assertSame(2,DB::table('legal_acceptances')->where('customer_id',$customer->id)->count());
    }

    public function test_new_persian_google_customer_completes_kyc_and_can_log_in_again(): void
    {
        $this->legalDocuments('fa');
        $this->expectIranianIdentity();
        $this->fakeGoogle();
        $this->runCallback()->assertRedirect('/register');
        $this->get('/register')->assertOk()->assertSee('google-test@gmail.com')->assertSee('readonly');
        $this->post('/register',['phone'=>'09121234567','type'=>'individual'])->assertRedirect('/register/verify');
        $this->get('/register/verify')->assertOk();
        $this->post('/register/verify',['code'=>'not-the-code'])->assertSessionHasErrors('code');
        $this->assertGuest('customer');
        $this->post('/register/verify',['code'=>$this->codes['09121234567']])->assertRedirect('/register/identity');
        $this->get('/register/identity')->assertOk();
        $this->post('/register/identity',['national_id'=>'0084575948','birth_date'=>'1370/05/12'])
            ->assertRedirect('/register/finish');
        $this->assertSame('pending',Customer::firstOrFail()->status);
        $this->assertGuest('customer');
        $this->get('/register/finish')->assertOk();
        $this->post('/register/finish',['password'=>'test-password','password_confirmation'=>'test-password','terms'=>'1'])
            ->assertRedirect('/account');
        $c=Customer::firstOrFail();
        $this->assertSame('active',$c->status);
        $this->assertSame('fa',$c->locale);
        $this->assertSame('100200300',$c->google_subject);
        $this->assertNotNull($c->email_verified_at);
        $this->assertNotNull($c->phone_verified_at);
        $this->assertTrue(Hash::check('test-password',$c->password));
        $this->assertSame('علی محمدی',$c->displayName());
        $this->assertTrue($c->isVerified());
        $this->assertFalse(\App\Services\Customer\IranSalesGate::blocks($c,'IR'));
        $profile=$c->defaultProfile();
        $this->assertSame('verified',$profile->status);
        $this->assertNotNull($profile->verified_at);
        $this->assertSame('0084575948',Crypt::decryptString($profile->national_id_enc));
        $this->finishAndRelogin('',$c);
    }

    public function test_failed_shahkar_does_not_activate_a_google_customer(): void
    {
        $this->expectIranianIdentity(false);
        $this->fakeGoogle();
        $this->runCallback();
        $this->post('/register',['phone'=>'09121234567','type'=>'individual']);
        $this->post('/register/verify',['code'=>$this->codes['09121234567']]);
        $this->post('/register/identity',['national_id'=>'0084575948','birth_date'=>'1370/05/12'])
            ->assertSessionHasErrors('national_id');
        $this->post('/register/finish',['password'=>'test-password','password_confirmation'=>'test-password','terms'=>'1'])
            ->assertRedirect('/register/identity');
        $c=Customer::firstOrFail();
        $this->assertSame('pending',$c->status);
        $this->assertNull($c->google_subject);
        $this->assertGuest('customer');
        $this->runCallback()->assertRedirect('/register');
        $this->assertGuest('customer');
        $this->assertSame(1,Customer::count());
    }

    public function test_button_is_translated_on_all_registration_and_login_pages(): void
    {
        foreach (['' => 'ادامه با گوگل', '/en' => 'Continue with Google', '/tr' => 'Google ile devam et'] as $prefix => $label) {
            foreach (['/login', '/register'] as $page) {
                $this->get($prefix.$page)->assertOk()->assertSee($label)->assertSee($prefix.'/login/google');
            }
        }
    }

    public function test_missing_configuration_hides_button_and_closes_endpoint(): void
    {
        config(['google-auth.client_secret' => null]);
        $this->get('/login')->assertOk()->assertDontSee('ادامه با گوگل');
        $this->get('/login/google')->assertNotFound();
    }

    public function test_authorization_uses_pkce_and_only_basic_scopes(): void
    {
        $r = $this->get('/en/login/google');
        parse_str(parse_url($r->headers->get('Location'), PHP_URL_QUERY), $q);
        $this->assertSame('openid email profile', $q['scope']);
        $this->assertSame('S256', $q['code_challenge_method']);
        $this->assertSame(url('/en/login/google/callback'), $q['redirect_uri']);
        $this->assertSame(session('google_oauth.state'), $q['state']);
        $this->assertArrayNotHasKey('access_type', $q);
    }

    public function test_invalid_state_never_contacts_google(): void
    {
        Http::fake();
        $this->get('/login/google');
        $this->get('/login/google/callback?state=wrong&code=x')->assertRedirect('/login')->assertSessionHasErrors();
        Http::assertNothingSent();
        $this->assertGuest('customer');
    }

    public function test_expired_or_cross_language_callback_is_rejected(): void
    {
        Http::fake();
        $this->withSession(['google_oauth' => ['state' => 'x', 'verifier' => 'v', 'created' => time()-601, 'locale' => 'fa']])
            ->get('/login/google/callback?state=x&code=x')->assertRedirect('/login');
        $this->withSession(['google_oauth' => ['state' => 'x', 'verifier' => 'v', 'created' => time(), 'locale' => 'en']])
            ->get('/login/google/callback?state=x&code=x')->assertRedirect('/login');
        Http::assertNothingSent();
    }

    public function test_cancel_is_a_recoverable_translated_error(): void
    {
        $this->get('/en/login/google');
        $state=session('google_oauth.state');
        $this->get('/en/login/google/callback?state='.$state.'&error=access_denied')
            ->assertRedirect('/en/login')->assertSessionHasErrors();
        $this->assertGuest('customer');
    }

    public function test_existing_customer_logs_in_without_duplicate_and_keeps_intended_order(): void
    {
        $c=$this->customer();
        $this->fakeGoogle();
        $this->withSession(['url.intended' => url('/account/invoices')]);
        $this->runCallback()->assertRedirect('/account/invoices');
        $this->assertAuthenticatedAs($c,'customer');
        $this->assertGuest('web');
        $this->assertSame(1,Customer::count());
        $this->assertSame('100200300',$c->fresh()->google_subject);
    }

    public function test_callback_cannot_be_replayed(): void
    {
        $this->fakeGoogle();
        $this->get('/en/login/google');
        $state=session('google_oauth.state');
        $url='/en/login/google/callback?state='.$state.'&code=x';
        $this->get($url)->assertRedirect('/en/register');
        $this->get($url)->assertRedirect('/en/login');
        Http::assertSentCount(2);
    }

    public function test_google_respects_two_factor_before_establishing_session(): void
    {
        $c=$this->customer();
        $secret=$c->startTwoFactorSetup();
        $c->forceFill(['two_factor_confirmed_at'=>now()])->save();
        $this->fakeGoogle();
        $this->runCallback()->assertRedirect('/login/2fa');
        $this->assertGuest('customer');
        $this->assertSame('google',session('login_2fa.channel'));
        $this->post('/login/2fa',['code'=>Totp::code($secret)])->assertRedirect('/account');
        $this->assertAuthenticatedAs($c,'customer');
    }

    public function test_suspended_and_temporarily_locked_customers_cannot_use_google(): void
    {
        $c=$this->customer(['status'=>'suspended']);
        $this->fakeGoogle();
        $this->runCallback()->assertRedirect('/login')->assertSessionHasErrors();
        $this->assertGuest('customer');
        $c->forceFill(['status'=>'active','locked_until'=>now()->addHour()])->save();
        $this->runCallback()->assertRedirect('/login');
        $this->assertGuest('customer');
        $this->assertNull($c->fresh()->google_subject);
    }

    public function test_subject_conflict_does_not_replace_existing_link(): void
    {
        $c=$this->customer(['google_subject'=>'999']);
        $this->fakeGoogle();
        $this->runCallback()->assertRedirect('/login')->assertSessionHasErrors();
        $this->assertGuest('customer');
        $this->assertSame('999',$c->fresh()->google_subject);
    }

    public function test_unverified_email_is_rejected(): void
    {
        $this->fakeGoogle(['email_verified'=>false]);
        $this->runCallback()->assertRedirect('/login');
        $this->assertGuest('customer');
        $this->assertNull(session('google_registration'));
    }

    public function test_non_authoritative_third_party_email_is_not_auto_linked(): void
    {
        $this->fakeGoogle(['email'=>'test@example.com']);
        $this->runCallback()->assertRedirect('/login');
        $this->assertGuest('customer');
    }

    public function test_english_signup_uses_verified_google_email_and_keeps_phone_unverified_when_stage_is_off(): void
    {
        $this->legalDocuments('en');
        Setting::put('foreign_phone_stage_off','1');
        $this->fakeGoogle();
        $this->runCallback('/en')->assertRedirect('/en/register');
        $this->assertSame(0,Customer::count());
        $this->get('/en/register')->assertOk()->assertSee('Continue registration')->assertSee('readonly');
        $this->post('/en/register',['email'=>'tampered@gmail.com','phone'=>'+447700900123','type'=>'individual','first_name'=>'Test','last_name'=>'User'])
            ->assertRedirect('/en/register/finish');
        $this->assertSame('google-test@gmail.com',session('reg.email'));
        $this->post('/en/register/finish',['password'=>'test-password','password_confirmation'=>'test-password','terms'=>'1'])
            ->assertRedirect('/en/account');
        $c=Customer::firstOrFail();
        $this->assertSame('100200300',$c->google_subject);
        $this->assertNotNull($c->email_verified_at);
        $this->assertNull($c->phone_verified_at);
        $this->assertFalse($c->isVerified());
        $this->assertTrue(\App\Services\Customer\IranSalesGate::blocks($c,'IR'));
        $this->assertAuthenticatedAs($c,'customer');
        $this->assertSame('en',$c->locale);
        $this->assertSame('draft',$c->defaultProfile()->status);
        $this->assertSame('Test',$c->defaultProfile()->first_name);
        $this->finishAndRelogin('/en',$c);
    }

    public function test_persian_signup_still_requires_sms_and_identity(): void
    {
        $this->fakeGoogle();
        $this->runCallback()->assertRedirect('/register');
        $this->post('/register',['phone'=>'09121234567','type'=>'individual'])
            ->assertRedirect('/register/verify');
        $this->assertFalse(session('reg.verified'));
        $this->post('/register/finish',['password'=>'test-password','password_confirmation'=>'test-password','terms'=>'1'])
            ->assertRedirect('/register');
        $this->post('/register/verify',['code'=>$this->codes['09121234567']])->assertRedirect('/register/identity');
        $this->post('/register/finish',['password'=>'test-password','password_confirmation'=>'test-password','terms'=>'1'])
            ->assertRedirect('/register/identity');
        $this->assertGuest('customer');
        $this->assertSame(0,Customer::count());
    }

    public function test_new_google_signup_does_not_skip_terms(): void
    {
        Setting::put('foreign_phone_stage_off','1');
        $this->fakeGoogle();
        $this->runCallback('/en');
        $this->post('/en/register',['phone'=>'+447700900123','type'=>'individual','first_name'=>'Test','last_name'=>'User']);
        $this->post('/en/register/finish',['password'=>'test-password','password_confirmation'=>'test-password'])
            ->assertSessionHasErrors(['terms'=>'You must accept the Terms of Service and Privacy Policy.']);
        $this->assertSame(0,Customer::count());
    }

    public function test_foreign_sms_stage_is_still_required_when_enabled(): void
    {
        $this->legalDocuments('en');
        $sns = \Mockery::mock(\App\Services\Sms\SnsSender::class);
        $sns->shouldReceive('enabled')->andReturn(true);
        $sns->shouldReceive('sandboxMode')->andReturn(false);
        $sns->shouldReceive('sendOtp')->once()->with('+447700900123', \Mockery::type('string'))
            ->andReturnUsing(function ($phone,$code) { $this->recordCode($phone,$code); return true; });
        $this->app->instance(\App\Services\Sms\SnsSender::class, $sns);
        $this->fakeGoogle();
        $this->runCallback('/en');
        $this->post('/en/register',['phone'=>'+447700900123','type'=>'individual','first_name'=>'Test','last_name'=>'User'])
            ->assertRedirect('/en/register/verify');
        $this->assertSame('sms',session('reg.channel'));
        $this->assertFalse(session('reg.verified'));
        $this->assertTrue(session('reg.email_ok'));
        $this->post('/en/register/finish',['password'=>'test-password','password_confirmation'=>'test-password','terms'=>'1'])
            ->assertRedirect('/en/register');
        $this->assertGuest('customer');
        $this->post('/en/register/verify',['code'=>$this->codes['+447700900123']])->assertRedirect('/en/register/finish');
        $this->post('/en/register/finish',['password'=>'test-password','password_confirmation'=>'test-password','terms'=>'1'])
            ->assertRedirect('/en/account');
        $c=Customer::firstOrFail();
        $this->assertNotNull($c->phone_verified_at);
        $this->assertFalse($c->isVerified());
        $this->finishAndRelogin('/en',$c);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('foreignLocales')]
    public function test_foreign_google_signup_never_calls_shahkar_even_on_direct_identity_post(string $locale): void
    {
        $prefix = '/'.$locale;
        $this->legalDocuments($locale);
        Setting::put('foreign_phone_stage_off', '1');
        $provider = \Mockery::mock(\App\Services\Identity\IdentityProvider::class);
        $provider->shouldNotReceive('shahkar');
        $provider->shouldNotReceive('identity');
        $this->app->instance(\App\Services\Identity\IdentityProvider::class, $provider);
        $this->fakeGoogle();
        $this->runCallback($prefix)->assertRedirect($prefix.'/register');
        $this->post($prefix.'/register', ['phone'=>'+905551234567', 'type'=>'individual',
            'first_name'=>'Test', 'last_name'=>'User'])->assertRedirect($prefix.'/register/finish');
        $this->assertFalse(session('reg.iranian'));
        $this->get($prefix.'/register/identity')->assertRedirect($prefix.'/register/finish');
        $this->post($prefix.'/register/identity', [])->assertRedirect($prefix.'/register/finish');
        $this->post($prefix.'/register/identity', ['national_id'=>'0084575948', 'birth_date'=>'1370/05/12'])
            ->assertRedirect($prefix.'/register/finish');
        $this->post($prefix.'/register/finish', ['password'=>'test-password',
            'password_confirmation'=>'test-password', 'terms'=>'1'])->assertRedirect($prefix.'/account');
        $c = Customer::firstOrFail();
        $this->assertSame($locale, $c->locale);
        $this->assertNull($c->phone_verified_at);
        $this->assertNotNull($c->email_verified_at);
        $this->assertSame(0, DB::table('identity_verifications')->count());
        $this->assertFalse($c->isVerified());
        $this->finishAndRelogin($prefix, $c);
    }

    public static function foreignLocales(): array
    {
        return ['English'=>['en'], 'Turkish'=>['tr']];
    }

    private function phoneCallback(string $prefix, array $numbers = [], bool $granted = true, array $user = [], int $status = 200)
    {
        config(['google-auth.phone_enabled' => true]);
        $this->get($prefix.'/register/google-phone')->assertRedirect();
        $state = session('google_oauth.state');
        // Laravel appends fake handlers; replace the earlier basic-sign-in fake for this consent.
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token'=>'test-only-token',
                'scope'=>$granted ? 'openid email profile https://www.googleapis.com/auth/user.phonenumbers.read' : 'openid email profile']),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response(array_merge([
                'sub'=>'100200300', 'email'=>'google-test@gmail.com', 'email_verified'=>true,
                'given_name'=>'Test', 'family_name'=>'User',
            ], $user)),
            'https://people.googleapis.com/v1/people/me*' => Http::response(['phoneNumbers'=>$numbers], $status),
        ]);
        return $this->get($prefix.'/login/google/callback?'.http_build_query(['state'=>$state,'code'=>'phone-test-code']));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('foreignLocales')]
    public function test_registration_google_button_requests_phone_and_saves_it_in_the_same_flow(string $locale): void
    {
        $prefix='/'.$locale;
        config(['google-auth.phone_enabled'=>true]);
        Setting::put('foreign_phone_stage_off','1');
        $this->legalDocuments($locale);
        $this->get($prefix.'/register')->assertOk()->assertSee($prefix.'/login/google?phone=1');
        $this->get($prefix.'/login')->assertOk()->assertDontSee($prefix.'/login/google?phone=1');
        $response=$this->get($prefix.'/login/google?phone=1')->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'),PHP_URL_QUERY),$query);
        $this->assertStringContainsString('user.phonenumbers.read',$query['scope']);
        $this->assertTrue(session('google_oauth.phone_signup'));
        $this->assertNull(session('google_oauth.phone'));
        Http::fake([
            'https://oauth2.googleapis.com/token'=>Http::response(['access_token'=>'test-only-token','scope'=>$query['scope']]),
            'https://openidconnect.googleapis.com/v1/userinfo'=>Http::response([
                'sub'=>'100200300','email'=>'google-test@gmail.com','email_verified'=>true,
                'given_name'=>'Test','family_name'=>'User']),
            'https://people.googleapis.com/v1/people/me*'=>Http::response(['phoneNumbers'=>[['canonicalForm'=>'+905551234567']]]),
        ]);
        $this->get($prefix.'/login/google/callback?'.http_build_query(['state'=>$query['state'],'code'=>'test-code']))
            ->assertRedirect($prefix.'/register')->assertSessionHas('reg_notice',fn($notice)=>str_contains($notice,__('google.phone_received')));
        $this->assertSame('+905551234567',session('google_registration.phone'));
        $this->assertNull(session('google_registration.phone_status'));
        $this->get($prefix.'/register')->assertOk()->assertSee('+905551234567');
        $this->post($prefix.'/register',['phone'=>'+905551234567','type'=>'individual','first_name'=>'Test','last_name'=>'User'])
            ->assertRedirect($prefix.'/register/finish');
        $this->post($prefix.'/register/finish',['password'=>'test-password','password_confirmation'=>'test-password','terms'=>'1'])
            ->assertRedirect($prefix.'/account');
        $customer=Customer::firstOrFail();
        $this->assertSame('+905551234567',$customer->phone);
        $this->assertSame('+905551234567',$customer->defaultProfile()->mobile);
        $this->assertNull($customer->phone_verified_at);
        $this->assertFalse($customer->isVerified());
        Http::assertSentCount(3);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        $this->fakeGoogle();
        $this->finishAndRelogin($prefix,$customer);
        Http::assertNotSent(fn($r)=>str_starts_with($r->url(),'https://people.googleapis.com/'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('phoneFallbacks')]
    public function test_same_flow_phone_failure_keeps_google_email_verified(bool $granted, array $numbers, int $status): void
    {
        config(['google-auth.phone_enabled'=>true]);
        $this->get('/en/login/google?phone=1');
        $state=session('google_oauth.state');
        Http::fake([
            'https://oauth2.googleapis.com/token'=>Http::response(['access_token'=>'test-only-token',
                'scope'=>$granted ? 'openid email profile https://www.googleapis.com/auth/user.phonenumbers.read' : 'openid email profile']),
            'https://openidconnect.googleapis.com/v1/userinfo'=>Http::response([
                'sub'=>'100200300','email'=>'google-test@gmail.com','email_verified'=>true]),
            'https://people.googleapis.com/v1/people/me*'=>Http::response(['phoneNumbers'=>$numbers],$status),
        ]);
        $notice=!$granted ? 'not_granted' : ($status===200 ? 'no_number' : 'unavailable');
        $this->get('/en/login/google/callback?'.http_build_query(['state'=>$state,'code'=>'test-code']))
            ->assertRedirect('/en/register')->assertSessionHas('reg_notice',fn($n)=>str_contains($n,__('google.phone_'.$notice)));
        $this->assertSame('google-test@gmail.com',session('google_registration.email'));
        $this->assertNull(session('google_registration.phone'));
        if (!$granted) Http::assertSentCount(2);
    }

    public function test_phone_query_is_ignored_in_persian_and_when_disabled(): void
    {
        config(['google-auth.phone_enabled'=>true]);
        foreach (['/login/google?phone=1','/en/login/google?phone=1'] as $i=>$path) {
            if ($i===1) config(['google-auth.phone_enabled'=>false]);
            $response=$this->get($path)->assertRedirect();
            parse_str(parse_url($response->headers->get('Location'),PHP_URL_QUERY),$query);
            $this->assertStringNotContainsString('user.phonenumbers.read',$query['scope']);
        }
        Http::assertNothingSent();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('foreignLocales')]
    public function test_google_phone_is_optional_editable_and_saved_without_marking_it_verified(string $locale): void
    {
        $prefix='/'.$locale;
        $this->legalDocuments($locale);
        Setting::put('foreign_phone_stage_off','1');
        config(['google-auth.phone_enabled'=>true]);
        $this->fakeGoogle();
        $this->runCallback($prefix);
        $this->get($prefix.'/register')->assertOk()->assertSee($prefix.'/register/google-phone');
        $response=$this->get($prefix.'/register/google-phone')->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'),PHP_URL_QUERY),$query);
        $this->assertStringContainsString('https://www.googleapis.com/auth/user.phonenumbers.read',$query['scope']);
        $this->phoneCallback($prefix, [
            ['value'=>'555', 'canonicalForm'=>'javascript:bad'],
            ['canonicalForm'=>'+905559876543'],
            ['canonicalForm'=>'+905551234567', 'metadata'=>['primary'=>true]],
        ])->assertRedirect($prefix.'/register');
        $this->assertSame('+905551234567',session('google_registration.phone'));
        $this->get($prefix.'/register')->assertOk()->assertSee('+905551234567');
        $this->get($prefix.'/register')->assertOk()->assertSee('+905551234567');
        $this->post($prefix.'/register',['phone'=>'+905551234567', 'type'=>'individual',
            'first_name'=>'Test','last_name'=>'User'])->assertRedirect($prefix.'/register/finish');
        $this->post($prefix.'/register/finish',['password'=>'test-password','password_confirmation'=>'test-password','terms'=>'1'])
            ->assertRedirect($prefix.'/account');
        $customer=Customer::firstOrFail();
        $this->assertSame('+905551234567',$customer->phone);
        $this->assertSame('+905551234567',$customer->defaultProfile()->mobile);
        $this->assertNull($customer->phone_verified_at);
        $this->assertFalse($customer->isVerified());
        Http::assertSent(fn($r)=>str_starts_with($r->url(),'https://people.googleapis.com/v1/people/me')
            && $r['personFields']==='phoneNumbers' && $r['sources']==='READ_SOURCE_TYPE_PROFILE');
        // Later ordinary sign-in never asks for or fetches the optional phone scope.
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        $this->fakeGoogle();
        $this->finishAndRelogin($prefix,$customer);
        Http::assertNotSent(fn($r)=>str_starts_with($r->url(),'https://people.googleapis.com/'));
    }

    public function test_google_phone_cancellation_preserves_signup_and_cannot_replay(): void
    {
        config(['google-auth.phone_enabled'=>true]);
        $this->fakeGoogle();
        $this->runCallback('/en');
        $before=session('google_registration');
        $this->get('/en/register/google-phone');
        $state=session('google_oauth.state');
        $this->get('/en/login/google/callback?'.http_build_query(['state'=>$state,'error'=>'access_denied']))
            ->assertRedirect('/en/register');
        $this->assertSame($before,session('google_registration'));
        $this->assertNull(session('google_oauth'));
        $this->get('/en/login/google/callback?'.http_build_query(['state'=>$state,'code'=>'unused']))
            ->assertRedirect('/en/login');
        $this->assertGuest('customer');
        Http::assertSentCount(2);
    }

    public function test_imported_google_phone_can_be_corrected_before_saving(): void
    {
        Setting::put('foreign_phone_stage_off','1');
        $this->fakeGoogle();
        $this->runCallback('/en');
        $this->phoneCallback('/en',[['canonicalForm'=>'+905551234567']])->assertRedirect('/en/register');
        $this->post('/en/register',['phone'=>'+447700900123','type'=>'individual','first_name'=>'Test','last_name'=>'User'])
            ->assertRedirect('/en/register/finish');
        $this->post('/en/register/finish',['password'=>'test-password','password_confirmation'=>'test-password','terms'=>'1'])
            ->assertRedirect('/en/account');
        $customer=Customer::firstOrFail();
        $this->assertSame('+447700900123',$customer->phone);
        $this->assertSame('+447700900123',$customer->defaultProfile()->mobile);
        $this->assertNull($customer->phone_verified_at);
    }

    public function test_google_phone_accepts_international_value_without_canonical_form(): void
    {
        $this->fakeGoogle();
        $this->runCallback('/en');
        $this->phoneCallback('/en', [['value'=>'+90 (555) 123-45-67']])->assertRedirect('/en/register');
        $this->assertSame('+905551234567',session('google_registration.phone'));
        $this->get('/en/register')->assertOk()->assertSee('+905551234567');
    }

    public function test_google_phone_does_not_guess_country_for_a_local_value(): void
    {
        $this->fakeGoogle();
        $this->runCallback('/en');
        $this->phoneCallback('/en', [['value'=>'0555 123 45 67']])->assertRedirect('/en/register');
        $this->assertNull(session('google_registration.phone'));
    }

    public function test_phone_diagnostic_storage_failure_does_not_block_google_identity(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token'=>Http::response(['access_token'=>'test-only-token',
                'scope'=>'openid email profile https://www.googleapis.com/auth/user.phonenumbers.read']),
            'https://openidconnect.googleapis.com/v1/userinfo'=>Http::response([
                'sub'=>'100200300','email'=>'google-test@gmail.com','email_verified'=>true]),
            'https://people.googleapis.com/v1/people/me*'=>Http::response(['phoneNumbers'=>[['canonicalForm'=>'+905551234567']]]),
        ]);
        \Illuminate\Support\Facades\Log::shouldReceive('build')->once()->andThrow(new \RuntimeException('Test storage unavailable'));
        $identity=app(\App\Services\Auth\GoogleIdentity::class)->exchange('test-code','test-verifier','https://example.test/callback',true);
        $this->assertSame('+905551234567',$identity['phone']);
        $this->assertSame('received',$identity['phone_status']);
    }

    public function test_google_phone_does_not_use_a_different_google_account(): void
    {
        $this->fakeGoogle();
        $this->runCallback('/en');
        $before=session('google_registration');
        $this->phoneCallback('/en',[['canonicalForm'=>'+905551234567']],true,
            ['sub'=>'999888777','email'=>'other@gmail.com'])->assertRedirect('/en/register');
        $this->assertSame($before,session('google_registration'));
        $this->assertSame(0,Customer::count());
        $this->assertGuest('customer');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('phoneFallbacks')]
    public function test_google_phone_fallback_allows_manual_registration(bool $granted, array $numbers, int $status): void
    {
        Setting::put('foreign_phone_stage_off','1');
        $this->fakeGoogle();
        $this->runCallback('/en');
        $this->phoneCallback('/en',$numbers,$granted,[],$status)->assertRedirect('/en/register');
        $this->assertNull(session('google_registration.phone'));
        $this->assertSame('google-test@gmail.com',session('google_registration.email'));
        $this->post('/en/register',['phone'=>'+447700900123','type'=>'individual','first_name'=>'Test','last_name'=>'User'])
            ->assertRedirect('/en/register/finish');
        $this->post('/en/register/finish',['password'=>'test-password','password_confirmation'=>'test-password','terms'=>'1'])
            ->assertRedirect('/en/account');
        $this->assertSame('+447700900123',Customer::firstOrFail()->phone);
        if (! $granted) {
            Http::assertNotSent(fn($r)=>str_starts_with($r->url(),'https://people.googleapis.com/'));
        }
    }

    public static function phoneFallbacks(): array
    {
        return ['no consent'=>[false,[],200], 'no numbers'=>[true,[],200],
            'invalid number'=>[true,[['canonicalForm'=>'not-a-number']],200], 'API unavailable'=>[true,[],503]];
    }

    public function test_google_phone_feature_is_disabled_by_default_and_unavailable_in_persian(): void
    {
        $this->get('/en/register/google-phone')->assertNotFound();
        $this->get('/tr/register/google-phone')->assertNotFound();
        config(['google-auth.phone_enabled'=>true]);
        $this->get('/register/google-phone')->assertNotFound();
        $this->get('/en/register/google-phone')->assertRedirect('/en/register');
        Http::assertNothingSent();
    }

    public function test_new_english_google_customer_keeps_two_factor_on_later_login(): void
    {
        Setting::put('foreign_phone_stage_off','1');
        $this->fakeGoogle();
        $this->runCallback('/en');
        $this->post('/en/register',['phone'=>'+447700900123','type'=>'individual','first_name'=>'Test','last_name'=>'User']);
        $this->post('/en/register/finish',['password'=>'test-password','password_confirmation'=>'test-password','terms'=>'1']);
        $c=Customer::firstOrFail();
        $secret=$c->startTwoFactorSetup();
        $c->forceFill(['two_factor_confirmed_at'=>now()])->save();
        $this->post('/en/logout');
        $this->runCallback('/en')->assertRedirect('/en/login/2fa');
        $this->assertGuest('customer');
        $this->get('/en/account')->assertRedirect('/en/login');
        $this->post('/en/login/2fa',['code'=>'not-a-code'])->assertSessionHasErrors('code');
        $this->assertGuest('customer');
        $this->post('/en/login/2fa',['code'=>Totp::code($secret)])->assertRedirect('/en/account');
        $this->assertAuthenticatedAs($c,'customer');
        $this->get('/en/account')->assertOk();
        $this->assertSame(1,Customer::count());
    }

    public function test_iranian_company_requires_document_verification_after_google_signup(): void
    {
        $this->expectIranianIdentity();
        $this->fakeGoogle();
        $this->runCallback();
        $this->post('/register',['phone'=>'09121234567','type'=>'company']);
        $this->post('/register/verify',['code'=>$this->codes['09121234567']]);
        $this->post('/register/identity',['national_id'=>'0084575948','birth_date'=>'1370/05/12']);
        $this->post('/register/finish',['password'=>'test-password','password_confirmation'=>'test-password','terms'=>'1'])
            ->assertRedirect('/account');
        $c=Customer::firstOrFail();
        $this->assertSame('verified',$c->identityVerification->status);
        $this->assertSame('company',$c->defaultProfile()->type);
        $this->assertSame('draft',$c->defaultProfile()->status);
        $this->assertNull($c->defaultProfile()->verified_at);
        $this->assertFalse($c->isVerified());
        $this->assertTrue(\App\Services\Customer\IranSalesGate::blocks($c,'IR'));
    }

    public function test_customer_activated_during_signup_cannot_be_overwritten(): void
    {
        Setting::put('foreign_phone_stage_off','1');
        $this->fakeGoogle();
        $this->runCallback('/en');
        $this->post('/en/register',['phone'=>'+447700900123','type'=>'individual','first_name'=>'Test','last_name'=>'User']);
        $c=$this->customer(['locale'=>'en']);
        $before=$c->password;
        $this->post('/en/register/finish',['password'=>'changed-password','password_confirmation'=>'changed-password','terms'=>'1'])
            ->assertSessionHasErrors('email');
        $this->assertGuest('customer');
        $this->assertSame($before,$c->fresh()->password);
        $this->assertNull($c->fresh()->google_subject);
    }

    public function test_token_exchange_failure_does_not_authenticate_or_leak_tokens(): void
    {
        $this->customer();
        Http::fake(['https://oauth2.googleapis.com/token'=>Http::response(['error'=>'invalid_grant'],400)]);
        $this->runCallback('/en')->assertRedirect('/en/login')->assertSessionHasErrors();
        $this->assertGuest('customer');
        $this->assertNull(session('google_registration'));
        Http::assertSentCount(1);
    }

    public function test_expired_google_signup_cannot_bind_an_identity(): void
    {
        Setting::put('foreign_phone_stage_off','1');
        $this->fakeGoogle();
        $this->runCallback('/en');
        $this->post('/en/register',['phone'=>'+447700900123','type'=>'individual','first_name'=>'Test','last_name'=>'User']);
        $g=session('google_registration');
        $g['expires']=time()-1;
        $this->withSession(['google_registration'=>$g])
            ->post('/en/register/finish',['password'=>'test-password','password_confirmation'=>'test-password','terms'=>'1'])
            ->assertRedirect('/en/register');
        $this->assertGuest('customer');
        $this->assertSame(0,Customer::count());
    }
}
