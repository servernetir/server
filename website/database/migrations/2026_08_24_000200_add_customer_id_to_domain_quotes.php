<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مالکیتِ استعلامِ دامنه — بستنِ نشتِ حریمِ خصوصی.
 *
 * ═══ ممیزیِ شهریور ۱۴۰۵ ═══
 *
 * 🔴 `/account/domains/checkout/{quote}` با شناسهٔ **عددیِ ترتیبی** بود، بدونِ
 * هیچ بررسیِ مالکیت: هر مشتریِ واردشده می‌توانست ۱..N را بپیماید و **همهٔ
 * دامنه‌هایی را که مشتری‌های دیگر جستجو کرده‌اند** ببیند — نامِ دامنه خودش
 * افشای نیتِ تجاری است (کسی که «brandx-shop.com» را می‌پرسد، دارد brandx را
 * می‌سازد). طرحِ اصلی (docs/billing/04) توکنِ ULID + مالکیت می‌خواست.
 *
 * ستون nullable است: استعلام از جستجوی **عمومی** ساخته می‌شود و در آن لحظه
 * مالکی ندارد. اولین مشتریِ واردشده‌ای که بازش کند، مالکش می‌شود
 * (`DomainQuote::claimFor`) و از آن به بعد برای بقیه ۴۰۴ است.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domain_quotes', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->nullable()->after('registrar')->index();
        });
    }

    public function down(): void
    {
        Schema::table('domain_quotes', function (Blueprint $table) {
            $table->dropColumn('customer_id');
        });
    }
};
