<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CloudPlan;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * مرکزِ تحویل‌ها — هر سفارشی که پولش آمده و سرویسش هنوز دستِ مشتری نیست.
 *
 * ═══ چرا این صفحه لازم شد ═══
 *
 * 🔴 همهٔ اجزای رسیدگی از قبل وجود داشتند ولی **پراکنده**: علتِ خطا روی
 * پروفایلِ مشتری، تلاشِ دوباره همان‌جا، قرنطینهٔ پلن در ErrorTracker، ضربانِ
 * کرون در صفحهٔ token‌دار. مدیر باید می‌دانست کجا را بگردد — و عملاً وقتی
 * می‌فهمید که مشتری شکایت می‌کرد یا خودش اتفاقی پروفایل را باز می‌کرد
 * (سفارشِ SN-604534 دقیقاً همین‌طور دیده شد).
 *
 * این صفحه همان داده‌ها را یک‌جا می‌آورد و برای هر ردیف به زبانِ آدم می‌گوید
 * «چه شده» و «حالا چه کن». هیچ منطقِ تازه‌ای برای خودِ تحویل ندارد — همهٔ
 * دکمه‌ها به روت‌های موجودِ provision / provision-override / پروفایل می‌روند.
 */
class ProvisioningController extends Controller
{
    /** بیشترین ردیف در هر دسته — صفحهٔ عملیاتی است، نه بایگانی */
    private const LIMIT = 50;

    /** صف‌ماندگیِ بیش از این (دقیقه) یعنی کرونِ تحویل باید بررسی شود */
    private const QUEUE_LATE_MIN = 30;

    public function index(Request $request): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        $base = fn () => Service::query()
            ->with(['customer', 'server', 'cloudPlan'])
            ->whereNotIn('status', Service::DEAD_STATUSES);

        $failed = $base()->where('provision_status', 'failed')
            ->orderByDesc('updated_at')->limit(self::LIMIT)->get();

        $manual = $base()->where('provision_status', 'manual')
            ->orderByDesc('updated_at')->limit(self::LIMIT)->get();

        // قفلِ کهنه: پروسه وسطِ ساخت مرده (دیپلوی/ری‌استارت). کرون این‌ها را
        // برنمی‌دارد؛ «تلاش دوباره»ی مدیر تنها درِ خروج است.
        $stuck = $base()->where('provision_status', 'running')
            ->where('updated_at', '<', now()->subMinutes(15))
            ->orderBy('updated_at')->limit(self::LIMIT)->get();

        // در صف: کرونِ بعدی برمی‌دارد. ردیفِ کهنه‌ترازحد یعنی یا کرون نمی‌دود
        // یا هر بار شکستِ ظرفیتی می‌خورد (علتش در provision_error همین‌جاست).
        $queued = $base()
            ->where(function ($q) {
                $q->where('provision_status', 'pending')
                    ->orWhere(fn ($qq) => $qq->whereNull('provision_status')
                        ->where('status', 'awaiting_provision'));
            })
            ->orderBy('created_at')->limit(self::LIMIT)->get();

        $heartbeat = null;

        try {
            $heartbeat = app(\App\Services\SystemHealth::class)->heartbeatAt();
        } catch (\Throwable) {
        }

