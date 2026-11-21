<?php

namespace App\Mcp\Tools;

use App\Services\Mcp\AdminAccess;
use App\Services\Mcp\ManagementRead;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class AdminServices extends AdminTool
{
    protected string $name = 'admin_services';
    protected string $scope = AdminAccess::MANAGEMENT_READ;
    protected string $description = 'List administrator service records with billing currency, due date and local provisioning state. This is local state, not a live provider check. No passwords, provisioning metadata or customer sign-in links.';
    public function schema(JsonSchema $schema): array
    {
        return ['customer_id' => $schema->integer()->min(1), 'after_id' => $schema->integer()->min(0)->default(0)];
    }
    public function handle(Request $request, ManagementRead $reader): Response
    {
        $data = $request->validate(['customer_id' => ['sometimes', 'integer', 'min:1'], 'after_id' => ['sometimes', 'integer', 'min:0']]);
        return $this->perform(fn () => $reader->services($data['customer_id'] ?? null, $data['after_id'] ?? 0));
    }
}
