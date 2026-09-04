<?php

namespace App\Services\Provisioning;

use App\Models\Server;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;

/**
 * کلاینتِ Storage Box هتزنر.
 *
 * 🔴 **میزبان با APIِ ابریِ هتزنر فرق دارد.** سرورِ مجازی روی
 * `api.hetzner.cloud/v1` است و Storage Box روی `api.hetzner.com/v1` —
 * دامنهٔ متفاوت، نه مسیرِ متفاوت. اگر کسی روزی این را با `HetznerClient`
 * یکی کند، هر تماس ۴۰۴ می‌گیرد و چون بدنه‌اش JSON است شبیهِ «توکن غلط»
 * به‌نظر می‌رسد نه «آدرس غلط».
 *
 * مسیرها و نامِ فیلدها از اسپکِ رسمیِ OpenAPI برداشته شده‌اند
 * (`https://docs.hetzner.cloud/hetzner.spec.json`)، نه از حدس. تلهٔ
 * «سفارشِ زیرساختِ ۲» در CLAUDE.md دقیقاً از حدس‌زدنِ شکلِ بدنه آمد.
 *
 * احراز: `Authorization: Bearer <token>`
 * خطا: بدنهٔ `{"error":{"code":…,"message":…}}` با کدِ HTTPِ 4xx/5xx.
 */
class HetznerStorageClient
{
    private const BASE = 'https://api.hetzner.com/v1';

    /** ساختِ باکس گاهی کند است؛ خواندن نباید این‌قدر صبر کند. */
    private const TIMEOUT_READ = 20;

    private const TIMEOUT_WRITE = 60;

    public function __construct(private Server $server) {}

