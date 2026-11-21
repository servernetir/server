<?php

namespace App\Mcp\Tools;

use App\Services\Mcp\AdminAccess;
use App\Services\Mcp\TicketReplies;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

final class AdminTicketReply extends AdminTool
{
    protected string $name = 'admin_ticket_reply';
    protected string $scope = AdminAccess::TICKET_REPLY;
    protected string $description = 'Send an explicitly user-authorized support reply or internal note, as the connected owner. Read the ticket first. External replies notify the customer. Supply a fresh UUID v4 request_id and the latest observed message ID; retry only the same request_id and exact payload. Unknown outcomes must be reconciled before any new submission. This does not close tickets or change money/services.';
    public function schema(JsonSchema $schema): array
    {
        return ['request_id' => $schema->string()->max(36)->required(),
            'ticket_id' => $schema->integer()->min(1)->required(), 'body' => $schema->string()->max(12000)->required(),
            'expected_last_message_id' => $schema->integer()->min(0)->required(),
            'internal' => $schema->boolean()->default(false)];
    }
    public function handle(Request $request, TicketReplies $writer): Response
    {
        $data = $request->validate(['request_id' => ['required', 'uuid'], 'ticket_id' => ['required', 'integer', 'min:1'],
            'body' => ['required', 'string', 'max:12000'], 'expected_last_message_id' => ['required', 'integer', 'min:0'],
            'internal' => ['sometimes', 'boolean']]);
        return $this->perform(fn () => $writer->post(request()->user('mcp_admin'), $data['request_id'],
            $data['ticket_id'], $data['body'], $data['expected_last_message_id'], $data['internal'] ?? false));
    }
}
