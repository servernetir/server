<?php

namespace App\Http\Controllers;

use App\Models\GrowthDocument;
use App\Models\Service;
use App\Models\Ticket;
use App\Services\Growth\GrowthAudit;
use App\Services\Growth\HermesSignature;
use App\Services\Growth\KnowledgeBrain;
use App\Services\Growth\Redactor;
use Illuminate\Http\Request;

class HermesKnowledgeController extends Controller
{
    public function handle(Request $request, string $action, KnowledgeBrain $brain, HermesSignature $signature)
    {
        $signature->verify($request);
        $data = $request->validate(['query' => 'nullable|string|max:1000', 'scope' => 'nullable|string|max:160',
            'document_id' => 'nullable|integer', 'ticket_id' => 'nullable|integer', 'title' => 'nullable|string|max:200',
            'body' => 'nullable|string|max:100000', 'source' => 'nullable|string|max:500', 'note' => 'nullable|string|max:1000']);
        $scope = $data['scope'] ?? 'general';
        if (isset($data['ticket_id'])) {
            $ticket = Ticket::findOrFail($data['ticket_id']);
            if ($ticket->service_id) {
                $service = Service::findOrFail($ticket->service_id);
                abort_unless((int) $service->customer_id === (int) $ticket->customer_id, 403);
                $scope = 'service:'.$service->id;
            } else {
                $scope = 'general';
            }
        }
        $result = match ($action) {
            'search' => $brain->search($data['query'] ?? '', $scope),
            'suggest' => $brain->suggest($data['query'] ?? '', $scope),
            'solution' => $this->solution((int) ($data['document_id'] ?? 0), $scope),
            'candidate' => $this->candidate($data, $scope, $brain),
            'feedback' => $this->feedback($data),
            'products' => $this->listing('product'),
            'changes' => $this->listing('change'),
            default => abort(404),
        };

        return response()->json(['data' => $result, 'executed_actions' => []])->header('Cache-Control', 'no-store');
    }

    private function solution(int $id, string $scope): array
    {
        $doc = GrowthDocument::whereKey($id)->where('status', 'verified')->where('review_at', '>', now())
            ->whereIn('classification', ['public', 'internal'])->whereIn('scope', array_unique(['general', $scope]))->firstOrFail();

        return $doc->only(['id', 'title', 'body', 'source', 'version', 'verified_at']) + ['citation' => 'knowledge:'.$doc->id.':v'.$doc->version];
    }

    private function candidate(array $data, string $scope, KnowledgeBrain $brain): array
    {
        abort_unless(filled($data['title'] ?? null) && filled($data['body'] ?? null) && filled($data['source'] ?? null), 422);
        $doc = $brain->candidate(['title' => $data['title'], 'body' => $data['body'], 'source' => $data['source'], 'scope' => $scope,
            'kind' => 'ticket', 'classification' => 'internal', 'review_at' => now()->addDays(30)], null);

        return ['id' => $doc->id, 'status' => $doc->status];
    }

    private function feedback(array $data): array
    {
        $doc = GrowthDocument::findOrFail($data['document_id'] ?? 0);
        $doc->update(['status' => 'outdated', 'review_note' => app(Redactor::class)->clean($data['note'] ?? 'Hermes requested human review.')]);
        GrowthAudit::record('knowledge.flagged', 'knowledge:'.$doc->id);

        return ['id' => $doc->id, 'status' => 'outdated'];
    }

    private function listing(string $kind): array
    {
        return GrowthDocument::where('kind', $kind)->where('status', 'verified')->where('review_at', '>', now())
            ->where('classification', 'public')->where('scope', 'general')->latest()->limit(20)
            ->get(['id', 'title', 'source', 'version', 'verified_at'])->toArray();
    }
}
