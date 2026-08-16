<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerApiToken;
use App\Models\Domain;
use App\Models\DomainQuote;
use App\Models\ResellerApiLog;
use App\Services\Domain\DomainSearch;
use App\Services\Domain\OpenProviderClient;
use App\Services\Domain\Reseller\ResellerOrderService;
use App\Services\Domain\Reseller\ResellerPricing;
use App\Services\Domain\Reseller\ResellerProgram;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * APIِ نمایندگیِ دامنه — نسخهٔ ۱.
 *
 * ═══ قراردادهای این لایه ═══
 *
 * **۱) پاسخ همیشه یک شکل دارد.** `{ok, data}` یا `{ok, error, message}`.
 * تماس‌گیرنده یک افزونهٔ WHMCS است؛ شکلِ متغیرِ پاسخ یعنی کدی که با `if`های
 * تودرتو حدس می‌زند چه اتفاقی افتاده.
 *
 * **۲) `error` ماشین‌خوان است و `message` انسان‌خوان.** ماژول روی `error`
 * تصمیم می‌گیرد و `message` را به اپراتور نشان می‌دهد. اگر ماژول مجبور شود
 * روی متنِ فارسی `str_contains` بزند، اولین ویرایشِ متن، منطقش را می‌شکند.
 *
 * **۳) بهایِ تمام‌شده هرگز بیرون نمی‌رود.** `Domain::$hidden` این را برای
 * سریال‌سازیِ خودکار تضمین می‌کند، ولی این کنترلر دستی آرایه می‌سازد پس
 * تضمین باید این‌جا هم باشد: هیچ متدی `cost_amount`/`op_id`/`owner_handle`
 * را برنمی‌گرداند.
 */
class DomainApiController extends Controller
{
    public function __construct(
        private DomainSearch $search,
        private ResellerOrderService $orders,
        private ResellerPricing $pricing,
        private ResellerProgram $program,
        private OpenProviderClient $op,
    ) {}

    // ═══════════════════════ خواندنی ═══════════════════════

    /**
     * آزمونِ اتصال — دکمهٔ «Test Connection» ماژولِ WHMCS همین را می‌زند.
     *
     * ⚠️ دسترسی‌های توکن هم برمی‌گردد تا ماژول بتواند **پیش از اولین فروش**
     * بگوید «این توکن اجازهٔ ثبت ندارد». کشفِ این موضوع در لحظهٔ سفارشِ یک
     * مشتریِ واقعی، بدترین زمانِ ممکن است.
     */
    public function ping(Request $request): JsonResponse
    {
        $c = $this->customer($request);
        $token = $this->token($request);
        $p = $this->program->progress($c);

        return $this->ok([
            'account' => [
                'code'   => $c->code,
                'name'   => $c->displayName(),
                'status' => $c->status,
            ],
            'reseller' => [
                'enabled'        => $this->program->isReseller($c),
                'level'          => $p['level']['key'] ?? null,
                'level_name'     => lc($p['level']['name'] ?? []) ?: ($p['level']['key'] ?? ''),
                'discount_pct'   => $p['discount_pct'],
                'active_domains' => $p['active_domains'],
            ],
            'credit' => ['IRT' => $c->creditBalance('IRT')],
            'token'  => [
                'name'      => $token?->name,
                'abilities' => array_values((array) ($token?->abilities ?? [])),
                'expires_at'=> optional($token?->expires_at)->toIso8601String(),
            ],
            'server_time' => now()->toIso8601String(),
            'api_version' => 'v1',
        ]);
    }

