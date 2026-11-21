<?php

namespace App\Mcp\Tools;

use App\Services\Mcp\AdminAccess;
use App\Services\Mcp\ManagementRead;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class AdminOverview extends AdminTool
{
    protected string $name = 'admin_overview';
    protected string $scope = AdminAccess::MANAGEMENT_READ;
    protected string $description = 'Read ticket and service status counts from the ServerNet administrator console. Does not log in as a customer or change anything.';
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
    public function handle(Request $request, ManagementRead $reader): Response
    {
        $data = $request->validate([]);
        return $this->perform(fn () => $reader->overview());
    }
}
