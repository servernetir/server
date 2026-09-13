<?php

namespace App\Http\Controllers;

use App\Models\CloudPlan;
use App\Services\Cloud\SaladClient;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * صفحهٔ فرودِ «سرور گرافیکی (GPU)» — /gpu (fa / en / tr).
 *
 * ═══ چرا زیرِ `/vps/` نیست ═══
 *
 * دو دلیلِ ساختاری، نه سلیقه:
 *
 * ۱) `/vps` خودش ۳۰۱ به `/cloud` می‌خورد. بچه‌ای زیرِ والدی که ریدایرکت می‌شود
 *    از نظرِ ساختار و کنونیکال ناهمگون است.
 *
 * ۲) 🔴 مهم‌تر: هر صفحهٔ `/vps/*` وعدهٔ «ماشینِ مجازیِ پایدار با root و
 *    سیستم‌عامل» می‌دهد. این محصول **هیچ‌کدام** را ندارد و قطع هم می‌شود.
 *    گذاشتنش زیرِ VPS همان اشتباهِ ثبت‌شدهٔ پکیجِ نمایندگی است که ماه‌ها «پنل»
 *    می‌فروخت و cPanelِ ساده تحویل می‌داد: تحویل «موفق» ثبت می‌شد و تنها راهِ
 *    فهمیدنش شکایتِ خودِ مشتری بود.
 *
 * ═══ قاعده‌ها ═══
 *
 * ۱) **هیچ عددی این‌جا ساخته نمی‌شود.** نرخِ ساعتی از همان `CloudPlan::hourlyIrt()`
 *    می‌آید که فروشگاه و مترِ ساعتی از آن می‌خوانند. عددِ سخت‌کد = قیمتِ دروغ.
 *
 * ۲) **کاتالوگِ خالی خطا نیست.** پیش از همگام‌سازی یا روی سرورِ مهاجرت‌نخورده،
 *    صفحه با متنِ توضیحی بالا می‌آید نه ۵۰۰.
 *
 * ۳) **سفیدبرچسبی.** نامِ زیرساخت به ویو نمی‌رسد؛ فقط نامِ خودِ کارت («RTX 4090»)
 *    که مشخصهٔ محصول است نه نامِ تأمین‌کننده.
 *
 * ۴) 🔴 **«تعداد» یعنی چند ماشینِ جدا، نه چند کارت در یک ماشین.**
 *    در اسپکِ زیرساخت `replicas` (۰..۵۰۰) نمونه‌های **مستقل** می‌سازد، و
 *    `GpuClass.gpu_count` تعدادِ کارتِ داخلِ خودِ آن کلاس است که **ثابت** است و
 *    انتخابی نیست. اگر رابط بنویسد «۴ عدد RTX 4090» و مشتری انتظارِ یک باکسِ
 *    چهارکارته داشته باشد، SSH که زد یکی می‌بیند — و ما هیچ خطایی نمی‌بینیم.
 *    پس برچسب‌ها صریح‌اند و `gpu_count` جدا از تعدادِ نمونه نشان داده می‌شود.
 */
class GpuController extends Controller
{
    /** سقفِ نمونه در پیکربند — سقفِ خودِ زیرساخت ۵۰۰ است، ولی این‌جا فروشِ خرد است */
    public const MAX_UNITS = 8;

