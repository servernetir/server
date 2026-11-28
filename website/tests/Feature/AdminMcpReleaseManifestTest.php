<?php

namespace Tests\Feature;

use App\Services\Mcp\ReleaseManifestReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminMcpReleaseManifestTest extends TestCase
{
    use RefreshDatabase;

    private const RELEASE = 'fixture-release-20261128';
    private const SOURCE = '1111111111111111111111111111111111111111';
    private const COMMIT = '2222222222222222222222222222222222222222';
    private string $home;

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = sys_get_temp_dir().'/servernet-manifest-fixture-'.Str::uuid();
        mkdir($this->home.'/app/resources/views/pages', 0700, true);
        mkdir($this->home.'/public/assets/css', 0700, true);
        config([
            'mcp_admin.release_app_root' => $this->home.'/app',
            'mcp_admin.release_public_root' => $this->home.'/public',
        ]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        if (isset($this->home)) {
            File::deleteDirectory($this->home);
        }
        parent::tearDown();
    }

    private function url(): string
    {
        return 'https://raw.githubusercontent.com/servernetir/server/'.self::COMMIT.'/scripts/releases/'.self::RELEASE.'/manifest.json';
    }

    private function body(array $files, array $extra = []): string
    {
        return json_encode(array_merge([
            'schema' => 'servernet-web-release/v1',
            'release' => self::RELEASE,
            'git_source_commit' => self::SOURCE,
            'deployment_authorized' => false,
            'files' => $files,
        ], $extra), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    private function view(?string $before = null, string $operation = 'replace'): array
    {
        return [
            'source' => 'website/resources/views/pages/fixture.blade.php',
            'destination' => 'servernet_app/resources/views/pages/fixture.blade.php',
            'operation' => $operation,
            'before' => $before,
            'after' => hash('sha256', '<h1>New fixture</h1>'),
        ];
    }

    private function asset(): array
    {
        return [
            'source' => 'website/public/assets/css/fixture.css',
            'destination' => 'public_html/assets/css/fixture.css',
            'operation' => 'add',
            'before' => null,
            'after' => hash('sha256', '.fixture {}'),
        ];
    }

    private function provide(string $body, int $status = 200, array $headers = []): void
    {
        Http::fake([$this->url() => Http::response($body, $status, $headers)]);
    }

    private function review(string $body): array
    {
        return app(ReleaseManifestReview::class)->review(self::RELEASE, self::COMMIT, hash('sha256', $body));
    }

    private function assertRejected(string $body, ?string $hash = null): void
    {
        $error = null;
        try {
            app(ReleaseManifestReview::class)->review(self::RELEASE, self::COMMIT, $hash ?? hash('sha256', $body));
        } catch (\Throwable $exception) {
            $error = $exception;
        }
        $this->assertNotNull($error, 'Invalid/restricted manifest must be refused');
        $this->assertStringNotContainsString('fixture-private-content', $error->getMessage());
    }

    public function test_pinned_manifest_reviews_exact_preimages_without_writing_or_authorizing(): void
    {
        $file = $this->home.'/app/resources/views/pages/fixture.blade.php';
        $original = '<h1>fixture-private-content</h1>';
        file_put_contents($file, $original);
        $body = $this->body([$this->view(hash('sha256', $original)), $this->asset()]);
        $this->provide($body);

        $result = $this->review($body);
        $this->assertSame('review_only', $result['status']);
        $this->assertFalse($result['deployment_authorized']);
        $this->assertSame(self::RELEASE, $result['release']);
        $this->assertSame(self::COMMIT, $result['manifest_commit']);
        $this->assertSame(self::SOURCE, $result['git_source_commit']);
        $this->assertSame(hash('sha256', $body), $result['manifest_sha256']);
        $this->assertSame(['matching', 'absent'], array_column($result['files'], 'status'));
        $this->assertSame(1, $result['counts']['matching']);
        $this->assertSame(1, $result['counts']['absent']);
        $this->assertSame(0, $result['counts']['conflict']);
        $this->assertTrue($result['preflight_unlocked']);
        $this->assertTrue($result['gates']['manifest_integrity_verified']);
        foreach (['git_source_bytes_verified', 'runtime_php_blade_validated', 'release_lock_held', 'private_backup_verified',
            'owner_deployment_approved', 'operator_coordination_confirmed', 'executor_provisioned', 'activation_verified'] as $gate) {
            $this->assertFalse($result['gates'][$gate]);
        }
        $this->assertStringNotContainsString('fixture-private-content', json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertSame($original, file_get_contents($file));
        $this->assertFileDoesNotExist($this->home.'/public/assets/css/fixture.css');
        $this->assertDatabaseCount('mcp_admin_actions', 0);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->url() === $this->url());
    }

    public function test_hash_drift_and_unexpected_existing_add_are_conflicts_not_overwrites(): void
    {
        $file = $this->home.'/app/resources/views/pages/fixture.blade.php';
        $css = $this->home.'/public/assets/css/fixture.css';
        file_put_contents($file, 'a later colleague change');
        file_put_contents($css, 'already present');
        $body = $this->body([$this->view(hash('sha256', 'old')), $this->asset()]);
        $this->provide($body);
        $result = $this->review($body);
        $this->assertSame(['conflict', 'conflict'], array_column($result['files'], 'status'));
        $this->assertSame(2, $result['counts']['conflict']);
        $this->assertFalse($result['deployment_authorized']);
        $this->assertSame('a later colleague change', file_get_contents($file));
        $this->assertSame('already present', file_get_contents($css));
    }

    public function test_symlink_is_never_followed_even_for_an_approved_relative_path(): void
    {
        $outside = $this->home.'/outside';
        file_put_contents($outside, 'fixture-private-content');
        symlink($outside, $this->home.'/app/resources/views/pages/fixture.blade.php');
        $body = $this->body([$this->view(hash('sha256', 'fixture-private-content'))]);
        $this->provide($body);
        $result = $this->review($body);
        $this->assertSame('unsafe', $result['files'][0]['status']);
        $this->assertSame(1, $result['counts']['unsafe']);
        $this->assertFalse($result['deployment_authorized']);
        $this->assertStringNotContainsString('fixture-private-content', json_encode($result));
    }

    public function test_mismatched_pinned_hash_is_refused(): void
    {
        $body = $this->body([$this->asset()]);
        $this->provide($body);
        $this->assertRejected($body, str_repeat('f', 64));
    }

    public function test_source_mapping_cannot_target_env_or_an_unrelated_public_path(): void
    {
        $row = $this->asset();
        $row['destination'] = 'servernet_app/.env';
        $body = $this->body([$row]);
        $this->provide($body);
        $this->assertRejected($body);
    }

    public function test_traversal_is_refused_even_if_destination_looks_valid(): void
    {
        $row = $this->asset();
        $row['source'] = 'website/public/assets/css/../css/fixture.css';
        $body = $this->body([$row]);
        $this->provide($body);
        $this->assertRejected($body);
    }

    public function test_duplicate_paths_are_refused(): void
    {
        $body = $this->body([$this->asset(), $this->asset()]);
        $this->provide($body);
        $this->assertRejected($body);
    }

    public function test_casefold_duplicate_destinations_are_refused(): void
    {
        $other = $this->asset();
        $other['source'] = 'website/public/assets/css/Fixture.css';
        $other['destination'] = 'public_html/assets/css/Fixture.css';
        $body = $this->body([$this->asset(), $other]);
        $this->provide($body);
        $this->assertRejected($body);
    }

    public function test_missing_replace_is_a_conflict_not_a_new_install(): void
    {
        $body = $this->body([$this->view(hash('sha256', 'old fixture'))]);
        $this->provide($body);
        $result = $this->review($body);
        $this->assertSame('conflict', $result['files'][0]['status']);
        $this->assertFileDoesNotExist($this->home.'/app/resources/views/pages/fixture.blade.php');
    }

    public function test_noop_is_refused(): void
    {
        $row = $this->view(hash('sha256', '<h1>New fixture</h1>'));
        $body = $this->body([$row]);
        $this->provide($body);
        $this->assertRejected($body);
    }

    public function test_destructive_operation_is_refused(): void
    {
        $row = $this->view(hash('sha256', 'old fixture'));
        $row['operation'] = 'delete';
        $body = $this->body([$row]);
        $this->provide($body);
        $this->assertRejected($body);
    }

    public function test_privileged_runtime_file_is_outside_admission_scope(): void
    {
        $row = $this->view(hash('sha256', 'old fixture'));
        $row['source'] = 'website/app/Services/Mcp/CommandApprovals.php';
        $row['destination'] = 'servernet_app/app/Services/Mcp/CommandApprovals.php';
        $body = $this->body([$row]);
        $this->provide($body);
        $this->assertRejected($body);
    }

    public function test_append_cannot_hide_preexisting_hash(): void
    {
        $row = $this->asset();
        $row['before'] = hash('sha256', 'old CSS');
        $body = $this->body([$row]);
        $this->provide($body);
        $this->assertRejected($body);
    }

    public function test_manifest_cannot_authorize_deployment_or_supply_commands(): void
    {
        $body = $this->body([$this->asset()], ['deployment_authorized' => true, 'command' => 'fixture-private-content']);
        $this->provide($body);
        $this->assertRejected($body);
    }

    public function test_duplicate_json_field_is_refused_not_silently_collapsed(): void
    {
        $body = str_replace('"deployment_authorized":false', '"deployment_authorized":true,"deployment_authorized":false', $this->body([$this->asset()]));
        $this->provide($body);
        $this->assertRejected($body);
    }

    public function test_non_successful_response_and_redirect_are_not_used_for_admission(): void
    {
        $body = $this->body([$this->asset()]);
        $this->provide($body, 302, ['Location' => 'https://other.invalid/manifest']);
        $this->assertRejected($body);
        Http::assertSentCount(1);
    }

    public function test_oversized_manifest_is_refused(): void
    {
        $body = str_repeat('a', 32769);
        $this->provide($body);
        $this->assertRejected($body);
    }

    public function test_invalid_identifier_is_refused_before_any_network_request(): void
    {
        Http::fake();
        $this->expectException(\Exception::class);
        try {
            app(ReleaseManifestReview::class)->review('../fixture', self::COMMIT, str_repeat('f', 64));
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_registered_pilot_metadata_preserves_all_reviewed_candidate_rows(): void
    {
        $release = 'international-market-pilot-20261128';
        $raw = file_get_contents(base_path('../scripts/releases/'.$release.'/manifest.json'));
        $candidate = json_decode(file_get_contents(base_path('../scripts/'.$release.'/candidate-install-plan.json')), true, flags: JSON_THROW_ON_ERROR);
        $canonical = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($candidate['files'], $canonical['files']);
        $this->assertFalse($canonical['deployment_authorized']);
        Http::fake(['https://raw.githubusercontent.com/servernetir/server/'.self::COMMIT.'/scripts/releases/'.$release.'/manifest.json' => Http::response($raw)]);
        $result = app(ReleaseManifestReview::class)->review($release, self::COMMIT, hash('sha256', $raw));
        $this->assertSame('review_only', $result['status']);
        $this->assertCount(14, $result['files']);
        $this->assertSame(14, array_sum($result['counts']));
        $this->assertFalse($result['deployment_authorized']);
        $this->assertDatabaseCount('mcp_admin_actions', 0);
    }
}
