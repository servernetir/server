<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\MarketingCampaign;
use App\Models\MarketingDelivery;
use App\Models\MarketingGrant;
use App\Models\MarketingRedemption;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\Marketing\CampaignManager;
use App\Services\Marketing\CampaignReport;
use App\Services\Marketing\CampaignRunner;
use App\Services\Marketing\DiscountEngine;
use App\Support\FinanceDates;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class CampaignController extends Controller
{
    private function authorizeAction(string $ability = 'manage'): void
    {
        Gate::authorize('marketing.'.$ability);
        abort_unless(Schema::hasTable('marketing_campaigns'), 503, 'Run the campaign migration first.');
    }

    public function index()
    {
        Gate::authorize('marketing.manage');
        $ready = Schema::hasTable('marketing_campaigns');

        return view('admin.marketing.campaigns', [
            'ready' => $ready, 'campaigns' => $ready ? MarketingCampaign::latest()->paginate(20) : collect(),
            'smsDriver' => app(\App\Services\Sms\SmsSender::class)->name(),
            'lastTick' => $ready ? Setting::get('marketing_last_tick') : null,
            'unknown' => $ready ? MarketingDelivery::whereIn('status', ['unknown', 'processing'])->count() : 0,
            'reconciliation' => $ready ? MarketingRedemption::where('status', 'reconciliation')->count() : 0,
        ]);
    }

    public function create()
    {
        $this->authorizeAction();

        return view('admin.marketing.campaign-form', ['campaign' => null,
            'products' => Product::where('is_active', true)->orderBy('name')->get(['id', 'name'])]);
    }

    public function edit(MarketingCampaign $campaign)
    {
        $this->authorizeAction();
        abort_if(MarketingGrant::where('campaign_id', $campaign->id)->exists(), 409, 'Issued campaigns are immutable.');

        return view('admin.marketing.campaign-form', ['campaign' => $campaign,
            'products' => Product::where('is_active', true)->orderBy('name')->get(['id', 'name'])]);
    }

    public function store(Request $request, CampaignManager $manager)
    {
        $this->authorizeAction();
        $c = $manager->save($this->input($request), $request->user('web'));

        return redirect('/admin/marketing/campaigns/'.$c->id);
    }

    public function update(Request $request, MarketingCampaign $campaign, CampaignManager $manager)
    {
        $this->authorizeAction();
        $manager->save($this->input($request), $request->user('web'), $campaign);

        return redirect('/admin/marketing/campaigns/'.$campaign->id);
    }

    private function input(Request $r): array
    {
        if ($r->isJson()) {
            return $r->all();
        }
        $r->validate(['timezone' => 'required|timezone', 'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i']);
        $list = fn ($key) => array_values(array_filter(array_map('trim', explode(',', (string) $r->input($key))), fn ($v) => $v !== ''));
        $moment = function ($key, $time) use ($r) {
            $day = FinanceDates::iso($r->input($key), $key);

            return $day ? Carbon::parse($day.' '.$r->input($time), $r->input('timezone'))->utc()->toIso8601String() : null;
        };
        $type = $r->input('discount_type');
        $value = (string) $r->input('discount_value');
        if ($type === 'percent') {
            if (! preg_match('/^\d{1,3}(?:\.\d{1,2})?$/D', $value)) {
                throw ValidationException::withMessages(['discount_value' => 'درصد را با حداکثر دو رقم اعشار وارد کنید.']);
            }
            [$whole, $decimal] = array_pad(explode('.', $value), 2, '');
            $value = (int) $whole * 100 + (int) str_pad($decimal, 2, '0');
        }

        return [
            'name' => $r->input('name'), 'currency_code' => $r->input('currency_code'), 'trigger' => $r->input('trigger'),
            'timezone' => $r->input('timezone'), 'budget' => $r->input('budget'),
            'max_uses' => $r->input('max_uses'), 'max_per_customer' => $r->input('max_per_customer'),
            'starts_at' => $moment('start_date', 'start_time'), 'ends_at' => $moment('end_date', 'end_time'),
            'rule' => ['type' => $type, 'value' => $value, 'max_discount' => $r->input('max_discount'),
                'min_purchase' => $r->input('min_purchase'), 'per_customer_cap' => $r->input('per_customer_cap'),
                'locales' => $r->input('locales', []), 'operations' => $r->input('operations', []),
                'product_ids' => array_map('intval', $r->input('product_ids', [])), 'families' => $list('families'),
                'providers' => $list('providers'), 'countries' => $list('countries'), 'plans' => $list('plans'),
                'stackable' => $r->boolean('stackable'), 'min_margin_bp' => $r->input('min_margin_bp'),
                'subsidy_approved' => $r->boolean('subsidy_approved'), 'subsidy_reason' => $r->input('subsidy_reason')],
            'audience' => ['customer_ids' => array_map('intval', $list('customer_ids')), 'locales' => $r->input('locales', []),
                'tags' => $list('tags'), 'service_plans' => $list('service_plans'),
                'active_service' => ! $r->filled('active_service') ? null : $r->boolean('active_service'),
                'min_purchases' => $r->input('min_purchases'), 'max_purchases' => $r->input('max_purchases')],
            'automation' => ['valid_hours' => $r->input('valid_hours'), 'at' => $r->input('at'),
                'reminder_hours' => $r->input('reminder_hours'), 'inactive_days' => $r->input('inactive_days')],
            'message' => ['enabled' => $r->boolean('message_enabled'), 'template' => $r->input('template', ''),
                'landing_path' => $r->input('landing_path'), 'service_name' => $r->input('service_name'),
                'limit' => $r->input('message_limit'), 'budget' => $r->input('message_budget')],
        ];
    }

    public function show(MarketingCampaign $campaign, CampaignRunner $runner, CampaignReport $reports)
    {
        $this->authorizeAction();

        return view('admin.marketing.campaign', ['campaign' => $campaign, 'preview' => $runner->preview($campaign),
            'report' => Gate::allows('marketing.finance') ? $reports->for($campaign) : null,
            'grants' => MarketingGrant::where('campaign_id', $campaign->id)->latest()->limit(30)->get(),
            'deliveries' => MarketingDelivery::where('campaign_id', $campaign->id)->latest()->limit(30)->get(),
            'redemptions' => MarketingRedemption::where('campaign_id', $campaign->id)->latest()->limit(30)->get(),
        ]);
    }

    public function transition(Request $request, MarketingCampaign $campaign, CampaignManager $manager)
    {
        $data = $request->validate(['action' => 'required|in:activate,pause,pause_messages,resume_messages', 'reason' => 'required|string|max:190']);
        $this->authorizeAction(in_array($data['action'], ['pause_messages', 'resume_messages']) ? 'send' : 'publish');
        if ($data['action'] === 'activate' && $campaign->trigger === 'birthday' &&
            filter_var(Setting::get('birthday_enabled', config('birthday.enabled', false)), FILTER_VALIDATE_BOOLEAN)) {
            throw ValidationException::withMessages(['campaign' => 'ابتدا برنامه قدیمی تولد را در تنظیمات غیرفعال کنید؛ دو برنامه هم‌زمان اجرا نمی‌شوند.']);
        }
        $manager->transition($campaign, $request->user('web'), $data['action'], $data['reason']);

        return back()->with('ok', 'وضعیت کمپین ثبت شد.');
    }

    public function issue(Request $request, MarketingCampaign $campaign, CampaignManager $manager)
    {
        $this->authorizeAction('publish');
        $data = $request->validate(['customer_id' => 'nullable|integer|exists:customers,id', 'public' => 'required|boolean',
            'request_key' => 'required|uuid', 'reason' => 'required|string|max:190']);
        $customer = ! empty($data['customer_id']) ? Customer::findOrFail($data['customer_id']) : null;
        $g = $manager->issue($campaign, $customer, $data['request_key'], (bool) $data['public']);
        $manager->audit($campaign, $request->user('web'), 'issue', $data['reason'], ['grant_id' => $g->id]);

        return back()->with('ok', 'کد صادر شد: '.$g->code);
    }

    public function revoke(Request $request, MarketingGrant $grant, CampaignManager $manager)
    {
        $this->authorizeAction('publish');
        $reason = $request->validate(['reason' => 'required|string|max:190'])['reason'];
        DB::transaction(function () use ($grant, $reason, $request, $manager) {
            $campaign = MarketingCampaign::whereKey($grant->campaign_id)->lockForUpdate()->firstOrFail();
            $g = MarketingGrant::whereKey($grant->id)->lockForUpdate()->firstOrFail();
            if (MarketingRedemption::where('grant_id', $g->id)->whereIn('status', ['reserved', 'reconciliation'])->exists()) {
                throw ValidationException::withMessages(['grant' => 'کد دارای رزرو یا پرداخت در حال تطبیق است. ابتدا سفارش را تعیین تکلیف کنید.']);
            }
            $g->update(['revoked_at' => now(), 'revoke_reason' => $reason]);
            $manager->audit(MarketingCampaign::find($g->campaign_id), $request->user('web'), 'revoke', $reason, ['grant_id' => $g->id]);
            if ($g->budget_held) {
                $campaign->decrement('held', $g->liability);
                $g->update(['budget_held' => false]);
            }
        }, 5);

        return back()->with('ok', 'ابطال کد با ثبت دلیل انجام شد.');
    }

    public function reconcile(Request $request, MarketingRedemption $redemption, CampaignManager $manager)
    {
        $this->authorizeAction('publish');
        Gate::authorize('marketing.finance');
        $reason = $request->validate(['reason' => 'required|string|max:190'])['reason'];
        DB::transaction(function () use ($request, $redemption, $reason, $manager) {
            app(DiscountEngine::class)->releaseAfterReview($redemption);
            $manager->audit(MarketingCampaign::findOrFail($redemption->campaign_id), $request->user('web'),
                'release_after_review', $reason, ['redemption_id' => $redemption->id, 'invoice_id' => $redemption->invoice_id]);
        }, 5);

        return back()->with('ok', 'رزرو پس از تطبیق آزاد شد؛ مبلغ جدیدی به کیف پول افزوده نشد.');
    }

    public function policy(Request $request, CampaignManager $manager)
    {
        $this->authorizeAction('send');
        $data = $request->validate(['enabled' => 'required|boolean', 'country_reviewed' => 'required|boolean',
            'segment_cost_irt' => 'nullable|integer|min:1|max:1000000', 'daily_budget' => 'required|integer|min:0|max:1000000000',
            'daily_limit' => 'required|integer|min:0|max:10000', 'reason' => 'required|string|max:190']);
        foreach (['enabled', 'country_reviewed', 'segment_cost_irt', 'daily_budget', 'daily_limit'] as $key) {
            Setting::put('marketing_sms_'.$key, (string) ($data[$key] ?? ''));
        }
        $manager->audit(null, $request->user('web'), 'sms_policy', $data['reason'], ['enabled' => $data['enabled']]);

        return back()->with('ok', 'سیاست ارسال ثبت شد.');
    }

    public function permissions(Request $request, CampaignManager $manager)
    {
        abort_unless($request->user('web')?->isAdmin(), 403);
        $request->merge(['abilities' => $request->input('abilities', [])]);
        $data = $request->validate(['user_id' => 'required|integer|exists:users,id',
            'abilities' => 'present|array', 'abilities.*' => 'in:manage,publish,send,finance',
            'reason' => 'required|string|max:190']);
        abort_unless(User::findOrFail($data['user_id'])->isSupport(), 422);
        DB::transaction(function () use ($data, $request, $manager) {
            DB::table('marketing_permissions')->where('user_id', $data['user_id'])->delete();
            foreach (array_unique($data['abilities']) as $ability) {
                DB::table('marketing_permissions')->insert(['user_id' => $data['user_id'], 'ability' => $ability]);
            }
            $manager->audit(null, $request->user('web'), 'permissions', $data['reason'],
                ['user_id' => $data['user_id'], 'abilities' => $data['abilities']]);
        });

        return back()->with('ok', 'دسترسی‌های بازاریابی ثبت شد.');
    }

    public function export(MarketingCampaign $campaign, CampaignReport $reports)
    {
        $this->authorizeAction('finance');
        $data = $reports->for($campaign);

        return response()->streamDownload(function () use ($data) {
            $file = fopen('php://output', 'w');
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, ['Metric', 'Value'], escape: '');
            foreach ($data as $key => $value) {
                $value = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : ($value === null ? 'unknown' : (string) $value);
                if (preg_match('/^\s*[=+@-]/u', $value)) {
                    $value = "'".$value;
                }
                fputcsv($file, [$key, $value], escape: '');
            }
            fclose($file);
        }, 'campaign-'.$campaign->id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
