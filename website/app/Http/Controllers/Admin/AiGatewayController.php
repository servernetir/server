<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\AiModelUnitPrice;
use App\Models\User;
use App\Services\Ai\AiFx;
use App\Services\Ai\AiModelRegistry;
use App\Services\Ai\AiPricing;
use App\Services\Ai\AiPricingException;
use App\Services\Ai\AiVat;
use App\Services\Ai\PriceBook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * هوش مصنوعی — زیرساختِ دروازه (M1).
 *
 * فقط رجیستری و قیمت. سه صفحه: ارائه‌دهنده‌ها · مدل‌ها · قیمت.
 * هیچ تماسِ شبکه‌ای، هیچ کلیدِ API روی صفحه (فقط «تنظیم‌شده/نه» که از
 * `Setting::getSecret` می‌پرسد)، هیچ نرخ‌دهیِ خودکار از ارائه‌دهنده —
 * قیمت همیشه ورودِ دستیِ ادمین است.
 *
 * قاعدهٔ این‌جا که M2+ هم چشم داشته باشد: سطرِ قیمت **فقط از
 * `PriceBook::supersede`** عوض می‌شود؛ ویرایشِ مستقیمِ سطرِ قیمت خطایِ
 * مالی است.
 */
class AiGatewayController extends Controller
{
    public function __construct(
        private readonly AiModelRegistry $registry,
        private readonly PriceBook $priceBook,
    ) {}

    /* ── ارائه‌دهنده‌ها ── */

    public function providers(): View
    {
        $providers = AiProvider::query()
            ->withCount(['models', 'models as active_models' => fn ($q) => $q->where('status', AiModel::STATUS_ACTIVE)])
            ->orderBy('priority')->orderBy('id')
            ->get();

        return view('admin.ai.providers', [
            'providers' => $providers,
            'keyStates' => $providers->mapWithKeys(fn (AiProvider $p) => [
                $p->id => \App\Models\Setting::getSecret('ai_provider_'.$p->slug.'_key') !== null,
            ]),
        ]);
    }

    public function updateProvider(Request $request, AiProvider $provider)
    {
        $data = $request->validate([
            'enabled'            => 'nullable|boolean',
            'commercial_enabled' => 'nullable|boolean',
            'resale_allowed'     => 'nullable|boolean',
            'agreement_status'   => 'nullable|in:'.AiProvider::STATUS_NONE.','.AiProvider::STATUS_REQUESTED.','.AiProvider::STATUS_SIGNED,
            'priority'           => 'nullable|integer|between:0,65535',
            'live_calls_enabled' => 'nullable|boolean',
            'notes'              => 'nullable|string|max:2000',
            'name'               => 'nullable|string|max:80',
            // سربارِ ارز به درصد با حداکثر دو رقمِ اعشار؛ به bp ذخیره می‌شود
            'fx_fee_pct'         => ['nullable', 'numeric', 'min:0', 'max:25', 'regex:/^\d{1,2}(\.\d{1,2})?$/'],
        ]);

        // boolean ها: تیک‌برداشته = صفر، نه «بی‌تغییر»
        foreach (['enabled', 'commercial_enabled', 'resale_allowed', 'live_calls_enabled'] as $flag) {
            $provider->{$flag} = (bool) $request->boolean($flag);
        }
        $provider->fill(collect($data)->only([
            'agreement_status', 'priority', 'notes', 'name',
        ])->filter()->all());

        /*
        | سربارِ ارز: فیلدِ **خالی** یعنی NULL = «فروختنی نیست»، نه صفر. صفر یک
        | ادعای صریح است («رساندنِ دلار برایم هیچ هزینه‌ای ندارد») و باید تایپ شود.
        | نبودِ فیلد در درخواست (فرمِ قدیمی) یعنی «دست نزن».
        | نگهبانِ ستون: کد پیش از مهاجرتِ 000050 روی سرور می‌نشیند.
        */
        if ($request->has('fx_fee_pct') && Schema::hasColumn('ai_providers', 'fx_fee_bp')) {
            $provider->fx_fee_bp = AiPricing::percentToBp($data['fx_fee_pct'] ?? null);
        }
        $provider->save();

        \App\Models\ActivityLog::record(
            null, 'ai_provider_update',
            'ارائه‌دهندهٔ AI «'.$provider->slug.'» تنظیم شد (enabled='.var_export((bool) $provider->enabled, true)
            .', commercial='.var_export((bool) $provider->commercial_enabled, true)
            .', fx_fee_bp='.var_export($provider->getAttribute('fx_fee_bp'), true).')',
            $request, 'staff',
        );

        return back()->with('ok', 'ثبت شد.');
    }

