<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Growth\GrowthDrive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GrowthDriveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Setting::put('google_client_id', 'fixture-client');
        Setting::putSecret('google_client_secret', 'fixture-secret');
    }

    public function test_oauth_verifies_expected_google_account_and_keeps_refresh_credentials_encrypted(): void
    {
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'fixture-access', 'refresh_token' => 'fixture-refresh', 'expires_in' => 3600, 'scope' => GrowthDrive::SCOPES]),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response(['email' => 'ehsanebrahimitr@gmail.com', 'email_verified' => true])]);
        app(GrowthDrive::class)->exchange('fixture-code', str_repeat('v', 64), 'https://example.test/callback');
        $this->assertTrue(app(GrowthDrive::class)->connected());
        $stored = Setting::where('key', 'growth_drive_credential')->value('value');
        $this->assertStringNotContainsString('fixture-refresh', $stored);
        $this->assertStringContainsString('fixture-refresh', Setting::getSecret('growth_drive_credential'));
    }

    public function test_wrong_account_never_becomes_connected(): void
    {
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'fixture-access', 'refresh_token' => 'fixture-refresh', 'scope' => GrowthDrive::SCOPES]),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response(['email' => 'different@example.test', 'email_verified' => true])]);
        try {
            app(GrowthDrive::class)->exchange('code', 'verifier', 'https://example.test/callback');
            $this->fail('Wrong account accepted');
        } catch (\RuntimeException $e) {
            $this->assertSame('google_account_mismatch', $e->getMessage());
            $this->assertFalse(app(GrowthDrive::class)->connected());
        }
    }

    public function test_archive_uses_refresh_and_multipart_related_and_retry_finds_existing_file(): void
    {
        Setting::putSecret('growth_drive_credential', json_encode(['refresh_token' => 'fixture-refresh', 'access_token' => 'expired', 'expires' => 1]));
        Setting::put('growth_drive_folder', 'owned-folder');
        $uploaded = false;
        Http::fake(function ($request) use (&$uploaded) {
            if (str_contains($request->url(), 'oauth2.googleapis.com')) {
                return Http::response(['access_token' => 'new-access', 'expires_in' => 3600]);
            }
            if (str_contains($request->url(), '/upload/')) {
                $uploaded = true;

                return Http::response(['id' => 'stored-file']);
            }

            return Http::response(['files' => $uploaded ? [['id' => 'stored-file']] : []]);
        });
        $drive = app(GrowthDrive::class);
        $this->assertSame('stored-file', $drive->archive('content-1-v1', 'content.md', 'Approved body', 'text/markdown'));
        $this->assertSame('stored-file', $drive->archive('content-1-v1', 'content.md', 'Approved body', 'text/markdown'));
        Http::assertSent(fn ($r) => str_contains($r->url(), '/upload/') && str_contains($r->header('Content-Type')[0], 'multipart/related') && str_contains($r->body(), 'Approved body'));
        $this->assertCount(1, Http::recorded(fn ($r) => str_contains($r->url(), '/upload/')));
    }

    public function test_refresh_failure_reports_failure_without_uploading_or_clearing_local_credentials(): void
    {
        Setting::putSecret('growth_drive_credential', json_encode(['refresh_token' => 'fixture-refresh', 'expires' => 1]));
        Http::fake(['*' => Http::response(['error' => 'invalid_grant'], 400)]);
        try {
            app(GrowthDrive::class)->archive('content-1-v1', 'content.md', 'Approved', 'text/markdown');
            $this->fail('Failure required');
        } catch (\RuntimeException $e) {
            $this->assertSame('drive_refresh_failed', $e->getMessage());
            $this->assertTrue(app(GrowthDrive::class)->connected());
        }
        Http::assertSentCount(1);
    }

    public function test_disconnect_keeps_generated_files_and_only_clears_this_modules_oauth_credentials(): void
    {
        Setting::putSecret('growth_drive_credential', 'fixture');
        Setting::putSecret('unrelated_secret', 'preserve');
        app(GrowthDrive::class)->disconnect();
        $this->assertFalse(app(GrowthDrive::class)->connected());
        $this->assertSame('preserve',Setting::getSecret('unrelated_secret'));
    }
}
