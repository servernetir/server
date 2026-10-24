<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DNSِ مدیریت‌شده v2 — قراردادِ `docs/dns/MANAGED-DNS-DESIGN.md` §3.
 *
 * 🔴 فقط افزایشی. مهاجرتِ 000100 کدِ منتشرشده است و دست نمی‌خورد؛ هر ستونِ
 * تازه nullable یا پیش‌فرض‌دار است تا ردیف‌های v1 بی‌هیچ backfill معتبر بمانند
 * (`desired_version=1 > applied_version=0` یعنی ناشر همه را یک بار منتشر می‌کند).
 *
 * ⚠️ هر گام نگهبانِ خودش را دارد و بازگشتِ زودهنگامِ سراسری ندارد: DDL در
 * MariaDB تراکنشی نیست و اجرای نیمه‌کاره باید با اجرای دوباره کامل شود.
 * نگهبان‌ها بیرون از حالتِ pretend سنجیده می‌شوند (همان درسِ 000050): در
 * `--pretend` هر SELECT خالی برمی‌گردد و خروجی «هیچ» نشان می‌داد.
 */
return new class extends Migration
{
    /** ستون‌های تازهٔ cdn_zones (§3.2) به ترتیبِ قرارداد */
    private function zoneColumns(): array
    {
        return [
            'desired_version'       => fn (Blueprint $t) => $t->unsignedBigInteger('desired_version')->default(1),
            'applied_version'       => fn (Blueprint $t) => $t->unsignedBigInteger('applied_version')->default(0),
            'publish_state'         => fn (Blueprint $t) => $t->string('publish_state', 20)->default('pending'),
            'publish_attempts'      => fn (Blueprint $t) => $t->unsignedInteger('publish_attempts')->default(0),
            'next_publish_at'       => fn (Blueprint $t) => $t->timestamp('next_publish_at')->nullable(),
            'last_publish_error'    => fn (Blueprint $t) => $t->string('last_publish_error', 255)->nullable(),
            'primary_serial'        => fn (Blueprint $t) => $t->unsignedBigInteger('primary_serial')->nullable(),
            'published_at'          => fn (Blueprint $t) => $t->timestamp('published_at')->nullable(),
            'delegation_state'      => fn (Blueprint $t) => $t->string('delegation_state', 20)->default('unknown'),
            'delegation_checked_at' => fn (Blueprint $t) => $t->timestamp('delegation_checked_at')->nullable(),
            'delegation_ns'         => fn (Blueprint $t) => $t->json('delegation_ns')->nullable(),
            'parent_ds'             => fn (Blueprint $t) => $t->json('parent_ds')->nullable(),
            'records_reviewed_at'   => fn (Blueprint $t) => $t->timestamp('records_reviewed_at')->nullable(),
            'claim_id'              => fn (Blueprint $t) => $t->unsignedBigInteger('claim_id')->nullable(),
        ];
    }

    /** ستون‌های تازهٔ cdn_events (§3.4) */
    private function eventColumns(): array
    {
        return [
            'actor' => fn (Blueprint $t) => $t->string('actor', 40)->nullable(),   // customer:{id} | admin:{id} | system
            'ip'    => fn (Blueprint $t) => $t->string('ip', 45)->nullable(),      // IPv6 کامل ۴۵ نویسه است
        ];
    }

    public function up(): void
    {
        /*
        | ادعای مالکیت. ادعا هیچ چیزی منتشر نمی‌کند و جلوی کسی را نمی‌گیرد، پس
        | `domain` عمداً یکتا **نیست**: چند حساب می‌توانند هم‌زمان در انتظار باشند.
        | ایندکسِ (customer_id,status) ستونِ customer_id را هم پوشش می‌دهد.
        */
        if (! $this->tableExists('dns_ownership_claims')) {
            Schema::create('dns_ownership_claims', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('customer_id');
                $t->string('domain', 253);                       // ASCII، نرمال‌شده
                $t->char('token', 32);                           // hexِ تصادفی
                $t->string('status', 20)->default('pending');    // pending|verified|expired|cancelled
                $t->timestamp('expires_at')->nullable();
                $t->timestamp('verified_at')->nullable();
                $t->string('verified_via', 20)->nullable();      // txt|registered
                $t->unsignedInteger('attempts')->default(0);
                $t->timestamp('last_checked_at')->nullable();
                $t->string('last_result', 40)->nullable();       // **کد**، نه متن (§5.2)
                $t->unsignedBigInteger('zone_id')->nullable();
                $t->timestamps();

                $t->index(['domain', 'status']);
                $t->index(['customer_id', 'status']);
            });
        }

        /*
        | وضعیتِ هر zone روی هر نیم‌سرورِ عمومی. یکتاییِ (zone,node) ستونِ
        | cdn_zone_id را هم ایندکس می‌کند و جلوی ردیفِ تکراری از دو بررسیِ
        | هم‌زمان را می‌گیرد.
        */
        if (! $this->tableExists('dns_zone_nodes')) {
            Schema::create('dns_zone_nodes', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('cdn_zone_id');
                $t->string('node', 253);                         // نامِ میزبانِ نیم‌سرور
                $t->unsignedBigInteger('serial')->nullable();
                $t->string('state', 20)->default('unknown');     // unknown|in_sync|lagging|missing|unreachable
                $t->timestamp('checked_at')->nullable();
                $t->string('last_error', 120)->nullable();
                $t->timestamps();

                $t->unique(['cdn_zone_id', 'node'], 'dns_zone_nodes_zone_node_unique');
            });
        }

        $this->addMissing('cdn_zones', $this->zoneColumns());
        $this->addMissing('cdn_events', $this->eventColumns());
    }

    /**
     * بازگشت. ستون‌ها و جدول‌های v2 می‌روند؛ ستون‌های v1 دست نمی‌خورند.
     *
     * ⚠️ این دادهٔ انتشار/تفویض/ادعا را پاک می‌کند. ستون‌های v1
     * (`verified_at`، `ownership`، …) می‌مانند، پس کدِ v1 پس از rollback کار می‌کند.
     */
    public function down(): void
    {
        Schema::dropIfExists('dns_zone_nodes');
        Schema::dropIfExists('dns_ownership_claims');

        $this->dropExisting('cdn_zones', array_keys($this->zoneColumns()));
        $this->dropExisting('cdn_events', array_keys($this->eventColumns()));
    }

    /** @param array<string,\Closure> $columns */
    private function addMissing(string $table, array $columns): void
    {
        if (! $this->tableExists($table)) {
            return;
        }

        $missing = array_filter(
            $columns,
            fn (string $col) => ! $this->columnExists($table, $col),
            ARRAY_FILTER_USE_KEY
        );

        if ($missing === []) {
            return;
        }

        /*
        | ⚠️ لاراول روی MariaDB برای هر ستون یک ALTER جدا می‌سازد (نه یکی برای
        | همه). افزودنِ ستونِ پایانی در MariaDB ‏10.3+ «INSTANT» است، پس هزینه‌اش
        | کم است؛ و چون هر ستون نگهبانِ خودش را دارد، شکست وسطِ کار با اجرای
        | دوباره کامل می‌شود.
        */
        Schema::table($table, function (Blueprint $t) use ($missing) {
            foreach ($missing as $add) {
                $add($t);
            }
        });
    }

    /** @param array<int,string> $columns */
    private function dropExisting(string $table, array $columns): void
    {
        if (! $this->tableExists($table)) {
            return;
        }

        $present = array_values(array_filter($columns, fn (string $c) => $this->columnExists($table, $c)));

        if ($present !== []) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn($present));
        }
    }

    private function tableExists(string $table): bool
    {
        return (bool) DB::connection()->withoutPretending(fn () => Schema::hasTable($table));
    }

    private function columnExists(string $table, string $column): bool
    {
        return (bool) DB::connection()->withoutPretending(fn () => Schema::hasColumn($table, $column));
    }
};
