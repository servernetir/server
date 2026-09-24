<?php

namespace Tests\Feature;

use App\Models\AiProject;
use App\Models\Customer;
use App\Models\CustomerApiToken;
use App\Services\Ai\AiAdmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * M2 — پروژهٔ AI و سیمِ admission.
 *
 * سطحِ مپ: پروژه‌ها + صدورِ کلیدِ AI + حکمِ `AiAdmission::authorize`.
 * هیچ پولی این‌جا جابه‌جا نمی‌شود — تست‌ها هم فقط admission می‌سنجند.
 */
class AiProjectsAndAdmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function customer(array $over = []): Customer
    {
        return Customer::create(array_merge([
            'email' => 'ai'.random_int(1, 999999).'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => null, 'status' => 'active', 'locale' => 'fa',
        ], $over));
    }

    private function project(Customer $c, array $over = []): AiProject
    {
        $p = $c->aiProjects()->create(array_merge([
            'name' => 'p'.random_int(1, 99999), 'slug' => 'p'.random_int(1, 99999),
            'status' => AiProject::STATUS_ACTIVE,
        ], $over));
        $p->refreshBudgetWindow();

        return $p;
    }

    // ── A: ساخت ──

    public function test_project_create(): void
    {
        $c = $this->customer();

        $this->actingAs($c, 'customer')
            ->post('/account/security/ai-project', ['name' => 'Support', 'slug' => 'support'])
            ->assertRedirect()->assertSessionHas('ok');

        $p = $c->aiProjects()->first();
        $this->assertNotNull($p);
        $this->assertSame('support', $p->slug);
        $this->assertSame(AiProject::STATUS_ACTIVE, $p->status);
    }

    public function test_project_create_budget_monthly(): void
    {
        $c = $this->customer();

        $this->actingAs($c, 'customer')->post('/account/security/ai-project', [
            'name' => 'team', 'monthly_budget' => 500000, 'budget_reset_day' => 15,
        ])->assertRedirect();

        $p = $c->aiProjects()->first();
        $this->assertSame(500000, $p->monthly_budget_irt);
        $this->assertSame(AiProject::PERIOD_MONTHLY, $p->budget_period);
        $this->assertSame(15, $p->budget_reset_day);
        $this->assertNotNull($p->budget_window_from, 'بازهٔ بودجه باید ذخیره شود');
    }

    public function test_monthly_period_without_budget_rejected(): void
    {
        $c = $this->customer();

        $this->actingAs($c, 'customer')->post('/account/security/ai-project', [
            'name' => 'x', 'budget_period' => AiProject::PERIOD_MONTHLY,
        ])->assertSessionHasErrors('monthly_budget');

        $this->assertSame(0, $c->aiProjects()->count());
    }

    public function test_duplicate_slug_within_account_rejected(): void
    {
        $c = $this->customer();
        $this->project($c, ['slug' => 'dup']);

        $this->actingAs($c, 'customer')
            ->post('/account/security/ai-project', ['name' => 'y', 'slug' => 'dup'])
            ->assertSessionHasErrors('slug');
    }

    public function test_same_slug_in_another_account_allowed(): void
    {
        $a = $this->customer();
        $b = $this->customer();
        $this->project($a, ['slug' => 'shared']);

        $this->actingAs($b, 'customer')
            ->post('/account/security/ai-project', ['name' => 'z', 'slug' => 'shared'])
            ->assertRedirect();

        $this->assertSame(1, $b->aiProjects()->count());
    }

    // ── B/C/D: تغییرِ وضعیت ──

    public function test_project_update_disable_and_reenable(): void
    {
        $c = $this->customer();
        $p = $this->project($c);

        $this->actingAs($c, 'customer')
            ->post("/account/security/ai-project/{$p->id}/update", ['status' => 'disabled'])
            ->assertRedirect();
        $this->assertSame(AiProject::STATUS_DISABLED, $p->fresh()->status);

        $this->actingAs($c, 'customer')
            ->post("/account/security/ai-project/{$p->id}/update", ['status' => 'active'])
            ->assertRedirect();
        $this->assertSame(AiProject::STATUS_ACTIVE, $p->fresh()->status);
    }

    public function test_archive_is_terminal(): void
    {
        $c = $this->customer();
        $p = $this->project($c);
        $p->archive();

        // آرشیو پایانی است — هیچ وضعیتی برنمی‌گردد
        $this->actingAs($c, 'customer')
            ->post("/account/security/ai-project/{$p->id}/update", ['status' => 'active'])
            ->assertSessionHasErrors('status');

        $this->assertSame(AiProject::STATUS_ARCHIVED, $p->fresh()->status);
    }

    // ── E/F/X: مالکیت ──

    public function test_project_isolated_per_customer_on_page(): void
    {
        $a = $this->customer();
        $b = $this->customer();
        $this->project($a, ['name' => 'A-project']);

        $this->actingAs($b, 'customer')->get('/account/security')
            ->assertOk()
            ->assertDontSee('A-project');
    }

    public function test_foreign_project_update_returns_404(): void
    {
        $a = $this->customer();
        $b = $this->customer();
        $pa = $this->project($a);

        $this->actingAs($b, 'customer')
            ->post("/account/security/ai-project/{$pa->id}/update", ['status' => 'disabled'])
            ->assertNotFound();

        $this->assertSame(AiProject::STATUS_ACTIVE, $pa->fresh()->status);
    }

    public function test_token_cannot_bind_foreign_project(): void
    {
        $a = $this->customer();
        $b = $this->customer();
        $pa = $this->project($a); // پروژهٔ مشتریِ دیگر

        $this->actingAs($b, 'customer')->post('/account/security/api-token', [
            'name' => 'k', 'abilities' => ['ai:chat'], 'ai_project_id' => $pa->id,
        ])->assertSessionHasErrors('ai_project_id');

        $this->assertSame(0, $b->apiTokens()->count(), 'کلیدِ AI نباید صادر شود');
    }

    // ── G/H/I: صدورِ کلیدِ AI ──

    public function test_token_binds_owned_active_project(): void
    {
        $c = $this->customer();
        $p = $this->project($c);

        $this->actingAs($c, 'customer')->post('/account/security/api-token', [
            'name' => 'k', 'abilities' => ['ai:chat', 'ai:models:read'], 'ai_project_id' => $p->id,
        ])->assertRedirect()->assertSessionHas('new_token');

        $t = $c->apiTokens()->first();
        $this->assertSame($p->id, $t->ai_project_id);
        $this->assertTrue($t->can('ai:chat'));
    }

    public function test_ai_abilities_without_project_rejected(): void
    {
        $c = $this->customer();
        $this->project($c); // پروژه هست ولی انتخاب نشده

        $this->actingAs($c, 'customer')->post('/account/security/api-token', [
            'name' => 'k', 'abilities' => ['ai:chat'],
        ])->assertSessionHasErrors('ai_project_id');

        $this->assertSame(0, $c->apiTokens()->count(), 'کلیدِ AIِ بی‌پروژه نباید صادر شود');
    }

    public function test_project_without_ai_ability_stays_non_ai(): void
    {
        $c = $this->customer();
        $p = $this->project($c);

        // پروژه فرستاده شده ولی تیکِ AI نیست → پروژه نادیده، توکنِ عادی
        $this->actingAs($c, 'customer')->post('/account/security/api-token', [
            'name' => 'k', 'abilities' => ['read'], 'ai_project_id' => $p->id,
        ])->assertRedirect();

        $t = $c->apiTokens()->first();
        $this->assertNull($t->ai_project_id, 'توکنِ عادی نباید AI شود');
        $this->assertFalse($t->isAiKey());
    }

    // ── J/K: صدور و هش ──

    public function test_plaintext_token_shown_once_and_hashed(): void
    {
        $c = $this->customer();

        $this->actingAs($c, 'customer')->post('/account/security/api-token', ['name' => 'k'])
            ->assertRedirect()->assertSessionHas('new_token');

        $plain = session('new_token');
        $this->assertStringStartsWith('sn_', $plain);

        $t = $c->apiTokens()->first();
        $this->assertSame(hash('sha256', $plain), $t->token_hash, 'فقط SHA-256 ذخیره می‌شود');
        $this->assertNotSame($plain, $t->token_hash);
    }

    // ── L/M/N: انقضا، ابطال، CIDR (مستقیم روی مدل — منطقِ واگذارشده) ──

    public function test_revoke_and_expiry_reasons(): void
    {
        $c = $this->customer();
        [$revoked] = CustomerApiToken::issue($c->id, 'r', ['read']);
        [$expired] = CustomerApiToken::issue($c->id, 'e', ['read'], [], now()->subDay());

        $revoked->revoke();
        $this->assertSame('token_revoked', $revoked->unusableReason());
        $this->assertSame('token_expired', $expired->unusableReason());
        $this->assertSame(0, $c->apiTokens()->usable()->count());
    }

    public function test_cidr_allow_and_deny(): void
    {
        $c = $this->customer();
        [$t] = CustomerApiToken::issue($c->id, 'c', ['read'], [], null, null);
        $t->forceFill(['allowed_cidrs' => ['10.0.0.0/8']])->save();

        $this->assertTrue($t->allowsIp('10.1.2.3'));
        $this->assertFalse($t->allowsIp('185.10.20.30'));
        $this->assertFalse($t->allowsIp(null), 'IPِ ناموجود با فهرستِ پر رد می‌شود');
    }

    // ── O/P/Q/R/S/T + V/W: سیمِ admission ──

    private function aiToken(Customer $c, ?AiProject $p, array $abilities = ['ai:chat']): CustomerApiToken
    {
        [$t] = CustomerApiToken::issue($c->id, 'ai', $abilities, [], null, $p?->id);

        return $t;
    }

    public function test_admission_allows_explicit_scope(): void
    {
        $c = $this->customer();
        $p = $this->project($c);
        $t = $this->aiToken($c, $p);

        $ctx = AiAdmission::authorize($t, 'ai:chat', '1.2.3.4');

        $this->assertTrue($ctx->ok);
        $this->assertSame('authorized', $ctx->code);
        $this->assertSame($p->id, $ctx->project->id);
    }

    public function test_admission_denies_missing_scope(): void
    {
        $c = $this->customer();
        $p = $this->project($c);
        $t = $this->aiToken($c, $p, ['ai:models:read']);

        $this->assertFalse(AiAdmission::authorize($t, 'ai:chat')->ok);
        $this->assertSame('insufficient_scope', AiAdmission::authorize($t, 'ai:chat')->code);
    }

    public function test_admission_rejects_non_ai_ability_request(): void
    {
        $c = $this->customer();
        $p = $this->project($c);
        $t = $this->aiToken($c, $p);

        $this->assertSame('not_ai_scope', AiAdmission::authorize($t, 'domains:read')->code);
    }

    public function test_legacy_wildcard_token_cannot_access_ai(): void
    {
        $c = $this->customer();
        [$t] = CustomerApiToken::issue($c->id, 'old', ['*']);
        // حتی اگر آدراساً پروژه بسته شود، «*» AI نمی‌خرد
        $p = $this->project($c);
        $t->forceFill(['ai_project_id' => $p->id])->save();

        $this->assertFalse($t->can('ai:chat'));
        $this->assertSame('not_ai_key', AiAdmission::authorize($t, 'ai:chat')->code);
    }

    public function test_admission_denies_inactive_project(): void
    {
        $c = $this->customer();
        $p = $this->project($c);
        $t = $this->aiToken($c, $p);
        $p->disable();

        $this->assertSame('project_inactive', AiAdmission::authorize($t, 'ai:chat')->code);
    }

    public function test_admission_denies_archived_and_missing_project(): void
    {
        $c = $this->customer();

        // آرشیو به‌عنوانِ وضعیت (بدونِ archive() که توکن را هم ابطال می‌کند)
        // — این‌جا فقط سیمِ status سنجیده می‌شود؛ ابطالِ کلید تستِ خودش را دارد.
        $pa = $this->project($c);
        $ta = $this->aiToken($c, $pa);
        $pa->forceFill(['status' => AiProject::STATUS_ARCHIVED])->save();
        $this->assertSame('project_inactive', AiAdmission::authorize($ta, 'ai:chat')->code);

        // گم‌شده: سطرِ پروژه حذف شد (nullOnDelete) → کلید یا بی‌پروژه می‌افتد
        // (not_ai_key) یا اگر ستون مانده باشد project_missing — هر دو ردند.
        $pm = $this->project($c);
        $tm = $this->aiToken($c, $pm);
        $pm->delete();

        $code = AiAdmission::authorize($tm->fresh(), 'ai:chat')->code;
        $this->assertFalse($code === 'authorized', 'حذفِ پروژه نباید admission را باز بگذارد');
        $this->assertContains($code, ['project_missing', 'not_ai_key']);
    }

    public function test_admission_denies_inactive_account(): void
    {
        $c = $this->customer();
        $p = $this->project($c);
        $t = $this->aiToken($c, $p);

        $c->forceFill(['status' => 'suspended'])->save();
        $this->assertFalse($c->fresh()->isActive());

        $this->assertSame('account_inactive', AiAdmission::authorize($t, 'ai:chat')->code);
    }

    public function test_admission_denies_cidr_and_expired_and_revoked(): void
    {
        $c = $this->customer();
        $p = $this->project($c);

        $t = $this->aiToken($c, $p);
        $t->forceFill(['allowed_cidrs' => ['10.0.0.0/8']])->save();
        $this->assertSame('ip_not_allowed', AiAdmission::authorize($t, 'ai:chat', '185.1.1.1')->code);

        $t2 = $this->aiToken($c, $p);
        $t2->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->assertSame('token_expired', AiAdmission::authorize($t2, 'ai:chat')->code);

        $t3 = $this->aiToken($c, $p);
        $t3->revoke();
        $this->assertSame('token_revoked', AiAdmission::authorize($t3, 'ai:chat')->code);
    }

    public function test_use_count_increments_exactly_once_on_success(): void
    {
        $c = $this->customer();
        $p = $this->project($c);
        $t = $this->aiToken($c, $p);

        $this->assertSame(0, (int) $t->fresh()->use_count);

        $this->assertTrue(AiAdmission::authorize($t->fresh(), 'ai:chat')->ok);
        $this->assertSame(1, (int) $t->fresh()->use_count, 'یک admission موفق = یک افزایش');

        $this->assertTrue(AiAdmission::authorize($t->fresh(), 'ai:chat')->ok);
        $this->assertSame(2, (int) $t->fresh()->use_count);
    }

    public function test_denied_admission_does_not_increment_use_count(): void
    {
        $c = $this->customer();
        $p = $this->project($c);
        $t = $this->aiToken($c, $p);
        $p->disable();

        AiAdmission::authorize($t, 'ai:chat');
        AiAdmission::authorize($t, 'domains:read');
        $this->assertSame(0, (int) $t->fresh()->use_count, 'ردِ admission نباید شمرد');
    }

    // ── U: بازهٔ بودجه ──

    public function test_budget_window_boundaries(): void
    {
        // ⚠️ بازهٔ بودجه از now() ساخته می‌شود، پس بی‌ساعتِ ثابت این تست فقط
        //    بینِ ۱۵ نوامبر و ۱۴ دسامبر سبز بود — قرمزیِ تقویمی، نه باگ.
        Carbon::setTestNow('2026-11-20 10:00:00');

        $c = $this->customer();
        $p = $this->project($c, [
            'monthly_budget_irt' => 100000,
            'budget_period' => AiProject::PERIOD_MONTHLY,
            'budget_reset_day' => 15,
        ]);
        $p->refreshBudgetWindow();

        // ۲۰ نوامبر → سیکلِ [۱۵ نوامبر، ۱۵ دسامبر)
        $w = $p->budgetWindowFor(Carbon::parse('2026-11-20 10:00'));
        $this->assertSame('2026-11-15', $w['from']->format('Y-m-d'));
        $this->assertSame('2026-12-15', $w['until']->format('Y-m-d'));
        $this->assertSame('2026-11', $w['key']);

        // مرزِ نیم‌بازه: خودِ `until` مالِ سیکلِ بعد است
        $this->assertTrue($p->budgetWindowCovers(Carbon::parse('2026-11-15 00:00')));
        $this->assertTrue($p->budgetWindowCovers(Carbon::parse('2026-11-20 10:00')));
        $this->assertFalse($p->budgetWindowCovers(Carbon::parse('2026-12-15 00:00')));

        // ۱۴ نوامبر → هنوز سیکلِ [۱۵ اکتبر، ۱۵ نوامبر)
        $w2 = $p->budgetWindowFor(Carbon::parse('2026-11-14 10:00'));
        $this->assertSame('2026-10-15', $w2['from']->format('Y-m-d'));

        // بدونِ دورهٔ ماهانه → بی‌سقف و همیشه پوشیده
        $p2 = $this->project($c);
        $this->assertNull($p2->budgetWindowFor(now()));
        $this->assertTrue($p2->budgetWindowCovers(now()));
    }

    public function test_stale_budget_window_denies_admission(): void
    {
        $c = $this->customer();
        $p = $this->project($c, [
            'monthly_budget_irt' => 100000,
            'budget_period' => AiProject::PERIOD_MONTHLY,
            'budget_reset_day' => 1,
        ]);
        $p->refreshBudgetWindow();
        $t = $this->aiToken($c, $p);

        // پنجرهٔ ذخیره‌شده یک ماه عقب است → admission باید رد کند تا تازه شود
        $p->forceFill([
            'budget_window_from' => now()->subMonths(2)->startOfMonth(),
            'budget_window_until' => now()->subMonths(1)->startOfMonth(),
        ])->save();

        $this->assertSame('budget_window_stale', AiAdmission::authorize($t, 'ai:chat')->code);

        $p->refreshBudgetWindow();
        $this->assertTrue(AiAdmission::authorize($t->fresh(), 'ai:chat')->ok);
    }

    // ── Z: قطعِ کلید ──

    public function test_disable_and_archive_leave_no_usable_bound_ai_token(): void
    {
        $c = $this->customer();

        // disable: سطرِ توکن می‌مانَد (برخاستنی) ولی admission رد می‌کند
        $pd = $this->project($c);
        $td = $this->aiToken($c, $pd);
        $pd->disable();
        $this->assertNull($td->fresh()->revoked_at, 'disable ابطال نمی‌کند — برخاستنی است');
        $this->assertSame('project_inactive', AiAdmission::authorize($td, 'ai:chat')->code);

        // archive: کلیدهایِ usable نرم ابطال می‌شوند
        $pa = $this->project($c);
        $ta = $this->aiToken($c, $pa);
        $pa->archive();
        $this->assertNotNull($ta->fresh()->revoked_at, 'آرشیو باید کلیدِ زنده را باطل کند');
        $this->assertSame(0, $pa->tokens()->usable()->count());
    }

    // ── Y: برابریِ کلیدهای locale ──

    public function test_ai_translation_key_parity_en_fa_tr(): void
    {
        $pick = function (string $locale): array {
            $all = require lang_path("$locale/ui.php");

            return array_values(array_filter(array_keys($all), fn ($k) => str_starts_with((string) $k, 'sec_ai') || str_starts_with((string) $k, 'act_ai')));
        };

        $en = $pick('en');
        $fa = $pick('fa');
        $tr = $pick('tr');

        sort($en); sort($fa); sort($tr);

        $this->assertSame($en, $fa, 'کلیدهای AI فا و ان یکسان نیستند');
        $this->assertSame($en, $tr, 'کلیدهای AI ترکی و ان یکسان نیستند');
        $this->assertNotEmpty($en);
    }
}
