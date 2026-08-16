<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\ResellerApiLog;
use App\Services\Domain\Reseller\ResellerProgram;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * پنلِ نمایندگیِ دامنه.
 *
 * سه سؤالی که نماینده هر روز می‌پرسد و این صفحه باید بی‌کلیکِ اضافه جواب بدهد:
 * «سطحم چیست و تا بعدی چقدر مانده؟» · «اعتبارم چقدر است؟» ·
 * «چرا آن سفارش رد شد؟»
 */
class ResellerController extends Controller
{
    public function __construct(private ResellerProgram $program) {}

    public function index(Request $request): View
    {
        $c = $this->customer();

        /*
        | ⚠️ صفحه برای مشتریِ **غیرِ نماینده** هم باز می‌شود و حالتِ «معرفی +
        | درخواستِ فعال‌سازی» نشان می‌دهد. ۴۰۴ دادن یعنی لینکِ صفحه در
        | بازاریابی بی‌مصرف است و مشتریِ علاقه‌مند به دیوار می‌خورد.
        */
        /*
        |----------------------------------------------------------------------
        | 🔴 پیش از مهاجرت هم باید بالا بیاید
        |----------------------------------------------------------------------
        |
        | دیپلویِ این پروژه فایل‌به‌فایل است و مهاجرتِ پروداکشن **دستی** اجرا
        | می‌شود، پس همیشه پنجره‌ای هست که کد روی سرور است و ستون‌ها نیستند.
        |
        | بی‌این محافظ، آن پنجره یک ۵۰۰ روی صفحه‌ای می‌سازد که صفحهٔ فروشِ
        | عمومیِ `/domain/reseller` مستقیم به آن لینک می‌دهد — یعنی درست همان
        | کسی که تازه ترغیب شده، به خطا می‌خورد. `scopeUsable` به `revoked_at`
        | و `expires_at` تکیه دارد و `is_reseller` هم ستونِ تازه است.
        |
        | ⚠️ حالتِ «هنوز فعال نشده» عمداً همان چیزی است که به مشتریِ غیرنماینده
        | نشان داده می‌شود: صفحه معنا دارد، و به‌محضِ اجرای مهاجرت خودبه‌خود
        | کامل می‌شود. هیچ‌کس لازم نیست چیزی را به یاد بیاورد.
        */
        $ready = Schema::hasColumn('customers', 'is_reseller')
            && Schema::hasColumn('customer_api_tokens', 'revoked_at');

        $enabled = $ready && $this->program->isReseller($c);

        return view('account.reseller', AccountController::shell('reseller') + [
            'isReseller' => $enabled,
            'progress'   => $enabled ? $this->program->progress($c) : null,
            'levels'     => $this->program->levels(),
            'credit'     => $c->creditBalance('IRT'),
            'tokens'     => $ready ? $c->apiTokens()->usable()->orderByDesc('id')->get() : collect(),
            'domains'    => Domain::where('customer_id', $c->id)->alive()->count(),
            'logs'       => $enabled
                ? ResellerApiLog::where('customer_id', $c->id)->recent()->limit(30)->get()
                : collect(),
            'spentToday' => $enabled ? ResellerApiLog::spentToday($c->id) : 0,
            'dailyCap'   => (int) ($c->reseller_daily_cap_irt
                ?: config('domain_reseller.limits.daily_spend_irt', 0)),
        ]);
    }

    /**
     * افزونه‌های قابلِ دانلود.
     *
     * ⚠️ `root` نامِ پوشهٔ **داخلِ** zip است و دلخواه نیست: WHMCS ماژول را در
     * `modules/registrars/servernet/` می‌خواهد و وردپرس افزونه را در
     * `wp-content/plugins/servernet-domains/`. اگر فایل‌ها در ریشهٔ zip باشند،
     * نماینده باید پوشه را دستی بسازد — و اولین کسی که اشتباه بسازد، افزونهٔ
     * «نصب‌شده‌ای» دارد که میزبانش اصلاً نمی‌بیندش.
     *
     * @var array<string,array{src:string, root:string, version_key:string}>
     */
    private const MODULES = [
        'whmcs' => [
            'src'         => 'whmcs/servernet',
            'root'        => 'servernet',
            'version_key' => 'domain_reseller.whmcs.version',
        ],
        'wordpress' => [
            'src'         => 'wordpress/servernet-domains',
            'root'        => 'servernet-domains',
            'version_key' => 'domain_reseller.wordpress.version',
        ],
    ];

    /**
     * دانلودِ افزونه به‌صورت zip.
     *
     * ⚠️ zip در لحظه ساخته می‌شود و در مخزن نگهداری نمی‌شود: فایلِ باینریِ
     * ساخته‌شده در گیت یعنی روزی سورس تغییر می‌کند و zip نه — و نماینده
     * نسخه‌ای نصب می‌کند که هیچ‌جا وجود ندارد.
     */
    public function download(Request $request, string $kind = 'whmcs'): BinaryFileResponse|StreamedResponse
    {
        $mod = self::MODULES[$kind] ?? null;

        abort_if($mod === null, 404);

        $src = resource_path($mod['src']);

        abort_unless(is_dir($src) && class_exists(\ZipArchive::class), 404);

        $version = (string) config($mod['version_key'], '1.0.0');
        $name = 'servernet-'.$kind.'-'.$version.'.zip';
        $path = storage_path('app/'.$name);

        // ساختِ دوباره فقط وقتی سورس تازه‌تر از zip است
        if (! is_file($path) || filemtime($path) < $this->newestMtime($src)) {
            $zip = new \ZipArchive;
            $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

            foreach ($this->files($src) as $file) {
                $zip->addFile($file, $mod['root'].'/'.str_replace('\\', '/', substr($file, strlen($src) + 1)));
            }

            $zip->close();
        }

        return response()->download($path, $name);
    }

    /** @return array<int,string> */
    private function files(string $dir): array
    {
        $out = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $f) {
            if ($f->isFile()) {
                $out[] = $f->getPathname();
            }
        }

        return $out;
    }

    private function newestMtime(string $dir): int
    {
        $newest = 0;

        foreach ($this->files($dir) as $f) {
            $newest = max($newest, (int) filemtime($f));
        }

        return $newest;
    }

    private function customer(): Customer
    {
        return Auth::guard('customer')->user();
    }
}
