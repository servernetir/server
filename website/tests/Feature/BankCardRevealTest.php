<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankCardRevealTest extends TestCase
{
    use RefreshDatabase;
    private const PAN = '6037997512345678';

    private function fixtures(): array
    {
        $customer = Customer::create(['code'=>'SN-CARD','email'=>'card@example.test','phone'=>'09120000003','password'=>bcrypt('x'),'status'=>'active','locale'=>'fa']);
        $account = BankAccount::create(['customer_id'=>$customer->id,'card_bin'=>'603799','card_last4'=>'5678','card_number_enc'=>self::PAN,'status'=>'verified']);
        return [$customer, $account];
    }
    private function user(string $role): User { return User::create(['name'=>$role,'email'=>$role.random_int(1,9999).'@x.test','password'=>bcrypt('x'),'role'=>$role]); }

    public function test_initial_admin_page_contains_only_masked_card(): void
    {
        [$c] = $this->fixtures();
        $html = $this->actingAs($this->user('admin'))->get('/admin/customers/'.$c->id)->assertOk()->getContent();
        $this->assertStringNotContainsString(self::PAN, $html);
        $this->assertStringContainsString('603799 **** **** 5678', $html);
    }

    public function test_only_admin_can_explicitly_reveal_and_access_is_audited_without_pan(): void
    {
        [$c,$a] = $this->fixtures();
        $url = "/admin/customers/{$c->id}/bank-accounts/{$a->id}/reveal";
        $this->actingAs($this->user('support'))->post($url)->assertForbidden();
        $response = $this->actingAs($this->user('admin'))->post($url)->assertOk();
        $response->assertHeader('Cache-Control', 'no-store, private, max-age=0');
        $response->assertSee(self::PAN);
        $log = \App\Models\ActivityLog::where('action','bank_card_revealed')->firstOrFail();
        $this->assertStringNotContainsString(self::PAN, $log->description);
    }

    public function test_reveal_rejects_cross_customer_idor(): void
    {
        [$c,$a] = $this->fixtures();
        $other = Customer::create(['code'=>'SN-OTHER','email'=>'other@example.test','phone'=>'09120000004','password'=>bcrypt('x'),'status'=>'active','locale'=>'fa']);
        $this->actingAs($this->user('admin'))->post("/admin/customers/{$other->id}/bank-accounts/{$a->id}/reveal")->assertNotFound();
    }
}