    public function show(): View
    {
        $locale = app()->getLocale();
        $isFa = $locale === 'fa';

        $cards = [];
        $fromHourlyRaw = 0;
        $anyInterruptible = false;

        if (Schema::hasTable('cloud_plans') && Schema::hasColumn('cloud_plans', 'gpu_model')) {
            /*
            | ⚠️ از `offers()` می‌خوانیم نه پرس‌وجوی دستی: همان گروه‌بندی و همان
            | «ارزان‌ترینِ فروختنی» که فروشگاه می‌بیند. پرس‌وجوی موازی یعنی روزی
            | صفحه چیزی نشان دهد که سبدِ خرید نمی‌فروشد.
            |
            | ⚠️ و فیلترِ GPU روی `gpu_model` است نه روی نامِ زیرساخت: اگر فردا
            | زیرساختِ دومی هم کارت بفروشد، همین صفحه بی‌تغییر نشانش می‌دهد.
            */
            foreach (CloudPlan::offers() as $plan) {
                if (blank($plan->gpu_model)) {
                    continue;
                }

                $hourly = $plan->hourlyIrt();

                if ($hourly <= 0) {
                    continue;
                }

                $count = max(1, (int) ($plan->gpu_count ?: 1));

                $cards[] = [
                    'slug'        => $plan->slug,
                    'gpu'         => $plan->gpu_model,
                    'gpu_count'   => $count,
                    'vcpu'        => (int) $plan->vcpu,
                    'ram_gb'      => (int) round($plan->ram_mb / 1024),
                    'disk_gb'     => (int) $plan->disk_gb,
                    'hourly_raw'  => $hourly,
                    'hourly'      => cloud_price($hourly),
                    'interruptible' => (bool) $plan->is_interruptible,
                ];

                if ($plan->is_interruptible) {
                    $anyInterruptible = true;
                }

                if ($fromHourlyRaw === 0 || $hourly < $fromHourlyRaw) {
                    $fromHourlyRaw = $hourly;
                }
            }
        }

        // ارزان‌ترین کارت اول — همان ترتیبی که مشتری انتظار دارد
        usort($cards, fn ($a, $b) => $a['hourly_raw'] <=> $b['hourly_raw']);

        /*
        | دو کلاسِ GPU با نامِ یکسان و مشخصات و قیمتِ نمایشیِ یکسان (نمونهٔ
        | واقعیِ کاتالوگ: «RTX PRO 6000 Blackwell» در دو نسخهٔ زیرساختی) دو
        | کارتِ بایت‌به‌بایت تکراری روی صفحهٔ فروش می‌ساختند — برای مشتری فقط
        | گیج‌کننده است. کلیدِ یکتاسازی دقیقاً چیزهایی است که مشتری می‌بیند؛
        | اگر هر کدام فرق کند (حتی قیمت)، هر دو کارت می‌مانند — همان قاعدهٔ
        | ثبت‌شدهٔ «دو ردیف فقط وقتی یکی می‌شوند که در دیدِ مشتری یکی باشند».
        | چون فهرست از قبل ارزان‌مرتب است، نمایندهٔ باقی‌مانده ارزان‌ترین است.
        */
        $seen = [];
        $cards = array_values(array_filter($cards, function (array $c) use (&$seen): bool {
            $key = $c['gpu'].'|'.$c['gpu_count'].'|'.$c['vcpu'].'|'.$c['ram_gb'].'|'.$c['disk_gb'].'|'.$c['hourly_raw'];

            if (isset($seen[$key])) {
                return false;
            }

            $seen[$key] = true;

            return true;
        }));

        return view('pages.gpu', [
            'isFa'      => $isFa,
            'cards'     => $cards,
            'maxUnits'  => self::MAX_UNITS,
            'fromHourly' => $fromHourlyRaw > 0 ? cloud_price($fromHourlyRaw) : null,
            /*
            | 🔴 این پرچم تزئینی نیست: تا وقتی حتی یک کارتِ قطع‌شدنی در فهرست
            | باشد، نوارِ هشدار روی صفحه می‌مانَد. زیرساختِ امروز **همه‌اش**
            | قطع‌شدنی است، ولی شرط را روی داده گذاشتیم نه روی فرض تا اگر روزی
            | کارتِ پایدار اضافه شد، متن خودبه‌خود درست بمانَد.
            */
            'interruptible' => $anyInterruptible,
            // کفِ اعتبارِ شروع از همان ثابتی که فروشگاه می‌خوانَد
            'minHours'  => CloudPlan::HOURLY_START_MIN_HOURS,
            'priority'  => app(SaladClient::class)->isConfigured(),
        ]);
    }
}
