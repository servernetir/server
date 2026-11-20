<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Services\Legal\LegalDocuments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RegistrationLegalPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->mock(\App\Services\Notify\AdminNotifier::class)->shouldReceive('event')->andReturnNull();
        $this->mock(\App\Services\Notify\Notifier::class)->shouldReceive('fire')->andReturnNull();
    }

    private function registration(): array
    {
        return ['email' => 'legal@example.com', 'phone' => '+447700900123',
            'type' => 'individual', 'iranian' => false, 'verified' => true,
            'email_ok' => true, 'phone_ok' => true, 'channel' => 'sms',
            'first_name' => 'Legal', 'last_name' => 'Test'];
    }

    private function password(): array
    {
        return ['password' => 'test-only-password', 'password_confirmation' => 'test-only-password'];
    }

    public function test_registration_shows_only_a_short_terms_notice_and_preserves_explicit_consent(): void
    {
        foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $locale => $prefix) {
            $this->get($prefix.'/register')->assertOk()
                ->assertSee(trans('auth_notice.terms', [], $locale))
                ->assertDontSee(trans('ui.auth_credit_policy', [], $locale));
            $response = $this->withSession(['reg' => $this->registration()])
                ->get($prefix.'/register/finish')->assertOk()
                ->assertDontSee(trans('ui.auth_credit_policy', [], $locale))
                ->assertDontSee(trans('ui.auth_credit_accept', [], $locale));
            $this->assertMatchesRegularExpression('/name="terms" value="1" required/', $response->getContent());
            $this->assertDoesNotMatchRegularExpression('/name="terms"[^>]*checked/', $response->getContent());
        }
    }

    public function test_unchecked_terms_cannot_activate_an_account(): void
    {
        $this->withSession(['reg' => $this->registration()])->post('/en/register/finish', $this->password())
            ->assertSessionHasErrors('terms');
        $this->assertSame(0, Customer::where('status', 'active')->count());
        $this->assertSame(0, DB::table('legal_acceptances')->count());
        $this->assertGuest('customer');
    }

    public function test_registration_records_current_text_even_if_no_documents_were_seeded(): void
    {
        $this->withSession(['reg' => $this->registration()])->withServerVariables([
            'REMOTE_ADDR' => '192.0.2.5', 'HTTP_USER_AGENT' => 'Legal policy test',
        ])->post('/en/register/finish', $this->password() + ['terms' => '1'])->assertRedirect('/en/account');
        $customer = Customer::where('email', 'legal@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($customer, 'customer');
        $rows = DB::table('legal_acceptances')->where('customer_id', $customer->id)->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $document = DB::table('legal_documents')->find($row->legal_document_id);
            $this->assertSame('en', $document->locale);
            $this->assertSame(app(LegalDocuments::class)->body($document->kind, 'en'), $document->body);
            $this->assertSame(hash('sha256', $document->body), $document->sha256);
            $this->assertNotNull($row->accepted_at);
            $this->assertSame('192.0.2.5', $row->ip);
            $this->assertSame('Legal policy test', $row->user_agent);
        }
    }

    public function test_failed_legal_recording_rolls_back_account_activation(): void
    {
        $this->mock(LegalDocuments::class)->shouldReceive('publishCurrent')->once()
            ->andThrow(new \RuntimeException('Test-only legal storage failure'));
        $this->withSession(['reg' => $this->registration()])
            ->post('/en/register/finish', $this->password() + ['terms' => '1'])->assertServerError();
        $this->assertSame(0, Customer::where('status', 'active')->count());
        $this->assertSame(0, DB::table('legal_acceptances')->count());
        $this->assertGuest('customer');
    }

    public function test_publication_is_immutable_and_changed_text_gets_a_new_version(): void
    {
        $documents = app(LegalDocuments::class);
        $this->travelTo(now()->subDays(2));
        $old = $documents->publishCurrent('fa')->firstWhere('kind', 'terms');
        $before = (array) DB::table('legal_documents')->find($old->id);
        $this->travelBack();
        $documents->publishCurrent('fa');
        $this->assertSame($before, (array) DB::table('legal_documents')->find($old->id));
        config(['pages.terms.fa.lead' => config('pages.terms.fa.lead').' Updated test text.']);
        $new = $documents->publishCurrent('fa')->firstWhere('kind', 'terms');
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame($before, (array) DB::table('legal_documents')->find($old->id));
        $this->assertSame(0, DB::table('legal_acceptances')->count());
    }
}
