<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| ترجمهٔ ردیف‌های ذخیره‌شدهٔ فارسی برای مشتریانِ en/tr — اصلاحِ داده، نه ساختار.
|
| ═══ چرا (۶ شهریور ۱۴۰۵) ═══
|
| کارفرما: «ریست شد، بازم داره فارسی نشون میده کسر ساعتی ۱ ساعت رو و نامِ
| سرورِ ساعتی رو فارسی میاره.» کدِ تازه فقط ردیف‌های **تازه** را به زبانِ
| مشتری می‌نویسد؛ نامِ سرویس و لاگ‌هایی که پیش از دیپلوی نوشته شده بودند
| متنِ ذخیره‌شده‌اند و با هیچ ریستِ opcache عوض نمی‌شوند. این مهاجرت همان
| ردیف‌های قدیمی را — فقط برای مشتریانِ غیرفارسی — یک‌بار ترجمه می‌کند.
|
| ⚠️ عمداً str_replace/regex روی الگوهای **دقیقِ** کدِ قدیم است (نه ترجمهٔ
| آزاد): متنِ ناشناخته دست‌نخورده می‌مانَد، و اجرای دوباره no-op است چون
| بعد از تعویض دیگر الگوی فارسی وجود ندارد.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('services') || ! Schema::hasTable('customers')) {
            return;
        }

        foreach (['en', 'tr'] as $locale) {
            $ids = DB::table('customers')->where('locale', $locale)->pluck('id');
            if ($ids->isEmpty()) {
                continue;
            }

            $this->fixServices($ids, $locale);

            if (Schema::hasTable('activity_logs')) {
                $this->fixLogs($ids, $locale);
            }
        }
    }

    /** نام و توضیحِ سرویس‌های ابریِ فارسی‌مانده. */
    private function fixServices($customerIds, string $locale): void
    {
        $rows = DB::table('services')
            ->whereIn('customer_id', $customerIds)
            ->where(function ($q) {
                $q->where('name', 'like', 'سرور مجازی %')
                    ->orWhere('description', 'like', '%مشخصات: %');
            })
            ->get(['id', 'name', 'description']);

        foreach ($rows as $row) {
            $name = (string) $row->name;
            if (str_starts_with($name, 'سرور مجازی ')) {
                $label = trim(mb_substr($name, mb_strlen('سرور مجازی ')));
                $hourly = str_ends_with($label, '(ساعتی)');
                if ($hourly) {
                    $label = trim(mb_substr($label, 0, -mb_strlen('(ساعتی)')));
                }
                $name = mb_substr(__(
                    $hourly ? 'ui.svc_name_vps_hourly' : 'ui.svc_name_vps',
                    ['label' => $label],
                    $locale
                ), 0, 150);
            }

            DB::table('services')->where('id', $row->id)->update([
                'name'        => $name,
                'description' => $this->translateDescription((string) $row->description, $locale),
            ]);
        }
    }

    /**
     * توضیحِ سرویس سطربه‌سطر ترجمه می‌شود: فقط پیشوندهای ثابتِ کدِ قدیم
     * عوض می‌شوند، مقدارها (عدد، نامِ ایمیج، برچسبِ SSH) سرِ جایشان می‌مانند.
     */
    private function translateDescription(string $text, string $locale): string
    {
        if ($text === '' || ! str_contains($text, 'مشخصات: ')) {
            return $text;
        }

        // پیشوندِ هر سطر → کلیدِ ترجمه (همان کلیدهایی که کدِ تازه می‌نویسد)
        $lines = [];
        foreach (preg_split('/\r?\n/', $text) as $line) {
            $lines[] = match (true) {
                str_starts_with($line, 'مشخصات: ') => $this->specsLine($line, $locale),
                str_starts_with($line, 'مکان: ') => __('ui.svd_loc', ['loc' => mb_substr($line, mb_strlen('مکان: '))], $locale),
                str_starts_with($line, 'سیستم‌عامل: ') => __('ui.svd_os', ['os' => mb_substr($line, mb_strlen('سیستم‌عامل: '))], $locale),
                str_starts_with($line, 'نامِ سرور: ') => __('ui.svd_srvname', ['name' => mb_substr($line, mb_strlen('نامِ سرور: '))], $locale),
                str_starts_with($line, 'IP اضافه: ') => __('ui.svd_extra_ip', ['n' => trim(str_replace(' عدد', '', mb_substr($line, mb_strlen('IP اضافه: '))))], $locale),
                str_starts_with($line, 'ورود با کلیدِ SSH: ') => __('ui.svd_ssh', ['key' => mb_substr($line, mb_strlen('ورود با کلیدِ SSH: '))], $locale),
                default => $line,
            };
        }

        return implode("\n", $lines);
    }

    /** «مشخصات: ۲ هسته · 4 GB رم · 40 GB NVME · ترافیک …» → سطرِ svd_specs. */
    private function specsLine(string $line, string $locale): string
    {
        $body = mb_substr($line, mb_strlen('مشخصات: '));
        $parts = array_map('trim', explode('·', $body));
        if (count($parts) < 4) {
            return $line; // شکلِ ناشناخته — دست نزن
        }

        return __('ui.svd_specs', [
            'core'    => trim(str_replace('هسته', '', $parts[0])),
            'ram'     => trim(str_replace('رم', '', $parts[1])),
            'disk'    => $parts[2],
            'traffic' => trim(mb_substr($parts[3], mb_strlen('ترافیک '))),
        ], $locale);
    }

    /** لاگ‌های ساعتیِ فارسی‌مانده — تاریخچه بازنویسی نمی‌شود، فقط ترجمه. */
    private function fixLogs($customerIds, string $locale): void
    {
        $map = [
            'اتمامِ اعتبارِ ساعتی → تعلیق'          => __('ui.act_hourly_suspend', [], $locale),
            'شارژِ مجدد → روشن‌شدنِ سرورِ ساعتی' => __('ui.act_hourly_resume', [], $locale),
            // act_hourly_convert جای‌نگهدارِ :amount دارد که ردیفِ قدیمی ندارد — متنِ بی‌متغیر:
            'تبدیلِ ساعتی → ماهانه (اتمامِ اعتبار)' => $locale === 'tr'
                ? 'Saatlikten aylige gecildi (bakiye bitti)'
                : 'Converted hourly to monthly (credit ran out)',
        ];

        foreach ($map as $old => $new) {
            if (! is_string($new) || $new === '' || str_starts_with($new, 'ui.')) {
                continue; // کلیدِ ترجمه‌نشده — چیزی خراب نکن
            }
            DB::table('activity_logs')->whereIn('customer_id', $customerIds)
                ->where('description', $old)->update(['description' => $new]);
        }

        // «کسرِ ساعتی: N ساعت» — قالبِ قدیم مبلغ و مانده نداشت؛ همان دو عدد ترجمه می‌شود.
        $rows = DB::table('activity_logs')->whereIn('customer_id', $customerIds)
            ->where('description', 'like', 'کسرِ ساعتی: %')
            ->get(['id', 'description']);
        foreach ($rows as $row) {
            if (preg_match('/^کسرِ ساعتی: (\d+) ساعت$/u', (string) $row->description, $m) !== 1) {
                continue;
            }
            $new = $locale === 'tr' ? "Saatlik kesinti: {$m[1]} saat" : "Hourly charge: {$m[1]} h";
            DB::table('activity_logs')->where('id', $row->id)->update(['description' => $new]);
        }

        // «سفارشِ سرورِ ساعتی «label» — بقیه» — پیشوند ترجمه، دنباله (پلن · مکان · نرخ) می‌مانَد.
        $rows = DB::table('activity_logs')->whereIn('customer_id', $customerIds)
            ->where('description', 'like', 'سفارشِ سرورِ ساعتی %')
            ->get(['id', 'description']);
        foreach ($rows as $row) {
            $rest = mb_substr((string) $row->description, mb_strlen('سفارشِ سرورِ ساعتی '));
            $prefix = $locale === 'tr' ? 'Saatlik sunucu siparisi ' : 'Hourly server ordered ';
            DB::table('activity_logs')->where('id', $row->id)->update(['description' => $prefix.$rest]);
        }
    }

    public function down(): void
    {
        // اصلاحِ داده است؛ برگشتی ندارد.
    }
};