        return view('admin.provisioning', [
            'failed'    => $failed,
            'manual'    => $manual,
            'stuck'     => $stuck,
            'queued'    => $queued,
            'lateQueue' => $queued->filter(
                fn ($s) => $s->created_at && $s->created_at->lt(now()->subMinutes(self::QUEUE_LATE_MIN))
            )->count(),
            'heartbeat' => $heartbeat,
            'servers'   => Server::orderBy('name')->get(),
            'diagnose'  => fn (Service $s) => $this->diagnose($s),
            'catalog'   => $this->catalogHealth(),
        ]);
    }

    /**
     * ترجمهٔ حالتِ یک سفارشِ تحویل‌نشده به زبانِ مدیر: [چه شده، حالا چه کن].
     *
     * ⚠️ رشته‌های الگو عینِ متن‌هایی‌اند که CloudProvisioner /
     * ProvisioningService در `provision_error` می‌نویسند؛ اگر آن‌جا عوض شوند
     * این‌جا فقط به دستهٔ عمومی می‌افتد — هیچ‌چیز نمی‌شکند، فقط کم‌دقت می‌شود.
     */
    private function diagnose(Service $s): array
    {
        $err = mb_strtolower((string) $s->provision_error);
        $isCloud = $s->isCloud();

        if (str_contains($err, 'نیازمند') && str_contains($err, 'تأیید')) {
            return ['محافظِ سوءاستفاده سفارش را نگه داشته',
                'هویت و سابقهٔ مشتری را بررسی کنید. سالم بود ⇒ «تأیید و ساخت». مشکوک بود ⇒ از پروفایل «لغو + بازگشت وجه».'];
        }

        if (str_contains($err, 'ظرفیت') || str_contains($err, 'پذیرشِ حساب')) {
            return ['سرورِ تحویل پر یا خارج از دسترس است',
                'در «سرورهای تحویل» ظرفیت/وضعیت را درست کنید یا سرورِ دیگری بدهید؛ کرون خودش دوباره تلاش می‌کند.'];
        }

        if (str_contains($err, 'سرور حذف شده')) {
            return ['سرورِ تحویلِ این سرویس حذف شده',
                'از پروفایلِ مشتری سرورِ تازه انتخاب کنید و «ساخت روی سرور» بزنید.'];
        }

        foreach (['permission', 'unauthor', 'forbidden', 'invalid token', 'insufficient',
            'balance', 'payment', 'quota', 'proxy internal server error'] as $w) {
            if (str_contains($err, $w)) {
                return ['زیرساختِ فروشنده سفارش را نپذیرفت (حساب/دسترس/اعتبار)',
                    'حساب یا توکنِ زیرساخت را در «زیرساختِ ابری» درست کنید، بعد «تلاش دوباره». پلن‌های قرنطینه‌شده را همان‌جا باز کنید.'];
            }
        }

        if (str_contains($err, 'timeout') || str_contains($err, 'curl') || str_contains($err, 'خطای غیرمنتظره')) {
            return ['خطای گذرا (شبکه/مهلت)',
                $isCloud
                    ? '«تلاش دوباره» بزنید. اگر تکرار شد، همین صفحه پلن را در «پلن‌های پرخطا» بالا می‌آورد.'
                    : '«تلاش دوباره» بزنید؛ وارسیِ خودکار (provision:verify-failed) هم اگر حساب واقعاً ساخته شده باشد خودش تحویل می‌کند.'];
        }

        if ($s->provision_status === 'manual' && ! $isCloud) {
            return ['این نوع سرویس تحویلِ خودکار ندارد',
                'سرویس را دستی روی زیرساخت آماده کنید، بعد از پروفایلِ مشتری مشخصاتِ ورود را ثبت و وضعیت را «فعال» کنید.'];
        }

        if (! $isCloud && ! $s->server_id) {
            return ['سفارش بدونِ سرورِ تحویل ثبت شده',
                'از همین‌جا (یا پروفایل) سرور را انتخاب و «ساخت روی سرور» بزنید.'];
        }

        if ($s->provision_status === 'running') {
            return ['وسطِ ساخت رها شده (دیپلوی/ری‌استارت)',
                'کرون این ردیف را برنمی‌دارد؛ «تلاش دوباره» تنها درِ خروج است.'];
        }

        if (blank($err)) {
            return ['در صفِ تحویل', 'کرونِ بعدی (هر دقیقه) برمی‌دارد؛ اگر ماند، ضربانِ کرون را بالا ببینید.'];
        }

        return ['خطای ثبت‌شده از زیرساخت', 'متنِ خطا را بخوانید و «تلاش دوباره» بزنید؛ اگر مبهم است /admin/errors را ببینید.'];
    }

    /**
     * سلامتِ کاتالوگ: چیزی که الان می‌فروشیم ولی نمی‌توانیم بسازیم، یا خودکار
     * از فروش برداشته شده و مدیر باید بداند. قاعدهٔ کارفرما: «یا حتماً تحویل
     * شود، یا اصلاً برای فروش موجود نباشد.»
     */
    private function catalogHealth(): array
    {
        $out = ['quarantined' => collect(), 'failing' => collect(), 'noImage' => collect(), 'whmOpen' => null];

        try {
            if (Schema::hasTable('cloud_plans')) {
                $out['quarantined'] = CloudPlan::query()
                    ->where('admin_disabled', true)
                    ->get()
                    ->groupBy('provider')
                    ->map(fn ($rows, $prov) => [
                        'provider' => $prov,
                        'count'    => $rows->count(),
                        'note'     => (string) $rows->firstWhere('admin_note', '!=', null)?->admin_note,
                        'auto'     => $rows->contains(fn ($r) => str_starts_with((string) $r->admin_note,
                            \App\Services\Cloud\CloudProvisioner::QUARANTINE_PREFIX)),
                    ])->values();

                /*
                | پلن‌های پرخطا: ≥۲ شکستِ تحویل در ۱۴ روز. قرنطینهٔ خودکار فقط
                | خطاهای «ساختاریِ حساب» را می‌گیرد؛ پلنی که خودِ زیرساخت دیگر
                | نداردش (کاتالوگِ کهنه) از آن رد می‌شود و هر مشتریِ بعدی همان
                | شکست را می‌خرد — این فهرست همان حفره است، با دکمهٔ بستنِ فروش.
                */
                $counts = Service::query()
                    ->whereNotNull('cloud_plan_id')
                    ->where('provision_status', 'failed')
                    ->where('updated_at', '>=', now()->subDays(14))
                    ->selectRaw('cloud_plan_id, count(*) as n')
                    ->groupBy('cloud_plan_id')
                    ->havingRaw('count(*) >= 2')
                    ->pluck('n', 'cloud_plan_id');

                if ($counts->isNotEmpty()) {
                    $out['failing'] = CloudPlan::whereIn('id', $counts->keys())->get()
                        ->map(fn ($p) => ['plan' => $p, 'fails' => (int) $counts[$p->id]])
                        ->sortByDesc('fails')->values();
                }

                // مکانی که پلنِ فروختنی دارد ولی هیچ سیستم‌عاملی برایش نیست =
                // صفحهٔ خرید عملاً مرده — مشتری نمی‌تواند سفارش را کامل کند.
                if (Schema::hasTable('cloud_images') && Schema::hasTable('cloud_locations')) {
                    foreach (\App\Models\CloudLocation::where('is_active', true)->orderBy('code')->get() as $loc) {
                        $providers = CloudPlan::query()->sellable()
                            ->where('location_code', $loc->code)->pluck('provider')->unique();

                        if ($providers->isEmpty()) {
                            continue;
                        }

                        $imgs = \App\Models\CloudImage::query()->usable()
                            ->whereIn('provider', $providers)->count();

                        if ($imgs === 0) {
                            $out['noImage']->push($loc->code);
                        }
                    }
                }
            }

            if (Schema::hasTable('servers')) {
                $whm = Server::where('type', 'whm')->get();
                $out['whmOpen'] = [
                    'open'  => $whm->filter(fn ($s) => $s->canAcceptNew())->count(),
                    'total' => $whm->count(),
                ];
            }
        } catch (\Throwable $e) {
            \App\Support\ErrorTracker::note('provision', $e, ['area' => 'catalog-health']);
        }

        return $out;
    }
}