    public function editProvider(Request $request): View
    {
        $provider = AiProvider::query()->find((int) $request->integer('provider'))
            ?? AiProvider::query()->orderBy('id')->first();

        return view('admin.ai.provider-edit', [
            'provider' => $provider,
            'hasFeeColumn' => Schema::hasColumn('ai_providers', 'fx_fee_bp'),
        ]);
    }

    /* ── مدل‌ها ── */

    public function models(Request $request): View
    {
        $category = $request->string('category', '')->toString();
        $status   = $request->string('status', '')->toString();

        $models = AiModel::query()->with(['provider', 'replacement'])
            ->withCount(['unitPrices as active_prices' => fn ($q) => $q->where('active', true)])
            ->when(in_array($status, AiModel::STATUSES, true), fn ($q) => $q->where('status', $status))
            ->when(in_array($category, AiModel::CATEGORIES, true), fn ($q) => $q->where('category', $category))
            ->orderBy('category')->orderBy('provider_priority')->orderBy('id')
            ->paginate(50)->withQueryString();

        return view('admin.ai.models', [
            'models'   => $models,
            'category' => $category,
            'status'   => $status,
        ]);
    }

    public function editModel(AiModel $model): View
    {
        $model->load(['provider', 'replacement']);

        return view('admin.ai.model-edit', [
            'model' => $model,
            'statuses'  => AiModel::STATUSES,
            'categories'=> AiModel::CATEGORIES,
            'hasMarginColumn' => Schema::hasColumn('ai_models', 'margin_bp'),
            'globalMarginBp' => AiPricing::globalMarginBp(),
        ]);
    }

