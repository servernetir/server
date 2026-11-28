<?php

namespace App\Mcp\Tools;

use App\Services\Mcp\AdminAccess;
use App\Services\Mcp\ReleaseManifestReview as ManifestReviewer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class ReleaseManifestReview extends AdminTool
{
    protected string $name = 'release_manifest_review';
    protected string $scope = AdminAccess::RELEASE_REVIEW;
    protected string $description = 'Read-only review of one pinned ServerNet release manifest and its destination preconditions. Supply the safe release ID, exact 40-character lowercase Git commit SHA and 64-character lowercase manifest SHA-256. The result is evidence for an independently authorized operator handoff only: it never authorizes, stages, installs, activates, rolls back or publishes a release, registers or executes SSH commands, or changes another permission.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'release' => $schema->string()->max(80)->required(),
            'commit' => $schema->string()->max(40)->required(),
            'manifest_sha256' => $schema->string()->max(64)->required(),
        ];
    }

    public function handle(Request $request, ManifestReviewer $reviewer): Response
    {
        $data = $request->validate([
            'release' => ['required', 'string', 'max:80', 'regex:/\\A[a-z0-9][a-z0-9-]{0,79}\\z/'],
            'commit' => ['required', 'string', 'size:40', 'regex:/\\A[0-9a-f]{40}\\z/'],
            'manifest_sha256' => ['required', 'string', 'size:64', 'regex:/\\A[0-9a-f]{64}\\z/'],
        ]);

        return $this->perform(fn () => $reviewer->review($data['release'], $data['commit'], $data['manifest_sha256']));
    }
}
