<?php

namespace App\Http\Controllers;

use App\Models\AiModel;
use App\Services\Ai\AiAvailability;
use Illuminate\Http\Request;

class AiStorefrontController extends Controller
{
    public function home()
    {
        $models = AiModel::with('provider')->where('category', 'chat')->where('status', 'active')->orderByDesc('context_tokens')->limit(6)->get();
        return view('pages.ai.home', ['models' => $this->cards($models), 'count' => AiModel::count()]);
    }

    public function models(Request $request)
    {
        $query = AiModel::with('provider')->orderBy('name');
        $search = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        $category = (string) $request->query('type', '');
        if ($search !== '') {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('vendor', 'like', '%'.addcslashes($search, '%_\\').'%'));
        }
        if (in_array($category, AiModel::CATEGORIES, true)) {
            $query->where('category', $category);
        }
        $page = $query->paginate(24)->withQueryString();
        return view('pages.ai.models', ['models' => $this->cards($page->getCollection()), 'page' => $page,
            'search' => $search, 'category' => $category]);
    }

    public function model(string $slug)
    {
        $model = AiModel::with('provider')->where('slug', $slug)->firstOrFail();
        return view('pages.ai.model', ['model' => $model, 'state' => app(AiAvailability::class)->inspect($model)]);
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
}
