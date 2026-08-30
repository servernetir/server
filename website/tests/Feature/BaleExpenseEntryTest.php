<?php

namespace Tests\Feature;

use App\Models\BusinessEntry;
use App\Services\Finance\BusinessLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ثبتِ هزینه از ربات بله باید در **همان دفترِ مالیِ پنل** بنشیند.
 *
 * 🔴 انبارِ موازی یعنی جمعِ داشبورد با جمعِ ربات نخوانَد و هیچ‌کدام معلوم
 * نباشد کدام درست است. برای یک سامانهٔ مالی، دو عددِ متفاوت از هیچ عددی بدتر
 * است.
 */
class BaleExpenseEntryTest extends TestCase
{
    use RefreshDatabase;

    /** 🔴 ثبت از هر مسیری، همان ردیفِ `business_entries` را می‌سازد. */
    public function test_an_expense_lands_in_the_same_ledger_the_panel_reads(): void
    {
        $ledger = app(BusinessLedger::class);

        $entry = $ledger->manual('expense', 250000, 'server', null, 'اجارهٔ سرور هتزنر', null);

        $this->assertNotNull($entry, 'ثبت نشد');
        $this->assertSame('out', $entry->direction, 'هزینه باید جهتِ خروجی داشته باشد');
        $this->assertSame('server', $entry->category);

        // و در همان جمعی که داشبورد نشان می‌دهد دیده می‌شود
        $this->assertGreaterThanOrEqual(250000, (int) ($ledger->summary()['expense'] ?? 0));
    }

    /**
     * 🔴 مبلغِ صفر یا منفی ردیف نمی‌سازد.
     *
     * دفترِ مالی‌ای که ردیفِ صفر بپذیرد، جمع‌هایش را با نویز پر می‌کند.
     */
    public function test_a_zero_or_negative_amount_creates_nothing(): void
    {
        $before = BusinessEntry::count();

        app(BusinessLedger::class)->manual('expense', 0, 'server', null, null, null);
        app(BusinessLedger::class)->manual('expense', -5000, 'server', null, null, null);

        $this->assertSame($before, BusinessEntry::count(), 'ردیفِ بی‌مبلغ ساخته شد');
    }

    /** ⚠️ دستهٔ ناشناخته نباید وارد دفتر شود. */
    public function test_the_category_list_is_the_single_source(): void
    {
        $router = (string) file_get_contents(app_path('Services/Bale/Admin/AdminBaleRouter.php'));

        $this->assertStringContainsString('BusinessLedger::EXPENSE_CATEGORIES', $router,
            'ربات فهرستِ دستیِ دسته‌ها دارد — روزی از پنل عقب می‌افتد');
        $this->assertStringContainsString('BusinessLedger::categoryLabel', $router);
    }

    /**
     * ⚠️ برچسب‌ها هم یک منبع دارند.
     *
     * تا امروز فقط در `finance.blade.php` بودند؛ کپی‌شدنشان در ربات یعنی روزی
     * پنل و ربات دو نام برای یک دسته بگویند.
     */
    public function test_the_panel_reads_the_shared_labels_too(): void
    {
        $blade = (string) file_get_contents(resource_path('views/admin/finance.blade.php'));

        $this->assertStringContainsString('BusinessLedger::CATEGORY_LABELS', $blade);
        $this->assertStringNotContainsString("'server'=>'سرور و زیرساخت'", $blade,
            'نگاشتِ محلی هنوز در Blade هست');
    }

    /** هر دستهٔ اعلام‌شده باید برچسب داشته باشد، وگرنه در ربات کلیدِ خام دیده می‌شود. */
    public function test_every_category_has_a_label(): void
    {
        $missing = array_values(array_filter(
            BusinessLedger::EXPENSE_CATEGORIES,
            fn ($c) => BusinessLedger::categoryLabel($c) === $c,
        ));

        $this->assertSame([], $missing, 'دستهٔ بی‌برچسب: '.implode('، ', $missing));
    }
}
