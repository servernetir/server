<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ترمیمِ `ai_calls`ِ نیمه‌ساخته — مهاجرتِ 2026_11_02_001400.
 *
 * ═══ رخداد (۲۴ شهریور ۱۴۰۵) ═══
 *
 * روی سرورِ زنده، 001300 پیش از رسیدنِ 001200 اجرا شد. MariaDB نبودِ جدولِ والد
 * را errno 150 گزارش می‌کند و چون DDL تراکنشی نیست، جدول با ستون‌هایش ماند ولی
 * **یونیکِ (customer_id, idempotency_key)، ایندکسِ گزارش و کلیدِ خارجیِ رزرو**
 * ساخته نشدند. بعداً گاردِ `hasTable` همان مهاجرت را «Ran» ثبت کرد، پس
 * `migrate` دیگر هرگز سراغش نمی‌رود.
 *
 * 🔴 چرا مهم است: بی آن یونیک، تضمینِ «یک کلیدِ هم‌ارزی = یک سجل» فقط در کد
 * است. دو درخواستِ هم‌زمان با یک Idempotency-Key می‌توانند دو ردیف بنویسند —
 * یعنی همان دوباره‌کسری که کلید برای جلوگیری‌اش ساخته شده بود.
 *
 * ⚠️ کلیدِ خارجی روی SQLite با ALTER افزوده نمی‌شود، پس این تست بخشِ قابلِ
 * سنجش در SQLite را می‌سنجد: نیم‌ساختِ واقعی، پاک‌سازیِ داده و ایندکس‌ها.
 */
class AiCallsRepairMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const FILE = 'database/migrations/2026_11_02_001400_repair_ai_gateway_fks.php';

    private function runRepair(): void
    {
        $migration = require base_path(self::FILE);
        $migration->up();
    }

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'rep'.random_int(1, 999999).'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'secret1234', 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    /** بازسازیِ دقیقِ آنچه روی سرور ماند: ستون‌ها بله، یونیک/ایندکس نه */
    private function halfBuildAiCalls(): void
    {
        Schema::dropIfExists('ai_calls');

        Schema::create('ai_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('ai_reservation_id')->nullable();
            $table->string('model_slug', 80);
            $table->string('idempotency_key', 80)->nullable();
            $table->boolean('ok')->default(true);
            $table->json('request_body')->nullable();
            $table->json('response_body')->nullable();
            $table->unsignedSmallInteger('upstream_status')->nullable();
            $table->timestamps();
        });
    }

    private function insertCall(int $customerId, ?string $key, ?int $reservationId = null): int
    {
        return (int) DB::table('ai_calls')->insertGetId([
            'customer_id' => $customerId,
            'ai_reservation_id' => $reservationId,
            'model_slug' => 'llama-3.1-8b',
            'idempotency_key' => $key,
            'ok' => true,
            'upstream_status' => 200,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_it_adds_the_indexes_that_the_failed_migration_left_out(): void
    {
        $this->halfBuildAiCalls();

        $this->assertFalse(Schema::hasIndex('ai_calls', ['customer_id', 'idempotency_key']));
        $this->assertFalse(Schema::hasIndex('ai_calls', ['customer_id', 'created_at']));

        $this->runRepair();

        $this->assertTrue(Schema::hasIndex('ai_calls', ['customer_id', 'idempotency_key']),
            'یونیکِ کلیدِ هم‌ارزی باید ساخته شود — تضمینِ ضدِ دوباره‌کسر');
        $this->assertTrue(Schema::hasIndex('ai_calls', ['customer_id', 'created_at']));
    }

    /** 🔴 پس از ترمیم، دیتابیس خودش دوباره‌نویسی با یک کلید را رد می‌کند */
    public function test_after_the_repair_the_database_refuses_a_duplicate_idempotency_key(): void
    {
        $this->halfBuildAiCalls();
        $c = $this->customer();
        $this->insertCall($c->id, 'key-1');

        $this->runRepair();

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $this->insertCall($c->id, 'key-1');
    }

    /** دادهٔ تکراریِ از پیش موجود جلوی یونیک را نمی‌گیرد: قدیمی‌ترین می‌مانَد */
    public function test_existing_duplicates_are_resolved_oldest_first(): void
    {
        $this->halfBuildAiCalls();
        $c = $this->customer();
        $first = $this->insertCall($c->id, 'dup');
        $second = $this->insertCall($c->id, 'dup');
        $other = $this->insertCall($c->id, 'unique-one');

        $this->runRepair();

        $this->assertSame('dup', DB::table('ai_calls')->where('id', $first)->value('idempotency_key'));
        $this->assertNull(DB::table('ai_calls')->where('id', $second)->value('idempotency_key'),
            'سجلِ دومِ همان کلید بی‌کلید می‌شود، نه حذف — تاریخچه پاک نمی‌شود');
        $this->assertSame('unique-one', DB::table('ai_calls')->where('id', $other)->value('idempotency_key'));
        $this->assertSame(3, DB::table('ai_calls')->count(), 'هیچ ردیفی حذف نمی‌شود');
    }

    /** ارجاعِ یتیم پیش از کلیدِ خارجی نال می‌شود، وگرنه خودِ ALTER می‌شکند */
    public function test_orphan_reservation_references_are_nulled(): void
    {
        $this->halfBuildAiCalls();
        $c = $this->customer();
        $orphan = $this->insertCall($c->id, 'k-orphan', 987654);

        $this->runRepair();

        $this->assertNull(DB::table('ai_calls')->where('id', $orphan)->value('ai_reservation_id'));
    }

    /** اجرای دوباره روی جدولِ سالم هیچ کاری نمی‌کند (و نمی‌شکند) */
    public function test_running_it_twice_is_a_no_op(): void
    {
        $this->halfBuildAiCalls();
        $c = $this->customer();
        $id = $this->insertCall($c->id, 'k-1');

        $this->runRepair();
        $this->runRepair();

        $this->assertTrue(Schema::hasIndex('ai_calls', ['customer_id', 'idempotency_key']));
        $this->assertSame('k-1', DB::table('ai_calls')->where('id', $id)->value('idempotency_key'));
    }

    /** روی نصبِ سالم (جدولِ کامل) هم بی‌اثر است */
    public function test_a_healthy_table_is_left_alone(): void
    {
        $before = count(Schema::getIndexes('ai_calls'));

        $this->runRepair();

        $this->assertSame($before, count(Schema::getIndexes('ai_calls')));
    }
}