    /**
     * فهرستِ پسوندها با قیمتِ نماینده.
     *
     * ⚠️ عمداً از `TldPriceBook` می‌خواند (کشِ روزانه) و نه استعلامِ زنده:
     * ماژولِ WHMCS این را برای «Import Pricing» صدا می‌زند و آن دکمه ممکن
     * است ده‌ها بار زده شود. استعلامِ زنده یعنی سیلی از تماس با حسابی که
     * یک‌بار به‌خاطرِ تماسِ زیاد علامت خورده.
     */
    public function tlds(Request $request, \App\Services\Domain\TldPriceBook $book): JsonResponse
    {
        $c = $this->customer($request);

        $tlds = array_values(array_filter(array_map(
            fn ($t) => strtolower(trim((string) $t, " \t.")),
            (array) $request->query('tlds', [])
        )));

        if ($tlds === []) {
            $tlds = DomainSearch::firstBatch();
        }

        $tlds = array_slice($tlds, 0, 60);
        $rows = [];

        foreach ($book->fullForTlds($tlds) as $tld => $info) {
            if (! DomainSearch::sells($tld)) {
                continue;
            }

            $register = (int) ($info['register'] ?? 0);
            $renew = (int) ($info['renew'] ?? $register);
            $transfer = (int) ($info['transfer'] ?? $register);

            if ($register <= 0) {
                continue;
            }

            $r = $this->pricing->price($register, null, $c);
            $n = $this->pricing->price($renew, null, $c);
            $t = $this->pricing->price($transfer, null, $c);

            $rows[] = [
                'tld'      => $tld,
                'currency' => 'IRT',
                'register' => $r['price'],
                'renew'    => $n['price'],
                'transfer' => $t['price'],
                'retail'   => ['register' => $register, 'renew' => $renew, 'transfer' => $transfer],
                'discount_pct' => $r['applied_pct'],
            ];
        }

        return $this->ok($rows);
    }

    /**
     * استعلامِ دسترس‌بودن و قیمت.
     *
     * ⚠️ `state` همان مقداری است که کلِ سایت استفاده می‌کند و ماژول باید روی
     * آن تصمیم بگیرد، نه روی `available`. سه رابطِ این پروژه یک بار دقیقاً
     * به‌خاطرِ حدس‌زدنِ وضعیت از روی فیلدهای خام واگرا شدند: یکی «نمی‌دانم» را
     * «ثبت‌شده» می‌خواند و به مشتری می‌گفت اسمِ دلخواهش گرفته شده.
     */
    public function check(Request $request): JsonResponse
    {
        $c = $this->customer($request);

        $data = $this->validated($request, [
            'domain' => ['required', 'string', 'max:253'],
            'tlds'   => ['nullable', 'array', 'max:12'],
            'tlds.*' => ['string', 'max:63'],
        ]);

        if ($data instanceof JsonResponse) {
            return $data;
        }

        try {
            $rows = $this->search->search($data['domain'], $data['tlds'] ?? []);
        } catch (\Throwable) {
            return $this->err('lookup_failed', 'استعلام ممکن نشد. کمی بعد دوباره تلاش کنید.', 503);
        }

        if (! $this->search->lookupOk() && $rows === []) {
            return $this->err('lookup_failed', 'استعلام ممکن نشد.', 503);
        }

        $out = [];

        foreach ($rows as $row) {
            $quote = isset($row['quote_id']) ? DomainQuote::find($row['quote_id']) : null;

            $reg = $quote
                ? $this->pricing->forQuote($quote, $c, 'register')
                : ['price' => 0, 'retail' => 0, 'floored' => false, 'applied_pct' => 0.0];

            $ren = $quote
                ? $this->pricing->forQuote($quote, $c, 'renew')
                : ['price' => 0, 'retail' => 0, 'floored' => false, 'applied_pct' => 0.0];

            $out[] = [
                'domain'     => $row['domain'],
                'tld'        => $row['tld'],
                'state'      => $row['state'],
                'available'  => (bool) ($row['available'] ?? false),
                'is_premium' => (bool) ($row['is_premium'] ?? false),
                'orderable'  => (bool) ($row['orderable'] ?? false),
                'currency'   => 'IRT',
                'price'      => [
                    'register' => (int) ($reg['price'] ?? 0),
                    'renew'    => (int) ($ren['price'] ?? 0),
                    'retail'   => (int) ($reg['retail'] ?? 0),
                ],
                'discount_pct' => (float) ($reg['applied_pct'] ?? 0),
                // 🔴 وقتی کفِ حاشیه تخفیف را بریده، **گفته می‌شود**. تخفیفی که
                //    وعده‌اش ۱۵٪ بوده و بی‌توضیح ۴٪ شده، برنامهٔ وفاداری را به
                //    سندِ بی‌اعتمادی تبدیل می‌کند.
                'price_floored' => (bool) ($reg['floored'] ?? false),
                'reason'        => $row['reason'] ?? null,
            ];
        }

        return $this->ok($out);
    }

