<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تنظیمات کلید-مقدارِ ساده — چیزهایی که مدیر ویرایش می‌کند و در .env نیستند.
 *
 * اولین کاربرد: مشخصات حساب بانکی شرکت برای «واریز به حساب». این‌طور شمارهٔ
 * شبا/حساب واقعی را مدیر خودش در پنل وارد می‌کند و ما در کد یا env نگهش
 * نمی‌داریم.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        // کلیدهای مشخصات بانکی، خالی — مدیر پرشان می‌کند
        $now = now();
        $keys = ['bank_holder', 'bank_name', 'bank_account', 'bank_sheba', 'bank_card', 'bank_note'];
        foreach ($keys as $k) {
            Schema::getConnection()->table('settings')->insert([
                'key' => $k, 'value' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
