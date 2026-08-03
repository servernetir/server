<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\Domain\DomainRegistrar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * دامنه‌ها در پنلِ مدیریت.
 *
 * 🔴 چرا لازم بود: `DomainRegistrar` دامنهٔ مشکل‌دار را به
 * `provision_status='manual'` می‌بَرد تا کرون دیگر برش ندارد و آدم تصمیم
 * بگیرد. ولی **هیچ آدمی آن صف را نمی‌دید**: نه صفحه‌ای بود، نه اعلانی، و
 * خروجیِ فرمان به `/dev/null` کرون می‌رفت. یعنی مشتری پول داده بود، دامنه‌اش
 * ثبت نشده بود، و تنها نشانه‌اش یک ردیف در دیتابیس بود که کسی نگاهش نمی‌کرد.
 */
class DomainController extends Controller
{
    /** بیشینهٔ ردیف؛ بیشتر از این را کسی در یک صفحه نمی‌خواند */
    private const MAX_ROWS = 300;

    public function index(Request $request): View
    {
        $filter = (string) $request->query('f', 'attention');

        $q = Domain::query()->with('customer:id,code,email');

        // ⚠️ پیش‌فرض عمداً «نیازمندِ رسیدگی» است، نه «همه»: صفحه‌ای که با
        // فهرستِ کاملِ دامنه‌ها باز شود، ردیفِ گیرکرده را در انبوهِ ردیف‌های
        // سالم پنهان می‌کند — و همان ردیف دقیقاً چیزی است که پول رویش خوابیده.
        match ($filter) {
            'manual'  => $q->where('provision_status', 'manual'),
            'pending' => $q->where('status', 'pending'),
            'active'  => $q->where('status', 'active'),
            'expiring' => $q->expiringWithin(45),
            'all'     => null,
            default   => $q->where(fn ($w) => $w
                ->where('provision_status', 'manual')
                ->orWhere('status', 'pending')),
        };

        $rows = $q->orderByDesc('id')->limit(self::MAX_ROWS + 1)->get();
        $truncated = $rows->count() > self::MAX_ROWS;

        return view('admin.domains', [
            'rows'      => $rows->take(self::MAX_ROWS),
            'filter'    => $filter,
            'truncated' => $truncated,
            'counts'    => $this->counts(),
        ]);
    }

    /**
     * تلاشِ دوبارهٔ ثبت — برای دامنه‌ای که در صفِ دستی گیر کرده.
     *
     * ⚠️ خودِ ثبت را این‌جا اجرا نمی‌کنیم و فقط پرچم را به `pending` برمی‌گردانیم:
     * تماسِ رجیسترار چند ثانیه طول می‌کشد و اگر داخلِ درخواستِ مدیر باشد، هم
     * صفحه معطل می‌مانَد هم قفلِ اتمی با کرون رقابت می‌کند. کرون همان دقیقه
     * برش می‌دارد.
     */
    public function retry(Domain $domain): RedirectResponse
    {
        if ($domain->isDead()) {
            return back()->withErrors('این دامنه بسته شده است.');
        }

        if ($domain->provision_status === 'done') {
            return back()->withErrors('این دامنه از قبل ثبت شده است.');
        }

        $domain->forceFill([
            'provision_status' => 'pending',
            'provision_tries'  => 0,
            'provision_error'  => null,
        ])->save();

        return back()->with('ok', 'در صفِ ثبت قرار گرفت. کرون تا یک دقیقهٔ دیگر تلاش می‌کند.');
    }

    /** ثبتِ فوری — وقتی مدیر می‌خواهد همان لحظه نتیجه را ببیند */
    public function registerNow(Domain $domain, DomainRegistrar $registrar): RedirectResponse
    {
        if ($domain->provision_status === 'done') {
            return back()->withErrors('این دامنه از قبل ثبت شده است.');
        }

        // پرچم را pending می‌کنیم تا قفلِ اتمیِ خودِ registrar بتواند برش دارد
        $domain->forceFill(['provision_status' => 'pending'])->save();

        $res = $registrar->register($domain);

        return $res['ok']
            ? back()->with('ok', 'ثبت شد.')
            : back()->withErrors('ثبت نشد: '.$res['message']);
    }

    /** @return array<string,int> */
    private function counts(): array
    {
        return [
            'manual'   => Domain::where('provision_status', 'manual')->count(),
            'pending'  => Domain::where('status', 'pending')->count(),
            'active'   => Domain::where('status', 'active')->count(),
            'expiring' => Domain::expiringWithin(45)->count(),
        ];
    }
}
