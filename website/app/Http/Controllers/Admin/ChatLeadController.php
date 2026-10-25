<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CrmLead;
use App\Models\CrmMessage;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ChatLeadController extends Controller
{
    public const STAGES = [
        'new' => 'جدید', 'reviewed' => 'بررسی شده', 'contacted' => 'تماس گرفته شد',
        'proposal' => 'در حال پیگیری', 'won' => 'تبدیل به مشتری', 'lost' => 'بسته شده',
    ];

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:190'],
            'stage' => ['nullable', 'in:'.implode(',', array_keys(self::STAGES))],
        ]);
        $q = trim((string) ($filters['q'] ?? ''));
        $stage = (string) ($filters['stage'] ?? '');
        $query = CrmLead::where('source', 'site-chat')
            ->when($stage !== '', fn ($query) => $query->where('stage', $stage))
            ->when($q !== '', fn ($query) => $query->where(function ($query) use ($q) {
                $query->where('contact_name', 'like', '%'.$q.'%')->orWhere('phone', 'like', '%'.$q.'%')
                    ->orWhere('email', 'like', '%'.$q.'%')
                    ->orWhereHas('messages', fn ($messages) => $messages->where('direction', 'in')
                        ->where('channel', 'chat')->where(function ($messages) use ($q) {
                            $messages->where('subject', 'like', '%'.$q.'%')->orWhere('body', 'like', '%'.$q.'%');
                        }));
            }));
        return view('admin.marketing.chat', [
            'leads' => (clone $query)->with(['messages' => fn ($messages) => $messages
                ->where('direction', 'in')->where('channel', 'chat')->orderBy('id')])
                ->orderByDesc('id')->paginate(30)->withQueryString(),
            'total' => (clone $query)->count(),
            'counts' => CrmLead::where('source', 'site-chat')->selectRaw('stage, count(*) as c')
                ->groupBy('stage')->pluck('c', 'stage')->all(),
            'stages' => self::STAGES, 'q' => $q, 'stage' => $stage,
        ]);
    }

    public function show(CrmLead $lead): View
    {
        abort_unless($lead->source === 'site-chat', 404);
        return view('admin.marketing.chat-lead', [
            'lead' => $lead, 'stages' => self::STAGES,
            'messages' => $lead->messages()->orderBy('id')->get(),
        ]);
    }

    public function update(Request $request, CrmLead $lead): RedirectResponse
    {
        abort_unless($lead->source === 'site-chat', 404);
        $data = $request->validate([
            'stage' => ['required', 'in:'.implode(',', array_keys(self::STAGES))],
            'notes' => ['nullable', 'string', 'max:5000'],
            'reason' => ['nullable', 'string', 'max:120'],
            // Last history entry is an optimistic version, including same-second edits.
            'version' => ['required', 'integer', 'min:0'],
        ]);
        DB::transaction(function () use ($request, $lead, $data) {
            $locked = CrmLead::whereKey($lead->id)->lockForUpdate()->firstOrFail();
            $version = (int) $locked->messages()->max('id');
            abort_if($version !== (int) $data['version'], 409, 'پرونده تغییر کرده است؛ صفحه را تازه کنید.');
            $before = $locked->stage;
            $notes = $data['notes'] ?? null;
            $reason = $data['stage'] === 'lost' ? ($data['reason'] ?? null) : null;
            if ($before === $data['stage'] && $locked->notes === $notes && $locked->lost_reason === $reason) {
                return;
            }
            $locked->fill([
                'stage' => $data['stage'], 'notes' => $notes, 'lost_reason' => $reason,
                'next_action_at' => null,
                'won_at' => $data['stage'] === 'won' ? ($locked->won_at ?? now()) : null,
                'lost_at' => $data['stage'] === 'lost' ? ($locked->lost_at ?? now()) : null,
            ]);
            if ($before !== 'contacted' && $data['stage'] === 'contacted') {
                $locked->last_contacted_at = now();
            }
            $locked->save();
            CrmMessage::create([
                'lead_id' => $locked->id, 'channel' => 'note', 'direction' => 'internal',
                'status' => 'logged', 'subject' => 'ثبت پیگیری',
                'body' => json_encode([
                    'admin_id' => $request->user()->id, 'admin_name' => $request->user()->name,
                    'from' => self::STAGES[$before] ?? $before, 'to' => self::STAGES[$data['stage']],
                    'notes' => $notes, 'reason' => $reason,
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
        });
        return back()->with('ok', 'وضعیت و یادداشت پیگیری ثبت شد.');
    }
}
