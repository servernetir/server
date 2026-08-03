<?php

namespace Tests\Feature;

use App\Models\StatusIncident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * صفحهٔ وضعیت و سندِ SLA.
 *
 * چرا این دو با هم تست می‌شوند: هر دو یک کار می‌کنند — تبدیلِ «۹۹٫۹٪ آپتایمِ
 * تضمینی» از یک ادعای تبلیغاتی به چیزی که بشود به آن استناد کرد. سایت این را
 * تبلیغ می‌کرد و `/status` اصلاً وجود نداشت.
 */
class StatusPageTest extends TestCase
{
    use RefreshDatabase;

    private function incident(array $over = []): StatusIncident
    {
        return StatusIncident::create(array_merge([
            'title' => 'اختلال در دسترسی سرورهای تهران',
            'state' => 'investigating', 'impact' => 'major',
            'body' => 'در حال بررسی هستیم.', 'started_at' => now()->subHour(),
        ], $over));
    }

    private function admin(): User
    {
        return User::create(['name' => 'مدیر', 'email' => 'a'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('x'), 'role' => 'admin']);
    }

    // ═══════════ صفحهٔ عمومی ═══════════

    public function test_status_page_is_public_and_says_all_is_well(): void
    {
        $html = $this->get('/status')->assertOk()->getContent();

        $this->assertStringContainsString(__('ui.status_all_ok'), $html);
    }

    public function test_an_open_incident_is_shown(): void
    {
        $this->incident();

        $html = $this->get('/status')->assertOk()->getContent();

        $this->assertStringContainsString('اختلال در دسترسی سرورهای تهران', $html);
        $this->assertStringContainsString(__('ui.status_has_issue'), $html);
    }

    public function test_a_resolved_incident_moves_to_history(): void
    {
        $this->incident(['state' => 'resolved', 'resolved_at' => now()->subMinutes(10)]);

        $html = $this->get('/status')->assertOk()->getContent();

        $this->assertStringContainsString(__('ui.status_all_ok'), $html, 'بسته‌شده نباید «اختلال در جریان» بدهد');
        $this->assertStringContainsString('اختلال در دسترسی سرورهای تهران', $html);
    }

    /**
     * 🔴 صفحهٔ وضعیت باید حتی بدونِ جدول بالا بیاید.
     *
     * روی سروری که هنوز مهاجرت نخورده، صفحه‌ای که خودش ۵۰۰ می‌دهد از نبودش
     * بدتر است — دقیقاً وقتی لازم می‌شود که اوضاع خراب است.
     */
    public function test_it_survives_a_missing_table(): void
    {
        \Illuminate\Support\Facades\Schema::drop('status_incidents');

        $this->get('/status')->assertOk();
    }

    /** ⚠️ عددِ آپتایمِ ساختگی نباید چاپ شود */
    public function test_it_does_not_invent_an_uptime_number(): void
    {
        $html = $this->get('/status')->assertOk()->getContent();

        $this->assertStringNotContainsString('99.9%', $html);
        $this->assertStringContainsString(__('ui.status_note_t'), $html);
    }

    public function test_status_works_in_every_language(): void
    {
        foreach (['/status', '/en/status', '/tr/status'] as $u) {
            $html = $this->get($u)->assertOk()->getContent();
            $this->assertStringNotContainsString('ui.status_', $html, "$u کلیدِ خام دارد");
        }
    }

    // ═══════════ سند SLA ═══════════

    public function test_the_sla_document_publishes_the_credit_table(): void
    {
        $html = $this->get('/sla')->assertOk()->getContent();

        // بدونِ جدولِ اعتبار، تعهد «یک‌طرفه و بدونِ سقف» است — بدترین حالت برای فروشنده
        foreach (config('sla.credits') as $row) {
            $this->assertStringContainsString(fa_num($row['credit']), $html);
        }

        $this->assertStringContainsString(__('ui.sla_s3_t'), $html);   // سقفِ مسئولیت
        $this->assertStringContainsString(__('ui.sla_s6_t'), $html);   // فرآیندِ مطالبه
    }

    /** بندِ قوّهٔ قاهره باید قطعیِ سراسری و تحریم را نام ببرد */
    public function test_force_majeure_names_the_real_risks(): void
    {
        $html = $this->get('/sla')->assertOk()->getContent();

        $this->assertStringContainsString(__('ui.sla_fm1'), $html);
        $this->assertStringContainsString(__('ui.sla_fm3'), $html);
    }

    public function test_sla_works_in_every_language(): void
    {
        foreach (['/sla', '/en/sla', '/tr/sla'] as $u) {
            $html = $this->get($u)->assertOk()->getContent();
            $this->assertStringNotContainsString('ui.sla_', $html, "$u کلیدِ خام دارد");
        }
    }

    // ═══════════ پنلِ مدیریت ═══════════

    public function test_an_admin_can_declare_and_resolve_an_incident(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/status', [
            'title' => 'قطعی برق دیتاسنتر', 'state' => 'identified', 'impact' => 'major',
            'body' => 'برق دیتاسنتر قطع شده است.',
        ])->assertRedirect();

        $inc = StatusIncident::firstOrFail();
        $this->assertNull($inc->resolved_at);
        $this->assertStringContainsString('قطعی برق دیتاسنتر', $this->get('/status')->getContent());

        $this->actingAs($admin)->post('/admin/status/'.$inc->id, [
            'title' => $inc->title, 'state' => 'resolved', 'impact' => 'major',
        ])->assertRedirect();

        // 🔴 «برطرف شد» باید زمانِ پایان بگذارد، وگرنه ردیف تا ابد باز می‌مانَد
        // و صفحه به مشتری می‌گوید هنوز مشکلی هست.
        $this->assertNotNull($inc->fresh()->resolved_at);
        $this->assertStringContainsString(__('ui.status_all_ok'), $this->get('/status')->getContent());
    }

    public function test_reopening_clears_the_resolved_time(): void
    {
        $admin = $this->admin();
        $inc = $this->incident(['state' => 'resolved', 'resolved_at' => now()]);

        $this->actingAs($admin)->post('/admin/status/'.$inc->id, [
            'title' => $inc->title, 'state' => 'monitoring', 'impact' => 'minor',
        ]);

        $this->assertNull($inc->fresh()->resolved_at);
    }

    public function test_guests_cannot_declare_an_incident(): void
    {
        $this->post('/admin/status', ['title' => 'جعلی', 'state' => 'investigating', 'impact' => 'major'])
            ->assertRedirect();

        $this->assertSame(0, StatusIncident::count());
    }
}
