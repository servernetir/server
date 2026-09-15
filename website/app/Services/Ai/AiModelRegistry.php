<?php

namespace App\Services\Ai;

use App\Models\AiModel;
use App\Models\AiProvider;
use Illuminate\Support\Collection;

/**
 * رجیستریِ ارائه‌دهنده و مدل — تنها مسیرِ خواندنِ «چه چیزی در دسترس است».
 *
 * همهٔ منطقِ خرید/مسیرِ M4 به چیزی که این کلاس می‌گوید بسته است؛ کنترلرها *
 * هرگز مستقیم از جدول نمی‌پرسند. قاعدهٔ اصلی: مدلِ غیرفروش / ارائه‌دهندهٔ
 * خاموش، *نیستِ بی‌صدا* و غایب — خلافِ آن هیچ صفحه‌ای ستاره نمی‌گذارد.
 */
final class AiModelRegistry
{
    /** ارائه‌دهنده بر اساس اسلاگ */
    public function provider(string $slug): ?AiProvider
    {
        return AiProvider::query()->where('slug', $slug)->first();
    }

    /**
     * ارائه‌دهنده‌های فنی-فعال، مرتب با `priority`. تماسِ زنده معیارِ
     * جدایی است: فهرستِ پاس‌دهیِ M4 از `liveCapable()` می‌آید نه از
     * این‌جا — و گیتِ فروشِ M2 هم از `isCommerciallySellable()`.
     */
    public function enabledProviders(): Collection
    {
        return AiProvider::query()
            ->where('enabled', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }

    /** ارائه‌دهنده‌هایی که مجازِ تماسِ زنده‌اند — ورودیِ M4 */
    public function liveCapable(): Collection
    {
        return AiProvider::query()
            ->where('enabled', true)
            ->where('live_calls_enabled', true)
            ->orderBy('priority')
            ->get();
    }

    /** Generic model lookup by public slug — technical boundary only; commercial/routing gates live elsewhere. */
    public function model(string $slug): ?AiModel
    {
        return AiModel::query()->where('slug', $slug)->with('provider')->first();
    }

    /**
     * مدل‌های قابلِ مسیردهی برای یک واحدِ درخواست‌شده — فعال + ارائه‌دهندهٔ
     * فنی-فعال، مرتب با اولویتِ ارائه‌دهنده. خریدِ مشتری در M2 فیلترِ *
     * `commercial_enabled` را روی همین سطرهای فروشنده می‌نوازد؛ این‌جا
     * فقط مسیردهیِ فنی.
     */
    public function routeableModels(?string $category): Collection
    {
        return AiModel::query()
            ->where('status', AiModel::STATUS_ACTIVE)
            ->when($category !== null && $category !== '',
                fn ($q) => $q->where('category', $category))
            ->whereHas('provider', fn ($p) => $p->where('enabled', true))
            ->with('provider')
            ->orderBy('category')
            ->orderBy('provider_priority')
            ->get();
    }

    /** مدل‌های قابلِ فروش — گیتِ تجاری کامل (M2 / `/v1/models`). */
    public function sellableModels(?string $category = null): Collection
    {
        return $this->routeableModels($category)
            ->filter(fn (AiModel $m) => $m->isSellable())
            ->values();
    }
}
