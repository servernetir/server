<?php

namespace Tests\Feature;

use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تنظیمِ دستیِ کیفِ پول از پنلِ مدیریت.
 *
 * 🔴 دفترِ اعتبار افزودنی است: موجودی جمعِ سطرهاست. پس هر ادعای این تست روی
 * **سطرِ تازه** است، نه روی یک ستونِ موجودی — چون چنین ستونی وجود ندارد و اگر
 * روزی کسی بسازدش، همان لحظه دو منبعِ حقیقت می‌شود.
 */
class AdminCreditAdjustTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function customer(int $startingCredit = 0): Customer
    {
        $c = Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'c'.random_int(1000, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);

        if ($startingCredit !== 0) {
            CreditEntry::create([
                'customer_id' => $c->id, 'currency_code' => 'IRT',
                'amount' => $startingCredit, 'balance_after' => $startingCredit,
                'reason' => 'refund', 'note' => 'موجودیِ اولیهٔ تست',
            ]);
        }

        return $c;
    }

    public function test_an_admin_can_add_credit_with_a_note(): void
    {
        $c = $this->customer();

        $this->actingAs($this->admin)
            ->post('/admin/customers/'.$c->id.'/credit', [
                'direction' => 'add', 'amount' => 500000, 'note' => 'هدیهٔ نوروزی',
            ])->assertRedirect();

        $this->assertSame(500000, $c->fresh()->creditBalance('IRT'));

        $row = CreditEntry::where('customer_id', $c->id)->latest('id')->first();
        $this->assertSame('adjustment', $row->reason);
        $this->assertSame('هدیهٔ نوروزی', $row->note);
        $this->assertSame(500000, $row->amount);
    }

    public function test_an_admin_can_subtract_credit(): void
    {
        $c = $this->customer(594000);

        $this->actingAs($this->admin)
            ->post('/admin/customers/'.$c->id.'/credit', [
                'direction' => 'subtract', 'amount' => 594000,
                'note' => 'عودتِ وجه کارت‌به‌کارت شد',
            ])->assertRedirect();

        $this->assertSame(0, $c->fresh()->creditBalance('IRT'));

        // 🔴 سطرِ قبلی باید سرِ جایش باشد — کاهش یعنی سطرِ تازه، نه پاک‌کردنِ تاریخچه
        $this->assertSame(2, CreditEntry::where('customer_id', $c->id)->count());
    }

    /**
     * توضیح اجباری است. یک جابه‌جاییِ پولِ بی‌دلیل، ماه‌ها بعد قابلِ بازسازی
     * نیست — نه معلوم است بابتِ چه بوده، نه اینکه اصلاً درست بوده.
     */
    public function test_it_refuses_an_adjustment_without_a_note(): void
    {
        $c = $this->customer(100000);

        $this->actingAs($this->admin)
            ->post('/admin/customers/'.$c->id.'/credit', [
                'direction' => 'add', 'amount' => 50000,
            ])->assertSessionHasErrors('note');

        $this->assertSame(100000, $c->fresh()->creditBalance('IRT'));
    }

    /**
     * ⚠️ موجودیِ منفی را هیچ‌جای این سیستم نمی‌فهمد (پرداختِ فاکتور از کیفِ
     * پول، مترِ ساعتی، APIِ مشتری). اجازه‌دادنش یعنی بدهیِ خاموشی که هیچ
     * صورت‌حسابی نشانش نمی‌دهد.
     */
    public function test_the_balance_can_never_go_negative(): void
    {
        $c = $this->customer(100000);

        $this->actingAs($this->admin)
            ->post('/admin/customers/'.$c->id.'/credit', [
                'direction' => 'subtract', 'amount' => 100001, 'note' => 'یکی بیشتر',
            ])->assertSessionHasErrors();

        $this->assertSame(100000, $c->fresh()->creditBalance('IRT'),
            'کسرِ ردشده نباید هیچ سطری بنویسد.');
        $this->assertSame(1, CreditEntry::where('customer_id', $c->id)->count());
    }

    public function test_a_non_admin_cannot_touch_the_wallet(): void
    {
        $c = $this->customer(100000);
        $staff = User::factory()->create(['role' => 'user']);

        $this->actingAs($staff)
            ->post('/admin/customers/'.$c->id.'/credit', [
                'direction' => 'subtract', 'amount' => 100000, 'note' => 'تلاشِ غیرمجاز',
            ])->assertForbidden();

        $this->assertSame(100000, $c->fresh()->creditBalance('IRT'));
    }

    /**
     * ⚠️ «کدِ ۲۰۰ یعنی هیچ» — این ادعا روی خودِ **فرم** است، نه روی وضعیتِ
     * صفحه. اگر فرم در ویو نباشد، قابلیت ساخته شده و از پنل به آن نمی‌شود رسید.
     */
    public function test_the_wallet_form_is_actually_on_the_customer_page(): void
    {
        $c = $this->customer(594000);

        $this->actingAs($this->admin)
            ->get('/admin/customers/'.$c->id)
            ->assertOk()
            ->assertSee('/admin/customers/'.$c->id.'/credit', false)
            ->assertSee('name="note"', false)
            ->assertSee('name="direction"', false);
    }
}