    /** فهرستِ دامنه‌های نماینده */
    public function index(Request $request): JsonResponse
    {
        $c = $this->customer($request);

        $q = Domain::where('customer_id', $c->id);

        if ($request->boolean('alive', true)) {
            $q->alive();
        }

        if ($s = trim((string) $request->query('search', ''))) {
            $q->where('domain', 'like', '%'.mb_substr($s, 0, 100).'%');
        }

        $perPage = min(200, max(1, (int) $request->query('per_page', 100)));
        $page = $q->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'ok'   => true,
            'data' => array_map(fn ($d) => $this->shape($d), $page->items()),
            'meta' => [
                'page'     => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total'    => $page->total(),
            ],
        ]);
    }

    /** جزئیاتِ یک دامنه — ماژولِ WHMCS برای `_Sync` همین را می‌زند */
    public function show(Request $request, string $domain): JsonResponse
    {
        $d = $this->owned($request, $domain);

        return $d instanceof JsonResponse ? $d : $this->ok($this->shape($d, detailed: true));
    }

    // ═══════════════════════ نوشتنی ═══════════════════════

    /**
     * ثبتِ دامنه — از اعتبارِ حساب کسر می‌شود.
     *
     * ⚠️ مالکِ ثبت‌شده (registrant) در این نسخه **خودِ نماینده** است، نه
     * مشتریِ نهایی. ماژولِ WHMCS فیلدهای تماسِ مشتری را می‌فرستد و ما آنها را
     * **عمداً نادیده می‌گیریم و ذخیره نمی‌کنیم**: انتقالِ اطلاعاتِ هویتیِ
     * مشتریِ نهایی به ما یک مسیرِ دادهٔ کاملاً جدا (رضایت، DPA، نگهداری، حذف)
     * می‌خواهد که هنوز ساخته نشده. پذیرفتن و ذخیره‌کردنِ خاموشِ آن داده،
     * ریسکی است که هیچ‌کس تصمیمش را نگرفته.
     *
     * پاسخ صریح `registrant: "reseller"` برمی‌گرداند تا این انتخاب پنهان نمانَد.
     */
    public function register(Request $request): JsonResponse
    {
        $c = $this->customer($request);

        $data = $this->validated($request, [
            'domain' => ['required', 'string', 'max:253'],
            'years'  => ['nullable', 'integer', 'min:1', 'max:10'],
            'nameservers'   => ['nullable', 'array', 'max:5'],
            'nameservers.*' => ['string', 'max:253'],
        ]);

        if ($data instanceof JsonResponse) {
            return $data;
        }

        return $this->idempotent($request, 'register', $data['domain'], function () use ($c, $data) {
            $res = $this->orders->register(
                $c,
                $data['domain'],
                (int) ($data['years'] ?? 1),
                (array) ($data['nameservers'] ?? []),
            );

            if (! ($res['ok'] ?? false)) {
                return [
                    'status'  => $res['status'] ?? 422,
                    'body'    => array_filter([
                        'ok'      => false,
                        'error'   => $res['error'],
                        'message' => $res['message'],
                        'data'    => $res['data'] ?? null,
                    ], fn ($v) => $v !== null),
                    'amount'  => 0,
                ];
            }

            /** @var Domain $domain */
            $domain = $res['domain'];

            // تلاشِ همزمان برای ثبت؛ اگر نشد، کرونِ هر-دقیقه ادامه می‌دهد
            $state = $this->orders->deliver($domain);
            $domain->refresh();

            return [
                'status' => 201,
                'body'   => [
                    'ok'   => true,
                    'data' => $this->shape($domain, detailed: true) + [
                        'order_state' => $state,          // registered | pending | manual | failed
                        'registrant'  => 'reseller',
                        'charged'     => (int) $res['charged'],
                        'currency'    => 'IRT',
                        'invoice'     => $res['invoice']->number ?? null,
                        'price_floored' => (bool) ($res['pricing']['floored'] ?? false),
                    ],
                ],
                'amount' => (int) $res['charged'],
            ];
        });
    }

    /** تمدید — از اعتبار کسر می‌شود */
    public function renew(Request $request, string $domain): JsonResponse
    {
        $c = $this->customer($request);
        $d = $this->owned($request, $domain);

        if ($d instanceof JsonResponse) {
            return $d;
        }

        $data = $this->validated($request, [
            'years' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        if ($data instanceof JsonResponse) {
            return $data;
        }

        return $this->idempotent($request, 'renew', $d->domain, function () use ($c, $d, $data) {
            $res = $this->orders->renew($c, $d, (int) ($data['years'] ?? 1));

            if (! ($res['ok'] ?? false)) {
                return [
                    'status' => $res['status'] ?? 422,
                    'body'   => array_filter([
                        'ok' => false, 'error' => $res['error'],
                        'message' => $res['message'], 'data' => $res['data'] ?? null,
                    ], fn ($v) => $v !== null),
                    'amount' => 0,
                ];
            }

            $d->refresh();

            return [
                'status' => 200,
                'body'   => [
                    'ok'   => true,
                    'data' => $this->shape($d, detailed: true) + [
                        // تمدید عمداً inline انجام نمی‌شود: کرونِ `domains:renew`
                        // هر دقیقه می‌دود و تمدیدِ تکراری — برخلافِ ثبتِ تکراری —
                        // را رجیستری **می‌پذیرد** و پول می‌گیرد. یک مسیر، یک قفل.
                        'order_state' => 'pending',
                        'charged'     => (int) $res['charged'],
                        'currency'    => 'IRT',
                        'invoice'     => $res['invoice']->number ?? null,
                    ],
                ],
                'amount' => (int) $res['charged'],
            ];
        });
    }

    /** تغییرِ نام‌سرورها */
    public function nameservers(Request $request, string $domain): JsonResponse
    {
        $d = $this->owned($request, $domain);

        if ($d instanceof JsonResponse) {
            return $d;
        }

        $data = $this->validated($request, [
            'nameservers'   => ['required', 'array', 'min:2', 'max:5'],
            'nameservers.*' => ['required', 'string', 'max:253', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i'],
        ]);

        if ($data instanceof JsonResponse) {
            return $data;
        }

        if (! $d->op_id) {
            return $this->err('not_registered', 'این دامنه هنوز نزدِ رجیسترار ثبت نشده است.', 409);
        }

        $ns = array_values(array_unique(array_map(
            fn ($v) => strtolower(trim((string) $v, " \t.")),
            $data['nameservers']
        )));

        $res = $this->op->setNameServers((int) $d->op_id, $ns);

        if (! ($res['ok'] ?? false)) {
            // ⚠️ پیامِ خامِ رجیسترار برنمی‌گردد — ممکن است نام یا شناسهٔ داخلیِ
            //    ما را داشته باشد. همان قاعدهٔ سفیدبرچسبیِ سرورِ ابری.
            return $this->err('registrar_rejected', 'رجیسترار این تغییر را نپذیرفت.', 502);
        }

        $d->update(['name_servers' => $ns]);
        $this->log($request, 'nameservers', $d->domain, true);

        return $this->ok(['domain' => $d->domain, 'nameservers' => $ns]);
    }

    /**
     * قفلِ انتقال — فقط **روشن‌کردن**.
     *
     * 🔴 خاموش‌کردن عمداً از دسترسِ توکن بیرون است. قفلِ باز، پیش‌نیازِ بردنِ
     * دامنه است؛ یک توکنِ لورفته که بتواند قفل را باز کند یعنی پرتفویِ
     * مشتریانِ نماینده در یک حلقهٔ `for` از دستش می‌رود. عملی که فقط محافظت
     * اضافه می‌کند بی‌خطر است؛ عملی که محافظت را برمی‌دارد باید انسان ببیندش.
     */
    public function lock(Request $request, string $domain): JsonResponse
    {
        $d = $this->owned($request, $domain);

        if ($d instanceof JsonResponse) {
            return $d;
        }

        if (! $request->boolean('locked', true)) {
            return $this->err('panel_only',
                'خاموش‌کردنِ قفلِ انتقال از API ممکن نیست. از پنلِ کاربری انجام دهید.', 403);
        }

        if (! $d->op_id) {
            return $this->err('not_registered', 'این دامنه هنوز نزدِ رجیسترار ثبت نشده است.', 409);
        }

        $res = $this->op->setLock((int) $d->op_id, true);

        if (! ($res['ok'] ?? false)) {
            return $this->err('registrar_rejected', 'رجیسترار این تغییر را نپذیرفت.', 502);
        }

        $d->update(['is_locked' => true]);
        $this->log($request, 'lock', $d->domain, true);

        return $this->ok(['domain' => $d->domain, 'locked' => true]);
    }

    /** تمدیدِ خودکار — فقط پرچمِ محلی، عمداً نزدِ رجیسترار ست نمی‌شود */
    public function autoRenew(Request $request, string $domain): JsonResponse
    {
        $d = $this->owned($request, $domain);

        if ($d instanceof JsonResponse) {
            return $d;
        }

        $on = $request->boolean('auto_renew');
        $d->update(['auto_renew' => $on]);

        // ⚠️ نزدِ رجیسترار روشن نمی‌شود: تمدید را **ما** می‌فروشیم. اگر رجیسترار
        //    خودش تمدید کند، برای دامنه‌ای که نماینده پولش را نداده هم ما پول
        //    می‌دهیم و راهی برای پس‌گرفتنش نیست.
        return $this->ok(['domain' => $d->domain, 'auto_renew' => $on]);
    }

    // ═══════════════════════ زیرساخت ═══════════════════════

    /**
     * پوششِ idempotency — «کلید را اول claim کن، بعد کار کن».
     *
     * 🔴 چرا claim اول: الگوی «اول بخوان ببین هست، بعد بنویس» بینِ خواندن و
     * نوشتن یک پنجرهٔ رقابت دارد، و WHMCS دقیقاً در همان پنجره درخواستِ
     * تکراری می‌فرستد (خودش روی timeout دوباره می‌فرستد). با claimِ اتمی روی
     * **ایندکسِ یکتای دیتابیس**، درخواستِ دوم اصلاً به منطقِ خرید نمی‌رسد.
     *
     * ⚠️ اگر کلید نیامده باشد هیچ محافظی نیست و این عمدی است: اجباری‌کردنش
     * یعنی هر کلاینتِ ساده‌ای که فقط می‌خواهد قیمت بگیرد هم باید کلید بسازد.
     * مستندات و ماژولِ خودمان کلید می‌فرستند؛ مسئولیتِ نفرستادنش با تماس‌گیرنده
     * است و در مستندات صریح نوشته شده.
     *
     * @param  callable():array{status:int, body:array, amount:int}  $work
     */
    private function idempotent(Request $request, string $action, string $domain, callable $work): JsonResponse
    {
        $c = $this->customer($request);
        $key = trim((string) $request->header('Idempotency-Key', ''));
        $started = microtime(true);
        $log = null;

        if ($key !== '') {
            if (mb_strlen($key) > 80) {
                return $this->err('bad_idempotency_key', 'کلیدِ یکتاسازی نباید از ۸۰ نویسه بیشتر باشد.');
            }

            try {
                $log = ResellerApiLog::create([
                    'customer_id'     => $c->id,
                    'token_id'        => $this->token($request)?->id,
                    'action'          => $action,
                    'domain'          => mb_substr($domain, 0, 253),
                    'ok'              => false,
                    'ip'              => $request->ip(),
                    'idempotency_key' => $key,
                ]);
            } catch (UniqueConstraintViolationException) {
                $prev = ResellerApiLog::replay($c->id, $key);

                if ($prev === null) {
                    return $this->err('conflict', 'درخواستِ تکراری.', 409);
                }

                if ($prev->response === null) {
                    // درخواستِ اول هنوز تمام نشده — «در جریان» نه «شکست»
                    return $this->err('request_in_progress',
                        'درخواستی با همین کلید در حالِ پردازش است. کمی بعد وضعیت را استعلام کنید.', 409);
                }

                return response()->json($prev->response + ['replayed' => true], $prev->ok ? 200 : 422);
            }
        }

        try {
            $out = $work();
        } catch (\Throwable $e) {
            /*
            | 🔴 استثنا نباید کلید را برای همیشه بسوزانَد.
            |
            | claim پیش از کار گرفته می‌شود (لازم است، وگرنه پنجرهٔ رقابت باز
            | می‌مانَد)، ولی اگر کار بترکد و ردیف با `response = null` بمانَد،
            | هر تلاشِ بعدیِ همان کلید تا ابد `request_in_progress` می‌گیرد —
            | یعنی نماینده برای دامنه‌ای که هرگز خریده نشده، راهی به جلو ندارد
            | و باید کلیدش را عوض کند (که هیچ‌جا به او نگفته‌ایم).
            |
            | آزادکردنش امن است چون `charge()` تراکنشی است: استثنا یعنی هیچ
            | پولی جابه‌جا نشده.
            */
            $log?->delete();

            throw $e;
        }

        $ok = (bool) ($out['body']['ok'] ?? false);

        /*
        | 🔴 شکست کش **نمی‌شود** — کلید آزاد می‌شود.
        |
        | معنای idempotency «همان درخواست دو بار انجام نشود» است، نه «همان
        | اشتباه تا ابد تکرار شود». نماینده‌ای که `insufficient_credit` گرفته،
        | حساب را شارژ می‌کند و همان سفارش را دوباره می‌فرستد — با همان کلید،
        | چون ماژول کلید را از شناسهٔ سفارشِ WHMCS می‌سازد و آن عوض نمی‌شود.
        | اگر خطا را کش کنیم، آن سفارش برای همیشه شکست‌خورده می‌مانَد.
        |
        | امنیتش از جای دیگری می‌آید: تنها شکستی که ممکن است پول خرج کرده
        | باشد، شکستِ **تحویل** است — و آن‌جا ردیفِ `Domain` از قبل ساخته شده،
        | پس تلاشِ دوباره به گیتِ `already_yours` می‌خورد نه به خریدِ دوم.
        */
        if (! $ok && $log !== null) {
            $log->forceFill(['idempotency_key' => null])->save();
        }

        $log ??= new ResellerApiLog([
            'customer_id' => $c->id,
            'token_id'    => $this->token($request)?->id,
            'action'      => $action,
            'domain'      => mb_substr($domain, 0, 253),
            'ip'          => $request->ip(),
        ]);

        try {
            $log->forceFill([
                'ok'          => $ok,
                'error_code'  => $out['body']['error'] ?? null,
                'amount_irt'  => (int) ($out['amount'] ?? 0),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'response'    => $out['body'],
            ])->save();
        } catch (\Throwable) {
            // لاگ نباید سفارشِ انجام‌شده را بشکند
        }

        return response()->json($out['body'], $out['status']);
    }

    /** لاگِ یک عملیاتِ بی‌پول (تغییر NS، قفل) */
    private function log(Request $request, string $action, string $domain, bool $ok, ?string $error = null): void
    {
        try {
            ResellerApiLog::create([
                'customer_id' => $this->customer($request)->id,
                'token_id'    => $this->token($request)?->id,
                'action'      => $action,
                'domain'      => mb_substr($domain, 0, 253),
                'ok'          => $ok,
                'error_code'  => $error,
                'ip'          => $request->ip(),
            ]);
        } catch (\Throwable) {
        }
    }

    /**
     * شکلِ عمومیِ یک دامنه.
     *
     * 🔴 آرایه **دستی** ساخته می‌شود و از `toArray()` نمی‌آید. `Domain::$hidden`
     * امروز `cost_amount`/`op_id`/`owner_handle` را می‌پوشانَد، ولی ستونِ
     * حساسِ بعدی که کسی اضافه کند خودبه‌خود پوشیده نیست — و در سریال‌سازیِ
     * خودکار، نشت **پیش‌فرض** است و پوشاندن استثنا. این‌جا برعکس است.
     *
     * @return array<string,mixed>
     */
    private function shape(Domain $d, bool $detailed = false): array
    {
        $out = [
            'domain'      => $d->domain,
            'tld'         => $d->tld,
            'status'      => $d->status,
            'registered_at' => optional($d->registered_at)->toIso8601String(),
            'expires_at'  => optional($d->expires_at)->toIso8601String(),
            'days_left'   => $d->daysLeft(),
            'auto_renew'  => (bool) $d->auto_renew,
            'locked'      => (bool) $d->is_locked,
            'period_years'=> (int) $d->period_years,
        ];

        if ($detailed) {
            $out += [
                'nameservers' => $d->effectiveNameServers(),
                'renew_price' => (int) $d->renew_toman,
                'currency'    => 'IRT',
                /*
                | ⚠️ `provision_status` یک برچسبِ **داخلی** است و به‌عنوان
                | «وضعیتِ سفارش» بیرون می‌رود، نه با نامِ خودش: ماژولی که رویش
                | شرط بگذارد، با اولین تغییرِ داخلیِ ما می‌شکند.
                */
                'order_state' => match ($d->provision_status) {
                    'done'    => 'registered',
                    'failed'  => 'failed',
                    'manual'  => 'manual',
                    'none'    => $d->isActive() ? 'registered' : 'idle',
                    default   => 'pending',
                },
            ];
        }

        return $out;
    }

    private function owned(Request $request, string $domain): Domain|JsonResponse
    {
        $fqdn = strtolower(trim($domain, ". \t\n\r"));

        $d = Domain::where('customer_id', $this->customer($request)->id)
            ->where('domain', $fqdn)
            ->first();

        return $d ?? $this->err('not_found', 'این دامنه در حسابِ شما پیدا نشد.', 404);
    }

    /**
     * 🔴 اعتبارسنجیِ دستی و نه `$request->validate()`.
     *
     * `bootstrap/app.php` می‌گوید `shouldRenderJsonWhen(is('api/*'))` — که
     * برای `api/v1` درست کار می‌کند. ولی `validate()` یک
     * `ValidationException` پرتاب می‌کند که شکلِ پاسخش با قراردادِ این کلاس
     * (`{ok,error,message}`) یکی نیست: `{message, errors}` برمی‌گردد و
     * ماژولِ WHMCS که روی `error` شرط می‌گذارد، خطای اعتبارسنجی را
     * «موفقیتِ بی‌داده» می‌خواند.
     *
     * @param  array<string,mixed>  $rules
     * @return array<string,mixed>|JsonResponse
     */
    private function validated(Request $request, array $rules): array|JsonResponse
    {
        $v = Validator::make($request->all(), $rules);

        if ($v->fails()) {
            return response()->json([
                'ok'      => false,
                'error'   => 'validation_failed',
                'message' => (string) $v->errors()->first(),
                'fields'  => $v->errors()->toArray(),
            ], 422);
        }

        return $v->validated();
    }

    private function customer(Request $request): Customer
    {
        return $request->attributes->get('api_customer');
    }

    private function token(Request $request): ?CustomerApiToken
    {
        return $request->attributes->get('api_token');
    }

    private function ok(mixed $data): JsonResponse
    {
        return response()->json(['ok' => true, 'data' => $data]);
    }

    private function err(string $code, string $message, int $status = 422): JsonResponse
    {
        return response()->json(['ok' => false, 'error' => $code, 'message' => $message], $status);
    }
}
