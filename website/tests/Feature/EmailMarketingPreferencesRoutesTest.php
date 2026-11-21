<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\EmailMarketingPreference;
use App\Models\User;
use App\Services\Marketing\EmailMarketingPreferences;
use App\Support\EmailMarketingPrivacy;
use App\Support\ErrorTracker;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class EmailMarketingPreferencesRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('m', 32)),
            'email-marketing.enabled' => true,
            'birthday.enabled' => false,
            'marketing.sms_enabled' => false,
            'pagecache.enabled' => false,
        ]);
        Http::preventStrayRequests();
        Mail::fake();
    }

    private function customer(string $email, bool $verified = true): Customer
    {
        $customer = Customer::create([
            'email' => $email, 'password' => 'test-password-not-real', 'locale' => 'fa',
            'timezone' => 'Asia/Tehran', 'status' => 'active',
        ]);
        if ($verified) {
            $customer->forceFill(['email_verified_at' => now()])->save();
        }

        return $customer;
    }

    public function test_csv_stages_only_known_verified_accounts_without_granting_or_resetting_withdrawals(): void
    {
        $known = $this->customer('known@example.test');
        $unverified = $this->customer('unverified@example.test', false);
        $withdrawn = $this->customer('withdrawn@example.test');
        $service = app(EmailMarketingPreferences::class);
        $this->actingAs($withdrawn, 'customer');
        $old = $service->setConsent($withdrawn, false);
        Auth::guard('customer')->logout();
        $admin = User::factory()->create(['role' => 'admin']);

        $csv = UploadedFile::fake()->createWithContent('contacts.csv', "name,email\nKnown,known@example.test\nDuplicate,KNOWN@example.test\nUnverified,unverified@example.test\nExternal,external@example.test\nWithdrawn,withdrawn@example.test\nBad,not-an-email\n");
        $this->actingAs($admin, 'web')->post('/admin/email-marketing/stage', [
            'file' => $csv, 'acknowledge' => '1',
        ])->assertRedirect()->assertSessionHas('stage_result');

        $this->assertSame(EmailMarketingPreference::STATE_PENDING, $service->preferenceFor($known)->state);
        $this->assertFalse($service->eligible($known));
        $this->assertNull($service->preferenceFor($unverified));
        $this->assertSame(EmailMarketingPreference::STATE_WITHDRAWN, $old->fresh()->state);
        $this->assertSame(2, EmailMarketingPreference::count());
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_non_admin_cannot_stage_or_inspect_preferences(): void
    {
        $author = User::factory()->create(['role' => 'author']);
        $this->actingAs($author, 'web')->get('/admin/email-marketing')->assertForbidden();
        $this->actingAs($author, 'web')->post('/admin/email-marketing/stage', [
            'file' => UploadedFile::fake()->createWithContent('list.csv', "email\nknown@example.test\n"),
            'acknowledge' => '1',
        ])->assertForbidden();
        $this->assertSame(0, EmailMarketingPreference::count());
    }

    public function test_account_requires_explicit_consent_and_rejects_unverified_or_impersonated_grants(): void
    {
        $verified = $this->customer('verified@example.test');
        $unverified = $this->customer('unverified@example.test', false);
        $service = app(EmailMarketingPreferences::class);
        $this->actingAs($verified, 'customer')->post('/account/email-preferences', ['action' => 'subscribe'])
            ->assertSessionHasErrors('consent');
        $this->actingAs($unverified, 'customer')->post('/account/email-preferences', ['action' => 'subscribe', 'consent' => '1'])
            ->assertSessionHasErrors('action');
        $this->actingAs($verified, 'customer')->withSession(['impersonator_id' => 123])
            ->post('/account/email-preferences', ['action' => 'subscribe', 'consent' => '1'])
            ->assertSessionHasErrors('action');
        $this->post('/account/email-preferences', ['action' => 'unsubscribe'])->assertSessionHasErrors('action');
        $this->assertFalse($service->eligible($verified));
        $this->assertFalse($service->eligible($unverified));
        Mail::assertNothingSent();
    }

    public function test_unsubscribe_get_is_non_mutating_and_post_withdraws_even_when_enrollment_is_disabled(): void
    {
        $customer = $this->customer('subscriber@example.test');
        $service = app(EmailMarketingPreferences::class);
        $this->actingAs($customer, 'customer')->post('/account/email-preferences', ['action' => 'subscribe', 'consent' => '1'])
            ->assertRedirect();
        $this->assertTrue($service->eligible($customer));
        $token = $service->unsubscribeToken($service->preferenceFor($customer));
        Auth::guard('customer')->logout();
        config(['email-marketing.enabled' => false, 'pagecache.enabled' => true, 'pagecache.mode' => 'denylist']);
        $path = '/email-marketing/unsubscribe/'.$token;
        foreach (['', '/en', '/tr'] as $prefix) {
            $response = $this->get($prefix.$path)->assertOk()->assertDontSee($customer->email);
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
            $response->assertHeader('X-Cache', 'BYPASS');
            $this->get($prefix.$path)->assertOk()->assertHeader('X-Cache', 'BYPASS');
            $this->assertTrue($service->eligible($customer));
        }
        $last = substr($token, -1);
        $invalid = substr($token, 0, -1).($last === 'a' ? 'b' : 'a');
        $this->post('/email-marketing/unsubscribe/'.$invalid, ['List-Unsubscribe' => 'One-Click'])->assertNotFound();
        $this->assertTrue($service->eligible($customer));
        $this->post($path, ['List-Unsubscribe' => 'One-Click'])->assertOk();
        $this->post($path, ['List-Unsubscribe' => 'One-Click'])->assertOk();
        $this->assertFalse($service->eligible($customer));
        $this->assertSame(EmailMarketingPreference::STATE_WITHDRAWN, $service->preferenceFor($customer)->state);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_email_change_clears_previous_verification_and_blocks_new_grant(): void
    {
        $customer = $this->customer('old@example.test');
        $this->actingAs($customer, 'customer')->post('/account/email-preferences', ['action' => 'subscribe', 'consent' => '1'])->assertRedirect();
        $customer->forceFill(['email' => 'new@example.test', 'email_verified_at' => now()])->save();
        $this->assertNull($customer->fresh()->email_verified_at);
        $this->assertFalse(app(EmailMarketingPreferences::class)->eligible($customer));
        $this->post('/account/email-preferences', ['action' => 'subscribe', 'consent' => '1'])->assertSessionHasErrors('action');
        Mail::assertNothingSent();
    }

    public function test_only_public_withdrawal_exempts_forgery_middleware_and_redacts_tokens(): void
    {
        foreach (['', 'en.', 'tr.'] as $prefix) {
            $public = Route::getRoutes()->getByName($prefix.'email-marketing.unsubscribe.submit');
            $account = Route::getRoutes()->getByName($prefix.'account.email-preferences.update');
            $this->assertContains(PreventRequestForgery::class, $public->excludedMiddleware());
            $this->assertNotContains(PreventRequestForgery::class, $account->excludedMiddleware());
        }
        $this->assertNotContains(PreventRequestForgery::class, Route::getRoutes()->getByName('admin.email-marketing.stage')->excludedMiddleware());
        $request = Request::create('https://servernet.cloud/tr/email-marketing/unsubscribe/synthetic-withdrawal-token');
        $this->assertTrue(EmailMarketingPrivacy::sensitive($request));
        $this->assertTrue(EmailMarketingPrivacy::sensitiveUrl($request->fullUrl()));
        $redacted = (new \ReflectionMethod(ErrorTracker::class, 'requestUrl'))->invoke(null, $request);
        $this->assertStringNotContainsString('synthetic-withdrawal-token', $redacted);
        $this->assertStringContainsString('[redacted]', $redacted);
    }
}
