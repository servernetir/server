<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * کلیدِ SSH مشتری + افزودنی‌های پولیِ سرورِ ابری (IP اضافه).
 *
 * ═══ چرا کلیدِ SSH جدول می‌خواهد و در سرویس نمی‌نشیند ═══
 *
 * یک مشتری چند سرور دارد و یک کلید. اگر کلید را روی سرویس ذخیره کنیم، مشتری
 * باید برای هر خرید دوباره کلیدش را بچسباند — و بدتر، تغییرِ کلید باید در چند
 * جا تکرار شود. پس کلید مالِ **مشتری** است و سرویس فقط به آن اشاره می‌کند.
 *
 * `provider_refs` نگاشتِ «کدام زیرساخت این کلید را با چه شناسه‌ای دارد» است.
 * دلیلش این است که زیرساخت‌ها کلید را باید **از قبل** در حسابِ ما داشته باشند
 * تا بشود سرِ ساختِ سرور به آن اشاره کرد؛ پس یک‌بار بارگذاری می‌کنیم و شناسه‌اش
 * را نگه می‌داریم. بی‌این، هر ساختِ سرور یک بارگذاریِ تکراری و یک خطای «کلیدِ
 * تکراری» بود.
 *
 * ═══ چرا افزودنی‌ها JSON اند ═══
 *
 * فعلاً یک افزودنیِ پولی داریم (IP اضافه). ساختنِ جدولِ کاملِ
 * order_items/addons برای یک قلم، پیچیدگیِ بی‌جاست؛ ولی ستونِ عددیِ مخصوصِ
 * `extra_ipv4` هم یعنی هر افزودنیِ بعدی یک مهاجرت. JSON میانهٔ درست است:
 * `{"extra_ipv4": 2}`. **قیمت داخلش ذخیره نمی‌شود** — قیمت همیشه از
 * `CloudAddons` خوانده می‌شود تا دو منبعِ حقیقت نداشته باشیم.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cloud_ssh_keys')) {
            Schema::create('cloud_ssh_keys', function (Blueprint $table) {
                $table->id();
                $table->foreignId('customer_id')->index();
                $table->string('name', 80);
                // کلیدِ عمومی — راز نیست، پس رمزنگاری لازم ندارد
                $table->text('public_key');
                // اثرِ انگشتِ MD5 به شکلِ استانداردِ SSH (aa:bb:cc:…) — برای
                // تشخیصِ کلیدِ تکراری بی‌مقایسهٔ متنِ کامل
                $table->string('fingerprint', 95)->nullable()->index();
                $table->string('key_type', 24)->nullable();      // ssh-ed25519, ssh-rsa…
                // {"hetzner": "12345", "aeza": "77"} — شناسهٔ کلید نزدِ هر زیرساخت
                $table->json('provider_refs')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();

                $table->unique(['customer_id', 'name']);
            });
        }

        if (Schema::hasTable('services')) {
            Schema::table('services', function (Blueprint $table) {
                if (! Schema::hasColumn('services', 'cloud_ssh_key_id')) {
                    $table->unsignedBigInteger('cloud_ssh_key_id')->nullable()->after('cloud_image_key');
                }
                if (! Schema::hasColumn('services', 'cloud_addons')) {
                    $table->json('cloud_addons')->nullable()->after('cloud_ssh_key_id');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('services')) {
            Schema::table('services', function (Blueprint $table) {
                foreach (['cloud_ssh_key_id', 'cloud_addons'] as $col) {
                    if (Schema::hasColumn('services', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        Schema::dropIfExists('cloud_ssh_keys');
    }
};