    /**
     * توکنِ API.
     *
     * ✅ **همان توکنِ سرورِ ابری کار می‌کند.** اسپکِ رسمی می‌گوید توکن را از
     * «Hetzner Console → Project → Security → API Tokens» بسازید — دقیقاً همان
     * جایی که توکنِ `hetzner_api_token` در `/admin/settings` از آن آمده. دو
     * میزبانِ متفاوت (`api.hetzner.com` و `api.hetzner.cloud`)، ولی یک توکنِ
     * **پروژه‌ای**.
     *
     * پس اگر ردیفِ سرور توکن نداشته باشد، از تنظیمات خوانده می‌شود. عمداً
     * این‌طور است: نگه‌داشتنِ دو نسخه از یک رازِ واحد یعنی روزی یکی چرخانده
     * می‌شود و دیگری کهنه می‌مانَد — و خطایش «توکن نامعتبر» است که آدم را
     * دنبالِ هتزنر می‌فرستد، نه دنبالِ نسخهٔ دومِ فراموش‌شده.
     *
     * ⚠️ توکن باید **Read & Write** باشد. توکنِ فقط‌خواندنی کاتالوگ را می‌دهد
     * ولی ساختِ باکس را رد می‌کند — یعنی فروش انجام می‌شود و تحویل نه.
     */
    private function token(): string
    {
        $own = (string) ($this->server->api_token ?? '');

        if ($own !== '') {
            return $own;
        }

        try {
            return (string) (Setting::getSecret('hetzner_api_token') ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    /** توکنی در دسترس هست؟ (ردیفِ سرور یا تنظیماتِ سرورِ ابری) */
    public function isConfigured(): bool
    {
        return $this->token() !== '';
    }

    /**
     * یک تماس با API.
     *
     * خروجی همیشه آرایه است و هرگز استثنا پرتاب نمی‌شود — مثلِ بقیهٔ
     * درایورهای این پروژه.
     *
     * 🔴 پرچمِ `transport` عمداً هست و معنایش «نشنیدیم» است، نه «نه گفت».
     * بی‌آن، یک تایم‌اوت در لحظهٔ ساخت یعنی باکسی که واقعاً ساخته شده و
     * پولش رفته، ولی ما به مشتری «تحویل ناموفق» می‌گوییم — همان رخدادِ
     * zhina.shop در §۱۰ که برای WHM بسته شد.
     */
    public function call(string $method, string $path, array $payload = [], array $query = []): array
    {
        $url = self::BASE.$path;

        /*
        | بی‌توکن اصلاً تماس نمی‌گیریم: هتزنر ۴۰۱ می‌دهد و پیامش «unauthorized»
        | است، که آدم را دنبالِ توکنِ باطل می‌فرستد نه دنبالِ توکنِ **نبود**.
        */
        if (! $this->isConfigured()) {
            return [
                'ok' => false, 'transport' => false, 'status' => 0,
                'reason' => 'توکنِ API هتزنر ثبت نشده — نه روی این سرور، نه در تنظیمات.',
                'data' => [],
            ];
        }

        try {
            $req = Http::acceptJson()
                ->connectTimeout(10)
                ->timeout($method === 'get' ? self::TIMEOUT_READ : self::TIMEOUT_WRITE)
                // یک تلاش، صفر تکرار: ساختِ باکس پول خرج می‌کند و تلاشِ
                // دوباره ممکن است باکسِ دوم بسازد. همان قاعدهٔ WhmClient.
                ->retry(1, 500, throw: false)
                ->withToken($this->token());

            $resp = match ($method) {
                'get'    => $req->get($url, $query),
                'post'   => $req->post($url, $payload),
                'put'    => $req->put($url, $payload),
                'delete' => $req->delete($url),
                default  => $req->get($url, $query),
            };
        } catch (\Throwable $e) {
            return [
                'ok' => false, 'transport' => true, 'status' => 0,
                'reason' => 'ارتباط با هتزنر برقرار نشد: '.mb_substr($e->getMessage(), 0, 160),
                'data' => [],
            ];
        }

        $json = $resp->json();

        /*
        | ⚠️ بدنهٔ نامعتبر عمداً `transport` نیست.
        |
        | توکنِ باطل، سهمیهٔ تمام‌شده و صفحهٔ خطای گیت‌وی همگی بدنهٔ غیرِ JSON
        | می‌دهند. اینها خرابیِ **پایدار**ند نه سکسکهٔ گذرا؛ اگر «نمی‌دانم»
        | بخوانیمشان سرویس در حالتِ ساکتِ دستی می‌نشیند و کسی خبردار نمی‌شود.
        */
        if (! is_array($json)) {
            return [
                'ok' => false, 'transport' => false, 'status' => $resp->status(),
                'reason' => 'پاسخِ نامعتبر از هتزنر (HTTP '.$resp->status().')',
                'data' => [],
            ];
        }

        if (isset($json['error'])) {
            return [
                'ok' => false, 'transport' => false, 'status' => $resp->status(),
                'code' => (string) ($json['error']['code'] ?? ''),
                'reason' => (string) ($json['error']['message'] ?? 'خطای ناشناخته'),
                'data' => $json,
            ];
        }

        if (! $resp->successful()) {
            return [
                'ok' => false, 'transport' => false, 'status' => $resp->status(),
                'reason' => 'هتزنر کدِ '.$resp->status().' برگرداند.',
                'data' => $json,
            ];
        }

        return ['ok' => true, 'transport' => false, 'status' => $resp->status(), 'reason' => '', 'data' => $json];
    }

    /** کاتالوگِ نوع‌ها — اندازه، سقفِ زیرحساب و قیمتِ هر مکان */
    public function types(): array
    {
        return $this->call('get', '/storage_box_types', [], ['per_page' => 50]);
    }

    /** فهرستِ باکس‌ها؛ با `name` فیلتر می‌شود (پارامترِ رسمیِ همین مسیر) */
    public function listBoxes(?string $name = null): array
    {
        return $this->call('get', '/storage_boxes', [], array_filter([
            'name'     => $name,
            'per_page' => 50,
        ]));
    }

    /**
     * وضعیتِ سه‌حالتهٔ «این باکس هست؟» — آرایهٔ باکس / false / null.
     *
     * 🔴 `null` یعنی **نپرسیدیم**، نه «نیست». تفاوتشان همان تفاوتی است که
     * `WhmClient::accountState()` را لازم کرد: اگر «نپرسیدیم» را «نیست»
     * بخوانیم، تلاشِ دوباره یک باکسِ دومِ پولی می‌سازد.
     */
    public function boxState(string $name): array|false|null
    {
        $r = $this->listBoxes($name);

        if (! $r['ok']) {
            return $r['transport'] ? null : false;
        }

        foreach (($r['data']['storage_boxes'] ?? []) as $box) {
            if (($box['name'] ?? null) === $name) {
                return $box;
            }
        }

        /*
        | ⚠️ فیلترِ `name` سمتِ هتزنر است و اگر روزی رفتارش عوض شود (مثلاً
        | جستجوی جزئی شود) حلقهٔ بالا همچنان تطبیقِ **دقیق** می‌خواهد. پس
        | خروجیِ خالیِ این تابع واقعاً یعنی «چنین نامی نیست».
        */
        return false;
    }

    /**
     * ساختِ باکس.
     *
     * فیلدهای اجباری طبقِ اسپک: storage_box_type · location · name · password
     */
    public function createBox(array $spec): array
    {
        return $this->call('post', '/storage_boxes', array_filter([
            'name'             => $spec['name'] ?? null,
            'storage_box_type' => $spec['type'] ?? null,
            'location'         => $spec['location'] ?? null,
            'password'         => $spec['password'] ?? null,
            'labels'           => $spec['labels'] ?? null,
            'access_settings'  => $spec['access_settings'] ?? null,
        ], fn ($v) => $v !== null && $v !== []));
    }

    public function deleteBox(int|string $id): array
    {
        $r = $this->call('delete', '/storage_boxes/'.$id);

        /*
        | حذفِ چیزی که از قبل نیست = موفق. همان قاعدهٔ `terminate()` در بقیهٔ
        | درایورها: وگرنه یک خاتمهٔ تکراری برای همیشه در صفِ «تلاشِ دوباره»
        | می‌مانَد و هر ساعت به هتزنر می‌کوبد.
        */
        if (! $r['ok'] && ! $r['transport'] && $r['status'] === 404) {
            return ['ok' => true, 'transport' => false, 'status' => 404, 'reason' => '', 'data' => []];
        }

        return $r;
    }

    public function resetPassword(int|string $id, string $password): array
    {
        return $this->call('post', '/storage_boxes/'.$id.'/actions/reset_password', ['password' => $password]);
    }

    /** روشن/خاموش کردنِ پروتکل‌ها و دسترسیِ بیرونی */
    public function updateAccessSettings(int|string $id, array $settings): array
    {
        return $this->call('post', '/storage_boxes/'.$id.'/actions/update_access_settings', $settings);
    }

    /** آزمونِ اتصال برای `/admin/servers` — فقط می‌خوانَد، چیزی نمی‌سازد */
    public function testConnection(): array
    {
        $r = $this->types();

        if ($r['ok']) {
            $n = count($r['data']['storage_box_types'] ?? []);

            return ['ok' => true, 'message' => 'اتصال برقرار است — '.$n.' نوع Storage Box دیده شد.'];
        }

        return ['ok' => false, 'message' => $r['reason']];
    }
}
