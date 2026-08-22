<?php

namespace App\Services\CloudPhone;

use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\PhoneCall;
use App\Support\IranianPhone;

/**
 * «این شماره مالِ کدام مشتری است؟»
 *
 * ═══ 🔴 چرا این ساده نیست ═══
 *
 * تلفن ابری شمارهٔ ثابت را **بدونِ پیش‌شمارهٔ شهر** تحویل می‌دهد. با داده
 * واقعی ثابت شد: شمارهٔ خودمان `02171057757` است و در payload به‌صورت
 * `71057757` می‌آید.
 *
 * پس `34261000` می‌تواند مالِ سه مشتری در سه شهر باشد. و تطبیقِ **غلط** بدتر
 * از نداشتنِ تطبیق است: کارشناس صفحهٔ مشتریِ اشتباه را باز می‌کند، اسمِ اشتباه
 * را صدا می‌زند، و بدتر — ممکن است اطلاعاتِ حسابِ یک نفر را به نفرِ دیگری
 * بگوید. یعنی نشتِ اطلاعات، نه صرفاً یک باگِ رابط کاربری.
 *
 * قاعده: **وقتی مبهم است، وصل نکن.** بیش از یک نفر خورد ⇒ هیچ‌کدام.
 */
final class CustomerMatcher
{
    /**
     * @return array{customer_id: ?int, confidence: string}
     */
    public function match(?string $rawNumber): array
    {
        $none = ['customer_id' => null, 'confidence' => PhoneCall::MATCH_NONE];

        if ($rawNumber === null || $rawNumber === '') {
            return $none;
        }

        $norm = IranianPhone::normalize($rawNumber);
        $kind = IranianPhone::kind($rawNumber);

        if ($norm === null) {
            return $none;
        }

        /*
        | داخلیِ کوتاه هرگز مشتری نیست — تماسِ داخلیِ خودمان است.
        | بدونِ این، یک داخلیِ سه‌رقمی با پسوندِ شمارهٔ هر مشتری‌ای جفت می‌شد.
        */
        if ($kind === IranianPhone::KIND_EXTENSION || $kind === IranianPhone::KIND_UNKNOWN) {
            return $none;
        }

        $ids = $kind === IranianPhone::KIND_LOCAL
            ? $this->bySuffix($norm)
            : $this->byExactNumber($norm);

        if ($ids === []) {
            return $none;
        }

        if (count($ids) > 1) {
            // 🔴 عمداً هیچ‌کدام. نگاه کن به توضیحِ بالای کلاس.
            return ['customer_id' => null, 'confidence' => PhoneCall::MATCH_MANY];
        }

        return [
            'customer_id' => $ids[0],
            'confidence' => $kind === IranianPhone::KIND_LOCAL
                ? PhoneCall::MATCH_LOCAL     // ⚠️ یک نفر خورد، ولی پیش‌شماره نداشتیم
                : PhoneCall::MATCH_EXACT,
        ];
    }

    /**
     * موبایل یا ثابتِ کامل — برابریِ دقیق روی شکلِ نرمال‌شده.
     *
     * ⚠️ شماره‌ها در دیتابیس **نرمال‌نشده** ذخیره شده‌اند (`customers.phone`
     * ادعای E.164 دارد، `customer_profiles.mobile` شکلِ `09…`). پس نمی‌شود در
     * SQL مقایسه کرد؛ باید در PHP نرمال کرد.
     *
     * برای اینکه این کارِ کلِ جدول نشود، اول با `LIKE` روی ۷ رقمِ آخر غربال
     * می‌کنیم و بعد دقیق مقایسه — ایندکس کمکی نمی‌کند ولی مجموعهٔ کاندید
     * کوچک می‌شود.
     */
    private function byExactNumber(string $norm): array
    {
        $tail = substr($norm, -7);
        $ids = [];

        Customer::query()
            ->select(['id', 'phone'])
            ->whereNotNull('phone')
            ->where('phone', 'like', '%'.$tail)
            ->chunkById(500, function ($rows) use ($norm, &$ids) {
                foreach ($rows as $row) {
                    if (IranianPhone::normalize((string) $row->phone) === $norm) {
                        $ids[] = (int) $row->id;
                    }
                }
            });

        CustomerProfile::query()
            ->select(['id', 'customer_id', 'mobile'])
            ->whereNotNull('mobile')
            ->where('mobile', 'like', '%'.$tail)
            ->chunkById(500, function ($rows) use ($norm, &$ids) {
                foreach ($rows as $row) {
                    if (IranianPhone::normalize((string) $row->mobile) === $norm) {
                        $ids[] = (int) $row->customer_id;
                    }
                }
            });

        return array_values(array_unique($ids));
    }

    /**
     * شمارهٔ محلیِ بی‌پیش‌شماره — تطبیقِ پسوندی.
     *
     * ⚠️ `IranianPhone::KIND_LOCAL` دستِ‌کم ۶ رقم را تضمین می‌کند، پس پسوندِ
     * کوتاه و پرتصادف نداریم. با این حال نتیجه `MATCH_LOCAL` علامت می‌خورد و
     * نه `MATCH_EXACT` — رابط کاربری باید تردید را نشان دهد.
     */
    private function bySuffix(string $local): array
    {
        $ids = [];

        Customer::query()
            ->select(['id', 'phone'])
            ->whereNotNull('phone')
            ->where('phone', 'like', '%'.$local)
            ->chunkById(500, function ($rows) use ($local, &$ids) {
                foreach ($rows as $row) {
                    if (IranianPhone::couldMatch((string) $row->phone, $local)) {
                        $ids[] = (int) $row->id;
                    }
                }
            });

        CustomerProfile::query()
            ->select(['id', 'customer_id', 'mobile'])
            ->whereNotNull('mobile')
            ->where('mobile', 'like', '%'.$local)
            ->chunkById(500, function ($rows) use ($local, &$ids) {
                foreach ($rows as $row) {
                    if (IranianPhone::couldMatch((string) $row->mobile, $local)) {
                        $ids[] = (int) $row->customer_id;
                    }
                }
            });

        return array_values(array_unique($ids));
    }
}
