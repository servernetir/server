<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CalendarEvent;
use App\Models\CalendarLayerPreference;
use App\Models\GoogleCalendarToken;
use App\Services\Calendar\CalendarItem;
use App\Services\Calendar\CalendarService;
use App\Services\Calendar\Google\GoogleCalendarClient;
use App\Services\Calendar\Providers\GoogleCalendarProvider;
use App\Support\Jalali;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * تقویمِ کسب‌وکار — همهٔ سررسیدهای شرکت در یک صفحه.
 *
 * چرا این صفحه ارزش دارد: سررسیدها امروز در پنج جای متفاوت‌اند (دامنه‌ها،
 * سرویس‌ها، فاکتورها، صفِ انتشار، و ذهنِ مدیر). هیچ‌کدام از آن صفحه‌ها به
 * دیگری نگاه نمی‌کند، پس «این هفته چه چیزهایی سررسید دارد؟» جوابی ندارد مگر
 * با باز کردنِ چهار تب. یک تقویمِ واحد همان سؤال را یک‌نگاهی می‌کند.
 *
 * ⚠️ این صفحه **هیچ داده‌ای را کپی نمی‌کند** — در لحظهٔ نمایش از جدول‌های اصلی
 * می‌خواند. توضیحِ کاملش در مهاجرتِ `calendar_events`.
 */
class CalendarController extends Controller
{
    public function __construct(private readonly CalendarService $calendar) {}

    /* ==================================================================== */
    /*  صفحه                                                                */
    /* ==================================================================== */

    public function index(Request $request): View
    {
        $userId = $request->user()?->id;
        $prefs = CalendarLayerPreference::forUser($userId);

        [$jy, $jm] = $this->requestedMonth($request);
        [$from, $to] = $this->monthBounds($jy, $jm);

        $visible = array_keys(array_filter($prefs));

        /*
         * بارِ اولِ صفحه از سرور می‌آید، نه با یک fetchِ بعد از رندر.
         * وگرنه مدیر هر بار یک اسکلتِ خالی می‌بیند و بعد پرش — روی اتصالِ کُند،
         * تقویمی که «خالی به‌نظر می‌رسد» با تقویمی که واقعاً خالی است فرق ندارد.
         */
        $events = $this->calendar->events($from, $to, $visible);
        $failures = $this->calendar->failures();
        $truncated = $this->calendar->truncatedLayers();

        // ⚠️ بعد از برداشتنِ failures بالا، چون `upcoming()` آن را ریست می‌کند
        $upcoming = $this->calendar->upcoming($visible);

        $layers = (array) config('calendar.layers', []);
        $today = $this->todayJalali();

        /*
         * وضعیتِ اتصالِ گوگلِ **همین کاربر**.
         *
         * ⚠️ اگر اعتبارنامهٔ اپ در تنظیمات نباشد، لایه اصلاً نشان داده نمی‌شود:
         * چیپی که هیچ‌وقت چیزی نمی‌آورد از نبودنش بدتر است.
         */
        $googleToken = GoogleCalendarToken::forUser($userId);
        $google = [
            'configured'  => GoogleCalendarClient::configured(),
            'connected'   => $googleToken !== null,
            'email'       => $googleToken?->google_email,
            'last_error'  => $googleToken?->last_error,
            'synced_at'   => $googleToken?->synced_at?->diffForHumans(),
        ];

        /*
         * ⚠️ چیپِ گوگل فقط برای کاربرِ **وصل‌شده** ساخته می‌شود.
         *
         * پیش از این، اعتبارنامهٔ اپ کافی بود و چیپ برای همه می‌آمد — یعنی
         * کاربری که حسابش را وصل نکرده یک چیپ می‌دید که هرگز چیزی نمی‌آورد و
         * فکر می‌کرد خراب است. کنترلی که کاری نمی‌کند از نبودش بدتر است.
         */
        if (! $google['configured'] || ! $google['connected']) {
            unset($layers['google']);
        }

        return view('admin.calendar', [
            'layers'   => $layers,
            'statuses' => (array) config('calendar.statuses', []),
            'prefs'    => $prefs,
            'jy'       => $jy,
            'jm'       => $jm,
            'weekdays' => Jalali::WEEKDAY_NAMES,
            'upcoming' => $upcoming,
            'upcomingDays' => (int) config('calendar.upcoming_days', 7),
            'dueSoonDays'  => (int) config('calendar.due_soon_days', 3),
            'google'       => $google,

            /*
             * تنها دریچهٔ داده به جاوااسکریپت. یک شیء، نه ده متغیرِ پراکنده در
             * صفت‌های data — چون هر صفتِ تازه یعنی یک جای دیگر که می‌تواند با
             * سرور ناهم‌خوان شود.
             */
            'boot' => [
                'year'      => $jy,
                'month'     => $jm,
                'today'     => Jalali::format($today[0], $today[1], $today[2]),
                'grid'      => $this->grid($jy, $jm) + [
                    'year'       => $jy,
                    'month'      => $jm,
                    'month_name' => Jalali::monthName($jm),
                ],
                'events'    => $events->map(fn (CalendarItem $i) => $i->toArray())->all(),
                'layers'    => array_map(static fn (array $l) => [
                    'label' => $l['label'] ?? '',
                    'tone'  => $l['tone'] ?? 'task',
                    'icon'  => $l['icon'] ?? 'i-check',
                ], $layers),
                'prefs'        => $prefs,
                'statuses'     => (array) config('calendar.statuses', []),
                'repeats'      => (array) config('calendar.repeats', []),
                'googleConnected' => $google['connected'],
                'failures'     => $failures,
                'truncated'    => $truncated,
                'upcomingDays' => (int) config('calendar.upcoming_days', 7),
                'dueSoonDays'  => (int) config('calendar.due_soon_days', 3),
            ],
        ]);
    }

