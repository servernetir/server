<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * پکیج‌های فروش — چیزی که مشتری آنلاین می‌خرد.
 *
 * برخلاف کاتالوگِ بازاریابیِ config-driven، این‌ها را خودِ مدیر در پنل می‌سازد
 * و ویرایش می‌کند. هر پکیج می‌تواند به یک سرورِ تحویل وصل باشد تا پس از خرید،
 * خودکار ساخته شود. همهٔ مبالغ BIGINT در واحدِ فرعی (تومان).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('slug', 160)->unique();
            $table->string('category', 20)->default('shared'); // shared|reseller|vps|dedicated|plesk|directadmin|other
            $table->foreignId('server_id')->nullable();         // سرورِ تحویل (بدونِ FK آبشاری)
            $table->string('plan', 80)->nullable();             // نامِ package در WHM
            $table->string('currency_code', 3)->default('IRT');
            $table->bigInteger('price')->default(0);            // مبلغ هر دوره، پیش از مالیات
            $table->bigInteger('setup_fee')->default(0);        // هزینهٔ راه‌اندازیِ یک‌بار
            $table->string('cycle', 12)->default('monthly');    // once|monthly|quarterly|yearly
            $table->unsignedTinyInteger('tax_percent')->default(10);
            $table->json('specs')->nullable();                  // [{label,value}] مشخصات نمایشی
            $table->text('description')->nullable();
            $table->boolean('requires_domain')->default(false); // هاست دامنه می‌خواهد
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'category', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
