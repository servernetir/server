<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * کاتالوگِ سرورِ ابری (VPS) — سفیدبرچسب.
 *
 * ⚠️ قاعدهٔ مطلقِ این حوزه: **مشتری هرگز نباید بفهمد سرور از کجا خریده شده.**
 * نامِ ارائه‌دهنده (hetzner/aeza) فقط در ستونِ `provider` می‌نشیند و هیچ‌وقت به
 * ویوهای عمومی نمی‌رسد. به همین دلیل:
 *
 *  • «مکان» جدولِ مستقلِ خودش را دارد (`cloud_locations`) با کدِ **خودمان**
 *    (de-fsn، fi-hel، ru-msk…). دو ارائه‌دهنده می‌توانند یک کشور را پوشش دهند و
 *    مشتری فقط «آلمان — فالکن‌اشتاین» را می‌بیند.
 *  • سیستم‌عامل با کلیدِ **یکسان‌شدهٔ** خودمان شناخته می‌شود (`key` = ubuntu-24.04)
 *    تا «اوبونتو ۲۴٫۰۴»ی که در هتزنر `ubuntu-24.04` و در آیزا شناسهٔ عددی است،
 *    برای مشتری یک گزینه باشد. در لحظهٔ تحویل، ردیفِ همان ارائه‌دهنده خوانده و
 *    شناسهٔ بومی‌اش فرستاده می‌شود.
 *
 * طولِ ستون‌ها دست‌ودل‌بازانه گرفته شده: SQLite طولِ VARCHAR را نادیده می‌گیرد ولی
 * MariaDB اجرا می‌کند، و یک‌بار همین تفاوت باعث شد سرویس‌ها روی پروداکشن ساخته
 * نشوند («Data too long for column 'status'») بدون آنکه هیچ تستی بگیردش.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─────────────── مکان‌ها (سفیدبرچسب) ───────────────
        if (! Schema::hasTable('cloud_locations')) {
            Schema::create('cloud_locations', function (Blueprint $table) {
                $table->id();
                $table->string('code', 32)->unique();        // کدِ خودمان: de-fsn
                $table->string('country', 2);                // ISO-3166: DE
                $table->string('city', 64)->nullable();
                $table->string('label_fa', 96)->nullable();
                $table->string('label_en', 96)->nullable();
                $table->string('label_tr', 96)->nullable();
                $table->string('flag', 16)->nullable();      // 🇩🇪
                $table->decimal('latitude', 9, 6)->nullable();
                $table->decimal('longitude', 9, 6)->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort')->default(0);
                $table->timestamps();

                $table->index(['is_active', 'sort']);
                $table->index('country');
            });
        }

        // ─────────────── سیستم‌عامل و نرم‌افزارِ آماده ───────────────
        if (! Schema::hasTable('cloud_images')) {
            Schema::create('cloud_images', function (Blueprint $table) {
                $table->id();
                $table->string('provider', 24);              // hetzner | aeza — داخلی
                $table->string('provider_ref', 96);          // ubuntu-24.04 یا 1234
                // کلیدِ یکسان‌شدهٔ ما — مشتری همین را انتخاب می‌کند
                $table->string('key', 64);
                $table->string('kind', 8)->default('os');    // os | app
                $table->string('family', 40)->nullable();    // ubuntu, debian, docker
                $table->string('version', 40)->nullable();   // 24.04
                $table->string('label', 96);                 // Ubuntu 24.04
                $table->string('arch', 12)->default('x86');  // x86 | arm
                $table->unsignedSmallInteger('min_disk_gb')->default(0);
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort')->default(0);
                $table->timestamps();

                $table->unique(['provider', 'provider_ref']);
                $table->index(['key', 'is_active']);
                $table->index(['kind', 'is_active']);
            });
        }

        // ─────────────── پلن‌ها (هر ردیف = یک پلن در یک مکان) ───────────────
        if (! Schema::hasTable('cloud_plans')) {
            Schema::create('cloud_plans', function (Blueprint $table) {
                $table->id();
                $table->string('provider', 24);              // داخلی — هرگز در ویو
                $table->string('provider_ref', 96);          // cx22 یا productId
                $table->string('provider_location', 64)->nullable(); // fsn1 / شناسهٔ آیزا
                $table->string('location_code', 32);         // → cloud_locations.code

                // نامِ عمومیِ سفیدبرچسب. مشتری «CV-2-4» می‌بیند نه «CX22».
                $table->string('public_name', 64);
                // ⚠️ عمداً **یکتا نیست**: اسلاگ از «مشخصات + مکان» ساخته می‌شود، پس
                // اگر هر دو ارائه‌دهنده پلنِ ۲هسته/۴گیگ در فرانکفورت داشته باشند،
                // هر دو ردیف یک اسلاگ می‌گیرند و همان اسلاگ **کلیدِ گروه** است.
                // مشتری یک گزینه می‌بیند و ما ارزان‌ترینِ موجود را تحویل می‌دهیم —
                // هم سفیدبرچسبی حفظ می‌شود هم حاشیهٔ سود.
                $table->string('slug', 96)->index();

                // مشخصات
                $table->unsignedSmallInteger('vcpu')->default(1);
                $table->unsignedInteger('ram_mb')->default(1024);
                $table->unsignedInteger('disk_gb')->default(20);
                $table->string('disk_type', 12)->default('nvme');
                $table->unsignedInteger('traffic_gb')->default(0);   // ۰ = نامحدود/منصفانه
                $table->string('cpu_kind', 16)->default('shared');   // shared | dedicated
                $table->string('arch', 12)->default('x86');

                // پول — همیشه عددِ صحیح در واحدِ فرعی، بی‌هیچ float
                $table->unsignedBigInteger('cost_eur_cents')->default(0);  // بهایِ تمام‌شدهٔ ما
                $table->unsignedBigInteger('price_eur_cents')->default(0); // فروش (یورو)
                $table->unsignedBigInteger('price_irt')->default(0);       // فروش (تومان)

                $table->boolean('is_active')->default(true);
                $table->boolean('in_stock')->default(true);   // ظرفیتِ ارائه‌دهنده
                $table->unsignedSmallInteger('sort')->default(0);
                $table->timestamp('synced_at')->nullable();
                $table->timestamps();

                $table->unique(['provider', 'provider_ref', 'location_code'], 'cloud_plans_unique_ref');
                $table->index(['is_active', 'in_stock', 'sort']);
                $table->index(['location_code', 'is_active']);
            });
        }

        // ─────────────── نمونهٔ ساخته‌شده (سرورِ واقعیِ مشتری) ───────────────
        if (! Schema::hasTable('cloud_instances')) {
            Schema::create('cloud_instances', function (Blueprint $table) {
                $table->id();
                $table->foreignId('service_id')->unique();   // ۱:۱ با سرویس
                $table->string('provider', 24);
                $table->string('provider_ref', 96)->nullable();  // شناسهٔ سرور نزدِ ارائه‌دهنده
                $table->string('location_code', 32)->nullable();
                $table->string('image_key', 64)->nullable();
                $table->string('hostname', 190)->nullable();

                $table->string('ipv4', 45)->nullable();
                $table->string('ipv6', 64)->nullable();

                // رمزِ root فقط رمزنگاری‌شده. یک‌بار به مشتری نشان داده می‌شود.
                $table->text('root_password_enc')->nullable();
                $table->boolean('password_seen')->default(false);

                // running | off | building | error | deleted  (۲۴ کاراکتر، با درسِ status)
                $table->string('status', 24)->default('building');
                $table->text('last_error')->nullable();
                $table->json('specs')->nullable();           // عکسِ لحظه‌ایِ مشخصات
                $table->json('meta')->nullable();
                $table->timestamp('synced_at')->nullable();
                $table->timestamps();

                $table->index(['provider', 'provider_ref']);
                $table->index('status');
            });
        }

        // پیوندِ پکیجِ فروش به پلنِ ابری: مشتری «سرور ابری» می‌خرد و
        // ProvisioningService از این ستون می‌فهمد کدام پلن را کجا بسازد.
        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'cloud_plan_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->unsignedBigInteger('cloud_plan_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'cloud_plan_id')) {
            Schema::table('products', fn (Blueprint $t) => $t->dropColumn('cloud_plan_id'));
        }

        foreach (['cloud_instances', 'cloud_plans', 'cloud_images', 'cloud_locations'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