    /* ==================================================================== */
    /*  API                                                                 */
    /* ==================================================================== */

    /**
     * رویدادهای یک بازه. `from` و `to` **شمسی** (`1405-05-01`).
     *
     * ⚠️ یک میان‌برِ اختیاری هم دارد: `y`+`m` (سال و ماهِ شمسی). با آن، سرور
     * خودش مرزهای ماه را حساب می‌کند و **داربستِ شبکه** را هم برمی‌گرداند.
     *
     * 🔴 چرا این میان‌بر لازم بود: بی‌آن، جاوااسکریپت برای دانستنِ «مردادِ
     * ۱۴۰۵ چند روز دارد و روزِ یکش چه روزِ هفته‌ای است» باید الگوریتمِ جلالی را
     * **دوباره** پیاده می‌کرد. دو پیاده‌سازی یعنی روزی یک روز اختلاف پیدا
     * می‌کنند، و آن اختلاف در صفحه‌ای که سررسیدِ فاکتور نشان می‌دهد بی‌صدا
     * غلط می‌شود. یک الگوریتم، در PHP.
     */
    public function events(Request $request): JsonResponse
    {
        $data = $this->check($request, [
            'from'     => ['required_without_all:y,m', 'nullable', 'string', 'max:10'],
            'to'       => ['required_without_all:y,m', 'nullable', 'string', 'max:10'],
            'y'        => ['nullable', 'integer', 'min:1300', 'max:1500'],
            'm'        => ['nullable', 'integer', 'min:1', 'max:12'],
            'layers'   => ['nullable', 'array'],
            /*
             * ⚠️ رشتهٔ خالی هم مجاز است و **معنی دارد**: در رشتهٔ کوئری راهی
             * برای فرستادنِ «آرایهٔ خالی» نیست، پس مرورگر `layers[]=` می‌فرستد
             * تا بگوید «هیچ لایه‌ای». اگر این‌جا رد می‌شد، خاموش‌کردنِ همهٔ
             * چیپ‌ها یک خطای ۴۲۲ می‌داد به‌جای یک تقویمِ خالی.
             */
            'layers.*' => ['nullable', 'string', Rule::in([...CalendarEvent::types(), ''])],
            // ستونِ «پیش‌رو» همیشه از **امروز** است، نه از ماهِ نمایش‌داده‌شده،
            // پس بازهٔ خودش را دارد. اختیاری است تا هر ناوبریِ ماه دو پرس‌وجوی
            // اضافه نزند وقتی لازم نیست.
            'with_upcoming' => ['nullable', 'boolean'],
        ], ['from' => 'تاریخ شروع', 'to' => 'تاریخ پایان', 'y' => 'سال', 'm' => 'ماه']);

        if ($data instanceof JsonResponse) {
            return $data;
        }

        $tz = $this->timezone();
        $grid = null;

        if (($data['y'] ?? null) !== null && ($data['m'] ?? null) !== null) {
            $jy = (int) $data['y'];
            $jm = (int) $data['m'];
            [$from, $to] = $this->monthBounds($jy, $jm);
            $fromJ = [$jy, $jm, 1];
            $toJ = [$jy, $jm, Jalali::daysInMonth($jy, $jm)];
            $grid = $this->grid($jy, $jm) + ['year' => $jy, 'month' => $jm, 'month_name' => Jalali::monthName($jm)];
        } else {
            $fromJ = Jalali::parse($data['from'] ?? null);
            $toJ = Jalali::parse($data['to'] ?? null);

            if ($fromJ === null || $toJ === null) {
                return response()->json(['ok' => false, 'error' => 'bad_date'], 422);
            }

            $from = Jalali::startOfDay($fromJ[0], $fromJ[1], $fromJ[2], $tz);
            $to = Jalali::startOfDay($toJ[0], $toJ[1], $toJ[2], $tz)->endOfDay();
        }

        if ($to->lessThan($from)) {
            return response()->json(['ok' => false, 'error' => 'reversed_range'], 422);
        }

        /*
         * سقفِ بازه. بی‌این، یک درخواستِ `from=1300-01-01&to=1500-12-29` هر پنج
         * provider را روی کلِ تاریخِ جدول‌ها می‌دواند — یک DoS رایگان از داخلِ
         * پنل، روی همان دیتابیسی که نشست و کش هم رویش است.
         */
        $maxDays = (int) config('calendar.max_range_days', 62);
        if ((int) abs($from->diffInDays($to)) > $maxDays) {
            return response()->json(['ok' => false, 'error' => 'range_too_wide', 'max_days' => $maxDays], 422);
        }

        $layers = $this->requestedLayers($request, $data['layers'] ?? null);
        $events = $this->calendar->events($from, $to, $layers);

        $payload = [
            'ok'        => true,
            'from'      => Jalali::format($fromJ[0], $fromJ[1], $fromJ[2]),
            'to'        => Jalali::format($toJ[0], $toJ[1], $toJ[2]),
            'grid'      => $grid,
            'today'     => Jalali::format(...$this->todayJalali()),
            'events'    => $events->map(fn (CalendarItem $i) => $i->toArray())->all(),
            // خرابیِ یک لایه **گزارش** می‌شود؛ لایهٔ خالیِ خراب نباید شبیهِ
            // لایهٔ خالیِ سالم باشد.
            'failures'  => $this->calendar->failures(),
            'truncated' => $this->calendar->truncatedLayers(),
        ];

        if ($request->boolean('with_upcoming')) {
            /*
             * ⚠️ `upcoming()` خودش `failures` را ریست می‌کند، پس **بعد** از
             * برداشتنِ خرابی‌های بازهٔ اصلی صدا زده می‌شود. برعکسش یعنی گزارشِ
             * خرابی همیشه مالِ هفتهٔ پیشِ‌رو بود، نه ماهی که کاربر می‌بیند.
             */
            $payload['upcoming'] = $this->calendar->upcoming($layers)
                ->map(fn (CalendarItem $i) => $i->toArray())->all();
        }

        return response()->json(array_filter($payload, static fn ($v) => $v !== null));
    }

