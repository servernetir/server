<?php
namespace Tests\Feature;
use App\Models\CrmLead;
use App\Models\User;
use App\Services\Crm\AssistantLeadCapture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
class AssistantLeadCaptureTest extends TestCase
{
 use RefreshDatabase;
 protected function setUp(): void { parent::setUp(); config(['services.bale.token'=>'bot','servernet.contact.notify_phones'=>[],'servernet.contact.notify_chat_ids'=>['101','202'],'servernet.contact.email'=>'']); Http::swap(new Factory); Http::fake(['*'=>Http::response(['ok'=>true])]); Mail::fake(); }
 public function test_capture_is_idempotent_and_notifies_all_admin_destinations_once(): void
 {
  $s=app(AssistantLeadCapture::class); $data=['name'=>'علی','phone'=>'09120000000','email'=>'lead@example.test','summary'=>'سرور ایران','product'=>'VPS'];
  $first=$s->capture($data,'session-1','fa','https://servernet.cloud/vps',null);
  $second=$s->capture($data+['summary'=>'خلاصه تازه'],'session-1','fa','https://servernet.cloud/vps',null);
  $this->assertSame($first->id,$second->id); $this->assertSame(1,CrmLead::where('source','assistant')->count()); $this->assertSame('خلاصه تازه',$second->fresh()->observation);
  Http::assertSentCount(2);
  foreach(['101','202'] as $chat) Http::assertSent(fn($r)=>(string)($r->data()['chat_id']??'')===$chat);
  Mail::assertNothingSent(); // ایمیل فعلی را n8n می‌فرستد؛ اپ نباید آن را دوبرابر کند.
 }
 public function test_notification_failure_never_rolls_back_or_duplicates_the_lead(): void
 {
  Http::fake(fn()=>throw new \Illuminate\Http\Client\ConnectionException('bale unavailable'));
  $service=app(AssistantLeadCapture::class);
  $first=$service->capture(['name'=>'Persisted'],'stable-session','en',null,null);
  $second=$service->capture(['name'=>'Persisted'],'stable-session','en',null,null);
  $this->assertNotNull($first); $this->assertSame($first->id,$second->id);
  $this->assertSame(1,CrmLead::where('session_key',hash('sha256','assistant|stable-session'))->count());
 }
 public function test_assistant_leads_are_filterable_and_stage_change_is_audited(): void
 {
  $lead=app(AssistantLeadCapture::class)->capture(['name'=>'Lead'],'session-2','tr',null,null);
  $admin=User::create(['name'=>'A','email'=>'a@lead.test','password'=>bcrypt('x'),'role'=>'admin']);
  $this->actingAs($admin)->get('/admin/marketing?source=assistant')->assertOk()->assertSee('Lead');
  $this->actingAs($admin)->post('/admin/marketing/'.$lead->id.'/stage',['stage'=>'contacted'])->assertRedirect();
  $this->assertSame('contacted',$lead->fresh()->stage); $this->assertDatabaseHas('activity_logs',['action'=>'crm_lead_stage','actor'=>'staff']);
 }
}
