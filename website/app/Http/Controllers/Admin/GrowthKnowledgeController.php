<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GrowthDocument;
use App\Services\Growth\ConversationImport;
use App\Services\Growth\GrowthAudit;
use App\Services\Growth\KnowledgeBrain;
use App\Support\FinanceDates;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GrowthKnowledgeController extends Controller
{
    public function store(Request $request, KnowledgeBrain $brain)
    {
        $data = $request->validate(['title' => 'required|string|max:200', 'source' => 'required|string|max:500', 'body' => 'required|string|max:100000',
            'kind' => 'required|in:runbook,product,change,ticket,research,conversation', 'locale' => 'required|in:fa,en,tr',
            'classification' => 'required|in:public,internal,restricted', 'scope' => 'required|string|max:160',
            'review_date' => 'required|string|max:10', 'supersedes_id' => 'nullable|integer|exists:growth_documents,id']);
        $date = FinanceDates::iso($data['review_date'], 'review_date');
        abort_unless($date && Carbon::parse($date, 'Asia/Tehran')->isFuture(), 422);
        unset($data['review_date']);
        $data['review_at'] = Carbon::parse($date, 'Asia/Tehran')->utc();
        $brain->candidate($data, (int) $request->user()->id);

        return back()->with('ok', __('growth.saved'));
    }

    public function review(Request $request, GrowthDocument $document, KnowledgeBrain $brain)
    {
        $data = $request->validate(['status' => 'required|in:verified,rejected,outdated', 'review_note' => 'nullable|string|max:1000', 'review_date' => 'nullable|string|max:10', 'confirmed' => 'accepted']);
        $reviewAt = null;
        if (! empty($data['review_date'])) {
            $iso = FinanceDates::iso($data['review_date'], 'review_date');
            $reviewAt = $iso ? Carbon::parse($iso, 'Asia/Tehran')->utc() : null;
        }
        $brain->review($document->id, $data['status'], (int) $request->user()->id, $data['review_note'] ?? null, $reviewAt);

        return back()->with('ok', __('growth.saved'));
    }

    public function delete(Request $request, GrowthDocument $document)
    {
        $request->validate(['confirmed' => 'accepted']);
        DB::transaction(function () use ($document, $request) {
            GrowthAudit::record('knowledge.deleted', 'knowledge:'.$document->id, (int) $request->user()->id, ['sha256' => $document->sha256]);
            $document->delete();
        });

        return back()->with('ok', __('growth.saved'));
    }

    public function export(Request $request)
    {
        // Explicit, selected public documents only. Notebook consumer integration stays manual.
        $data = $request->validate(['ids' => 'required|array|min:1|max:30', 'ids.*' => 'integer']);
        $docs = GrowthDocument::whereIn('id', $data['ids'])->where('classification', 'public')->where('status', 'verified')->where('review_at', '>', now())->get();
        abort_unless($docs->count() === count(array_unique($data['ids'])), 422, 'فقط دانش عمومی تأییدشده قابل خروجی است.');
        GrowthAudit::record('knowledge.exported', 'notebook', (int) $request->user()->id, ['ids' => $docs->pluck('id')->all()]);

        return response()->streamDownload(function () use ($docs) {
            foreach ($docs as $doc) {
                echo '# '.$doc->title."\n\n".$doc->body."\n\nSource: ".$doc->source.' | knowledge:'.$doc->id.':v'.$doc->version."\n\n---\n\n";
            }
        },
            'servernet-notebook-export.md', ['Content-Type' => 'text/markdown; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    public function preview(Request $request, ConversationImport $importer)
    {
        $request->validate(['export' => 'required|file|max:2000']);
        $items = $importer->preview(file_get_contents($request->file('export')->getRealPath()));
        $key = Str::random(48);
        Cache::put('growth-import:'.$request->user()->id.':'.$key, Crypt::encryptString(json_encode($items, JSON_THROW_ON_ERROR)), 600);
        $request->session()->put('growth_import_key', $key);

        return back()->with('growth_import_preview', $items);
    }

    public function import(Request $request, KnowledgeBrain $brain)
    {
        $data = $request->validate(['selected' => 'required|array|min:1|max:30', 'selected.*' => 'integer', 'confirmed' => 'accepted']);
        $key = $request->session()->get('growth_import_key');
        $encrypted = $key ? Cache::get('growth-import:'.$request->user()->id.':'.$key) : null;
        abort_unless($encrypted, 422, 'پیش‌نمایش منقضی شده است.');
        $items = json_decode(Crypt::decryptString($encrypted), true, 64, JSON_THROW_ON_ERROR);
        $selected = array_values(array_unique($data['selected']));
        foreach ($selected as $id) {
            abort_unless(collect($items)->contains('index', $id), 422);
        }
        DB::transaction(function () use ($items, $selected, $brain, $request) {
            foreach ($items as $item) {
                if (in_array($item['index'], $selected, true)) {
                    $brain->candidate([
                        'title' => $item['title'], 'body' => $item['body'], 'source' => $item['source'], 'kind' => 'conversation', 'locale' => 'fa',
                        'classification' => 'internal', 'scope' => 'general', 'review_at' => now()->addDays(30)], (int) $request->user()->id);
                }
            }
        });
        Cache::forget('growth-import:'.$request->user()->id.':'.$key);
        $request->session()->forget('growth_import_key');

        return back()->with('ok',__('growth.saved'));
    }
}