    /** ساختِ یادآوری/کارِ دستی */
    public function store(Request $request): JsonResponse
    {
        $data = $this->check($request, [
            'type'        => ['required', 'string', Rule::in(CalendarEvent::types())],
            'title'       => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'event_date'  => ['required', 'string', 'max:10'],
            'status'      => ['nullable', 'string', Rule::in(CalendarEvent::statuses())],
            'repeat'      => ['nullable', 'string', Rule::in(CalendarEvent::repeats())],
            'repeat_until' => ['nullable', 'string', 'max:10'],
            // مبلغ در واحدِ فرعی (تومان) — عددِ صحیح، مثلِ بقیهٔ پولِ پروژه
            'amount'      => ['nullable', 'integer', 'min:0', 'max:99999999999999'],
            // کجا ثبت شود: دفترِ داخلی یا تقویمِ گوگلِ خودِ کاربر
            'target'      => ['nullable', 'string', Rule::in(['local', 'google'])],
        ], [
            'type' => 'نوع', 'title' => 'عنوان', 'description' => 'توضیح',
            'event_date' => 'تاریخ', 'repeat' => 'تکرار',
            'repeat_until' => 'تکرار تا', 'amount' => 'مبلغ',
        ]);

        if ($data instanceof JsonResponse) {
            return $data;
        }

        $jalali = Jalali::parse($data['event_date']);

        if ($jalali === null) {
            return response()->json(['ok' => false, 'error' => 'bad_date'], 422);
        }

        $until = null;
        if (($data['repeat_until'] ?? null) !== null && trim((string) $data['repeat_until']) !== '') {
            $untilJ = Jalali::parse($data['repeat_until']);

            if ($untilJ === null) {
                return response()->json(['ok' => false, 'error' => 'bad_until_date'], 422);
            }

            $until = $this->gregorian($untilJ);

            // پایانِ زودتر از شروع یعنی سری هیچ‌وقت دیده نمی‌شود — رد، نه ذخیرهٔ خاموش
            if ($until < $this->gregorian($jalali)) {
                return response()->json(['ok' => false, 'error' => 'until_before_start'], 422);
            }
        }

        /*
         * 🔴 مقصد **یکی** است، نه هر دو.
         *
         * اگر هم‌زمان محلی و گوگل ثبت می‌شد، همان رویداد دو بار در تقویم
         * می‌آمد — یک‌بار زیرِ لایهٔ خودش و یک‌بار زیرِ «تقویم گوگل من» — و
         * تیک‌زدنِ یکی روی دیگری اثری نداشت.
         *
         * ⚠️ گوگل تکرار و مبلغ را نمی‌فهمد (RRULE خودش را دارد و فیلدِ مبلغ
         * ندارد). پس برای اجاره و هزینه، مقصد باید داخلی باشد؛ برای جلسه‌ای
         * که باید روی گوشی بیاید، گوگل.
         */
        if (($data['target'] ?? 'local') === 'google') {
            return $this->storeInGoogle($request, $data, $jalali);
        }

        $event = CalendarEvent::create([
            'type'         => $data['type'],
            'title'        => $data['title'],
            'description'  => $data['description'] ?? null,
            'event_date'   => $this->gregorian($jalali),
            'repeat'       => $data['repeat'] ?? 'none',
            'repeat_until' => $until,
            'amount'       => $data['amount'] ?? null,
            'currency_code' => ($data['amount'] ?? null) !== null ? 'IRT' : null,
            'status'       => $data['status'] ?? 'pending',
            'user_id'      => $request->user()?->id,
            'meta'         => ['created_by_name' => $request->user()?->name],
        ]);

        return response()->json(['ok' => true, 'event' => $this->itemOf($event)->toArray()], 201);
    }

