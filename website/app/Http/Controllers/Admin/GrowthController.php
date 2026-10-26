<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GrowthAsset;
use App\Models\GrowthCampaign;
use App\Models\GrowthContent;
use App\Models\GrowthDocument;
use App\Models\GrowthJob;
use App\Models\Setting;
use App\Services\Ai\AiModelRegistry;
use App\Services\Growth\ContentWorkflow;
use App\Services\Growth\GrowthAudit;
use App\Services\Growth\GrowthAutopilot;
use App\Services\Growth\GrowthCalendar;
use App\Services\Growth\GrowthDrive;
use App\Services\Growth\GrowthGateway;
use App\Services\Growth\GrowthJobs;
use App\Services\Growth\KnowledgeBrain;
use App\Services\Growth\MediaArchive;
use App\Support\FinanceDates;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GrowthController extends Controller
{
    public function index(Request $request)
    {
        $tab = $request->query('tab', 'overview');
        abort_unless(in_array($tab, ['overview', 'sociality', 'calendar', 'creative', 'seo', 'knowledge', 'hermes', 'research', 'analytics', 'integrations', 'automation', 'costs', 'settings'], true), 404);
        $items = GrowthContent::query()->when($request->filled('q'), fn ($q) => $q->where('title', 'like', '%'.mb_substr($request->string('q'), 0, 120).'%'))
            ->when(in_array($request->query('status'), ContentWorkflow::STATUSES, true), fn ($q) => $q->where('status', $request->query('status')))
            ->when($tab === 'calendar', fn ($q) => $q->orderBy('scheduled_at'))->orderByDesc('id')->paginate(20)->withQueryString();
        $brain = app(KnowledgeBrain::class);
        $calendarDate = $request->filled('calendar_date') ? FinanceDates::iso((string) $request->input('calendar_date'), 'calendar_date') : null;
        $calendarTimezone = (string) $request->input('calendar_timezone', 'Asia/Tehran');
        abort_unless(in_array($calendarTimezone, ['Asia/Tehran', 'UTC', 'Europe/Berlin', 'Europe/Istanbul'], true), 422);

        return view('admin.growth.index', [
            'autopilot' => app(GrowthAutopilot::class)->status(),
            'tab' => $tab, 'items' => $items, 'calendar' => $tab === 'calendar' ? app(GrowthCalendar::class)->month($calendarDate, $calendarTimezone) : null, 'campaigns' => GrowthCampaign::orderByDesc('id')->get(),
            'control' => DB::table('growth_controls')->find(1), 'stats' => GrowthContent::select('status', DB::raw('count(*) as count'))->groupBy('status')->pluck('count', 'status'),
            'jobs' => GrowthJob::latest()->limit(30)->get(), 'documents' => GrowthDocument::latest()->limit(40)->get(),
            'matches' => $request->filled('q') ? $brain->search(mb_substr($request->string('q'), 0, 500), (string) $request->query('scope', 'general')) : [],
            'daySpend' => app(GrowthJobs::class)->spend(now()->startOfDay()), 'monthSpend' => app(GrowthJobs::class)->spend(now()->startOfMonth()),
            'drive' => app(GrowthDrive::class), 'hasGateway' => filled(Setting::getSecret('growth_gateway_key')),
            'hasHermes' => filled(Setting::getSecret('growth_hermes_key')), 'importPreview' => session('growth_import_preview', []),
            'audits' => DB::table('growth_audits')->orderByDesc('id')->limit(20)->get(),
        ]);
    }

    public function store(Request $request, ContentWorkflow $workflow)
    {
        $data = $request->validate(['title' => 'required|string|max:200', 'brief' => 'required|string|max:16000', 'locale' => 'required|in:fa,en,tr',
            'channel' => 'required|in:'.implode(',', ContentWorkflow::CHANNELS), 'format' => 'required|in:'.implode(',', ContentWorkflow::FORMATS),
            'objective' => 'required|in:awareness,signup,consultation,purchase,education', 'target_url' => 'nullable|url:https|max:500',
            'campaign_name' => 'nullable|string|max:180']);
        if (! empty($data['target_url'])) {
            abort_unless(parse_url($data['target_url'], PHP_URL_HOST) === 'servernet.cloud' && ! parse_url($data['target_url'], PHP_URL_USER) && ! parse_url($data['target_url'], PHP_URL_QUERY) && ! parse_url($data['target_url'], PHP_URL_FRAGMENT), 422, 'فقط لینک مقصد واقعی ServerNet مجاز است.');
        }
        $campaign = trim($data['campaign_name'] ?? '');
        unset($data['campaign_name']);
        if ($campaign !== '') {
            $data['campaign_id'] = GrowthCampaign::firstOrCreate(['utm_campaign' => Str::slug($campaign) ?: hash('sha256', $campaign)], ['name' => $campaign, 'objective' => $data['objective']])->id;
        }
        $item = $workflow->create($data, (int) $request->user()->id);

        return redirect('/admin/ai-growth/content/'.$item->id)->with('ok', __('growth.saved'));
    }

    public function show(GrowthContent $content)
    {
        return view('admin.growth.content', ['item' => $content, 'models' => app(AiModelRegistry::class)->routeableModels(null)
            ->filter(fn ($m) => in_array($m->category, ['chat', 'image'], true) && $m->provider?->isLiveCapable()),
            'jobs' => GrowthJob::where('content_id', $content->id)->latest()->get(), 'assets' => GrowthAsset::where('content_id', $content->id)->get(),
            'revisions' => DB::table('growth_revisions')->where('content_id', $content->id)->orderByDesc('version')->get()]);
    }

    public function edit(Request $request, GrowthContent $content, ContentWorkflow $workflow)
    {
        $data = $request->validate(['version' => 'required|integer|min:1', 'body' => 'nullable|string|max:80000', 'brief' => 'required|string|max:16000']);
        $version = (int) $data['version'];
        unset($data['version']);
        $workflow->edit($content->id, $data, $version, (int) $request->user()->id);

        return back()->with('ok', __('growth.saved'));
    }

    public function transition(Request $request, GrowthContent $content, ContentWorkflow $workflow)
    {
        $data = $request->validate(['status' => 'required|in:draft,review,approved,scheduled', 'version' => 'required|integer',
            'date' => 'nullable|string|max:10', 'time' => 'nullable|date_format:H:i', 'timezone' => 'nullable|timezone']);
        $extra = [];
        if ($data['status'] === 'approved') {
            $request->validate(['confirmed' => 'accepted']);
        }
        if ($data['status'] === 'scheduled') {
            $date = FinanceDates::iso($data['date'] ?? '', 'date');
            abort_unless($date && ! empty($data['time']), 422, 'تاریخ و ساعت لازم است.');
            $tz = $data['timezone'] ?? 'Asia/Tehran';
            $at = Carbon::createFromFormat('!Y-m-d H:i', $date.' '.$data['time'], $tz)->utc();
            abort_unless($at->isFuture(), 422, 'زمان آینده را انتخاب کنید.');
            $extra = ['scheduled_at' => $at, 'timezone' => $tz];
        }
        $workflow->transition($content->id, $data['status'], (int) $data['version'], (int) $request->user()->id, $extra);

        return back()->with('ok', __('growth.saved'));
    }

    public function quote(Request $request, GrowthContent $content, GrowthGateway $gateway)
    {
        $data = $request->validate(['kind' => 'required|in:text,image', 'model' => 'required|string|max:200', 'aspect' => 'nullable|in:1:1,9:16,16:9,4:5']);
        abort_unless($content->status === 'draft', 409);
        $payload = $gateway->payload($content, $data['kind'], $data['model'], $data);
        try {
            $estimate = $gateway->estimate($payload, $data['kind']);
        } catch (\Throwable) {
            return back()->with('err', __('growth.quote_failed'));
        }
        $quote = Crypt::encryptString(json_encode(['actor' => $request->user()->id, 'content' => $content->id, 'version' => $content->version,
            'kind' => $data['kind'], 'payload' => $payload, 'max' => $estimate['max_cost_irt'], 'gateway_token_id' => $estimate['gateway_token_id'], 'expires' => now()->addMinutes(10)->timestamp,
            'key' => Str::random(40)], JSON_THROW_ON_ERROR));

        return back()->with('growth_quote', ['token' => $quote, 'max' => $estimate['max_cost_irt'], 'kind' => $data['kind'], 'model' => $data['model']]);
    }

    public function generate(Request $request, GrowthContent $content, GrowthJobs $jobs)
    {
        $request->validate(['quote' => 'required|string|max:60000', 'confirmed' => 'accepted']);
        try {
            $quote = json_decode(Crypt::decryptString((string) $request->input('quote')), true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(422);
        }
        abort_unless(($quote['actor'] ?? null) === $request->user()->id && ($quote['content'] ?? null) === $content->id && ($quote['expires'] ?? 0) > now()->timestamp, 422, 'برآورد منقضی یا نامعتبر است.');
        $jobs->enqueue($content->id, $quote['kind'], $quote['payload'], (int) $quote['max'], (int) $quote['version'], $quote['key'], (int) $request->user()->id, (int) $quote['gateway_token_id']);

        return back()->with('ok', __('growth.queued'));
    }

    public function upload(Request $request, GrowthContent $content)
    {
        $data = $request->validate(['image' => 'required|file|mimes:png,jpg,jpeg,webp|max:10000', 'rights' => 'required|string|max:240', 'version' => 'required|integer']);
        DB::transaction(function () use ($request, $content, $data) {
            $item = GrowthContent::lockForUpdate()->findOrFail($content->id);
            app(ContentWorkflow::class)->editable($item, (int) $data['version']);
            app(MediaArchive::class)->save($item, file_get_contents($request->file('image')->getRealPath()), $data['rights']);
            // A new visual invalidates prior approval just like a text edit.
            $item->update(['version' => $item->version + 1, 'status' => 'draft', 'approved_at' => null, 'approved_by' => null]);
            app(ContentWorkflow::class)->revision($item, (int) $request->user()->id);
            GrowthAudit::record('asset.uploaded', 'content:'.$item->id, (int) $request->user()->id);
        });

        return back()->with('ok', __('growth.saved'));
    }

    public function asset(GrowthAsset $asset)
    {
        abort_unless(Storage::disk('local')->exists($asset->path), 404);

        return response()->file(Storage::disk('local')->path($asset->path), ['Content-Type' => $asset->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function export(GrowthContent $content)
    {
        GrowthAudit::record('content.exported', 'content:'.$content->id, (int) auth()->id());

        return response()->streamDownload(fn () => print ($this->markdown($content)), 'servernet-content-'.$content->id.'-v'.$content->version.'.md', ['Content-Type' => 'text/markdown; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    private function markdown(GrowthContent $content): string
    {
        return '# '.$content->title."\n\n".$content->body."\n\n".'servernet.cloud'."\n".'Version: '.$content->version.' | '.$content->locale.' | '.$content->channel;
    }

    public function archive(Request $request, GrowthContent $content, GrowthDrive $drive)
    {
        $request->validate(['external_consent' => 'accepted', 'version' => 'required|integer']);
        abort_unless(in_array($content->status, ['approved', 'scheduled', 'published'], true) && (int) $content->version === (int) $request->input('version'), 409, 'فقط نسخه تأییدشده قابل آرشیو خارجی است.');
        try {
            $id = $drive->archive('content-'.$content->id.'-v'.$content->version, 'content-'.$content->id.'-v'.$content->version.'.md', $this->markdown($content), 'text/markdown');
            foreach (GrowthAsset::where('content_id', $content->id)->whereNull('drive_id')->get() as $asset) {
                $driveId = $drive->archive('asset-'.$asset->id.'-'.$asset->sha256, 'asset-'.$asset->id.'.png', Storage::disk('local')->get($asset->path), $asset->mime);
                $asset->update(['drive_id' => $driveId]);
            }
            GrowthAudit::record('drive.archived', 'content:'.$content->id, (int) $request->user()->id, ['version' => $content->version, 'drive_id' => $id]);
        } catch (\Throwable) {
            return back()->with('err', __('growth.archive_failed'));
        }

        return back()->with('ok', __('growth.archived'));
    }

    public function published(Request $request, GrowthContent $content)
    {
        $data = $request->validate(['publication_url' => 'required|url:https|max:500', 'version' => 'required|integer', 'confirmed' => 'accepted']);
        DB::transaction(function () use ($content, $data, $request) {
            $item = GrowthContent::lockForUpdate()->findOrFail($content->id);
            abort_unless(in_array($item->status, ['approved', 'scheduled'], true) && $item->channel !== 'blog' && (int) $item->version === (int) $data['version'], 409);
            $item->update(['status' => 'published', 'publication_url' => $data['publication_url'], 'published_at' => now()]);
            GrowthAudit::record('content.manually_published', 'content:'.$item->id, (int) $request->user()->id);
        });

        return back()->with('ok', __('growth.saved'));
    }

    public function settings(Request $request)
    {
        $data = $request->validate(['enabled' => 'nullable|boolean', 'autopilot_enabled' => 'nullable|boolean', 'autopilot_images' => 'nullable|boolean', 'daily_tasks' => 'nullable|boolean', 'notify' => 'nullable|boolean',
            'daily_irt' => 'required|integer|min:0|max:1000000000', 'monthly_irt' => 'required|integer|min:0|max:10000000000',
            'gateway_key' => 'nullable|string|max:512', 'hermes_key' => 'nullable|string|min:32|max:512']);
        DB::transaction(function () use ($data, $request) {
            DB::table('growth_controls')->where('id', 1)->lockForUpdate()->update(['enabled' => $request->boolean('enabled'),
                'notify' => $request->boolean('notify'), 'daily_tasks' => $request->boolean('daily_tasks'),
                'daily_irt' => $data['daily_irt'], 'monthly_irt' => $data['monthly_irt'], 'updated_at' => now()]);
            foreach (['gateway_key', 'hermes_key'] as $key) {
                if (! empty($data[$key])) {
                    Setting::putSecret('growth_'.$key, $data[$key]);
                }
            }
            Setting::put('growth_autopilot_enabled', $request->boolean('autopilot_enabled') ? '1' : '0');
            Setting::put('growth_autopilot_images', $request->boolean('autopilot_images') ? '1' : '0');
            Setting::put('growth_autopilot_actor_id', (string) $request->user()->id);
            GrowthAudit::record('settings.updated', 'growth', (int) $request->user()->id);
        });

        return back()->with('ok', __('growth.saved'));
    }

    public function googleConnect(Request $request, GrowthDrive $drive)
    {
        abort_unless($drive->configured(), 422, 'ابتدا OAuth Client را در تنظیمات Google موجود تنظیم کنید.');
        $state = Str::random(48);
        $verifier = Str::random(64);
        $request->session()->put('growth_oauth', ['state' => $state, 'verifier' => $verifier, 'expires' => now()->addMinutes(10)->timestamp]);

        return redirect()->away($drive->authUrl($state, $verifier, route('admin.growth.google.callback')));
    }

    public function googleCallback(Request $request, GrowthDrive $drive)
    {
        $stored = $request->session()->pull('growth_oauth');
        abort_unless(is_array($stored) && ($stored['expires'] ?? 0) > now()->timestamp && is_string($request->query('state'))
            && hash_equals($stored['state'], $request->query('state')), 403);
        try {
            $drive->exchange((string) $request->query('code'), $stored['verifier'], route('admin.growth.google.callback'));
        } catch (\Throwable) {
            return redirect('/admin/ai-growth?tab=integrations')->with('err', __('growth.google_failed'));
        }
        GrowthAudit::record('google.connected', 'growth', (int) $request->user()->id);

        return redirect('/admin/ai-growth?tab=integrations')->with('ok', __('growth.saved'));
    }

    public function googleDisconnect(Request $request, GrowthDrive $drive)
    {
        $drive->disconnect();
        GrowthAudit::record('google.disconnected', 'growth', (int) $request->user()->id);

        return back()->with('ok', __('growth.saved'));
    }
}
