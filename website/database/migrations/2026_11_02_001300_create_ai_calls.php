<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| سجلِ تماس‌هایِ AI — ضبطِ درخواست/پاسخ و بازپخشِ هم‌ارزی (M4-c)
|--------------------------------------------------------------------------
|
| ردیفِ این جدول «پاسخِ ضبط‌شدهٔ» یک تماسِ سبز است: با کلیدِ هم‌ارزیِ
| مشتری تکرارِ درخواست، به‌جای تماسِ دومی به بالادست (و خرجِ دومی)،
| دقیقاً همان بدنهٔ بار اول را از همین‌جا برمی‌گرداند. کل UNIQUE روی
| (`customer_id`, `idempotency_key`) همان قیدِ `ai_reservations` است —
| یک کلید = یک رزرو = حداکثر یک سجل.
|
| 🔴 هیچ بدنه‌ای این‌جا «نثر» اول‌بار نمی‌نویسد: `response_body` همیشه
|    عینِ JSON ارائه‌دهنده است و عمداً *بی‌تفسیر* بازپخش می‌شود — همان
|    قراردادِ سازگارِ OpenAI. فقط تماس‌هایِ سبز (`ok=1`) ضبط می‌شوند؛
|    شکست‌ها release می‌خورند و جایی برای بازپخش ندارند.
*/

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_calls')) {
            return;
        }

        Schema::create('ai_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_reservation_id')->nullable()
                ->constrained('ai_reservations')->nullOnDelete(); // ر؟ رزروِ settled این تماس
            $table->string('model_slug', 80);       // اسلاگِ عمومی که مشتری خواست — نه upstream
            /* کلیدِ هم‌ارزی — نال یعنی تماسِ بی‌کلید که بازپخش هم ندارد؛
               نال‌ها در یونیکِ MySQL چندتایی مجازند و از قید رد می‌شوند */
            $table->string('idempotency_key', 80)->nullable();
            $table->boolean('ok')->default(true);   // فعلاً فقط سبز ضبط می‌شود؛ ستونی برای آینده

            $table->json('request_body')->nullable();   // عینِ بدنهٔ ورودیِ /v1 (بی model)
            $table->json('response_body')->nullable();  // عینِ پاسخِ ارائه‌دهنده — بی‌تفسیر
            $table->unsignedSmallInteger('upstream_status')->nullable(); // کدِ HTTPِ بالادست

            $table->timestamps();

            $table->unique(['customer_id', 'idempotency_key']); // یک کلید = یک سجل
            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_calls');
    }
};