    /**
     * تغییرِ وضعیتِ یک رویدادِ دستی.
     *
     * ⚠️ بایندِ مدل روی `calendar_events` است، و رویدادهای **خودکار** اصلاً
     * ردیفی در آن جدول ندارند. پس «فقط رویدادِ دستی» بدونِ هیچ شرطِ اضافه‌ای
     * برقرار است: شناسهٔ `invoice:5` هرگز به مدل نمی‌رسد و ۴۰۴ می‌گیرد.
     */
    public function update(Request $request, CalendarEvent $event): JsonResponse
    {
        $data = $this->check($request, [
            'status'      => ['nullable', 'string', Rule::in(CalendarEvent::statuses())],
            'title'       => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'event_date'  => ['nullable', 'string', 'max:10'],
            // تاریخِ **میلادیِ** یک تکرارِ مشخص از یک سری (`2026-08-27`)
            'occurrence'  => ['nullable', 'string', 'date_format:Y-m-d'],
        ], ['status' => 'وضعیت', 'title' => 'عنوان', 'event_date' => 'تاریخ']);

        if ($data instanceof JsonResponse) {
            return $data;
        }

        /*
         * 🔴 وضعیتِ یک **تکرار** روی خودِ سری نوشته نمی‌شود.
         *
         * اگر «انجام شد»ِ اجارهٔ مرداد را روی `status`ِ ردیف می‌نوشتیم، شهریور و
         * مهر و همهٔ ماه‌های بعد هم انجام‌شده می‌شدند — یعنی یادآوریِ اجاره بعد
         * از اولین پرداخت برای همیشه خاموش می‌شد، بی‌هیچ خطایی.
         */
        $occurrence = $data['occurrence'] ?? null;

        if ($occurrence !== null && $event->isRecurring()) {
            $status = $data['status'] ?? null;

            if ($status === null) {
                return response()->json(['ok' => false, 'error' => 'nothing_to_update'], 422);
            }

            $event->markOccurrence($occurrence, $status);

            return response()->json([
                'ok'    => true,
                'event' => $this->itemOf($event->refresh(), $occurrence)->toArray(),
            ]);
        }

        $changes = array_filter(
            [
                'status'      => $data['status'] ?? null,
                'title'       => $data['title'] ?? null,
                'description' => $data['description'] ?? null,
            ],
            fn ($v) => $v !== null,
        );

        // توضیحِ خالی یعنی «پاکش کن» — `array_filter` بالا نالِ نبود را حذف
        // می‌کند، پس فرستادنِ رشتهٔ خالی راهِ صریحِ پاک‌کردن است.
        if ($request->exists('description') && $request->input('description') === '') {
            $changes['description'] = null;
        }

        if (($data['event_date'] ?? null) !== null) {
            $jalali = Jalali::parse($data['event_date']);

            if ($jalali === null) {
                return response()->json(['ok' => false, 'error' => 'bad_date'], 422);
            }

            [$gy, $gm, $gd] = Jalali::toGregorian(...$jalali);
            $changes['event_date'] = sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
        }

        if ($changes === []) {
            return response()->json(['ok' => false, 'error' => 'nothing_to_update'], 422);
        }

        $event->update($changes);

        return response()->json(['ok' => true, 'event' => $this->itemOf($event->refresh())->toArray()]);
    }

