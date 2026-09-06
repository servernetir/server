<?php

use App\Support\CardRedactor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * پاک‌کردنِ شمارهٔ کاملِ کارت از متن‌هایی که پیش از محافظِ امروز ذخیره شده‌اند.
 *
 * 🔴 چرا مهاجرت و نه فقط یک فرمانِ artisan: روی این پروژه **SSH نداریم** و
 * تنها کانالِ اجرای کد روی پروداکشن همین مهاجرت‌هاست (`/system/migrate`).
 * فرمانی که کسی نتواند اجرایش کند، یعنی همان یک ردیفی که کلِ این کار را لازم
 * کرد سرِ جایش می‌مانَد. `security:redact-cards` برای بازبینیِ خشک باقی است.
 *
 * ⚠️ `down()` عمداً وجود ندارد. برگرداندنِ شمارهٔ کارت نه ممکن است و نه
 * خواستنی — این تنها مهاجرتی است که بازگشتش خودش باگ است.
 */
return new class extends Migration
{
    private const TARGETS = [
        'ticket_messages' => ['body'],
        'tickets'         => ['subject'],
    ];

    public function up(): void
    {
        foreach (self::TARGETS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;   // نصبِ تازه — چیزی برای پاک‌کردن نیست
            }

            foreach ($columns as $column) {
                DB::table($table)->select('id', $column)->orderBy('id')->chunk(500,
                    function ($rows) use ($table, $column) {
                        foreach ($rows as $row) {
                            $before = (string) ($row->{$column} ?? '');
                            $after  = (string) CardRedactor::mask($before);

                            // ماسکِ دوباره روی متنِ ماسک‌شده بی‌اثر است، پس این
                            // مهاجرت روی اجرای مکرر هم بی‌خطر است.
                            if ($after !== $before) {
                                DB::table($table)->where('id', $row->id)
                                    ->update([$column => $after]);
                            }
                        }
                    });
            }
        }
    }
};
