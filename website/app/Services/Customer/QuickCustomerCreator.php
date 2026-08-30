<?php

namespace App\Services\Customer;

use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Services\Otp\OtpService;
use Illuminate\Support\Facades\Schema;

/**
 * ساختِ سریعِ مشتری — برای وقتی که کارفرما پشتِ تلفن است.
 *
 * ═══ چرا جدا از ثبت‌نامِ خودِ مشتری ═══
 *
 * ثبت‌نامِ عادی OTP و احرازِ هویتِ ایرانی دارد و هر کدام **پول** خرج می‌کنند
 * (شاهکار + استعلامِ هویت ≈ ۸۱ هزار تومان). این‌جا هیچ‌کدام صدا زده نمی‌شوند:
 * کارفرما خودش با آدم حرف زده و همان لحظه ثبتش می‌کند.
 *
 * ⚠️ پس حسابی که این‌جا ساخته می‌شود **احراز نشده** است و باید بمانَد. اگر
 * روزی خودِ مشتری وارد شود، مسیرِ عادی احرازش می‌کند.
 *
 * ═══ محافظِ اصلی: مشتریِ تکراری ═══
 *
 * 🔴 موبایل و ایمیل هر دو در `customers` یکتا هستند. ساختِ تکراری نه‌تنها
 * خطای دیتابیس می‌دهد، بلکه اگر روزی آن قید برداشته شود، دو پروندهٔ موازی
 * برای یک آدم می‌سازد — و سرویس و فاکتورش بینشان پخش می‌شود.
 *
 * پس پیش از ساخت، هر دو پرسیده می‌شوند و اگر ردیفی باشد **همان برگردانده
 * می‌شود**، نه ردیفِ تازه.
 */
class QuickCustomerCreator
{
    /**
     * @return array{ok:bool,customer:?Customer,existing:bool,message:string}
     */
    public function create(string $name, string $mobile, ?string $email = null): array
    {
        if (! Schema::hasTable('customers')) {
            return ['ok' => false, 'customer' => null, 'existing' => false,
                    'message' => 'جدولِ مشتریان روی این نصب نیست.'];
        }

        $name = trim($name);

        /*
        | 🔴 **همان** نرمال‌سازی‌ای که ثبت‌نام و ورود می‌کنند — نه یک نسخهٔ دوم.
        |
        | `IranianMobile::national()` بخشِ ملی را می‌دهد (`912…`, بی‌صفر) و برای
        | اپراتورِ پیامک درست است، ولی ستونِ `customers.phone` همه‌جا `09…`
        | نگه می‌دارد. ذخیرهٔ قالبِ دوم یک خرابیِ کاملاً خاموش می‌ساخت:
        |
        |   • `where('phone', …)` مشتریِ موجود را پیدا نمی‌کرد ⇒ پروندهٔ موازی
        |   • ورودِ خودِ مشتری با موبایل هرگز این حساب را پیدا نمی‌کرد
        |
        | و هیچ‌کدام خطا نمی‌داد — فقط مشتری‌ای که نمی‌تواند وارد شود.
        */
        $mobile = app(OtpService::class)->normalize('sms', $mobile);
        $email  = $email !== null ? trim(mb_strtolower($email)) : null;

        if (mb_strlen($name) < 3) {
            return ['ok' => false, 'customer' => null, 'existing' => false,
                    'message' => 'نام خیلی کوتاه است.'];
        }

        if ($mobile === '') {
            return ['ok' => false, 'customer' => null, 'existing' => false,
                    'message' => 'شمارهٔ موبایل معتبر نیست (۰۹xxxxxxxxx).'];
        }

        if ($email !== null && $email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'customer' => null, 'existing' => false,
                    'message' => 'ایمیل معتبر نیست.'];
        }

        // ── مشتریِ موجود؟ همان را برگردان، ردیفِ دوم نساز ──
        $existing = Customer::where('phone', $mobile)
            ->when($email, fn ($q) => $q->orWhere('email', $email))
            ->first();

        if ($existing !== null) {
            return ['ok' => true, 'customer' => $existing, 'existing' => true,
                    'message' => 'این شماره یا ایمیل از قبل ثبت است — پروندهٔ موجود باز شد.'];
        }

        /*
        | ⚠️ ایمیل ستونِ اجباری است. اگر کارفرما نداشته باشد، یک نشانیِ
        | جای‌نگهدارِ **یکتا و آشکارا ساختگی** می‌گذاریم تا:
        |   • قیدِ یکتایی نشکند
        |   • و بعداً بشود با یک نگاه فهمید کدام حساب ایمیلِ واقعی ندارد
        |
        | ⚠️ هیچ ایمیلی به این نشانی نمی‌رود؛ دامنهٔ `.invalid` طبقِ RFC 2606
        | هرگز قابلِ ثبت نیست، پس امکان ندارد به دستِ کسی برسد.
        */
        $email = ($email !== null && $email !== '') ? $email : $mobile.'@no-email.invalid';

        try {
            $customer = Customer::create([
                'email'    => $email,
                'phone'    => $mobile,
                // 🔴 رمزِ تصادفیِ غیرقابلِ‌حدس. حسابِ بی‌رمز یعنی هر کسی که شماره
                // را بداند می‌تواند واردش شود؛ مشتری بعداً از «فراموشی رمز»
                // خودش می‌سازد.
                'password' => bcrypt(bin2hex(random_bytes(16))),
                'status'   => 'active',
                'locale'   => 'fa',
            ]);
        } catch (\Throwable $e) {
            \App\Support\ErrorTracker::note('customer', $e, ['step' => 'quick-create']);

            return ['ok' => false, 'customer' => null, 'existing' => false,
                    'message' => 'ساختِ مشتری انجام نشد.'];
        }

        // نام در پروفایل می‌نشیند، نه در `customers` — همان جایی که پنل می‌گذارد
        try {
            if (Schema::hasTable('customer_profiles')) {
                [$first, $last] = $this->splitName($name);

                CustomerProfile::create([
                    'customer_id' => $customer->id,
                    'type'        => 'individual',
                    'is_default'  => true,
                    'status'      => 'pending',      // احراز نشده، و باید بمانَد
                    'first_name'  => $first,
                    'last_name'   => $last,
                    'mobile'      => $mobile,
                    'email'       => $email,
                    'country'     => 'IR',
                ]);
            }
        } catch (\Throwable $e) {
            // پروفایل best-effort است: حساب ساخته شده و نباید برگردد
            \App\Support\ErrorTracker::note('customer', $e, ['step' => 'quick-profile']);
        }

        return ['ok' => true, 'customer' => $customer->fresh(), 'existing' => false,
                'message' => 'مشتری ساخته شد.'];
    }

    /** «محمد رضایی» → [محمد, رضایی] · تک‌واژه → نامِ خانوادگیِ خالی */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [$name];

        if (count($parts) === 1) {
            return [$parts[0], ''];
        }

        $last = array_pop($parts);

        return [implode(' ', $parts), $last];
    }
}