    /** حذف — فقط رویدادِ دستی (به همان دلیلِ توضیح‌داده‌شده در `update`) */
    public function destroy(CalendarEvent $event): JsonResponse
    {
        $id = $event->id;
        $event->delete();

        return response()->json(['ok' => true, 'deleted' => 'manual:'.$id]);
    }

    /** ذخیرهٔ «کدام لایه‌ها را می‌بینم» برای کاربرِ جاری */
    public function preferences(Request $request): JsonResponse
    {
        $data = $this->check($request, [
            'layers'   => ['required', 'array'],
            'layers.*' => ['boolean'],
        ], ['layers' => 'لایه‌ها']);

        if ($data instanceof JsonResponse) {
            return $data;
        }

        $userId = $request->user()?->id;

        if ($userId === null) {
            return response()->json(['ok' => false, 'error' => 'no_user'], 403);
        }

        $saved = CalendarLayerPreference::store($userId, array_map(
            static fn ($v) => filter_var($v, FILTER_VALIDATE_BOOLEAN),
            $data['layers'],
        ));

        return response()->json(['ok' => true, 'layers' => $saved]);
    }

    /* ==================================================================== */
    /*  اتصال به گوگل‌کلندر                                                  */
    /* ==================================================================== */

    /**
     * ساختِ رویداد مستقیماً در تقویمِ گوگلِ کاربر.
     *
     * ⚠️ اگر گوگل نپذیرد، **هیچ‌چیز ذخیره نمی‌شود و خطا برمی‌گردد**. حالتِ
     * «محلی ذخیره کن، بعداً می‌فرستیم» عمداً پیاده نشده: یک صفِ ارسال که
     * کاربر نبیندش یعنی رویدادی که فکر می‌کند روی گوشی‌اش هست و نیست.
     *
     * @param  array<string,mixed>  $data
     * @param  array{0:int,1:int,2:int}  $jalali
     */
    private function storeInGoogle(Request $request, array $data, array $jalali): JsonResponse
    {
        $token = GoogleCalendarToken::forUser($request->user()?->id);

        if ($token === null) {
            return response()->json(['ok' => false, 'error' => 'google_not_connected'], 422);
        }

        $day = $this->gregorian($jalali);

        $res = app(GoogleCalendarClient::class)->insertEvent(
            $token,
            $day,
            $data['title'],
            $data['description'] ?? null,
        );

        if (! $res['ok']) {
            return response()->json([
                'ok'    => false,
                'error' => 'google_insert_failed',
                'messages' => [$res['error'] ?? 'گوگل رویداد را نپذیرفت.'],
            ], 422);
        }

        // کش را پاک کن وگرنه رویدادِ تازه تا پنج دقیقه دیده نمی‌شود
        $this->forgetGoogleCache((int) $request->user()->id);

        return response()->json([
            'ok'    => true,
            'event' => (new CalendarItem(
                type: 'google',
                source: 'google',
                sourceId: (string) ($res['id'] ?? ''),
                title: $data['title'],
                description: $data['description'] ?? 'گوگل‌کلندر',
                at: Carbon::parse($day, $this->timezone()),
                status: 'pending',
                meta: ['all_day' => true],
                url: $res['link'] ?? null,
                editable: false,
            ))->toArray(),
        ], 201);
    }

    /**
     * شروعِ جریانِ OAuth.
     *
     * ⚠️ `state` یک رشتهٔ تصادفی است که در نشست می‌نشیند و در بازگشت سنجیده
     * می‌شود. بی‌آن، هر کسی می‌تواند کاربرِ واردشده را به یک `callback` با کدِ
     * **حسابِ خودش** بفرستد و تقویمِ خودش را به حسابِ او بچسباند (CSRF روی
     * OAuth). این نه یک تشریفات، که همان چیزی است که پارامترِ state برایش هست.
     */
    public function googleConnect(Request $request): RedirectResponse
    {
        if (! GoogleCalendarClient::configured()) {
            return redirect('/admin/calendar')
                ->with('err', 'اعتبارنامهٔ گوگل هنوز در تنظیمات وارد نشده است.');
        }

        $state = Str::random(40);
        $request->session()->put('google_calendar_state', $state);

        return redirect()->away(GoogleCalendarClient::authUrl($state, $this->googleRedirectUri()));
    }

