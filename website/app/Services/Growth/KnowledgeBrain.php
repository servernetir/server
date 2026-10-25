<?php

namespace App\Services\Growth;

use App\Models\GrowthDocument;
use Illuminate\Support\Facades\DB;

class KnowledgeBrain
{
    public function candidate(array $data, ?int $actor): GrowthDocument
    {
        $redactor = app(Redactor::class);
        foreach (['body', 'title', 'source', 'scope'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = $redactor->clean($data[$field]);
            }
        }
        $data['body'] = trim(strip_tags($data['body']));
        abort_if($data['body'] === '', 422, 'منبع خالی است.');
        $hash = hash('sha256', ($data['scope'] ?? 'general').'|'.($data['classification'] ?? 'internal').'|'.$data['body']);

        return DB::transaction(function () use ($data, $hash, $actor) {
            if ($old = GrowthDocument::where('sha256', $hash)->first()) {
                return $old;
            }
            $previous = isset($data['supersedes_id']) ? GrowthDocument::findOrFail($data['supersedes_id']) : null;
            $doc = GrowthDocument::create($data + ['sha256' => $hash, 'created_by' => $actor, 'status' => 'candidate', 'version' => $previous ? (int) $previous->version + 1 : 1]);
            $length = mb_strlen($doc->body);
            for ($start = 0,$position = 0; $start < $length; $start += 1000,$position++) {
                DB::table('growth_chunks')->insert(['document_id' => $doc->id, 'position' => $position, 'body' => mb_substr($doc->body, $start, 1200)]);
            }
            GrowthAudit::record('knowledge.candidate', 'knowledge:'.$doc->id, $actor);

            return $doc;
        });
    }

    public function review(int $id, string $status, int $actor, ?string $note = null, ?\DateTimeInterface $reviewAt = null): void
    {
        abort_unless(in_array($status, ['verified', 'rejected', 'outdated'], true), 422);
        DB::transaction(function () use ($id, $status, $actor, $note, $reviewAt) {
            $doc = GrowthDocument::lockForUpdate()->findOrFail($id);
            if ($reviewAt) {
                $doc->review_at = $reviewAt;
            }
            if ($status === 'verified' && (! $doc->review_at || $doc->review_at->isPast())) {
                abort(422, 'تاریخ بازبینی آینده لازم است.');
            }
            $doc->update(['status' => $status, 'verified_by' => $status === 'verified' ? $actor : null,
                'verified_at' => $status === 'verified' ? now() : null, 'review_note' => app(Redactor::class)->clean($note ?? '')]);
            if ($status === 'verified' && $doc->supersedes_id) {
                GrowthDocument::whereKey($doc->supersedes_id)->update(['status' => 'outdated']);
            }
            GrowthAudit::record('knowledge.'.$status, 'knowledge:'.$id, $actor);
        });
    }

    public function search(string $query, string $scope = 'general', int $limit = 6): array
    {
        $query = str_replace(['ي', 'ك'], ['ی', 'ک'], mb_strtolower($query));
        $terms = array_slice(array_values(array_unique(array_filter(preg_split('/[^\p{L}\p{N}_-]+/u', $query), fn ($s) => mb_strlen($s) > 1))), 0, 12);
        if (! $terms) {
            return [];
        }
        // Local, deterministic lexical retrieval; semantic mode is not claimed without an embedding connector.
        $rows = DB::table('growth_chunks as c')->join('growth_documents as d', 'd.id', '=', 'c.document_id')
            ->where('d.status', 'verified')->whereIn('d.classification', ['public', 'internal'])
            ->where('d.review_at', '>', now())->whereIn('d.scope', array_unique(['general', $scope]))
            ->where(function ($q) use ($terms) {
                foreach ($terms as $term) {
                    $q->orWhere('c.body', 'like', '%'.addcslashes($term, '%_\\').'%')
                        ->orWhere('d.title', 'like', '%'.addcslashes($term, '%_\\').'%');
                }
            })
            ->select('c.id', 'c.body', 'c.position', 'd.id as document_id', 'd.title', 'd.source', 'd.version', 'd.scope', 'd.verified_at')
            ->orderByDesc('d.verified_at')->limit(300)->get();

        return $rows->map(function ($row) use ($terms) {
            $text = str_replace(['ي', 'ك'], ['ی', 'ک'], mb_strtolower($row->title.' '.$row->body));
            $hits = count(array_filter($terms, fn ($term) => str_contains($text, $term)));

            return ['document_id' => $row->document_id, 'title' => $row->title, 'excerpt' => $row->body, 'source' => $row->source,
                'version' => $row->version, 'scope' => $row->scope, 'citation' => 'knowledge:'.$row->document_id.':v'.$row->version.'#chunk-'.$row->position,
                'relevance' => round($hits / count($terms), 3), 'verified_at' => $row->verified_at];
        })->sortByDesc('relevance')->take(max(1, min(12, $limit)))->values()->all();
    }

    public function suggest(string $question, string $scope = 'general'): array
    {
        $sources = $this->search(app(Redactor::class)->clean($question), $scope, 3);
        $highRisk = preg_match('/delete|format|firewall|refund|password|حذف|فایروال|عودت|پرداخت|رمز|شبکه/iu', $question) === 1;
        $draft = $sources ? implode("\n\n", array_map(fn ($s) => mb_substr($s['excerpt'], 0, 600).' ['.$s['citation'].']', $sources))
            : 'راهکار تأییدشده مرتبط پیدا نشد؛ بررسی کارشناس لازم است.';
        GrowthAudit::record('knowledge.suggested', 'hermes', null, ['source_ids' => array_column($sources, 'document_id'), 'high_risk' => $highRisk]);

        return ['draft' => $draft, 'citations' => $sources, 'retrieval_mode' => 'lexical', 'requires_human_review' => true,
            'high_risk' => $highRisk, 'executed_actions' => [], 'confidence' => $sources ? 'source_match_only' : 'insufficient_evidence'];
    }
}
