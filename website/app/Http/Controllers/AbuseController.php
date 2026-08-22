<?php

namespace App\Http\Controllers;

use App\Support\ErrorTracker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * کانالِ ورودیِ گزارشِ سوءاستفاده — ممیزی ۴ (امنیت):
 * «AUP ضمانتِ اجرا دارد ولی هیچ کانالِ ورودی‌ای تأیید نشده. سیاستی که راهِ
 * گزارش ندارد اجرا نمی‌شود؛ فقط مسئولیت ایجاد می‌کند.»
 *
 * ═══ طراحی ═══
 *
 * · **بدونِ جدولِ تازه.** گزارش‌ها JSONLِ ماهانه در storage می‌نشینند +
 *   `noteOnce` تا در /admin/errors دیده شوند — همان‌جایی که مدیر واقعاً نگاه
 *   می‌کند — + اعلانِ همان وب‌هوکی که فرمِ استخدام استفاده می‌کند. مهاجرتِ
 *   دیتابیس یعنی یک قدمِ دیپلویِ اضافه و یک وابستگیِ اضافه برای فرمی که باید
 *   در بدترین روزِ شبکه هم کار کند.
 * · تعهدِ زمانِ پاسخ (۲ روزِ کاری) روی خودِ صفحه اعلام می‌شود — تعهدِ
 *   بی‌اعلام از نظرِ ممیزی وجود ندارد.
 */
class AbuseController extends Controller
{
    public function show(): View
    {
        return view('pages.abuse');
    }

    public function report(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'target'  => 'required|string|max:200',
            'body'    => 'required|string|min:20|max:4000',
            'email'   => 'nullable|email|max:120',
            'website' => 'nullable|max:0', // honeypot — ربات پرش می‌کند، آدم نمی‌بیندش
        ], ['website.max' => 'spam']);

        // محدودیتِ نرخ ساده به ازای IP — الگوی فرمِ استخدام
        $rk = 'abuse.report.'.md5((string) $request->ip());

        if ((int) Cache::get($rk, 0) >= 4) {
            return back()->with('abuse_status', 'busy');
        }

        Cache::put($rk, (int) Cache::get($rk, 0) + 1, 1800);

        $id = substr(md5(uniqid('', true)), 0, 10);

        $row = [
            't'      => date('c'),
            'id'     => $id,
            'target' => $data['target'],
            'body'   => $data['body'],
            'email'  => $data['email'] ?? '',
            'ip'     => (string) $request->ip(),
        ];

        try {
            $dir = storage_path('app/abuse');

            if (! is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            @file_put_contents(
                $dir.'/reports-'.date('Y-m').'.jsonl',
                json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
                FILE_APPEND | LOCK_EX
            );
        } catch (\Throwable) {
            // ثبتِ فایل نباید فرم را بیندازد؛ noteOnce پایین همچنان خبر می‌دهد
        }

        /*
        | شناسه داخلِ متن است تا کلیدِ گلوگاهِ noteOnce (md5ِ متن) برای هر
        | گزارش یکتا شود و گزارشِ دوم پشتِ گلوگاهِ اولی ساکت نماند — همان
        | قاعدهٔ ثبت‌شدهٔ تحویلِ نمایندگی.
        */
        ErrorTracker::noteOnce('abuse', "گزارش سوءاستفادهٔ تازه #{$id} — هدف: {$data['target']} (storage/app/abuse)", 21600, [
            'id' => $id,
        ]);

        $this->notify($row);

        return back()->with('abuse_status', 'ok');
    }

    /** اعلانِ لحظه‌ای به همان وب‌هوکِ چتِ داخلی که فرمِ استخدام استفاده می‌کند. */
    private function notify(array $row): void
    {
        if (! $webhook = config('services.n8n.chat_webhook')) {
            return;
        }

        $body = "گزارش سوءاستفاده #{$row['id']}:\n"
            ."هدف: {$row['target']}\n"
            ."ایمیل گزارش‌دهنده: ".($row['email'] ?: '—')."\n"
            ."شرح: ".mb_substr($row['body'], 0, 500);

        $ch = curl_init($webhook);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode(['message' => $body, 'session' => 'abuse-'.$row['id']], JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}