    /** بازگشت از گوگل */
    public function googleCallback(Request $request, GoogleCalendarClient $google): RedirectResponse
    {
        $expected = $request->session()->pull('google_calendar_state');

        // ⚠️ مقایسهٔ زمان‌ثابت، و ردِ حالتی که اصلاً stateی در نشست نبوده
        if (blank($expected) || ! hash_equals((string) $expected, (string) $request->query('state'))) {
            return redirect('/admin/calendar')->with('err', 'درخواستِ اتصال معتبر نبود؛ دوباره تلاش کنید.');
        }

        if (filled($request->query('error'))) {
            return redirect('/admin/calendar')
                ->with('err', 'اتصال لغو شد: '.$request->query('error'));
        }

        $code = (string) $request->query('code');

        if (blank($code)) {
            return redirect('/admin/calendar')->with('err', 'کدِ بازگشتی از گوگل نیامد.');
        }

        $res = $google->exchangeCode($code, $this->googleRedirectUri());

        if (! $res['ok']) {
            /*
             * `no_refresh_token` پیامِ خودش را دارد چون علتش رفتاری است، نه
             * خرابی: گوگل برای حسابی که قبلاً یک بار پذیرفته refresh token
             * نمی‌فرستد مگر با `prompt=consent` — و اگر کاربر دسترسی را از
             * حسابش پاک نکند، ممکن است باز هم نیاید.
             */
            return redirect('/admin/calendar')->with('err', match ($res['error'] ?? '') {
                'no_refresh_token' => 'گوگل توکنِ ماندگار نداد. در حسابِ گوگل، دسترسیِ این اپ را حذف کنید و دوباره وصل شوید.',
                'network'          => 'ارتباط با گوگل برقرار نشد.',
                default            => 'اتصال ناموفق: '.($res['error'] ?? 'نامشخص'),
            });
        }

        GoogleCalendarToken::updateOrCreate(
            ['user_id' => $request->user()->id],
            [
                'google_email'  => $res['email'] ?? null,
                'calendar_id'   => 'primary',
                'access_token'  => $res['access_token'],
                'refresh_token' => $res['refresh_token'],
                'expires_at'    => $res['expires_at'],
                'last_error'    => null,
            ],
        );

        return redirect('/admin/calendar')->with('ok', 'تقویمِ گوگل وصل شد.');
    }

    /**
     * حذفِ یک رویداد از تقویمِ گوگلِ خودِ کاربر.
     *
     * ⚠️ شناسه از **مسیر** می‌آید و مستقیم به گوگل می‌رود، ولی خطری ندارد:
     * حذف همیشه روی تقویمِ توکنِ **همین کاربر** انجام می‌شود، پس بدترین حالت
     * این است که کسی رویدادِ خودش را پاک کند. شناسهٔ رویدادِ کاربرِ دیگر روی
     * تقویمِ این کاربر ۴۰۴ می‌گیرد.
     */
    public function googleDestroyEvent(Request $request, string $eventId, GoogleCalendarClient $google): JsonResponse
    {
        $token = GoogleCalendarToken::forUser($request->user()?->id);

        if ($token === null) {
            return response()->json(['ok' => false, 'error' => 'google_not_connected'], 422);
        }

        $res = $google->deleteEvent($token, $eventId);

        if (! $res['ok']) {
            return response()->json([
                'ok' => false, 'error' => 'google_delete_failed',
                'messages' => [$res['error'] ?? 'گوگل حذف را نپذیرفت.'],
            ], 422);
        }

        // بی‌این، رویدادِ حذف‌شده تا پنج دقیقه هنوز روی صفحه است
        $this->forgetGoogleCache((int) $request->user()->id);

        return response()->json(['ok' => true, 'deleted' => 'google:'.$eventId]);
    }

    /** قطعِ اتصال — فقط ردیفِ خودِ کاربر */
    public function googleDisconnect(Request $request): RedirectResponse
    {
        GoogleCalendarToken::query()->where('user_id', $request->user()->id)->delete();

        /*
         * کشِ همین کاربر هم باید برود، وگرنه تا پنج دقیقه رویدادهای یک حسابِ
         * قطع‌شده هنوز روی صفحه‌اند و کاربر فکر می‌کند قطع نشده.
         */
        $this->forgetGoogleCache($request->user()->id);

        return redirect('/admin/calendar')->with('ok', 'اتصالِ تقویمِ گوگل قطع شد.');
    }

