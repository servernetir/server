<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| ترجمهٔ لاگ‌های فعالیتِ فارسیِ ازپیش‌ذخیره‌شده برای مشتریانِ en/tr — دورِ دوم.
|
| ═══ چرا (۶ شهریور ۱۴۰۵) ═══
|
| مهاجرتِ 000101 فقط لاگ‌های ساعتی را ترجمه کرد؛ کارفرما دید بقیهٔ ردیف‌های
| «فعالیت حساب» (ورود، رمز، پرداخت، احراز، تحویل، …) هنوز فارسی‌اند. همان
| نویسنده‌ها حالا با کلیدهای ui.act_* به زبانِ مشتری می‌نویسند؛ این‌جا
| ردیف‌های قدیمی با **الگوی دقیقِ کدِ قدیم** ترجمه می‌شوند. متنِ ناشناخته
| دست نمی‌خورد و اجرای دوباره no-op است.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('activity_logs') || ! Schema::hasTable('customers')) {
            return;
        }

        foreach (['en', 'tr'] as $locale) {
            $ids = DB::table('customers')->where('locale', $locale)->pluck('id');
            if ($ids->isEmpty()) {
                continue;
            }

            DB::table('activity_logs')->whereIn('customer_id', $ids)
                ->orderBy('id')
                ->chunkById(200, function ($rows) use ($locale) {
                    foreach ($rows as $row) {
                        $new = $this->translate((string) $row->description, $locale);
                        if ($new !== null && $new !== $row->description) {
                            DB::table('activity_logs')->where('id', $row->id)
                                ->update(['description' => mb_substr($new, 0, 400)]);
                        }
                    }
                });
        }
    }

    /** ترجمهٔ یک description؛ null یعنی الگویی نشناختیم — دست نزن. */
    private function translate(string $d, string $loc): ?string
    {
        // بدونِ حرفِ فارسی؟ کاری نیست.
        if (preg_match('/[\x{0600}-\x{06FF}]/u', $d) !== 1) {
            return null;
        }

        $t = fn (string $key, array $vars = []) => __($key, $vars, $loc);

        // ── نگاشتِ متن‌های ثابت ──
        $exact = [
            'ورود موفق با ایمیل'  => $t('ui.act_login', ['channel' => $t('ui.act_ch_email')]),
            'ورود موفق با موبایل' => $t('ui.act_login', ['channel' => $t('ui.act_ch_mobile')]),
            'رمز عبور توسط خودِ کاربر تنظیم شد' => $t('ui.act_pw_self'),
            'رمز عبور توسط پشتیبانی تغییر کرد'  => $t('ui.act_pw_staff'),
            'درخواستِ تأییدِ هویت ثبت شد'         => $t('ui.act_kyc_req'),
            'درخواستِ تأییدِ هویتِ حقوقی ثبت شد' => $t('ui.act_kyc_req_co'),
            'رفعِ تعلیقِ خودکار — فاکتورِ تمدید پرداخت شد' => $t('ui.act_auto_unsuspend'),
            'سفارشِ سرور ثبت شد؛ ایمیلِ تحویل تا رسیدنِ IP نگه داشته شد.' => $t('ui.act_prov_ordered'),
            'لغوِ سفارشِ تحویل‌نشده توسط مشتری' => $t('ui.act_svc_cancel'),
            'حذفِ سرویس به‌خواستِ مشتری با تأییدِ کدِ یک‌بارمصرف' => $t('ui.act_svc_delete'),
        ];

        if (isset($exact[$d])) {
            return $exact[$d];
        }

        // ── الگوهای پارامتردار ──
        $rx = [
            ['/^هویت تأیید شد: (.*)$/su',  fn ($m) => $t('ui.act_kyc_ok', ['who' => $m[1]])],
            ['/^هویت رد شد: (.*)$/su',     fn ($m) => $t('ui.act_kyc_no', ['reason' => $m[1]])],
            ['/^پرداخت ([\d,٬.]+) تومان از طریق (.*) انجام شد$/u',
                fn ($m) => $t('ui.act_payment', ['amount' => $m[1].' Toman', 'gw' => $m[2]])],
            ['/^تعلیقِ خودکار — فاکتورِ سررسیدشده \((\d+) روز\) پرداخت نشد$/u',
                fn ($m) => $t('ui.act_auto_suspend', ['days' => $m[1]])],
            ['/^بازگشتِ خودکارِ وجه به کیفِ پول — تحویل انجام نشده بود \((.+)\)$/u',
                fn ($m) => $t('ui.act_auto_refund', ['amount' => $m[1]])],
            ['/^تحویلِ سرورِ ابری ناموفق: (.*)$/su', fn ($m) => $t('ui.act_prov_failed', ['reason' => $m[1]])],
            ['/^سفارش به صفِ بازبینیِ دستی رفت: (.*)$/su', fn ($m) => $t('ui.act_prov_manual', ['reason' => $m[1]])],
            ['/^ایمیلِ تحویلِ سرور فرستاده شد \(IP: (.*)\)\.$/u', fn ($m) => $t('ui.act_prov_mail', ['ip' => $m[1]])],
            ['/^رسید واریز برای فاکتور (.+) با شناسهٔ (.+) ثبت شد$/u',
                fn ($m) => $t('ui.act_bank_receipt', ['n' => $m[1], 'ref' => $m[2]])],
            ['/^سفارشِ آنلاینِ پکیج «(.+)» \((.+)\) — (.+) توسط مشتری ثبت شد$/u',
                fn ($m) => $t('ui.act_order_pkg', ['name' => $m[1], 'domain' => $m[2], 'tail' => $m[3]])],
            ['/^سفارشِ آنلاینِ لایسنس «(.+)» \(IP: (.+)\) — (.+) توسط مشتری ثبت شد$/u',
                fn ($m) => $t('ui.act_order_lic', ['name' => $m[1], 'ip' => $m[2], 'cycle' => $m[3]])],
            ['/^سفارشِ سایت‌ساز «(.+)» \((.+)\) — مرجعِ (.+)$/u',
                fn ($m) => $t('ui.act_order_builder', ['name' => $m[1], 'domain' => $m[2], 'ref' => $m[3]])],
            ['/^لغوِ سفارشِ تحویل‌نشده توسط مشتری — بازگشتِ (.+) به کیفِ پول$/u',
                fn ($m) => $t('ui.act_svc_cancel').$t('ui.act_refund_suffix', ['amount' => $m[1]])],
            ['/^حذفِ سرویس به‌خواستِ مشتری با تأییدِ کدِ یک‌بارمصرف — دلیل: (.*)$/su',
                fn ($m) => $t('ui.act_svc_delete').$t('ui.act_reason', ['why' => $m[1]])],
            ['/^سرورِ ابری #(\d+) — (.*)$/su',
                fn ($m) => $t('ui.act_cloud_evt', ['id' => $m[1], 'text' => $this->cloudInner($m[2], $loc)])],
        ];

        foreach ($rx as [$pattern, $make]) {
            if (preg_match($pattern, $d, $m) === 1) {
                return $make($m);
            }
        }

        return null;
    }

    /** متنِ داخلِ «سرورِ ابری #N — …» — اسلاگ‌ها (power:on) دست‌نخورده می‌مانند. */
    private function cloudInner(string $text, string $loc): string
    {
        $t = fn (string $key, array $vars = []) => __($key, $vars, $loc);

        $exact = [
            'رمزِ root تازه شد.'      => $t('ui.act_cloud_rootpw'),
            'کنسولِ تحتِ وب باز شد.' => $t('ui.act_cloud_console'),
            'ایجنتِ روتر ثبت شد'      => $t('ui.act_cloud_agent_new'),
            'توکنِ ایجنتِ روتر دوباره صادر شد' => $t('ui.act_cloud_agent_re'),
        ];

        if (isset($exact[$text])) {
            return $exact[$text];
        }

        $rx = [
            ['/^کشورِ خروج → (.*)$/su', fn ($m) => $t('ui.act_cloud_exit', ['label' => $m[1]])],
            ['/^نصبِ دوبارهٔ سیستم‌عامل: (.*)$/su', fn ($m) => $t('ui.act_cloud_reinstall', ['image' => $m[1]])],
            ['/^اکانتِ تونل «(.+)» \((.+)\) صادر شد$/u', fn ($m) => $t('ui.act_cloud_tun_new', ['name' => $m[1], 'ip' => $m[2]])],
            ['/^اکانتِ تونل «(.+)» حذف شد$/u', fn ($m) => $t('ui.act_cloud_tun_del', ['name' => $m[1]])],
        ];

        foreach ($rx as [$pattern, $make]) {
            if (preg_match($pattern, $text, $m) === 1) {
                return $make($m);
            }
        }

        return $text;
    }

    public function down(): void
    {
        // اصلاحِ داده است؛ برگشتی ندارد.
    }
};
