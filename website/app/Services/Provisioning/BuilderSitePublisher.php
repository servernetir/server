<?php

namespace App\Services\Provisioning;

use App\Models\Server;
use App\Models\Service;
use App\Support\ErrorTracker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * انتشارِ خودکارِ سایتِ ساخته‌شده با سایت‌ساز روی هاستِ تازه‌تحویل‌شده.
 *
 * زنجیره: کاربر در سایت‌ساز «استقرار» می‌زند → save() کدِ HTML را با یک مرجعِ
 * SB-XXXXXX نگه می‌دارد → تسویهٔ builder سرویس + دامنه را می‌سازد و مرجع را در
 * provision_meta['builder_ref'] قفل می‌کند → پس از پرداخت، provision:run هاست
 * را می‌سازد → این کلاس همان لحظه index.html را در public_html اکانت می‌نویسد.
 *
 * ⚠️ **هرگز تحویل را شکست نمی‌دهد.** حساب ساخته شده و رمز رفته؛ اگر نوشتنِ
 * فایل نشد، سرویس باید active بماند و فقط فریادِ ماشین‌خوان ثبت شود — همان
 * قاعدهٔ pointFreeSubdomain. تلاشِ دوباره امن است چون publish idempotent است
 * (بازنویسیِ همان فایل).
 */
class BuilderSitePublisher
{
    /** مسیرِ فایلِ ذخیره‌شدهٔ یک مرجع در دیسکِ local */
    public static function path(string $ref): string
    {
        return 'builder-sites/'.$ref.'.html';
    }

    /** HTML ذخیره‌شدهٔ یک مرجع — اول دیسک (پایدار)، بعد کش (سازگاری با گذشته) */
    public static function htmlFor(string $ref): ?string
    {
        $ref = strtoupper(preg_replace('~[^A-Za-z0-9-]~', '', $ref) ?? '');

        if ($ref === '') {
            return null;
        }

        if (Storage::disk('local')->exists(self::path($ref))) {
            $html = (string) Storage::disk('local')->get(self::path($ref));

            return trim($html) !== '' ? $html : null;
        }

        $cached = Cache::get('builder_deploy:'.$ref);

        return is_array($cached) && filled($cached['html'] ?? null) ? (string) $cached['html'] : null;
    }

    /**
     * اگر این سرویس سفارشِ سایت‌ساز است، سایت را روی اکانتش بنویس.
     *
     * خروجی فقط برای لاگ/تست است؛ فراخوان نباید به آن شرط بزند.
     *
     * @return array{ok:bool,skipped:bool,reason:string}
     */
    public function publish(Service $service, Server $server): array
    {
        $ref = (string) ($service->provision_meta['builder_ref'] ?? '');

        if ($ref === '') {
            return ['ok' => true, 'skipped' => true, 'reason' => 'not a builder order'];
        }

        if (filled($service->provision_meta['builder_published_at'] ?? null)) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'already published'];
        }

        $user = (string) $service->username;

        if ($user === '' || $server->type !== 'whm') {
            return $this->fail($service, 'اکانت/سرورِ WHM برای انتشارِ سایتِ ساخته‌شده در دسترس نیست');
        }

        $html = self::htmlFor($ref);

        if ($html === null) {
            // ۷ روز کش تمام شده و فایل هم نیست — مدیر باید از مشتری بخواهد
            // دوباره خروجی بگیرد؛ ولی هاستش سالم تحویل شده است.
            return $this->fail($service, 'کدِ سایتِ ساخته‌شده ('.$ref.') پیدا نشد — کش منقضی شده؟');
        }

        $res = (new WhmClient($server))->uapiAs($user, 'Fileman', 'save_file_content', [
            'dir'     => 'public_html',
            'file'    => 'index.html',
            'content' => $html,
            'charset' => 'utf-8',
        ]);

        if (! $res['ok']) {
            return $this->fail($service, 'نوشتنِ index.html شکست خورد: '.$res['reason']);
        }

        // مهرِ موفقیت — تا تلاشِ دوباره (retry تحویل) دوباره ننویسد
        $service->forceFill([
            'provision_meta' => array_merge((array) $service->provision_meta, [
                'builder_published_at' => now()->toIso8601String(),
            ]),
        ])->save();

        return ['ok' => true, 'skipped' => false, 'reason' => 'published'];
    }

    /** @return array{ok:bool,skipped:bool,reason:string} */
    private function fail(Service $service, string $reason): array
    {
        // شناسهٔ سرویس داخلِ متن: کلیدِ گلوگاهِ noteOnce از md5 همین متن ساخته
        // می‌شود؛ بی‌آن، سفارشِ دوم پشتِ گلوگاهِ اولی ساکت می‌مانَد.
        ErrorTracker::noteOnce('provision',
            'سایت‌ساز: انتشارِ خودکارِ سایت برای سرویسِ #'.$service->id.' انجام نشد — '.$reason);

        $service->forceFill([
            'provision_meta' => array_merge((array) $service->provision_meta, [
                'builder_publish_error' => mb_substr($reason, 0, 300),
            ]),
        ])->save();

        return ['ok' => false, 'skipped' => false, 'reason' => $reason];
    }
}