    /**
     * 🔴 آدرسِ بازگشت باید **دقیقاً** همانی باشد که در Google Cloud ثبت شده.
     *
     * `url()` از میزبانِ درخواست می‌سازد، و پنل روی زیردامنهٔ `console` است.
     * ساختنش از `config('app.url')` غلط می‌شد چون آن apex است و گوگل تطبیق را
     * کاراکتربه‌کاراکتر می‌سنجد ⇒ `redirect_uri_mismatch`.
     */
    private function googleRedirectUri(): string
    {
        return url('/admin/calendar/google/callback');
    }

    /**
     * 🔴 ابطالِ کشِ گوگل با **بالابردنِ نسخه**، نه پاک‌کردنِ کلیدها.
     *
     * تلاشِ اول کلیدها را از مرزهای **میلادیِ** ماه می‌ساخت، در حالی که کلیدهای
     * واقعی از مرزهای **شمسی** ساخته می‌شوند (`2026-07-23:2026-08-22`). پس
     * هیچ‌وقت هیچ کلیدی را پاک نمی‌کرد و عملاً بی‌اثر بود: کاربر رویداد را در
     * گوگل می‌ساخت، تقویم تا پنج دقیقه نشانش نمی‌داد، و او فکر می‌کرد ساخته
     * نشده. روی سرورِ واقعی دیده شد.
     */
    private function forgetGoogleCache(int $userId): void
    {
        GoogleCalendarProvider::bumpVersion($userId);
    }

    /* ==================================================================== */
    /*  کمکی‌ها                                                              */
    /* ==================================================================== */

    /**
     * اعتبارسنجی با پاسخِ **JSON** روی خطا.
     *
     * 🔴 چرا `$request->validate()` این‌جا کار نمی‌کند:
     * `bootstrap/app.php` می‌گوید `shouldRenderJsonWhen(is('api/*'))`. یعنی
     * برای هر مسیرِ غیرِ `api/*` — از جمله همین روت‌ها — لاراول خطای
     * اعتبارسنجی را **۳۰۲ به صفحهٔ قبل** رندر می‌کند، حتی وقتی درخواست
     * `Accept: application/json` فرستاده.
     *
     * پیامدش برای این صفحه: `fetch` یک ریدایرکت می‌گرفت، آن را دنبال می‌کرد،
     * HTMLِ صفحهٔ اصلی را می‌خواند، `r.json()` می‌ترکید و کاربر پیامِ کلیِ
     * «ذخیره نشد» می‌دید — بی‌آنکه بفهمد کدام فیلد اشکال دارد.
     *
     * ⚠️ عمداً محلی حل شده و نه با دست‌بردن در `shouldRenderJsonWhen`:
     * آن یک خطِ سراسری است و بازکردنش برای `admin/*` رفتارِ خطای **همهٔ**
     * فرم‌های پنل را عوض می‌کند — فرم‌هایی که درست به همان ریدایرکت و
     * `$errors` در Blade تکیه دارند.
     *
     * @param  array<string,mixed>  $rules
     * @param  array<string,string>  $attributes
     * @return array<string,mixed>|JsonResponse
     */
    private function check(Request $request, array $rules, array $attributes = []): array|JsonResponse
    {
        $validator = Validator::make($request->all(), $rules, [], $attributes);

        if ($validator->fails()) {
            return response()->json([
                'ok'       => false,
                'error'    => 'validation',
                'messages' => $validator->errors()->all(),
            ], 422);
        }

        return $validator->validated();
    }

    /**
     * ماهِ خواسته‌شده از کوئری، وگرنه ماهِ جاری.
     *
     * ⚠️ ماهِ نامعتبر (`?m=13`) به ماهِ جاری برمی‌گردد نه به استثنا: یک لینکِ
     * خراب در تاریخچهٔ مرورگر نباید صفحه را بشکند.
     *
     * @return array{0:int,1:int}
     */
    private function requestedMonth(Request $request): array
    {
        [$ty, $tm] = $this->todayJalali();

        $jy = (int) $request->query('y', (string) $ty);
        $jm = (int) $request->query('m', (string) $tm);

        // بازهٔ سالِ شمسیِ معقول — بیرونش یعنی ورودیِ دستکاری‌شده
        if ($jy < 1300 || $jy > 1500 || $jm < 1 || $jm > 12) {
            return [$ty, $tm];
        }

        return [$jy, $jm];
    }

