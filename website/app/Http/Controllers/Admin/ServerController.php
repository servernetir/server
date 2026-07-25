<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\Provisioning\WhmClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * مدیریتِ سرورهای تحویل — WHM/cPanel و… جایی که سرویسِ مشتری ساخته می‌شود.
 *
 * حذفِ کامل فقط برای مدیر. توکنِ API هرگز خام به فرم برنمی‌گردد؛ اگر خالی
 * فرستاده شود، توکنِ قبلی دست‌نخورده می‌ماند.
 */
class ServerController extends Controller
{
    public function index(): View
    {
        $ready = Schema::hasTable('servers');

        return view('admin.servers', [
            'servers'  => $ready ? Server::withCount('services')->orderBy('name')->get() : collect(),
            'notReady' => ! $ready,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $server = new Server();
        $server->fill($data);
        $server->api_token = $data['api_token'] ?: null;
        $server->save();

        return back()->with('ok', 'سرور «'.$server->name.'» اضافه شد.');
    }

    public function update(Request $request, Server $server): RedirectResponse
    {
        $data = $this->validated($request);

        $token = $data['api_token'];
        unset($data['api_token']);
        $server->fill($data);
        // توکن فقط اگر مقدارِ تازه داده شده عوض می‌شود (خالی = دست‌نخورده)
        if (filled($token)) {
            $server->api_token = $token;
        }
        $server->save();

        return back()->with('ok', 'سرور «'.$server->name.'» به‌روزرسانی شد.');
    }

    public function destroy(Request $request, Server $server): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        if ($server->services()->exists()) {
            return back()->withErrors('این سرور سرویسِ متصل دارد و حذف نمی‌شود. اول سرویس‌ها را جابه‌جا یا لغو کنید.');
        }

        $name = $server->name;
        $server->delete();

        return back()->with('ok', 'سرور «'.$name.'» حذف شد.');
    }

    /** آزمایشِ اتصال — برای WHM نسخه را می‌پرسد */
    public function test(Server $server): RedirectResponse
    {
        if (! $server->isAutoProvisioned()) {
            return back()->with('ok', 'این نوع سرور تحویلِ دستی دارد و آزمونِ API ندارد.');
        }

        $res = (new WhmClient($server))->call('version');

        if ($res['ok']) {
            $ver = $res['data']['version'] ?? ($res['raw']['data']['version'] ?? '?');

            return back()->with('ok', 'اتصال موفق ✓ — WHM نسخهٔ '.$ver);
        }

        return back()->withErrors('اتصال ناموفق: '.$res['reason']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name'         => ['required', 'string', 'max:80'],
            'type'         => ['required', 'in:'.implode(',', Server::TYPES)],
            // کشور از config/billing.php می‌آید؛ خالی مجاز است (در خرید نمایش نمی‌شود)
            'country'      => ['nullable', \Illuminate\Validation\Rule::in(array_keys((array) config('billing.locations', [])))],
            'city'         => ['nullable', 'string', 'max:60'],
            'hostname'     => ['nullable', 'string', 'max:190'],
            'port'         => ['nullable', 'integer', 'min:1', 'max:65535'],
            'username'     => ['nullable', 'string', 'max:60'],
            'api_token'    => ['nullable', 'string', 'max:400'],
            'verify_tls'   => ['nullable', 'boolean'],
            'server_ip'    => ['nullable', 'string', 'max:45'],
            'nameservers'  => ['nullable', 'string', 'max:190'],
            'status'       => ['required', 'in:active,maintenance,full'],
            'max_accounts' => ['nullable', 'integer', 'min:0'],
            'note'         => ['nullable', 'string', 'max:1000'],
        ]) + ['verify_tls' => $request->boolean('verify_tls'), 'username' => $request->input('username') ?: 'root'];
    }
}
