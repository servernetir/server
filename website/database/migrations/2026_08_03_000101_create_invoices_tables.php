<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فاکتور و ردیف‌هایش.
 *
 * ═══ قاعدهٔ مطلق پول ═══
 * هر مبلغ BIGINT در واحد فرعی ارز است. تومان exponent صفر دارد، پس عدد
 * ذخیره‌شده خودِ تومان است. یورو exponent دو دارد، پس عدد، سِنت است.
 * هیچ float و هیچ DECIMAL. یک بار جمع اعشاری اشتباه در فاکتور، یعنی
 * اختلاف حساب که ماه‌ها بعد پیدا می‌شود.
 *
 * ═══ چرا مالیات روی خود ردیف ═══
 * نرخ مالیات با زمان عوض می‌شود. اگر فقط به جدول tax_rates ارجاع بدهیم،
 * فاکتور پارسال با نرخ امسال بازخوانی می‌شود و عدد چاپ‌شده با عدد سیستم
 * نمی‌خواند. پس نرخ لحظهٔ صدور روی ردیف «منجمد» می‌شود.
 *
 * ═══ چرا شمارهٔ جدا از id ═══
 * شمارهٔ فاکتور به مشتری نشان داده و در مکاتبات استفاده می‌شود؛ id عددی
 * پیاپی تعداد کل فاکتورهای ما را لو می‌دهد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            $table->string('number', 24)->unique();          // INV-14050712-0007
            $table->string('kind', 16)->default('service');  // service | topup | domain
            $table->char('currency_code', 3);

            // همه در واحد فرعی — برای تومان یعنی خود تومان
            $table->bigInteger('subtotal')->default(0);
            $table->bigInteger('tax')->default(0);
            $table->bigInteger('total')->default(0);
            $table->bigInteger('paid')->default(0);          // جمع پرداخت‌های موفق

            // draft: هنوز به مشتری نشان داده نشده
            $table->string('status', 16)->default('unpaid'); // draft|unpaid|paid|void|refunded
            $table->text('note')->nullable();

            $table->timestamp('issued_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index('due_at');
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();

            $table->string('title', 190);
            $table->text('description')->nullable();

            $table->unsignedInteger('quantity')->default(1);
            $table->bigInteger('unit_price');                // واحد فرعی
            $table->bigInteger('line_total');                // quantity × unit_price

            // نرخ مالیات لحظهٔ صدور، بر حسب صدم‌درصد: ۱۰٪ = ۱۰۰۰
            $table->unsignedInteger('tax_rate_bp')->default(0);
            $table->bigInteger('tax_amount')->default(0);

            $table->timestamps();

            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
    }
};
