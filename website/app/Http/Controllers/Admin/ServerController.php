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
        /*
        | ⚠️ `?? null` و نه `$data['api_token']`: `validate()` کلیدی را که
        | اصلاً فرستاده نشده در خروجی نمی‌گذارد، پس هر POSTی که این فیلد را
        | نداشته باشد ۵۰۰ می‌داد. فرمِ پنل همیشه می‌فرستدش و برای همین سال‌ها
        | دیده نشد — ولی سرورِ دستی/اسکریپتی نه.
        */
        $server->api_token = ($data['api_token'] ?? null) ?: null;
        $server->save();

        return back()->with('ok', 'سرور «'.$server->name.'» اضافه شد.');
    }

    public function update(Request $request, Server $server): RedirectResponse
    {
        $data = $this->validated($request);

        $token = $data['api_token'] ?? null;
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
        $data = $request->validate([
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
            /*
            | نیم‌سرورهایی که به مشتریِ این سرور اعلام می‌شوند (کاما-جدا).
            |
            | ⚠️ اگر پر شود باید **حداقل دو تا** باشد: تقریباً همهٔ رجیستری‌ها
            | کمتر از دو نیم‌سرور را رد می‌کنند، پس یک مقدارِ تک‌عضوی یعنی
            | مشتری‌ای که عددِ ما را وارد می‌کند و رجیسترار قبولش نمی‌کند —
            | و همان تیکتی که این ستون برای حذفش هست، دوباره ساخته می‌شود.
            |
            | خالی‌گذاشتن بی‌خطر است: `Server::nameserverList()` به پیش‌فرضِ
            | کشور در config/provisioning.php می‌افتد.
            */
            'nameservers'  => ['nullable', 'string', 'max:190', function ($attr, $value, $fail) {
                $n = preg_split('/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                if ($n !== [] && count($n) < 2) {
                    $fail('حداقل دو نیم‌سرور لازم است؛ رجیسترارها کمتر از دو تا را رد می‌کنند. (یا خالی بگذارید تا پیش‌فرضِ کشور استفاده شود.)');
                }
            }],
            'status'       => ['required', 'in:active,maintenance,full'],
            'max_accounts' => ['nullable', 'integer', 'min:0'],

            /*
            | بهایِ اجاره. `nullable` عمدی است و «نمی‌دانم» را از «رایگان»
            | جدا نگه می‌دارد — دلیلِ کاملش در مهاجرتِ add_cost_to_servers.
            |
            | ⚠️ سقفِ `billing_day` روی ۲۸ است تا هر ماهی آن روز را داشته باشد.
            */
            'monthly_cost'  => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'cost_currency' => ['nullable', 'in:EUR,IRT,USD'],
            'billing_day'   => ['nullable', 'integer', 'min:1', 'max:28'],
            'vendor'        => ['nullable', 'string', 'max:60'],

            'note'         => ['nullable', 'string', 'max:1000'],
        ], [], [
            'monthly_cost' => 'اجارهٔ ماهانه', 'cost_currency' => 'ارزِ اجاره',
            'billing_day' => 'روزِ صورت‌حساب', 'vendor' => 'تأمین‌کننده',
        ]);

        /*
        | 🔴 ستون‌های هزینه فقط وقتی وارد `$data` می‌شوند که **واقعاً وجود
        | داشته باشند**.
        |
        | مهاجرت‌های پروداکشن دستی اجرا می‌شوند، پس کد همیشه مدتی جلوتر از
        | دیتابیس است. بی‌این گارد، در همان پنجره هر «افزودن سرور» و هر
        | «ذخیرهٔ تغییرات» با خطای SQL می‌ترکید — یعنی یک قابلیتِ گزارشیِ تازه،
        | مدیریتِ سرور را که کارِ روزمره است می‌خواباند.
        |
        | بقیهٔ کد این ستون‌ها را با `Schema::hasColumn` می‌خوانَد و آرام رد
        | می‌شود؛ تنها جای نوشتن همین‌جاست.
        */
        $costReady = Schema::hasTable('servers') && Schema::hasColumn('servers', 'monthly_cost');

        /*
        | ⚠️ از خودِ `$data` هم برداشته می‌شوند: `validate()` هر فیلدی را که
        | فرم فرستاده برمی‌گرداند، پس گاردِ پایین به‌تنهایی کافی نبود و
        | مقدارِ ارسالی از همان‌جا به INSERT می‌رسید.
        */
        if (! $costReady) {
            unset($data['monthly_cost'], $data['cost_currency'], $data['billing_day'], $data['vendor']);
        }

        return $data + array_filter([
            'monthly_cost'  => $costReady
                ? (filled($request->input('monthly_cost')) ? (int) $request->input('monthly_cost') : null)
                : false,
            'cost_currency' => $costReady ? ($request->input('cost_currency') ?: 'EUR') : false,
            'billing_day'   => $costReady
                ? (filled($request->input('billing_day')) ? (int) $request->input('billing_day') : null)
                : false,
            'vendor'        => $costReady ? $request->input('vendor') : false,
        ], fn ($v) => $v !== false) + [
            'verify_tls' => $request->boolean('verify_tls'),
            'username'   => $request->input('username') ?: 'root',
        ];
    }
}
