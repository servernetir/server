<?php

namespace App\Mcp\Tools;

use App\Services\Mcp\AdminAccess;
use App\Services\Mcp\ManagementRead;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class AdminService extends AdminTool
{
    protected string $name = 'admin_service';
    protected string $scope = AdminAccess::MANAGEMENT_READ;
    protected string $description = 'Read one local administrator service record. No password, provisioning metadata or SSO link is returned. Financial fields retain their stored currency and units.';
    public function schema(JsonSchema $schema): array
    {
        return ['service_id' => $schema->integer()->min(1)->required()];
    }
    public function handle(Request $request, ManagementRead $reader): Response
    {
        $data = $request->validate(['service_id' => ['required', 'integer', 'min:1']]);
        return $this->perform(fn () => $reader->service($data['service_id']));
    }
}
