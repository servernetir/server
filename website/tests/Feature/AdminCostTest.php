<?php

namespace Tests\Feature;

use App\Models\BusinessEntry;
use App\Models\ServiceCost;
use App\Models\User;
use App\Services\Finance\BusinessLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * هزینه‌های ثابت سرویس‌ها — که مدیر خودش تعیین می‌کند.
 *
 * محورها:
 *   • مقادیر اولیه از مهاجرت seed می‌شوند
 *   • ویرایش مبلغ، و دفتر مالی از عدد جدید حساب می‌کند (نه config)
 *   • هزینهٔ سیستمی حذف نمی‌شود؛ هزینهٔ دلخواه بله
 *   • تا وقتی مبلغ صفر است، هیچ هزینه‌ای در دفتر ثبت نمی‌شود
 */
class AdminCostTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::create([
            'name' => 'مدیر', 'email' => 's'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('secret1234'), 'role' => 'admin',
        ]);
    }

    public function test_costs_are_seeded_from_migration(): void
    {
        $this->assertSame(1500, ServiceCost::amountFor('sms'));
        $this->assertSame(13000, ServiceCost::amountFor('shahkar'));
    }

    public function test_edited_amount_is_what_the_ledger_records(): void
    {
        $sms = ServiceCost::where('key', 'sms')->first();

        $this->actingAs($this->staff(), 'web')
            ->post('/admin/costs', ['amount' => [$sms->id => 2200]])
            ->assertRedirect();

        $this->assertSame(2200, ServiceCost::amountFor('sms'));

        // دفتر مالی باید عددِ تازه را ثبت کند، نه ۱۵۰۰ config
        app(BusinessLedger::class)->recordApiCost('api_sms', 'sms', 'تست');
        $this->assertSame(2200, (int) BusinessEntry::where('kind', 'expense')->sum('amount'));
    }

    public function test_zero_cost_records_nothing(): void
    {
        $sms = ServiceCost::where('key', 'sms')->first();
        $sms->update(['amount' => 0]);

        app(BusinessLedger::class)->recordApiCost('api_sms', 'sms', 'تست صفر');

        $this->assertSame(0, BusinessEntry::where('kind', 'expense')->count());
    }

    public function test_custom_cost_can_be_added_and_deleted(): void
    {
        $this->actingAs($this->staff(), 'web')
            ->post('/admin/costs/add', ['label' => 'لایسنس ماهانه', 'amount' => 5000000])
            ->assertRedirect();

        $cost = ServiceCost::where('label', 'لایسنس ماهانه')->first();
        $this->assertNotNull($cost);
        $this->assertFalse($cost->is_system);

        $this->actingAs($this->staff(), 'web')
            ->post("/admin/costs/{$cost->id}/delete")
            ->assertRedirect();

        $this->assertNull(ServiceCost::find($cost->id));
    }

    public function test_system_cost_cannot_be_deleted(): void
    {
        $sms = ServiceCost::where('key', 'sms')->first();

        $this->actingAs($this->staff(), 'web')
            ->post("/admin/costs/{$sms->id}/delete")
            ->assertSessionHasErrors();

        $this->assertNotNull(ServiceCost::find($sms->id));
    }
}
