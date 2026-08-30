<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * هزینهٔ ثابتِ هر سرویس — که صاحب کسب‌وکار خودش می‌نویسد.
 *
 * چرا جدول و نه config: کارفرما پرسید «این ‎−۱۵۰۰ تومانِ پیامک از کجا می‌آید؟»
 * جوابش این بود که یک عددِ حدسی در config گذاشته بودیم. درست این است که خودِ
 * صاحب کسب‌وکار تعرفهٔ واقعی هر سرویس را — پیامک، شاهکار، استعلام هویت،
 * صاحب کارت و هر هزینهٔ ثابت دیگری — این‌جا وارد کند تا دفتر مالی با عددِ
 * واقعیِ او هزینه ثبت کند، نه با فرض ما.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_costs', function (Blueprint $table) {
            $table->id();
            // کلید ماشینی که کد با آن هزینه را پیدا می‌کند (sms، shahkar، …)
            $table->string('key', 60)->unique();
            $table->string('label');                       // برچسب فارسی برای پنل
            $table->string('currency_code', 3)->default('IRT');
            $table->bigInteger('amount')->default(0);      // واحد فرعی (تومان)
            $table->string('note')->nullable();
            // هزینه‌ای که خودِ ما اضافه کرده‌ایم، در برابر کلیدهای داخلیِ سیستم
            // که کد به آن‌ها تکیه دارد و نباید حذف شوند
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        // مقادیر اولیه از همان اعدادی که در config/finance.php بود — تا رفتار
        // عوض نشود، ولی از حالا قابل ویرایش باشد.
        $now = now();
        $seed = [
            ['key' => 'shahkar',    'label' => 'استعلام شاهکار (تطابق کد ملی و موبایل)', 'amount' => 13000, 'note' => 'زحل — هر استعلام'],
            ['key' => 'identity',   'label' => 'استعلام هویت (ثبت احوال)',              'amount' => 68000, 'note' => 'زحل — هر استعلام'],
            ['key' => 'card_owner', 'label' => 'استعلام صاحب کارت',                     'amount' => 6000,  'note' => 'زحل — هر استعلام'],
            ['key' => 'sms',        'label' => 'پیامک (هر پیامک موفق)',                 'amount' => 1500,  'note' => 'آی‌پی‌پنل — تخمینی، ویرایش کنید'],
        ];

        foreach ($seed as $row) {
            Schema::getConnection()->table('service_costs')->insert($row + [
                'currency_code' => 'IRT',
                'is_system'     => true,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('service_costs');
    }
};
