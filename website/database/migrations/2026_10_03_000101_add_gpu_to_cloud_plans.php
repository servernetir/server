<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ستون‌های GPU روی `cloud_plans`.
 *
 * ═══ چرا لازم شد ═══
 *
 * `cloud_plans` تا امروز فقط CPU را می‌شناخت: `vcpu` · `ram_mb` · `disk_gb` ·
 * `cpu_kind` · `arch`. برای فروشِ سرورِ GPU این یعنی **خودِ محصول جایی برای
 * نوشتن ندارد** — مشتری نمی‌داند چه کارتی می‌خرد، و صفحهٔ خرید دو پلن با دو
 * کارتِ کاملاً متفاوت را یکسان نشان می‌دهد.
 *
 * 🔴 و بدتر از نمایش: `CloudNaming::planSlug()` گروه‌بندیِ «عرضه» را از
 * مشخصات می‌سازد. بی‌ستونِ GPU، یک RTX 3060 و یک H100 با vCPU و رمِ یکسان
 * **اسلاگِ یکسان** می‌گیرند ⇒ در یک گروه می‌افتند و `bestForSlug()`
 * ارزان‌ترین را برمی‌دارد. یعنی مشتری پولِ H100 می‌دهد و 3060 تحویل می‌گیرد،
 * بی‌هیچ خطایی. همان تلهٔ ثبت‌شدهٔ «ARM و x86 با اسلاگِ یکسان».
 *
 * ⚠️ `null` ≠ `0` — همان قاعدهٔ `servers.monthly_cost`:
 *     null → «این پلن اصلاً GPU ندارد» (همهٔ پلن‌های امروز)
 *     0    → معنایی ندارد و نباید نوشته شود
 * پس `gpu_count` هم nullable است نه `default 0`؛ صفر یعنی «GPU دارد ولی صفر
 * تا»، که یک ردیفِ خراب است و باید در گزارش دیده شود نه اینکه شبیهِ «بدونِ
 * GPU» بماند.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cloud_plans')) {
            return;
        }

        Schema::table('cloud_plans', function (Blueprint $t) {
            if (! Schema::hasColumn('cloud_plans', 'gpu_model')) {
                // نامِ کارت آن‌طور که مشتری می‌شناسد: «RTX 4090»
                $t->string('gpu_model', 80)->nullable()->after('cpu_kind');
            }

            if (! Schema::hasColumn('cloud_plans', 'gpu_count')) {
                $t->unsignedTinyInteger('gpu_count')->nullable()->after('gpu_model');
            }

            if (! Schema::hasColumn('cloud_plans', 'gpu_vram_mb')) {
                $t->unsignedInteger('gpu_vram_mb')->nullable()->after('gpu_count');
            }

            /*
            | 🔴 «قطعِ شدنی» یک ویژگیِ محصول است، نه جزئیاتِ زیرساخت.
            |
            | زیرساختِ GPU روی ماشین‌های خانگی اجرا می‌شود و **حتی در بالاترین
            | اولویت** نمونه می‌تواند قطع شود (صاحبِ دستگاه برش می‌دارد). اگر
            | این را در ستون ننویسیم، همان صفحه‌ای که سرورِ پایدار می‌فروشد
            | این را هم کنارش می‌گذارد و مشتری با انتظارِ اشتباه می‌خرد —
            | و آن انتظار به `/sla` وصل است، یعنی تعهدِ قراردادی.
            */
            if (! Schema::hasColumn('cloud_plans', 'is_interruptible')) {
                $t->boolean('is_interruptible')->default(false)->after('gpu_vram_mb');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cloud_plans')) {
            return;
        }

        Schema::table('cloud_plans', function (Blueprint $t) {
            foreach (['gpu_model', 'gpu_count', 'gpu_vram_mb', 'is_interruptible'] as $c) {
                if (Schema::hasColumn('cloud_plans', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
