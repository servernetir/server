<?php

namespace Tests\Feature;

use App\Services\Studio\StudioDownloads;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class StudioDownloadsTest extends TestCase
{
    use RefreshDatabase;
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/sn-studio-test-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->directory);
        config(['studio.enabled' => true, 'studio.client_id' => 'servernet-studio',
            'studio.client_secret' => str_repeat('s', 48), 'studio.redirect_uri' => 'https://studio.servernet.test/auth/callback',
            'studio.downloads_enabled' => true, 'studio.downloads_directory' => $this->directory,
            'studio.release_manifest' => $this->directory.'/release.json']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function manifest(): array
    {
        $data = ['version' => '0.2.0', 'source_sha' => str_repeat('a', 40), 'public_ready' => true, 'files' => []];
        foreach (['windows_x64' => 'win-x64.exe', 'macos_arm64' => 'mac-arm64.dmg', 'macos_x64' => 'mac-x64.dmg'] as $key => $suffix) {
            $name = 'ServerNet-Studio-0.2.0-'.$suffix;
            file_put_contents($this->directory.'/'.$name, 'synthetic-artifact');
            $data['files'][$key] = ['file' => $name, 'sha256' => hash('sha256', 'synthetic-artifact'),
                'size' => strlen('synthetic-artifact'), 'signed' => true, 'notarized' => true];
        }
        return $data;
    }

    private function write(array $data): void
    {
        file_put_contents($this->directory.'/release.json', json_encode($data));
    }

    public function test_missing_release_shows_honest_preparation_state_in_every_language(): void
    {
        foreach (['' => 'در حال آماده‌سازی', '/en' => 'In preparation', '/tr' => 'Hazırlanıyor'] as $prefix => $label) {
            $this->get($prefix.'/studio/download')->assertOk()->assertSee($label)->assertDontSee('.exe')->assertDontSee('.dmg');
        }
    }

    public function test_approved_complete_release_has_only_fixed_servernet_download_urls(): void
    {
        $this->write($this->manifest());
        $release = app(StudioDownloads::class)->release();
        $this->assertSame('0.2.0', $release['version']);
        $this->assertCount(3, $release['downloads']);
        $this->get('/studio/download')->assertOk()->assertSee('https://servernet.cloud/downloads/studio/ServerNet-Studio-0.2.0-win-x64.exe');
        config(['studio.downloads_enabled' => false]);
        $this->assertNull(app(StudioDownloads::class)->release());
        config(['studio.downloads_enabled' => true, 'studio.enabled' => false]);
        $this->assertNull(app(StudioDownloads::class)->release());
    }

    public function test_unsigned_unnotarized_missing_and_malformed_artifacts_are_never_offered(): void
    {
        $baseline = $this->manifest();
        foreach ([['public_ready', false], ['version', ['invalid']], ['source_sha', 'bad']] as [$key, $value]) {
            $data = $baseline; $data[$key] = $value; $this->write($data);
            $this->assertNull(app(StudioDownloads::class)->release());
        }
        foreach ([['signed', false], ['notarized', false], ['file', '../../other.exe'], ['sha256', 'bad'], ['size', 1]] as [$key, $value]) {
            $data = $baseline; $data['files']['macos_x64'][$key] = $value; $this->write($data);
            $this->assertNull(app(StudioDownloads::class)->release());
        }
        unlink($this->directory.'/'.$baseline['files']['macos_x64']['file']);
        $this->write($baseline);
        $this->assertNull(app(StudioDownloads::class)->release());
    }
}
