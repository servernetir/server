<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * مدیریتِ پکیج‌های فروش — چیزی که مشتری در فروشگاهِ پنل می‌خرد.
 */
class ProductController extends Controller
{
    public function index(): View
    {
        $ready = Schema::hasTable('products');

        return view('admin.products', [
            'products' => $ready ? Product::with('server')->orderBy('category')->orderBy('sort')->get() : collect(),
            'servers'  => Schema::hasTable('servers') ? Server::orderBy('name')->get() : collect(),
            'notReady' => ! $ready,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['specs'] = $this->parseSpecs($request->input('specs_raw'));
        Product::create($data);

        return back()->with('ok', 'پکیج «'.$data['name'].'» اضافه شد.');
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $data = $this->validated($request);
        $data['specs'] = $this->parseSpecs($request->input('specs_raw'));
        $product->update($data);

        return back()->with('ok', 'پکیج «'.$product->name.'» به‌روزرسانی شد.');
    }

    public function destroy(Request $request, Product $product): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $name = $product->name;
        $product->delete();

        return back()->with('ok', 'پکیج «'.$name.'» حذف شد.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name'            => ['required', 'string', 'max:150'],
            'category'        => ['required', 'in:'.implode(',', array_keys(Product::CATEGORIES))],
            'server_id'       => ['nullable', 'integer', 'exists:servers,id'],
            'plan'            => ['nullable', 'string', 'max:80'],
            'price'           => ['required', 'integer', 'min:0', 'max:100000000000'],
            'setup_fee'       => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'cycle'           => ['required', 'in:once,monthly,quarterly,yearly'],
            'tax_percent'     => ['nullable', 'integer', 'min:0', 'max:100'],
            'description'     => ['nullable', 'string', 'max:2000'],
            'requires_domain' => ['nullable', 'boolean'],
            'is_active'       => ['nullable', 'boolean'],
            'sort'            => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]) + [
            'setup_fee'       => (int) $request->input('setup_fee', 0),
            'tax_percent'     => (int) $request->input('tax_percent', 10),
            'requires_domain' => $request->boolean('requires_domain'),
            'is_active'       => $request->boolean('is_active'),
            'sort'            => (int) $request->input('sort', 0),
        ];
    }

    /** «برچسب: مقدار» در هر خط → [{label,value}] */
    private function parseSpecs(?string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', (string) $raw) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = explode(':', $line, 2);
            $out[] = ['label' => trim($parts[0]), 'value' => trim($parts[1] ?? '')];
        }

        return $out;
    }
}
