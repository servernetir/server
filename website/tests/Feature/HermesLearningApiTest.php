<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class HermesLearningApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Setting::putSecret('growth_hermes_key', str_repeat('k', 40));
    }

    private function signed(string $action, array $data, string $nonce): array
    {
        $body = json_encode($data, JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $path = '/api/internal/growth/knowledge/'.$action;
        $signature = hash_hmac('sha256', $timestamp."\n".$nonce."\nPOST\n".$path."\n".hash('sha256', $body), str_repeat('k', 40));

        return [$path, $body, ['CONTENT_TYPE' => 'application/json',
            'HTTP_X_GROWTH_TIME' => $timestamp, 'HTTP_X_GROWTH_NONCE' => $nonce,
            'HTTP_X_GROWTH_SIGNATURE' => $signature]];
    }

    public function test_curriculum_requires_existing_hmac_and_never_grants_execution_rights(): void
    {
        $this->postJson('/api/internal/growth/knowledge/curriculum', [])->assertUnauthorized();
        [$url, $body, $server] = $this->signed('curriculum', [], str_repeat('c', 32));
        $this->call('POST', $url, [], [], [], $server, $body)->assertOk()
            ->assertJsonPath('data.version', 'servernet-six-sections-v1')
            ->assertJsonPath('data.financial_operations_enabled', false)
            ->assertJsonPath('data.grants_execution_rights', false)
            ->assertJsonPath('executed_actions', []);
        $this->call('POST', $url, [], [], [], $server, $body)->assertConflict();
        $this->assertDatabaseCount('growth_documents', 0);
        $this->assertDatabaseCount('growth_audits', 0);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_oversight_is_authenticated_aggregate_only_and_does_not_run_tasks(): void
    {
        $this->postJson('/api/internal/growth/knowledge/oversight', [])->assertUnauthorized();
        [$url, $body, $server] = $this->signed('oversight', [], str_repeat('o', 32));
        $this->call('POST', $url, [], [], [], $server, $body)->assertOk()
            ->assertJsonPath('data.coverage_count', 6)
            ->assertJsonPath('data.learning_assessed', false)
            ->assertJsonPath('data.executed_actions', [])
            ->assertJsonPath('executed_actions', []);
        $this->assertDatabaseCount('growth_automation_runs', 0);
        $this->assertDatabaseCount('growth_audits', 0);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_missing_answers_fail_without_marking_knowledge_verified(): void
    {
        [$url, $body, $server] = $this->signed('assess', [], str_repeat('a', 32));
        $this->call('POST', $url, [], [], [], $server, $body)->assertOk()
            ->assertJsonPath('data.passed_count', 0)
            ->assertJsonPath('data.decision_contract_passed', false)
            ->assertJsonPath('data.semantic_mastery_verified', false)
            ->assertJsonPath('data.execution_authorized_by_assessment', false)
            ->assertJsonPath('executed_actions', []);
        $this->assertDatabaseCount('growth_documents', 0);
        Mail::assertNothingSent();
    }

    public function test_signed_answer_tampering_is_rejected(): void
    {
        [$url, $body, $server] = $this->signed('assess', ['answers' => [
            'marketing_unknown' => ['decision' => 'reconcile_without_resend', 'verification' => 'prior_attempt_receipt'],
        ]], str_repeat('t', 32));
        $this->call('POST', $url, [], [], [], $server, str_replace('prior_attempt_receipt', 'invented_receipt', $body))
            ->assertUnauthorized();
    }

    public function test_malformed_answer_fields_are_rejected(): void
    {
        [$url, $body, $server] = $this->signed('assess', ['answers' => [
            'marketing_unknown' => ['decision' => ['not-a-string'], 'verification' => 'prior_attempt_receipt'],
        ]], str_repeat('m', 32));
        $server['HTTP_ACCEPT'] = 'application/json';
        $this->call('POST', $url, [], [], [], $server, $body)->assertStatus(422);
    }
}
