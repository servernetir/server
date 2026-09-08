<?php

namespace Tests\Feature;

use App\Models\CrmLead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AssistantLeadContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.n8n.chat_webhook' => 'https://n8n.example.test/chat',
            'services.bale.token' => '',
            'servernet.contact.email' => '',
        ]);
        Mail::fake();
    }

    public function test_structured_n8n_response_preserves_reply_and_persists_one_lead(): void
    {
        $fixture = json_decode(file_get_contents(base_path('../relay/n8n/chat-assistant-lead-response.example.json')), true, flags: JSON_THROW_ON_ERROR);
        Http::fake(['n8n.example.test/*' => Http::response($fixture)]);

        $payload = [
            'message' => 'برای VPS ایران تماس بگیرید',
            'session' => 'contract-session-1',
            'page_url' => 'https://servernet.cloud/vps',
        ];
        $this->post(route('chat'), $payload)->assertOk()->assertJson(['reply' => $fixture['reply']]);
        $this->post(route('chat'), $payload)->assertOk()->assertJson(['reply' => $fixture['reply']]);

        $this->assertSame(1, CrmLead::where('source', 'assistant')->count());
        $this->assertDatabaseHas('crm_leads', [
            'session_key' => hash('sha256', 'assistant|contract-session-1'),
            'contact_name' => 'علی رضایی',
            'email' => 'ali@example.test',
            'phone' => '09120000000',
            'interest' => 'VPS ایران',
            'locale' => 'fa',
            'page_url' => 'https://servernet.cloud/vps',
        ]);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://n8n.example.test/chat'
            && $request['session'] === 'contract-session-1'
            && $request['locale'] === 'fa');
        Mail::assertNothingSent();
    }

    public function test_reply_without_lead_keeps_previous_behavior_and_creates_nothing(): void
    {
        Http::fake(['n8n.example.test/*' => Http::response(['reply' => 'پاسخ عادی'])]);

        $this->post(route('chat'), ['message' => 'سلام', 'session' => 'no-lead'])
            ->assertOk()->assertJson(['reply' => 'پاسخ عادی', 'actions' => []]);

        $this->assertDatabaseCount('crm_leads', 0);
    }
}
