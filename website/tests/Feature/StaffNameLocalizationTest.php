<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * نامِ کارشناس در پیام‌های پشتیبانی — به زبانِ خودِ مشتری (خواستِ کارفرما،
 * ۷ شهریور ۱۴۰۵): مشتریِ فارسی «احسان ابراهیمی» ببیند، مشتریِ en/tr نسخهٔ
 * لاتین — و هرگز نامِ فارسی وسطِ رابطِ انگلیسی نیفتد.
 */
class StaffNameLocalizationTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::create([
            'name' => 'احسان ابراهیمی', 'name_latin' => 'Ehsan Ebrahimi',
            'email' => 's'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);
    }

    private function ticketFor(Customer $c, User $staff): Ticket
    {
        $t = Ticket::create([
            'customer_id' => $c->id, 'number' => 'TK-'.random_int(1000, 9999),
            'subject' => 'موضوع', 'department' => 'technical', 'priority' => 'normal',
            'status' => 'answered', 'last_reply_role' => 'staff', 'last_reply_at' => now(),
        ]);

        // author_name عمداً عکسِ کهنه است — نمایش باید از خودِ User بیاید
        $t->addMessage('customer', null, null, 'مشکل دارم');
        $t->addMessage('staff', $staff->id, 'ebrahimi', 'رسیدگی شد');

        return $t;
    }

    private function customer(string $locale): Customer
    {
        return Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'c'.random_int(1, 99999).'@example.com',
            'phone' => $locale === 'fa'
                ? '0912'.random_int(1000000, 9999999)
                : '+90532'.random_int(1000000, 9999999),
            'password' => 'secret1234', 'status' => 'active', 'locale' => $locale,
        ]);
    }

    public function test_persian_customer_sees_the_persian_staff_name(): void
    {
        $staff = $this->staff();
        $c = $this->customer('fa');
        $t = $this->ticketFor($c, $staff);

        $html = (string) $this->actingAs($c, 'customer')
            ->get('/account/tickets/'.$t->id)->assertOk()->getContent();

        $this->assertStringContainsString('احسان ابراهیمی', $html);
        // عکسِ کهنهٔ author_name نباید نشت کند
        $this->assertStringNotContainsString('ebrahimi', $html);
    }

    public function test_english_customer_sees_the_latin_staff_name_and_no_persian(): void
    {
        $staff = $this->staff();
        $c = $this->customer('en');
        $t = $this->ticketFor($c, $staff);

        $html = (string) $this->actingAs($c, 'customer')
            ->get('/en/account/tickets/'.$t->id)->assertOk()->getContent();

        $this->assertStringContainsString('Ehsan Ebrahimi', $html);
        $this->assertStringContainsString(__('ui.tk_staff', [], 'en'), $html);
        $this->assertStringNotContainsString('احسان ابراهیمی', $html);
    }

    /**
     * کارمندِ بی‌نامِ لاتین: مشتریِ خارجی فقط برچسبِ عمومی می‌بیند — نامِ
     * فارسی وسطِ صفحهٔ انگلیسی از ننوشتنِ نام بدتر است.
     */
    public function test_missing_latin_name_falls_back_to_the_generic_label(): void
    {
        $staff = User::create([
            'name' => 'کارمند بی‌لاتین',
            'email' => 'n'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('x'), 'role' => 'support',
        ]);
        $c = $this->customer('en');
        $t = $this->ticketFor($c, $staff);

        $html = (string) $this->actingAs($c, 'customer')
            ->get('/en/account/tickets/'.$t->id)->assertOk()->getContent();

        $this->assertStringContainsString(__('ui.tk_staff', [], 'en'), $html);
        $this->assertStringNotContainsString('کارمند بی‌لاتین', $html);
    }

    /** مهاجرت، نامِ قدیمیِ «ebrahimi» را دوزبانه کرده باشد — و idempotent بماند */
    public function test_the_migration_renames_the_owner_account(): void
    {
        $u = User::create([
            'name' => 'ebrahimi', 'email' => 'o'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);

        $m = require database_path('migrations/2026_10_06_000101_add_latin_name_to_users.php');
        $m->up();   // ستون هست؛ فقط backfill دوباره می‌دود — باید بی‌خطا باشد
        $m->up();

        $u->refresh();
        $this->assertSame('احسان ابراهیمی', $u->name);
        $this->assertSame('Ehsan Ebrahimi', $u->name_latin);
    }
}
