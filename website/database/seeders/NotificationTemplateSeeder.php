<?php

namespace Database\Seeders;

use App\Models\NotificationTemplate;
use Illuminate\Database\Seeder;

/**
 * کاتالوگِ پیام‌های سامانه.
 *
 * متن‌ها **همان چیزی‌اند که امروز واقعاً فرستاده می‌شود** — از نقطه‌به‌نقطهٔ کد
 * برداشته شده‌اند. پس روزِ اول که این جدول پر می‌شود، هیچ پیامی عوض نمی‌شود؛
 * فقط از آن به بعد می‌شود ویرایششان کرد.
 *
 * ⚠️ `updateOrCreate` روی `key` و **فقط برای ردیفِ نبود**: اگر مدیر متنی را
 * عوض کرده باشد، اجرای دوبارهٔ seeder نباید رویش بنویسد.
 */
class NotificationTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->catalog() as $row) {
            NotificationTemplate::firstOrCreate(['key' => $row['key']], $row);
        }

        $this->repairGhostPlaceholders();
    }

    /**
     * 🔴 ترمیمِ جای‌نگهدارهای شبح — الگویی که به‌خاطرِ یک متغیرِ ناموجود مرده است.
     *
     * `firstOrCreate` عمداً ردیفِ موجود را دست نمی‌زند تا متنِ ویرایش‌شدهٔ مدیر
     * با هر دیپلوی برنگردد. ولی همان محافظ یعنی یک **غلطِ ما** هم برای همیشه
     * در دیتابیس می‌مانَد.
     *
     * نمونهٔ واقعی: بدنهٔ `invoice` متغیرِ `{due}` داشت و هیچ فراخوانی آن را
     * نمی‌فرستاد. هر دو خوانندهٔ الگو (`body()` و `email()`) اگر بعد از
     * جایگزینی هنوز `{چیزی}` ببینند عمداً الگو را کنار می‌گذارند — پس **هیچ
     * ایمیلِ فاکتوری فرستاده نمی‌شد**، ماه‌ها، بی‌هیچ خطایی. و صفحهٔ
     * `/admin/templates` هم دروغ می‌گفت: دکمهٔ «ارسال آزمایشی» کار می‌کرد، چون
     * آن‌جا مقدارِ نمونه برای `{due}` ساخته می‌شد.
     *
     * ⚠️ فقط ردیفی ترمیم می‌شود که **دقیقاً** همان متنِ سیدشدهٔ خراب را دارد.
     * اگر مدیر ویرایشش کرده، دست نمی‌خورد — ترمیمِ خودکارِ متنِ دست‌نویس،
     * از خودِ باگ بدتر است.
     */
    private function repairGhostPlaceholders(): void
    {
        $broken = [
            'invoice' => [
                'bale_body'  => ['فاکتور {number} به مبلغ {amount} تومان صادر شد. سررسید: {due}'],
                'email_body' => ['<p>فاکتور شماره <b>{number}</b> به مبلغ <b>{amount}</b> تومان صادر شد.</p><p>سررسید: {due}</p>'],
            ],
        ];

        foreach ($this->catalog() as $row) {
            $key = $row['key'];

            if (! isset($broken[$key])) {
                continue;
            }

            $tpl = NotificationTemplate::where('key', $key)->first();

            if ($tpl === null) {
                continue;
            }

            $dirty = false;

            foreach ($broken[$key] as $column => $stale) {
                if (in_array((string) $tpl->{$column}, $stale, true)) {
                    $tpl->{$column} = $row[$column];
                    $dirty = true;
                }
            }

            // فهرستِ متغیرهای پیشنهادی هم باید درست شود، وگرنه پنل همان
            // متغیرِ شبح را دوباره به مدیر پیشنهاد می‌دهد
            if ($dirty) {
                $tpl->variables = $row['variables'];
                $tpl->save();

                $this->command?->info("الگوی «{$key}»: متغیرِ شبح ترمیم شد.");
            }
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function catalog(): array
    {
        return [
            // ─────────────── حساب کاربری ───────────────
            [
                'key' => 'otp', 'title' => 'کد ورود یک‌بارمصرف', 'group' => 'account',
                'sms_event' => 'otp',
                'email_subject' => 'کد ورود سرورنت',
                'email_body' => '<p>کد ورود شما: <b>{code}</b></p><p>این کد تا ۳ دقیقه معتبر است. اگر شما درخواستش نکرده‌اید، نادیده بگیرید.</p>',
                'bale_body' => 'کد ورود سرورنت: {code}',
                'variables' => [['name' => 'code', 'desc' => 'کد ۶ رقمی']],
            ],
            [
                'key' => 'welcome', 'title' => 'خوش‌آمد پس از ثبت‌نام', 'group' => 'account',
                'sms_event' => 'welcome',
                'email_subject' => 'به سرورنت خوش آمدید',
                'email_body' => '<p>ثبت‌نام شما کامل شد. از پنل کاربری می‌توانید سرویس بخرید و مدیریتش کنید.</p>',
                'bale_body' => 'به سرورنت خوش آمدید. حساب شما ساخته شد.',
                'variables' => [],
            ],
            [
                'key' => 'password_changed', 'title' => 'تغییر رمز عبور توسط پشتیبانی', 'group' => 'account',
                'email_subject' => 'رمز عبور حساب شما تغییر کرد',
                'email_body' => '<p>رمز عبور حساب سرورنت شما توسط پشتیبانی تغییر کرد.</p><p>اگر این کار را درخواست نکرده‌اید، <b>فوراً</b> با ما تماس بگیرید.</p>',
                'bale_body' => 'رمز عبور حساب سرورنت شما توسط پشتیبانی تغییر کرد. اگر این کار را درخواست نکرده‌اید، فوراً با ما تماس بگیرید.',
                'variables' => [],
            ],

            // ─────────────── مالی ───────────────
            [
                'key' => 'invoice', 'title' => 'صدور فاکتور', 'group' => 'billing',
                'sms_event' => 'invoice',
                'email_subject' => 'فاکتور تازه — سرورنت',
                'email_body' => '<p>فاکتور شماره <b>{number}</b> به مبلغ <b>{amount}</b> تومان صادر شد.</p><p>پرداخت: {link}</p>',
                'bale_body' => 'فاکتور {number} به مبلغ {amount} تومان صادر شد. پرداخت: {link}',
                'variables' => [
                    ['name' => 'number', 'desc' => 'شمارهٔ فاکتور'],
                    ['name' => 'amount', 'desc' => 'مبلغ (تومان)'],
                    ['name' => 'link', 'desc' => 'نشانی پرداخت'],
                ],
            ],
            [
                'key' => 'paid', 'title' => 'تأیید پرداخت', 'group' => 'billing',
                'sms_event' => 'paid',
                'email_subject' => 'پرداخت شما ثبت شد',
                'email_body' => '<p>پرداخت شما به مبلغ <b>{amount}</b> تومان با موفقیت ثبت شد.</p><p>ممنون از اعتمادتان.</p>',
                'bale_body' => 'پرداخت شما به مبلغ {amount} تومان با موفقیت ثبت شد.',
                'variables' => [['name' => 'amount', 'desc' => 'مبلغ (تومان)']],
            ],
            [
                'key' => 'bank_rejected', 'title' => 'رد رسید واریز بانکی', 'group' => 'billing',
                'email_subject' => 'رسید واریز شما تأیید نشد',
                'email_body' => '<p>رسید واریز شما تأیید نشد.</p><p>{reason}</p><p>برای پیگیری با پشتیبانی در تماس باشید.</p>',
                'bale_body' => 'رسید واریز شما تأیید نشد. {reason} برای پیگیری با پشتیبانی در تماس باشید.',
                'variables' => [['name' => 'reason', 'desc' => 'علت رد (اگر نوشته شده باشد)']],
            ],

            // ─────────────── سرویس ───────────────
            [
                'key' => 'service_ready', 'title' => 'تحویل سرویس / سرور', 'group' => 'service',
                'sms_event' => 'service_ready',
                'email_subject' => 'سرویس شما آماده شد',
                'email_body' => '<p>سرویس <b>{service}</b> شما آماده شد.</p><p>IP: <b>{ip}</b></p><p>مشخصات ورود در پنل کاربری شماست.</p>',
                'bale_body' => 'سرورِ «{service}» شما آماده شد. IP: {ip}',
                'variables' => [
                    ['name' => 'service', 'desc' => 'نام سرویس'],
                    ['name' => 'ip', 'desc' => 'آدرس IPv4'],
                ],
            ],
            [
                'key' => 'expiring', 'title' => 'یادآوری تمدید (۷ / ۳ / ۱ روز)', 'group' => 'service',
                'sms_event' => 'expiring',
                'email_subject' => 'یادآوری تمدید سرویس',
                'email_body' => '<p>سرویس <b>{service}</b> تا <b>{days}</b> روز دیگر به سررسید می‌رسد.</p><p>برای جلوگیری از قطعی، فاکتور تمدید را پرداخت کنید.</p>',
                'bale_body' => 'سرویسِ «{service}» تا {days} روز دیگر سررسید می‌شود. برای جلوگیری از قطعی، فاکتور تمدید را پرداخت کنید.',
                // ⚠️ این فهرست باید **دقیقاً** همان چیزی باشد که فراخوان می‌فرستد
                // (RunServiceLifecycle::remindCustomer). چیپِ متغیری که کد
                // نمی‌فرستد، تلهٔ تمام‌عیار است: در «ارسال آزمایشی» با مقدارِ
                // نمونه درست دیده می‌شود، ولی سرِ ارسالِ واقعی جای‌نگهدارش پر
                // نمی‌ماند و محافظِ `incomplete()` کلِ الگو را کنار می‌گذارد —
                // یعنی ویرایشِ مدیر برای همیشه بی‌اثر می‌شود، بی‌هیچ خطایی.
                // `{due}` قبلاً همین‌جا بود و کد هرگز نمی‌فرستادش.
                'variables' => [
                    ['name' => 'service', 'desc' => 'نام سرویس'],
                    ['name' => 'days', 'desc' => 'روز باقی‌مانده'],
                    ['name' => 'amount', 'desc' => 'مبلغ تمدید'],
                    ['name' => 'link', 'desc' => 'لینک فاکتورها'],
                ],
            ],
            [
                'key' => 'domain_expiring', 'title' => 'یادآوری انقضای دامنه (۷ / ۳ / ۱ روز)', 'group' => 'service',
                'email_subject' => 'دامنهٔ شما دارد منقضی می‌شود',
                'email_body' => '<p>دامنهٔ <b>{domain}</b> تا <b>{days}</b> روز دیگر منقضی می‌شود.</p><p>هزینهٔ تمدید: {amount}</p><p>اگر تمدید نشود، دامنه از دستِ شما خارج می‌شود و ممکن است شخصِ دیگری آن را ثبت کند.</p>',
                'bale_body' => '⏰ دامنهٔ «{domain}» تا {days} روز دیگر منقضی می‌شود (هزینهٔ تمدید: {amount}). برای اینکه دامنه‌تان را از دست ندهید پرداخت کنید: {link}',
                // ⚠️ دقیقاً همان چهار متغیری که RunDomainLifecycle::remind می‌فرستد.
                //    متغیرِ اضافه یعنی جای‌نگهدارِ پرنشده و کنارگذاشتنِ کلِ الگو.
                'variables' => [
                    ['name' => 'domain', 'desc' => 'نام دامنه'],
                    ['name' => 'days', 'desc' => 'روز باقی‌مانده'],
                    ['name' => 'amount', 'desc' => 'هزینهٔ تمدید'],
                    ['name' => 'link', 'desc' => 'لینک فاکتورها'],
                ],
            ],
            [
                'key' => 'domain_expired', 'title' => 'انقضای دامنه', 'group' => 'service',
                'email_subject' => 'دامنهٔ شما منقضی شد',
                'email_body' => '<p>دامنهٔ <b>{domain}</b> منقضی شد و دورهٔ بازیابی‌اش هم گذشت.</p><p>اگر هنوز به آن نیاز دارید، در اسرع وقت با پشتیبانی تماس بگیرید.</p>',
                'bale_body' => '⛔ دامنهٔ «{domain}» منقضی شد و دورهٔ بازیابی‌اش هم گذشت. اگر هنوز لازمش دارید با پشتیبانی تماس بگیرید.',
                'variables' => [['name' => 'domain', 'desc' => 'نام دامنه']],
            ],
            [
                'key' => 'suspended', 'title' => 'تعلیق به‌دلیل عدم تمدید', 'group' => 'service',
                'email_subject' => 'سرویس شما موقتاً غیرفعال شد',
                'email_body' => '<p>سرویس <b>{service}</b> به‌دلیل پرداخت‌نشدن فاکتور تمدید موقتاً غیرفعال شد.</p><p>اطلاعات و فایل‌هایتان محفوظ است؛ با پرداخت فاکتور بلافاصله برمی‌گردد.</p>',
                'bale_body' => '⚠️ سرویسِ «{service}» به‌دلیلِ پرداخت‌نشدنِ فاکتورِ تمدید موقتاً غیرفعال شد. اطلاعات و فایل‌هایتان محفوظ است؛ با پرداختِ فاکتور بلافاصله برمی‌گردد.',
                'variables' => [['name' => 'service', 'desc' => 'نام سرویس']],
            ],
            [
                'key' => 'reactivated', 'title' => 'رفع تعلیق پس از پرداخت', 'group' => 'service',
                'email_subject' => 'سرویس شما دوباره فعال شد',
                'email_body' => '<p>سرویس <b>{service}</b> تمدید شد و دوباره فعال است. ممنون از پرداختتان.</p>',
                'bale_body' => 'سرویسِ «{service}» شما تمدید شد و دوباره فعال است. ممنون از پرداختتان.',
                'variables' => [['name' => 'service', 'desc' => 'نام سرویس']],
            ],

            // ─────────────── پشتیبانی ───────────────
            [
                'key' => 'ticket_reply', 'title' => 'پاسخ به تیکت', 'group' => 'support',
                'sms_event' => 'ticket_reply',
                'email_subject' => 'پاسخ تازه به تیکت {number}',
                'email_body' => '<p>پاسخ جدیدی به تیکت <b>{number}</b> شما داده شد.</p><p>برای مشاهده به پنل کاربری مراجعه کنید.</p>',
                'bale_body' => 'پاسخ جدیدی به تیکت {number} شما داده شد. برای مشاهده به پنل کاربری مراجعه کنید.',
                'variables' => [['name' => 'number', 'desc' => 'شمارهٔ تیکت']],
            ],

            // ─────────────── سایر ───────────────
            [
                'key' => 'announce', 'title' => 'اطلاعیهٔ گروهی', 'group' => 'other',
                'email_subject' => '{title}',
                'email_body' => '<p>{body}</p>',
                'bale_body' => '{body}',
                'variables' => [
                    ['name' => 'title', 'desc' => 'عنوان اطلاعیه'],
                    ['name' => 'body', 'desc' => 'متن اطلاعیه'],
                ],
            ],

            /*
            |------------------------------------------------------------------
            | ۱۱ رویدادی که پیامشان زنده بود ولی ردیفی در این کاتالوگ نداشتند
            |------------------------------------------------------------------
            |
            | 🔴 مدیر می‌خواست متنِ «سرویس شما خاتمه یافت و داده‌هایش حذف شد» را
            | نرم‌تر کند، در این صفحه ردیفی برایش پیدا نمی‌کرد و نتیجه می‌گرفت
            | چنین پیامی وجود ندارد — در حالی که همان لحظه متنِ سخت‌کدِ
            | `ProvisioningService` داشت به مشتری می‌رفت. صفحه ادعای «کاتالوگِ
            | پیام‌ها» بودن می‌کرد و نصفِ پیام‌ها را نشان نمی‌داد.
            |
            | ⚠️ متغیرها **دقیقاً** همان‌هایی است که `NotifyEvent::vars()` اعلام
            | می‌کند. متغیرِ اضافه یعنی الگو برای همیشه کنار گذاشته می‌شود —
            | `NotificationSilenceTest` این را قفل کرده.
            |
            | ⚠️ اگر متغیری از قلم بیفتد، متنِ سخت‌کدِ فراخوان جایگزین می‌شود؛
            | یعنی بدترین حالت همان رفتارِ قبلی است، نه پیامِ ناقص.
            */
            [
                'key' => 'payment_due', 'title' => 'رسیدن موعد پرداخت', 'group' => 'billing',
                'sms_event' => 'payment_due',
                'email_subject' => 'یادآوری پرداخت — فاکتور {number}',
                'email_body' => '<p>فاکتور <b>{number}</b> به مبلغ <b>{amount}</b> تومان در انتظار پرداخت است.</p><p><a href="{link}">پرداخت فاکتور</a></p>',
                'bale_body' => 'یادآوری: فاکتور {number} به مبلغ {amount} تومان هنوز پرداخت نشده. {link}',
                'variables' => [
                    ['name' => 'number', 'desc' => 'شمارهٔ فاکتور'],
                    ['name' => 'amount', 'desc' => 'مبلغ (تومان)'],
                    ['name' => 'link', 'desc' => 'نشانی پرداخت'],
                ],
            ],
            [
                'key' => 'service_ordered', 'title' => 'ثبت سفارش سرویس', 'group' => 'service',
                'sms_event' => 'service_ordered',
                'email_subject' => 'سفارش شما ثبت شد — {service}',
                'email_body' => '<p>سفارش <b>{service}</b> به مبلغ <b>{amount}</b> تومان ثبت شد.</p><p>پس از پرداخت، سرویس به‌صورت خودکار تحویل می‌شود.</p>',
                'bale_body' => 'سفارش {service} به مبلغ {amount} تومان ثبت شد.',
                'variables' => [
                    ['name' => 'service', 'desc' => 'نام سرویس'],
                    ['name' => 'amount', 'desc' => 'مبلغ (تومان)'],
                ],
            ],
            [
                'key' => 'service_failed', 'title' => 'شکست در تحویل سرویس', 'group' => 'service',
                'sms_event' => 'service_failed',
                'email_subject' => 'تحویل {service} به تأخیر افتاد',
                'email_body' => '<p>تحویل خودکار <b>{service}</b> انجام نشد.</p><p>تیم پشتیبانی در حال بررسی است و به‌زودی با شما تماس می‌گیرد؛ مبلغی از دست نمی‌رود.</p>',
                'bale_body' => 'تحویل {service} انجام نشد؛ پشتیبانی در حال بررسی است.',
                'variables' => [
                    ['name' => 'service', 'desc' => 'نام سرویس'],
                ],
            ],
            [
                'key' => 'renewed', 'title' => 'تمدید موفق سرویس', 'group' => 'service',
                'sms_event' => 'renewed',
                'email_subject' => 'سرویس شما تمدید شد — {service}',
                'email_body' => '<p>سرویس <b>{service}</b> تمدید شد و تا <b>{until}</b> فعال است.</p>',
                'bale_body' => 'سرویس {service} تمدید شد. اعتبار تا {until}',
                'variables' => [
                    ['name' => 'service', 'desc' => 'نام سرویس'],
                    ['name' => 'until', 'desc' => 'تاریخ سررسید تازه'],
                ],
            ],
            [
                'key' => 'data_deletion_due', 'title' => 'هشدار حذف دائمی داده', 'group' => 'service',
                'sms_event' => 'data_deletion_due',
                'email_subject' => 'هشدار: داده‌های {service} تا {days} روز دیگر حذف می‌شود',
                'email_body' => '<p>سرویس <b>{service}</b> تمدید نشده و داده‌هایش تا <b>{days}</b> روز دیگر <b>برای همیشه</b> حذف می‌شود.</p><p>پس از حذف، بازگردانی ممکن نیست.</p>',
                'bale_body' => 'هشدار: داده‌های {service} تا {days} روز دیگر برای همیشه حذف می‌شود.',
                'variables' => [
                    ['name' => 'service', 'desc' => 'نام سرویس'],
                    ['name' => 'days', 'desc' => 'روزهای باقی‌مانده'],
                ],
            ],
            [
                'key' => 'terminated', 'title' => 'خاتمهٔ سرویس و حذف داده', 'group' => 'service',
                'sms_event' => 'terminated',
                'email_subject' => 'سرویس {service} خاتمه یافت',
                'email_body' => '<p>سرویس <b>{service}</b> خاتمه یافت و داده‌هایش حذف شد.</p><p>از همراهی شما سپاسگزاریم.</p>',
                'bale_body' => 'سرویس {service} خاتمه یافت و داده‌هایش حذف شد.',
                'variables' => [
                    ['name' => 'service', 'desc' => 'نام سرویس'],
                ],
            ],
            [
                'key' => 'domain_registered', 'title' => 'ثبت موفق دامنه', 'group' => 'domain',
                'sms_event' => 'domain_registered',
                'email_subject' => 'دامنه {domain} ثبت شد',
                'email_body' => '<p>دامنه <b>{domain}</b> با موفقیت ثبت شد و تا <b>{until}</b> اعتبار دارد.</p>',
                'bale_body' => 'دامنه {domain} ثبت شد. اعتبار تا {until}',
                'variables' => [
                    ['name' => 'domain', 'desc' => 'نام دامنه'],
                    ['name' => 'until', 'desc' => 'تاریخ انقضا'],
                ],
            ],
            [
                'key' => 'domain_renewed', 'title' => 'تمدید موفق دامنه', 'group' => 'domain',
                'sms_event' => 'domain_renewed',
                'email_subject' => 'دامنه {domain} تمدید شد',
                'email_body' => '<p>دامنه <b>{domain}</b> تمدید شد و تا <b>{until}</b> اعتبار دارد.</p>',
                'bale_body' => 'دامنه {domain} تمدید شد. اعتبار تا {until}',
                'variables' => [
                    ['name' => 'domain', 'desc' => 'نام دامنه'],
                    ['name' => 'until', 'desc' => 'تاریخ انقضای تازه'],
                ],
            ],
            [
                'key' => 'ticket_new', 'title' => 'ثبت تیکت جدید', 'group' => 'support',
                'sms_event' => 'ticket_new',
                'email_subject' => 'تیکت {number} ثبت شد',
                'email_body' => '<p>تیکت شماره <b>{number}</b> با موضوع «{subject}» ثبت شد.</p><p>پاسخ را از همین‌جا و در پنل کاربری دنبال کنید.</p>',
                'bale_body' => 'تیکت {number} ثبت شد: {subject}',
                'variables' => [
                    ['name' => 'number', 'desc' => 'شمارهٔ تیکت'],
                    ['name' => 'subject', 'desc' => 'موضوع تیکت'],
                ],
            ],
            [
                'key' => 'ticket_closed', 'title' => 'بستن تیکت', 'group' => 'support',
                'sms_event' => 'ticket_closed',
                'email_subject' => 'تیکت {number} بسته شد',
                'email_body' => '<p>تیکت شماره <b>{number}</b> بسته شد.</p><p>اگر موضوع حل نشده، با پاسخ‌دادن دوباره بازش کنید.</p>',
                'bale_body' => 'تیکت {number} بسته شد.',
                'variables' => [
                    ['name' => 'number', 'desc' => 'شمارهٔ تیکت'],
                ],
            ],
            [
                'key' => 'ticket_survey', 'title' => 'نظرسنجی پس از بستن تیکت', 'group' => 'support',
                'sms_event' => 'ticket_survey',
                'email_subject' => 'نظر شما دربارهٔ تیکت {number}',
                'email_body' => '<p>تیکت <b>{number}</b> بسته شد. اگر یک دقیقه وقت دارید، از کیفیت پشتیبانی بگویید.</p><p><a href="{link}">ثبت نظر</a></p>',
                'bale_body' => 'تیکت {number} بسته شد. نظرتان دربارهٔ پشتیبانی: {link}',
                'variables' => [
                    ['name' => 'number', 'desc' => 'شمارهٔ تیکت'],
                    ['name' => 'link', 'desc' => 'نشانی نظرسنجی'],
                ],
            ],
        ];
    }
}
