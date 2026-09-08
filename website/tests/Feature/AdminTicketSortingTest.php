<?php
namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminTicketSortingTest extends TestCase
{
    use RefreshDatabase;
    private function admin(): User { return User::create(['name'=>'A','email'=>'a@sort.test','password'=>bcrypt('x'),'role'=>'admin']); }
    private function customer(): Customer { return Customer::create(['code'=>'SN-SORT','email'=>'c@sort.test','phone'=>'09120000111','password'=>bcrypt('x'),'status'=>'active','locale'=>'fa']); }
    private function ticket(Customer $c, string $subject, string $status, string $created, string $reply): Ticket
    {
        $t=Ticket::create(['customer_id'=>$c->id,'subject'=>$subject,'department'=>'technical','priority'=>'normal','status'=>$status,'last_reply_role'=>$status==='open'?'customer':'staff','last_reply_at'=>Carbon::parse($reply)]);
        $t->forceFill(['created_at'=>Carbon::parse($created),'updated_at'=>Carbon::parse($created)])->saveQuietly(); return $t;
    }
    private function order(string $query): array
    {
        $admin = User::where('role', 'admin')->first() ?? $this->admin();
        $html=$this->actingAs($admin)->get('/admin/tickets?'.$query)->assertOk()->getContent();
        preg_match_all('/TK-\d+-\d+/', $html, $m); return array_values(array_unique($m[0]));
    }
    public function test_activity_and_creation_sorts_are_deterministic(): void
    {
        $c=$this->customer();
        $old=$this->ticket($c,'old','answered','2026-01-01','2026-04-01');
        $new=$this->ticket($c,'new','answered','2026-02-01','2026-03-01');
        $this->assertSame([$old->number,$new->number], $this->order('status=all&sort=activity_desc'));
        $this->assertSame([$new->number,$old->number], $this->order('status=all&sort=created_desc'));
    }
    public function test_default_workflow_keeps_actionable_oldest_first_then_recent_answered(): void
    {
        $c=$this->customer();
        $answered=$this->ticket($c,'answered','answered','2026-01-01','2026-06-01');
        $openNew=$this->ticket($c,'open-new','open','2026-01-02','2026-05-01');
        $openOld=$this->ticket($c,'open-old','open','2026-01-03','2026-04-01');
        $this->assertSame([$openOld->number,$openNew->number,$answered->number], $this->order(''));
    }
    public function test_customer_and_staff_reply_sorts_use_message_timestamps(): void
    {
        $c=$this->customer(); $a=$this->ticket($c,'a','answered','2026-01-01','2026-06-01'); $b=$this->ticket($c,'b','answered','2026-01-02','2026-05-01');
        Carbon::setTestNow('2026-07-01'); $b->addMessage('customer',$c->id,null,'customer');
        Carbon::setTestNow('2026-08-01'); $a->addMessage('staff',$this->admin()->id,'A','staff'); Carbon::setTestNow();
        $this->assertSame($b->number,$this->order('status=all&sort=customer_desc')[0]);
        $this->assertSame($a->number,$this->order('status=all&sort=staff_desc')[0]);
    }
}
