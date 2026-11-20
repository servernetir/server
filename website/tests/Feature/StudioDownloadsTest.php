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
            'studio.release_manifest' => $this->directory.'/release.json',
            'studio.preview_directory' => $this->directory.'/previews',
            'studio.preview_manifest' => $this->directory.'/preview.json']);
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

    private function previewManifest(): array
    {
        File::ensureDirectoryExists($this->directory.'/previews');
        $data = ['public_preview' => true, 'unsigned_test' => true, 'version' => '0.2.3',
            'source_sha' => str_repeat('b', 40), 'files' => []];
        foreach (['windows_x64' => 'win-x64.exe', 'macos_arm64' => 'mac-arm64.zip', 'macos_x64' => 'mac-x64.zip'] as $key => $suffix) {
            $filename = 'ServerNet-Studio-UNSIGNED-TEST-0.2.3-'.$suffix;
            $payload = 'synthetic-preview-'.$key;
            file_put_contents($this->directory.'/previews/'.$filename, $payload);
            $data['files'][$key] = [
                'file' => $filename, 'size' => strlen($payload), 'sha256' => hash('sha256', $payload),
                'native_verified' => true,
            ];
        }
        return $data;
    }

    private function writePreview(array $data): void
    {
        file_put_contents($this->directory.'/preview.json', json_encode($data));
    }

    public function test_missing_release_shows_honest_preparation_state_in_every_language(): void
    {
        $languages = [
            '' => ['در حال آماده‌سازی', 'باز کردن استودیو در مرورگر'],
            '/en' => ['In preparation', 'Open Studio in browser'],
            '/tr' => ['Hazırlanıyor', 'Studio’yu tarayıcıda aç'],
        ];
        foreach ($languages as $prefix => [$status, $launch]) {
            $this->get($prefix.'/studio/download')->assertOk()
                ->assertSee($status)->assertSee($launch)
                ->assertSee('href="https://studio.servernet.cloud/"', false)
                ->assertDontSee('.exe')->assertDontSee('.dmg');
        }

        config(['studio.enabled' => false]);
        $this->get('/studio/download')->assertOk()
            ->assertDontSee('href="https://studio.servernet.cloud/"', false);
    }

    public function test_ai_landing_opens_the_web_studio_before_the_signed_installer_is_ready(): void
    {
        foreach (['', '/en', '/tr'] as $prefix) {
            $this->get($prefix.'/ai')->assertOk()
                ->assertSee('href="https://studio.servernet.cloud/"', false)
                ->assertDontSeeText(__('ai_studio.download'));
        }

        config(['studio.enabled' => false]);
        $this->get('/ai')->assertOk()->assertDontSee('href="https://studio.servernet.cloud/"', false);
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

    public function test_windows_can_publish_before_either_mac_package_in_every_language(): void
    {
        $data = $this->manifest();
        unset($data['files']['macos_arm64'], $data['files']['macos_x64']);
        $this->write($data);
        $this->assertSame(['windows_x64'], array_keys(app(StudioDownloads::class)->release()['downloads']));
        foreach (['' => 'در حال آماده‌سازی', '/en' => 'In preparation', '/tr' => 'Hazırlanıyor'] as $prefix => $label) {
            $response = $this->get($prefix.'/studio/download')->assertOk()
                ->assertSee('https://servernet.cloud/downloads/studio/ServerNet-Studio-0.2.0-win-x64.exe')
                ->assertSee($label)->assertDontSee('.dmg');
            $this->assertSame(2, substr_count($response->getContent(), 'class="studio-pending"'));
        }
    }

    public function test_partial_release_still_requires_a_signed_present_windows_installer(): void
    {
        $data = $this->manifest();
        unset($data['files']['macos_arm64'], $data['files']['macos_x64']);
        $unsigned = $data;
        $unsigned['files']['windows_x64']['signed'] = false;
        $this->write($unsigned);
        $this->assertNull(app(StudioDownloads::class)->release());
        $this->write($data);
        unlink($this->directory.'/'.$data['files']['windows_x64']['file']);
        $this->assertNull(app(StudioDownloads::class)->release());
    }

    public function test_empty_unknown_and_null_platforms_cannot_create_a_public_release(): void
    {
        $data = $this->manifest();
        foreach ([[], ['linux_x64' => $data['files']['windows_x64']], ['windows_x64' => null]] as $files) {
            $data['files'] = $files;
            $this->write($data);
            $this->assertNull(app(StudioDownloads::class)->release());
        }
    }

    public function test_unsigned_previews_need_separate_approval_and_are_labelled_in_every_language(): void
    {
        $this->assertNull(app(StudioDownloads::class)->previews());
        $data = $this->previewManifest();
        $this->writePreview($data);
        config(['studio.downloads_enabled' => false]);
        $this->assertNull(app(StudioDownloads::class)->release());
        $preview = app(StudioDownloads::class)->previews();
        $this->assertSame('0.2.3', $preview['version']);
        $this->assertSame(str_repeat('b', 40), $preview['source_sha']);
        $this->assertCount(3, $preview['downloads']);
        foreach ([
            '' => 'آزمایشی بدون امضا',
            '/en' => 'Unsigned test',
            '/tr' => 'İmzasız test',
        ] as $prefix => $label) {
            $response = $this->get($prefix.'/studio/download')->assertOk()
                ->assertSee($label)->assertSee($data['source_sha'])
                ->assertDontSee('downloads/studio/ServerNet-Studio-0.2.3');
            foreach ($data['files'] as $file) {
                $response->assertSee('href="https://servernet.cloud/downloads/studio-preview/'.$file['file'].'"', false)
                    ->assertSee($file['sha256']);
            }
            $this->get($prefix.'/ai')->assertOk()->assertSeeText(__('studio_download.preview_page'));
        }
        config(['studio.enabled' => false]);
        $this->assertNull(app(StudioDownloads::class)->previews());
        $this->get('/studio/download')->assertOk()->assertDontSee('downloads/studio-preview/');
    }

    public function test_preview_rejects_malformed_unverified_missing_and_wrong_platform_files(): void
    {
        $baseline = $this->previewManifest();
        foreach ([
            ['public_preview', false], ['unsigned_test', false], ['source_sha', 'bad'],
            ['version', '../0.2.3'], ['files', []], ['files', ['unknown' => []]],
        ] as [$key, $value]) {
            $data = $baseline;
            $data[$key] = $value;
            $this->writePreview($data);
            $this->assertNull(app(StudioDownloads::class)->previews());
        }
        foreach ([
            ['native_verified', false], ['file', '../ServerNet-Studio-UNSIGNED-TEST-0.2.3-mac-x64.zip'],
            ['file', $baseline['files']['macos_arm64']['file']], ['size', 1], ['sha256', 'invalid'],
        ] as [$key, $value]) {
            $data = $baseline;
            $data['files']['macos_x64'][$key] = $value;
            $this->writePreview($data);
            $this->assertNull(app(StudioDownloads::class)->previews());
        }
        $this->writePreview($baseline);
        $path = $this->directory.'/previews/'.$baseline['files']['macos_x64']['file'];
        unlink($path);
        $this->assertNull(app(StudioDownloads::class)->previews());
        $elsewhere = $this->directory.'/outside.zip';
        file_put_contents($elsewhere, 'synthetic-preview-macos_x64');
        symlink($elsewhere, $path);
        $this->assertNull(app(StudioDownloads::class)->previews());
        $this->get('/studio/download')->assertOk()->assertDontSee('downloads/studio-preview/');
    }

    public function test_signed_versions_are_preferred_and_pending_architectures_stay_unavailable(): void
    {
        $preview = $this->previewManifest();
        $this->writePreview($preview);
        $signed = $this->manifest();
        $this->write($signed);
        $this->get('/studio/download')->assertOk()->assertDontSee('downloads/studio-preview/')
            ->assertSee('downloads/studio/ServerNet-Studio-0.2.0-win-x64.exe');

        unset($signed['files']['macos_x64'], $signed['files']['macos_arm64'], $preview['files']['macos_arm64']);
        $this->write($signed);
        $this->writePreview($preview);
        $response = $this->get('/en/studio/download')->assertOk()
            ->assertSee('downloads/studio/ServerNet-Studio-0.2.0-win-x64.exe')
            ->assertSee('downloads/studio-preview/ServerNet-Studio-UNSIGNED-TEST-0.2.3-mac-x64.zip')
            ->assertDontSee('downloads/studio-preview/ServerNet-Studio-UNSIGNED-TEST-0.2.3-win-x64.exe')
            ->assertDontSee('downloads/studio-preview/ServerNet-Studio-UNSIGNED-TEST-0.2.3-mac-arm64.zip')
            ->assertSee('In preparation');
        $this->assertSame(1, substr_count($response->getContent(), 'class="studio-pending"'));
    }
}
