<?php

namespace App\Http\Controllers;

use App\Models\AiModel;
use App\Services\Ai\AiAvailability;
use App\Services\Ai\AiModelPage;
use Illuminate\Http\Request;

class AiStorefrontController extends Controller
{
    public function home()
    {
        $models = AiModel::with('provider')->where('category', 'chat')->where('status', 'active')->orderByDesc('context_tokens')->limit(6)->get();
        return view('pages.ai.home', ['models' => $this->cards($models), 'count' => AiModel::count(),
            'categories' => $this->categories()]);
    }

    public function models(Request $request)
    {
        $query = AiModel::with('provider')->orderBy('name');
        $rawSearch = $request->query('q', '');
        $rawCategory = $request->query('type', '');
        abort_unless(is_string($rawSearch) && is_string($rawCategory), 400);
        $search = mb_substr(trim($rawSearch), 0, 100);
        $category = $rawCategory;
        if ($search !== '') {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('vendor', 'like', '%'.addcslashes($search, '%_\\').'%'));
        }
        if (in_array($category, AiModel::CATEGORIES, true)) {
            $query->where('category', $category);
        }
        $page = $query->paginate(24)->withQueryString();
        $categories = $this->categories();
        $categoryPage = $search === '' && in_array($category, $categories, true) && $page->isNotEmpty();
        $canonicalParams = $categoryPage ? ['type' => $category] : [];
        if ($page->currentPage() > 1) {
            $canonicalParams['page'] = $page->currentPage();
        }
        $altUrls = [];
        foreach (\App\Providers\AppServiceProvider::LOCALES as $locale => $prefix) {
            $altUrls[$locale] = route($prefix.'ai.models', $canonicalParams);
        }
        return view('pages.ai.models', ['models' => $this->cards($page->getCollection()), 'page' => $page,
            'search' => $search, 'category' => $category, 'altUrls' => $altUrls,
            'canonical' => lroute('ai.models', $canonicalParams), 'categoryPage' => $categoryPage,
            'categories' => $categories]);
    }

    public function model(string $slug)
    {
        $model = AiModel::with('provider')->where('slug', $slug)->firstOrFail();
        $state = app(AiAvailability::class)->inspect($model);
        $related = AiModel::with('provider')->where('category', $model->category)->whereKeyNot($model->id)
            ->where('status', 'active')->whereNull('suspended_at')
            ->orderByRaw('case when vendor = ? then 0 else 1 end', [$model->vendor])
            ->orderByRaw('ABS(CAST(context_tokens AS DECIMAL(20,0)) - ?)', [$model->context_tokens])->orderBy('name')->limit(6)->get();
        $relatedCards = $this->cards($related);
        $nativeTask = $state['quote']?->native['task'] ?? null;
        $candidates = $relatedCards->filter(fn ($card) => $nativeTask !== null
            ? ($card['state']['quote']?->native['task'] ?? null) === $nativeTask
            : in_array($model->category, ['chat', 'embedding', 'rerank'], true));
        return view('pages.ai.model', ['model' => $model, 'state' => $state,
            'seo' => app(AiModelPage::class)->build($model, $state), 'related' => $relatedCards,
            'comparisonSlugs' => implode(',', [$model->slug, ...$candidates->take(2)->pluck('model.slug')->all()])]);
    }


    /** Public comparison, using the same availability and quote contracts as model pages. */
    public function compare(Request $request)
    {
        $raw = $request->query('models', '');
        abort_unless(is_string($raw) && strlen($raw) <= 600, 400);
        $slugs = $raw === '' ? [] : array_values(array_unique(explode(',', $raw)));
        abort_if(count($slugs) > 3 || collect($slugs)->contains(fn ($slug) => ! preg_match('/^[a-zA-Z0-9_.-]{1,190}$/', $slug)), 400);
        $custom = $slugs !== [];
        $query = AiModel::with('provider');
        $selected = $custom ? $query->whereIn('slug', $slugs)->get()->sortBy(fn ($m) => array_search($m->slug, $slugs, true))->values()
            : $query->where('category', 'chat')->where('status', 'active')->whereNull('suspended_at')->orderByDesc('context_tokens')->orderBy('slug')->limit(3)->get();
        abort_if($custom && $selected->count() !== count($slugs), 404);
        $models = $this->cards($selected)->map(fn ($card) => $card + [
            'seo' => app(AiModelPage::class)->build($card['model'], $card['state']),
        ]);
        $altUrls = [];
        foreach (\App\Providers\AppServiceProvider::LOCALES as $locale => $prefix) {
            $altUrls[$locale] = route($prefix.'ai.compare', $custom ? ['models' => implode(',', $slugs)] : []);
        }
        return view('pages.ai.compare', compact('models', 'custom', 'altUrls'));
    }

    public function docs()
    {
        $model = AiModel::where('category', 'chat')->where('status', 'active')->orderBy('name')->first();
        return view('pages.ai.docs', ['exampleModel' => $model?->slug ?? 'MODEL_FROM_V1_MODELS']);
    }

    private function cards($models)
    {
        return $models->map(fn ($m) => ['model' => $m, 'state' => app(AiAvailability::class)->inspect($m)]);
    }

    private function categories(): array
    {
        $present = AiModel::where('status', 'active')->distinct()->pluck('category')->all();

        return array_values(array_intersect(AiModel::CATEGORIES, $present));
    }
}
