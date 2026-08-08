<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * پرداختِ رمزارز — درگاهِ خودمان، بدونِ واسطه.
 *
 * ═══ چرا «استخرِ آدرس» و نه مشتق‌گیری از xpub ═══
 *
 * طرحِ ایده‌آل، ساختِ آدرسِ یکتا از یک کلیدِ عمومیِ توسعه‌یافته است. ولی در PHP
 * به ریاضیِ منحنیِ بیضوی نیاز دارد (`elliptic-php` + `keccak`)، و دپلویِ این
 * پروژه فایل‌به‌فایل از راهِ UAPI است — یعنی آپلودِ هزاران فایلِ `vendor/`.
 * ضمناً GMP روی این محیط نیست و نسخهٔ bcmath کُند است.
 *
 * استخر سه بُرد هم‌زمان دارد:
 *   ۱. **هیچ کلیدی روی سرور نیست** — آدرس‌ها را مدیر در کیفِ خودش می‌سازد و
 *      فقط رشتهٔ آدرس را وارد می‌کند. سرور توانِ خرج‌کردن ندارد، نقطه.
 *   ۲. صفر وابستگیِ تازه.
 *   ۳. برای سولانا و TON هم کار می‌کند — آن دو روی ed25519 هستند و اصلاً
 *      مشتق‌گیریِ عمومی ندارند، پس فازهای بعد هم همین‌جا سوار می‌شوند.
 *
 * 🔴 **خطرِ ذاتیِ استفادهٔ دوباره از آدرس، و پاسخش:**
 *
 * آدرس بعد از انقضا به استخر برمی‌گردد. اگر مشتری **دیر** پرداخت کند و آن
 * آدرس به فاکتورِ دیگری رسیده باشد، بی‌محافظت پولِ او به حسابِ یک نفرِ دیگر
 * می‌نشیند. پس:
 *   · `cooldown_until` — آدرس تا چند ساعت بعد از آزادشدن دوباره داده نمی‌شود
 *   · هر تراکنشِ دیده‌شده با `txid` **یکتا** ثبت می‌شود؛ تکراری دوباره حساب نمی‌شود
 *   · واریزی که به هیچ پرداختِ بازی نخورد، **دور ریخته نمی‌شود** — با وضعیت
 *     `unmatched` می‌ماند تا مدیر ببیند. پولِ گم‌شده بدترین حالتِ ممکن است.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── استخرِ آدرس‌های دریافت ──────────────────────────────────────────
        Schema::create('crypto_wallets', function (Blueprint $table) {
            $table->id();
            $table->string('chain', 16);              // tron | evm | btc | sol | ton
            $table->string('address', 128);
            $table->string('label', 80)->nullable();
            $table->boolean('is_active')->default(true);

            // آدرسِ در حالِ استفاده — به پرداختِ باز قفل است
            $table->foreignId('busy_payment_id')->nullable();

            // ⚠️ بی‌این، پرداختِ دیرهنگام به جیبِ فاکتورِ بعدی می‌رود
            $table->timestamp('cooldown_until')->nullable();

            $table->timestamps();

            $table->unique(['chain', 'address']);
            $table->index(['chain', 'is_active', 'busy_payment_id']);
        });

        // ── پرداخت‌ها ──────────────────────────────────────────────────────
        Schema::create('crypto_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id');
            $table->foreignId('customer_id')->nullable();
            $table->foreignId('crypto_wallet_id')->nullable();

            $table->string('chain', 16);
            $table->string('asset', 16);             // USDT | TRX
            $table->string('network', 24);           // TRC20
            $table->string('address', 128);

            /*
            | مبلغ‌ها **همیشه عدد صحیح در کوچک‌ترین واحد**.
            | USDT روی ترون ۶ رقم اعشار دارد، TRX هم ۶ (sun). float برای پول
            | ممنوع است — همان قاعده‌ای که کلِ لایهٔ مالیِ این پروژه دارد.
            */
            $table->unsignedBigInteger('amount_atomic');
            $table->unsignedTinyInteger('decimals')->default(6);

            // مبلغِ فاکتور و نرخِ قفل‌شده در لحظهٔ صدور — نوسان نباید وسطِ
            // پرداخت مبلغ را عوض کند
            $table->unsignedBigInteger('invoice_amount');
            $table->string('invoice_currency', 8);
            $table->unsignedBigInteger('rate_micro');   // قیمتِ یک واحدِ دارایی × 1e6

            $table->unsignedBigInteger('received_atomic')->default(0);
            $table->string('txid', 128)->nullable();
            $table->unsignedInteger('confirmations')->default(0);

            // pending | seen | confirmed | expired | unmatched | manual
            $table->string('status', 12)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index(['chain', 'address', 'status']);
            $table->index('invoice_id');

            // ⚠️ یک تراکنش فقط یک بار می‌تواند پرداختی را تسویه کند
            $table->unique(['chain', 'txid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crypto_payments');
        Schema::dropIfExists('crypto_wallets');
    }
};