    public function updateModel(Request $request, AiModel $model)
    {
        $data = $request->validate([
            'name'                   => 'required|string|max:120',
            'description'            => 'nullable|string|max:4000',
            'status'                 => 'required|in:'.implode(',', AiModel::STATUSES),
            'category'               => 'required|in:'.implode(',', AiModel::CATEGORIES),
            'replacement_model_id'   => 'nullable|integer|exists:ai_models,id|not_in:'.$model->id,
            'docs_url'               => 'nullable|url|max:255',
            'claude_code_compatible' => 'nullable|boolean',
            'provider_priority'      => 'nullable|integer|between:0,65535',
            'context_tokens'         => 'nullable|integer|between:0,4294967295',
            'max_output_tokens'      => 'nullable|integer|between:0,4294967295',
            // حاشیهٔ اختصاصی؛ خالی = حاشیهٔ سراسری. صفر پذیرفته نیست (فروش به بها)
            'margin_pct'             => ['nullable', 'numeric', 'gt:0', 'max:500', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
        ]);

        $model->claude_code_compatible = $request->boolean('claude_code_compatible');
        $model->fill(collect($data)->except(['claude_code_compatible', 'margin_pct'])->all());

        if ($request->has('margin_pct') && Schema::hasColumn('ai_models', 'margin_bp')) {
            $model->margin_bp = AiPricing::percentToBp($data['margin_pct'] ?? null);
        }
        $model->save();

        \App\Models\ActivityLog::record(
            null, 'ai_model_update',
            'مدلِ AI «'.$model->slug.'» ویرایش شد (status='.$model->status.', category='.$model->category.')',
            $request, 'staff',
        );

        return redirect('/admin/ai/models')->with('ok', 'مدل ثبت شد.');
    }

    public function toggleModel(Request $request, AiModel $model)
    {
        $request->validate(['status' => 'required|in:'.implode(',', AiModel::STATUSES)]);

        $model->update(['status' => $request->string('status')->toString()]);

        return back()->with('ok', 'وضعیت مدل عوض شد.');
    }

    /* ── قیمت ── */

    public function pricing(Request $request): View
    {
        $modelId = (int) $request->integer('model', 0);

        $model = AiModel::query()->with('provider')->find($modelId)
            ?? AiModel::query()->with('provider')->orderBy('id')->first();

        $history = $model ? $model->unitPrices()->orderByDesc('id')->limit(100)->get() : collect();

        return view('admin.ai.pricing', [
            'model'   => $model,
            'models'  => AiModel::query()->orderBy('category')->orderBy('id')->get(['id', 'slug', 'name', 'category']),
            'history' => $history,
            'selectedUnit' => $request->string('unit', '')->toString(),
            'preview' => $model ? $this->preview($model) : null,
        ]);
    }

    /**
     * پیش‌نمایشِ قیمتِ فروش — همان `AiPricing` که شارژ را می‌سازد، پس عددِ
     * این صفحه دقیقاً عددی است که از کیفِ پولِ مشتری کم خواهد شد.
     *
     * هیچ تماسِ شبکه‌ای ندارد (نرخ فقط از کش) و هیچ چیزی جز نشانِ بالاترین نرخ
     * نمی‌نویسد. خطا این‌جا صفحه را نمی‌خوابانَد؛ دلیلِ «فروختنی نیست» را نشان می‌دهد.
     */
    private function preview(AiModel $model): array
    {
        $pricing = app(AiPricing::class);
        $out = [
            'gates' => $pricing->saleGates($model),
            'error' => null,
            'reasons' => [],
            'quote' => null,
            'sample' => null,
            'eurRate' => app(AiFx::class)->displayRate('EUR'),
            'vatBp' => app(AiVat::class)->iranRateBp(),
        ];

        try {
            $q = $pricing->quote($model);
        } catch (AiPricingException $e) {
            return ['error' => $e->errorCode, 'reasons' => $e->reasons] + $out;
        }

        // نمونهٔ ثابت تا مدیر دو مدل را با یک ترازو مقایسه کند
        $in = 10_000;
        $cached = 0;
        $completion = 1_000;
        $maxOutput = (int) ($model->max_output_tokens ?: config('ai.default_max_output', 4096));

        $out['quote'] = $q;
        $out['sample'] = [
            'prompt' => $in, 'cached' => $cached, 'completion' => $completion,
            'charge' => $pricing->charge($q, $in, $cached, $completion, $out['vatBp']),
            'charge_foreign' => $pricing->charge($q, $in, $cached, $completion, 0),
            'hold_output' => $maxOutput,
            'hold' => $pricing->hold($q, $in, $maxOutput, $out['vatBp']),
        ];

        return $out;
    }

    public function supersedePrice(Request $request)
    {
        $data = $request->validate([
            'model'    => 'required|integer|exists:ai_models,id',
            'unit'     => 'required|in:'.implode(',', AiModelUnitPrice::UNITS),
            'micros'   => 'required|integer|min:1|max:100000000000', // سقفِ منطقی: ۱۰۰٬۰۰۰ واحدِ ارز (۱۰۰٬۰۰۰٬۰۰۰٬۰۰۰ میکرو)
            // فقط USD/EUR: نرخِ ریال را `toToman` با ضریبِ ۱۰ می‌خواند (B17) و هر ارزِ
            // دیگری نرخِ کش‌شده ندارد — سطرش هرگز فروختنی نمی‌شد و فقط گیج می‌کرد.
            'currency' => 'nullable|in:USD,EUR,usd,eur',
            'note'     => 'nullable|string|max:255',
        ]);

        $model = AiModel::query()->with('provider')->findOrFail($data['model']);

        /** @var User $user */
        $user = $request->user();

        $row = $this->priceBook->supersede(
            $model, $data['unit'], (int) $data['micros'], $user->id,
            $data['note'] ?? null, $data['currency'] ?? null,
        );

        \App\Models\ActivityLog::record(null, 'ai_price_superseded',
            'قیمتِ «'.$row->unit.'» مدلِ '.$model->slug.' → نسخهٔ '.$row->version
            .' ('.$row->price_micro_units.' میکرو-'.$row->currency_code.')',
            $request, 'staff');

        return back()->with('ok', 'نسخهٔ جدید ثبت شد؛ نسخهٔ قبلی بسته شد.');
    }
}