    /**
     * مرزهای میلادیِ یک ماهِ شمسی، به وقتِ نمایش.
     *
     * @return array{0:Carbon,1:Carbon}
     */
    private function monthBounds(int $jy, int $jm): array
    {
        $tz = $this->timezone();
        $days = Jalali::daysInMonth($jy, $jm);

        return [
            Jalali::startOfDay($jy, $jm, 1, $tz),
            Jalali::startOfDay($jy, $jm, $days, $tz)->endOfDay(),
        ];
    }

    /**
     * داربستِ شبکهٔ ماه: چند خانهٔ خالی پیش از روزِ یک، و چند روز دارد.
     *
     * @return array{offset:int, days:int, cells:list<array{day:int|null, date:string|null}>}
     */
    private function grid(int $jy, int $jm): array
    {
        $tz = $this->timezone();
        $days = Jalali::daysInMonth($jy, $jm);
        $offset = Jalali::weekdayIndex(Jalali::startOfDay($jy, $jm, 1, $tz));

        $cells = array_fill(0, $offset, ['day' => null, 'date' => null]);

        for ($d = 1; $d <= $days; $d++) {
            $cells[] = ['day' => $d, 'date' => Jalali::format($jy, $jm, $d)];
        }

        // خانه‌های پایانی تا کاملِ شدنِ آخرین ردیفِ هفت‌تایی — بی‌این، آخرین
        // ردیف کوتاه می‌مانَد و شبکهٔ CSS ستون‌ها را کش می‌آورد.
        while (count($cells) % 7 !== 0) {
            $cells[] = ['day' => null, 'date' => null];
        }

        return ['offset' => $offset, 'days' => $days, 'cells' => $cells];
    }

    /** @return array{0:int,1:int,2:int} امروز به شمسی، به وقتِ نمایش */
    private function todayJalali(): array
    {
        return Jalali::ofMoment(Carbon::now(), $this->timezone());
    }

    /**
     * لایه‌های مؤثرِ یک درخواست.
     *
     * ترتیبِ اولویت: `layers[]` صریحِ درخواست ← ترجیحِ ذخیره‌شدهٔ کاربر ← همه.
     *
     * ⚠️ `layers=[]`ِ **صریح** یعنی «هیچ لایه‌ای»، ولی `layers`ِ **نبود** یعنی
     * «ترجیحِ من». اگر این دو را یکی می‌گرفتیم، خاموش‌کردنِ همهٔ چیپ‌ها ناگهان
     * همه‌چیز را نشان می‌داد. `CalendarService::events()` هم همین تفکیک را
     * دارد، پس فهرستِ خالی بی‌دستکاری رد می‌شود.
     *
     * @param  list<string>|null  $requested
     * @return list<string>
     */
    private function requestedLayers(Request $request, ?array $requested): array
    {
        if ($requested !== null) {
            // رشتهٔ خالیِ «هیچ لایه‌ای» بیرون می‌رود و فهرست **خالی** می‌مانَد —
            // که برای `CalendarService::events()` دقیقاً یعنی «هیچ‌کدام».
            return array_values(array_filter($requested, static fn ($l) => is_string($l) && $l !== ''));
        }

        return array_keys(array_filter(CalendarLayerPreference::forUser($request->user()?->id)));
    }

    /**
     * مدلِ ذخیره‌شده → همان شکلی که provider می‌سازد (پاسخِ یکدست).
     *
     * `$occurrence` تاریخِ میلادیِ یک تکرارِ مشخص است؛ نبودش یعنی خودِ ردیف.
     */
    private function itemOf(CalendarEvent $event, ?string $occurrence = null): CalendarItem
    {
        $day = $occurrence ?? $event->event_date->toDateString();

        return new CalendarItem(
            type: $event->type,
            source: 'manual',
            sourceId: $event->isRecurring() ? $event->id.'@'.$day : $event->id,
            title: $event->title,
            description: $event->description,
            at: Carbon::parse($day, $this->timezone()),
            status: $event->statusOn($day),
            meta: [
                'event_id'   => $event->id,
                'repeat'     => $event->repeat,
                'occurrence' => $event->isRecurring() ? $day : null,
                'amount'     => $event->amount,
                'currency'   => $event->currency_code,
            ] + (array) ($event->meta ?? []),
            url: null,
            editable: true,
        );
    }

    /** @param array{0:int,1:int,2:int} $jalali */
    private function gregorian(array $jalali): string
    {
        [$gy, $gm, $gd] = Jalali::toGregorian($jalali[0], $jalali[1], $jalali[2]);

        return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
    }

    private function timezone(): string
    {
        return (string) config('calendar.display_timezone', 'Asia/Tehran');
    }
}
