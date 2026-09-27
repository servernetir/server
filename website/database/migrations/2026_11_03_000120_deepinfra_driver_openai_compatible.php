<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M5.1b — درایورِ DeepInfra ِ سیدشده ⇒ `OpenAI-Compatible` (D16).
 *
 * ردیفِ سید با `driver='DeepInfra'` ساخته شده بود که `AiCaller` نمی‌شناسد، پس هر
 * تماس `driver_unsupported` می‌گرفت. DeepInfra سازگار با OpenAI است
 * (`https://api.deepinfra.com/v1/openai/chat/completions`).
 *
 * 🔴 چرا فقط هم‌راه با هستهٔ پولیِ M5.1b: درایورِ درست یعنی مسیرِ /v1 می‌تواند به
 * بالادست برسد. پیش از موتورِ تازه، آن مسیر باگِ B1 داشت (میکرو را تومان کسر
 * می‌کرد). با M5.1b مسیرِ /v1 سدهای فروش را هم می‌خوانَد و فروش بسته است
 * (`ai_sales_open` خالی) — این مهاجرت هیچ پرچمِ فروش یا فعال‌بودنی را عوض نمی‌کند.
 *
 * آدرسِ پایه فقط وقتی خالی است نوشته می‌شود؛ کلیدِ API هرگز این‌جا نیست
 * (از پنلِ مدیر، رمزشده).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_providers')) {
            return;
        }

        DB::table('ai_providers')
            ->where('slug', 'deepinfra')
            ->where('driver', 'DeepInfra')
            ->update(['driver' => 'OpenAI-Compatible', 'updated_at' => now()]);

        if (Schema::hasTable('settings')) {
            $key = 'ai_provider_deepinfra_base_url';
            $row = DB::table('settings')->where('key', $key)->first();

            if ($row === null) {
                DB::table('settings')->insert([
                    'key' => $key, 'value' => 'https://api.deepinfra.com/v1/openai',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } elseif (blank($row->value)) {
                DB::table('settings')->where('key', $key)
                    ->update(['value' => 'https://api.deepinfra.com/v1/openai', 'updated_at' => now()]);
            }

            // Setting::cached() کشِ ۳۰۰ ثانیه‌ای دارد؛ نوشتنِ مستقیم آن را کهنه می‌گذاشت
            \Illuminate\Support\Facades\Cache::forget('settings.all');
        }
    }

    public function down(): void {}
};
