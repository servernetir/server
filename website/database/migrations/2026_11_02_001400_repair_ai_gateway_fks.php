<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|------------------------------------------------------------------------------
| ترمیمِ جدولِ نیمه‌ساختهٔ `ai_calls` روی سرورِ زنده
|------------------------------------------------------------------------------
|
| 🔴 رخداد (۲۴ شهریور ۱۴۰۵، ۰۹:۲۶ UTC — ۲۲ دقیقه پس از مرجِ PR #20):
|
|     alter table ai_calls add constraint ai_calls_ai_reservation_id_foreign
|     foreign key (ai_reservation_id) references ai_reservations (id) ...
|     SQLSTATE[HY000]: 1005 errno 150
|
| علت **نوعِ ستون نبود**؛ هر دو سمت `bigint unsigned`اند. جدولِ والد یعنی
| `ai_reservations` در آن لحظه روی سرور **وجود نداشت**: انتشار فایل‌به‌فایل است و
| فایل‌های M3 (از جمله مهاجرتِ 001200) در آن دسته نبودند. MariaDB نبودنِ والد را
| با همان errno 150 گزارش می‌کند (MySQL 8 کدِ ۱۸۲۴ می‌دهد).
|
| 🔴 چرا `migrate` هرگز خودش درستش نمی‌کند:
|
| DDL در MariaDB تراکنشی نیست، پس ستون‌ها و کلیدِ خارجیِ `customer_id` ساخته
| شدند و فقط سه چیزِ آخر جا ماند. بعداً که 001200 هم منتشر شد و `migrate`
| دوباره اجرا شد، گاردِ `if (Schema::hasTable('ai_calls')) return;` در 001300
| زود برگشت و آن مهاجرت **Ran ثبت شد**. یعنی از دیدِ Laravel همه‌چیز تمام است،
| در حالی که روی دیسک این‌ها نیست:
|
|     • ai_calls_ai_reservation_id_foreign
|     • unique(customer_id, idempotency_key)      ← ضدِ دوباره‌کسرِ کلیدِ هم‌ارزی
|     • index(customer_id, created_at)
|
| یونیکِ غایب یعنی تضمینِ idempotency روی سرورِ زنده **فقط در کد** است، نه در
| دیتابیس؛ دو درخواستِ هم‌زمان با یک کلید می‌توانند دو سجل بنویسند.
|
| ═══ قاعده‌های این مهاجرت ═══
|
|  • **هر گام گاردِ خودش را دارد** (نه یک گاردِ سراسری در سر): همان تله‌ای که
|    اول کار را خراب کرد. روی نصبِ سالم هیچ‌کدام اجرا نمی‌شود.
|  • پیش از کلیدِ خارجی، ارجاع‌های یتیم نال می‌شوند؛ پیش از یونیک، کلیدهای
|    تکراری (قدیمی‌ترین می‌مانَد) — وگرنه خودِ ALTER می‌شکند.
|  • کلیدِ خارجی فقط روی MySQL/MariaDB؛ SQLite (تست) با ALTER کلیدِ خارجی
|    نمی‌پذیرد و آن‌جا جدول از اول درست ساخته شده است.
|  • `down()` عمداً هیچ کاری نمی‌کند: این مهاجرت چیزی «اضافه» نکرده، فقط
|    نبودِ ناخواسته را جبران کرده.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_calls')) {
            return;                     // نصبِ تازه — 001300 خودش کامل می‌سازد
        }

        $mysql = DB::getDriverName() === 'mysql';
        $hasReservations = Schema::hasTable('ai_reservations');

        // ── ۱) ارجاع‌های یتیم: کلیدِ خارجی روی داده‌ای که والد ندارد نمی‌نشیند ──
        if ($hasReservations && Schema::hasColumn('ai_calls', 'ai_reservation_id')) {
            DB::table('ai_calls')
                ->whereNotNull('ai_reservation_id')
                ->whereNotIn('ai_reservation_id', fn ($q) => $q->from('ai_reservations')->select('id'))
                ->update(['ai_reservation_id' => null]);
        }

        // ── ۲) کلیدِ هم‌ارزیِ تکراری: قدیمی‌ترین سجل می‌مانَد، بقیه بی‌کلید ──
        if (Schema::hasColumn('ai_calls', 'idempotency_key')) {
            $dupes = DB::table('ai_calls')
                ->select('customer_id', 'idempotency_key')
                ->whereNotNull('idempotency_key')
                ->groupBy('customer_id', 'idempotency_key')
                ->havingRaw('count(*) > 1')
                ->get();

            foreach ($dupes as $d) {
                $keep = DB::table('ai_calls')
                    ->where('customer_id', $d->customer_id)
                    ->where('idempotency_key', $d->idempotency_key)
                    ->min('id');

                DB::table('ai_calls')
                    ->where('customer_id', $d->customer_id)
                    ->where('idempotency_key', $d->idempotency_key)
                    ->where('id', '!=', $keep)
                    ->update(['idempotency_key' => null]);
            }
        }

        // ── ۳) یونیکِ گمشده — تضمینِ idempotency باید در خودِ دیتابیس باشد ──
        if (! Schema::hasIndex('ai_calls', ['customer_id', 'idempotency_key'])) {
            Schema::table('ai_calls', function (Blueprint $table) {
                $table->unique(['customer_id', 'idempotency_key']);
            });
        }

        // ── ۴) ایندکسِ گزارش‌گیری ──
        if (! Schema::hasIndex('ai_calls', ['customer_id', 'created_at'])) {
            Schema::table('ai_calls', function (Blueprint $table) {
                $table->index(['customer_id', 'created_at']);
            });
        }

        // ── ۵) کلیدِ خارجیِ همان ALTERِ شکست‌خورده ──
        if ($mysql && $hasReservations
            && Schema::hasColumn('ai_calls', 'ai_reservation_id')
            && ! Schema::hasForeignKey('ai_calls', 'ai_calls_ai_reservation_id_foreign')) {
            Schema::table('ai_calls', function (Blueprint $table) {
                $table->foreign('ai_reservation_id')->references('id')->on('ai_reservations')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        // عمداً خالی — این مهاجرت چیزی نیفزوده، نبودِ ناخواسته را جبران کرده است.
    }
};
