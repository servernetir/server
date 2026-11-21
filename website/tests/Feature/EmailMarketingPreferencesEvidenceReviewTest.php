<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\EmailMarketingPreference;
use App\Models\ExternalEmailConsentDocumentBatch;
use App\Models\ExternalEmailConsentDocumentItem;
use App\Models\ExternalEmailConsentReview;
use App\Models\ExternalEmailConsentReviewEvent;
use App\Models\User;
use App\Services\Marketing\EmailMarketingPreferences;
use DomainException;
use DOMDocument;
use DOMElement;
use DOMNodeList;
use DOMXPath;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmailMarketingPreferencesEvidenceReviewTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = "email,consent_date,consent_reference\n";

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('e', 32)),
            'app.previous_keys' => [],
            'email-marketing.enabled' => true,
            'email-marketing.evidence_review_enabled' => true,
            'birthday.enabled' => false,
            'marketing.sms_enabled' => false,
            'pagecache.enabled' => false,
        ]);
        Mail::fake();
        Http::preventStrayRequests();
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'web');

        return $admin;
    }

    private function upload(string $contents, bool $acknowledge = true)
    {
        return $this->post('/admin/email-marketing/evidence/stage', [
            'file' => UploadedFile::fake()->createWithContent('evidence.csv', $contents),
            'acknowledge' => $acknowledge ? '1' : '0',
        ]);
    }

    private function assertReviewOnly(): void
    {
        $this->assertDatabaseCount('email_marketing_preferences', 0);
        $this->assertDatabaseCount('email_marketing_preference_events', 0);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    private function pageDom(string $html): DOMXPath
    {
        $document = new DOMDocument;
        // The view uses HTML5 elements. Suppress HTML4-parser warnings, but
        // never permit network access or load external document resources.
        $this->assertTrue($document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING));

        return new DOMXPath($document);
    }

    private function elements(DOMXPath $dom, string $path): DOMNodeList
    {
        $nodes = $dom->query($path);
        $this->assertInstanceOf(DOMNodeList::class, $nodes);

        return $nodes;
    }

    private function element(DOMXPath $dom, string $path): DOMElement
    {
        $nodes = $this->elements($dom, $path);
        $this->assertSame(1, $nodes->length, $path);
        $node = $nodes->item(0);
        $this->assertInstanceOf(DOMElement::class, $node);

        return $node;
    }

    private function stageReview(
        string $address = 'member@external.example.test',
        string $reference = 'archive-original-001',
    ): ExternalEmailConsentReview {
        $date = now()->subDays(7)->toDateString();
        $this->upload(self::HEADER."{$address},{$date},{$reference}\n")->assertRedirect();

        return ExternalEmailConsentReview::query()->sole();
    }

    private function reviewUrl(ExternalEmailConsentReview $review): string
    {
        return '/admin/email-marketing/evidence/'.$review->getKey().'/review';
    }

    private function decisionUrl(ExternalEmailConsentReview $review): string
    {
        return '/admin/email-marketing/evidence/'.$review->getKey().'/decision';
    }

    private function documentUrl(): string
    {
        return '/admin/email-marketing/evidence/documents';
    }

    private function documentPayload(array $reviewIds, array $overrides = []): array
    {
        return array_replace([
            'selection' => 'selected',
            'review_ids' => $reviewIds,
            'document_reference' => 'document-archive-group-001',
            'acknowledge' => '1',
        ], $overrides);
    }

    private function postDocument(array $payload)
    {
        // Keep the real 3/minute throttle active; separate synthetic operations
        // into successive time windows to test the controller's atomic checks.
        $this->travel(65)->seconds();

        return $this->post($this->documentUrl(), $payload);
    }

    private function reviewPreimages(): array
    {
        return DB::table('external_email_consent_reviews')->orderBy('id')->get()
            ->map(fn ($row) => (array) $row)->all();
    }

    private function verificationPayload(
        ExternalEmailConsentReview $review,
        array $overrides = [],
    ): array {
        return array_replace([
            'decision' => 'verified',
            'expected_event' => '0',
            'acknowledge' => '1',
            'source_email' => 'member@external.example.test',
            'source_date' => $review->consent_date->format('Y-m-d'),
            'source_reference' => $review->evidence_reference,
            'source_kind' => 'web_form',
            'review_reference' => 'independent-verification-log-001',
        ], $overrides);
    }

    private function assertEvidenceCounts(int $pending, int $verified, int $rejected, int $revoked): void
    {
        $page = $this->get('/admin/email-marketing')->assertOk();
        $dom = $this->pageDom((string) $page->getContent());
        foreach ([
            'total' => $pending + $verified + $rejected + $revoked,
            'pending' => $pending,
            'verified' => $verified,
            'rejected' => $rejected,
            'revoked' => $revoked,
            'eligible' => 0,
        ] as $metric => $value) {
            $attribute = 'data-evidence-'.$metric;
            $this->assertSame((string) $value, $this->element($dom, '//*[@'.$attribute.']')->getAttribute($attribute));
        }
    }

    public function test_record_detail_exposes_only_one_review_and_requires_original_evidence_inputs(): void
    {
        $this->admin();
        $review = $this->stageReview();
        $this->assertEvidenceCounts(1, 0, 0, 0);

        $dashboard = $this->get('/admin/email-marketing')->assertOk()
            ->assertDontSee('member@external.example.test')
            ->assertDontSee('archive-original-001')
            ->assertDontSee($review->email_digest);
        $list = $this->pageDom((string) $dashboard->getContent());
        $reviewLink = $this->element($list, '//*[@id="evidence"]//a[@href="'.url($this->reviewUrl($review)).'"]');
        $this->assertNotEmpty(trim($reviewLink->textContent));
        $this->assertSame(0, $this->elements($list, '//*[@id="evidence"]//form[contains(@action, "/decision")]')->length);

        $page = $this->get($this->reviewUrl($review))->assertOk()
            ->assertSee((string) $review->contact_id)
            ->assertDontSee('member@external.example.test')
            ->assertDontSee('archive-original-001')
            ->assertDontSee($review->email_digest);
        $this->assertStringContainsString('no-store', $page->headers->get('Cache-Control'));
        $page->assertHeader('Referrer-Policy', 'no-referrer');
        $page->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $dom = $this->pageDom((string) $page->getContent());
        $this->assertSame('pending', $this->element($dom, '//*[@data-evidence-state]')->getAttribute('data-evidence-state'));
        $this->element($dom, '//*[@data-evidence-audit-empty]');
        $this->assertSame(0, $this->elements($dom, '//*[@data-evidence-decision]')->length);

        $forms = $this->elements($dom, '//form[@action="'.url($this->decisionUrl($review)).'"]');
        $this->assertSame(3, $forms->length);
        foreach (['verified', 'rejected', 'revoked'] as $decision) {
            $formPath = '//form[@action="'.url($this->decisionUrl($review)).'"][.//input[@name="decision" and @value="'.$decision.'"]]';
            $form = $this->element($dom, $formPath);
            $this->assertSame('post', strtolower($form->getAttribute('method')));
            $this->assertNotEmpty($this->element($dom, $formPath.'//input[@name="_token" and @type="hidden"]')->getAttribute('value'));
            $this->assertSame('0', $this->element($dom, $formPath.'//input[@name="expected_event" and @type="hidden"]')->getAttribute('value'));
            $ack = $this->element($dom, $formPath.'//input[@name="acknowledge" and @type="checkbox"]');
            $this->assertSame('1', $ack->getAttribute('value'));
            $this->assertTrue($ack->hasAttribute('required'));
            $this->assertFalse($ack->hasAttribute('checked'));
        }

        $verifyPath = '//form[.//input[@name="decision" and @value="verified"]]';
        foreach (['source_email', 'source_date', 'source_reference', 'review_reference'] as $field) {
            $input = $this->element($dom, $verifyPath.'//input[@name="'.$field.'"]');
            $this->assertTrue($input->hasAttribute('required'));
            $this->assertSame('', $input->getAttribute('value'), $field.' must never be pre-filled');
            $this->assertNotSame('hidden', strtolower($input->getAttribute('type')));
        }
        $this->assertTrue($this->element($dom, $verifyPath.'//select[@name="source_kind"]')->hasAttribute('required'));
        $this->assertDatabaseCount('external_email_consent_review_events', 0);
        $this->assertReviewOnly();
    }

    public function test_verified_decision_is_a_private_append_only_audit_event_and_not_sendable_consent(): void
    {
        $admin = $this->admin();
        $review = $this->stageReview();
        $before = DB::table('external_email_consent_reviews')->sole();

        $this->post($this->decisionUrl($review), $this->verificationPayload($review))
            ->assertRedirect(url($this->reviewUrl($review)))
            ->assertSessionHas('review_success')
            ->assertSessionMissing('_old_input');

        $row = DB::table('external_email_consent_review_events')->sole();
        $this->assertSame((int) $review->id, (int) $row->review_id);
        $this->assertSame('verified', $row->decision);
        $this->assertSame('evidence_checked', $row->reason);
        $this->assertSame('web_form', $row->source_kind);
        $this->assertSame((int) $admin->id, (int) $row->reviewed_by);
        $this->assertSame(now()->toDateString(), substr((string) $row->created_at, 0, 10));
        $this->assertNotEmpty($row->review_reference);
        $this->assertStringNotContainsString('independent-verification-log-001', $row->review_reference);
        $this->assertStringNotContainsString('archive-original-001', json_encode($row));
        $this->assertStringNotContainsString('member@external.example.test', json_encode($row));
        $this->assertStringNotContainsString('member@external.example.test', json_encode(session()->all()));
        $this->assertStringNotContainsString('archive-original-001', json_encode(session()->all()));
        $this->assertStringNotContainsString('independent-verification-log-001', json_encode(session()->all()));

        $event = ExternalEmailConsentReviewEvent::query()->sole();
        $this->assertSame('independent-verification-log-001', $event->review_reference);
        $this->assertArrayNotHasKey('review_reference', $event->toArray());
        $this->assertSame((array) $before, (array) DB::table('external_email_consent_reviews')->sole());
        $this->assertEvidenceCounts(0, 1, 0, 0);

        $page = $this->get($this->reviewUrl($review))->assertOk()
            ->assertDontSee('member@external.example.test')
            ->assertDontSee('archive-original-001')
            ->assertDontSee('independent-verification-log-001')
            ->assertSee($admin->name)
            ->assertSee(__('email-evidence-review.state_verified'))
            ->assertSee(__('email-evidence-review.reason_evidence_checked'))
            ->assertSee(__('email-evidence-review.source_web_form'));
        $dom = $this->pageDom((string) $page->getContent());
        $this->assertSame('verified', $this->element($dom, '//*[@data-evidence-state]')->getAttribute('data-evidence-state'));
        $auditRow = $this->element($dom, '//*[@data-evidence-decision="verified"]');
        $this->assertStringContainsString($admin->name, $auditRow->textContent);
        $this->assertStringContainsString($event->created_at->format('Y-m-d H:i:s T'), $auditRow->textContent);
        $this->assertSame(0, $this->elements($dom, '//form[.//input[@name="decision" and @value="verified"]]')->length);

        // A fresh model instance cannot update or erase a historical decision.
        try {
            $event->forceFill(['decision' => 'revoked'])->save();
            $this->fail('A review decision audit event must be immutable.');
        } catch (DomainException) {
            $this->assertSame('verified', $event->fresh()->decision);
        }
        try {
            $event->fresh()->delete();
            $this->fail('A review decision audit event cannot be deleted.');
        } catch (DomainException) {
            $this->assertDatabaseCount('external_email_consent_review_events', 1);
        }
        $this->assertReviewOnly();
    }

    #[DataProvider('invalidVerificationDecisions')]
    public function test_invalid_verification_neither_records_decisions_nor_flashes_sensitive_inputs(array $overrides): void
    {
        $this->admin();
        $review = $this->stageReview();
        $before = DB::table('external_email_consent_reviews')->sole();
        // Resolve the placeholders against the actual record's identities.
        if (($overrides['review_reference'] ?? null) === '{id}') {
            $overrides['review_reference'] = (string) $review->id;
        } elseif (($overrides['review_reference'] ?? null) === '{contact_id}') {
            $overrides['review_reference'] = (string) $review->contact_id;
        }
        $payload = $this->verificationPayload($review, $overrides);

        $this->post($this->decisionUrl($review), $payload)
            ->assertRedirect(url($this->reviewUrl($review)))
            ->assertSessionHas('review_error')
            ->assertSessionMissing('_old_input');
        $session = json_encode(session()->all());
        $this->assertStringNotContainsString('member@external.example.test', $session);
        $this->assertStringNotContainsString('wrong-member@external.example.test', $session);
        $this->assertStringNotContainsString('archive-original-001', $session);
        $this->assertStringNotContainsString('private-wrong-reference', $session);
        $this->assertStringNotContainsString('independent-verification-log-001', $session);
        $this->get($this->reviewUrl($review))->assertOk()
            ->assertDontSee('member@external.example.test')
            ->assertDontSee('wrong-member@external.example.test')
            ->assertDontSee('archive-original-001')
            ->assertDontSee('private-wrong-reference')
            ->assertDontSee('independent-verification-log-001');
        $this->assertDatabaseCount('external_email_consent_review_events', 0);
        $this->assertSame((array) $before, (array) DB::table('external_email_consent_reviews')->sole());
        $this->assertReviewOnly();
    }

    public static function invalidVerificationDecisions(): array
    {
        return [
            'not acknowledged' => [['acknowledge' => '0']],
            'no address supplied' => [['source_email' => '']],
            'wrong address' => [['source_email' => 'wrong-member@external.example.test']],
            'wrong source date' => [['source_date' => '1900-01-01']],
            'invalid calendar source date' => [['source_date' => '2025-02-30']],
            'wrong original reference' => [['source_reference' => 'private-wrong-reference']],
            'missing source kind' => [['source_kind' => '']],
            'unsupported source kind' => [['source_kind' => 'other_unverified']],
            'missing independent review pointer' => [['review_reference' => '']],
            'original reference is not independent evidence' => [['review_reference' => 'archive-original-001']],
            'database id is not independent evidence' => [['review_reference' => '{id}']],
            'contact id is not independent evidence' => [['review_reference' => '{contact_id}']],
            'not an individual decision' => [['decision' => 'bulk-verified']],
        ];
    }

    public function test_stale_decisions_cannot_be_replayed_and_rejected_or_revoked_records_never_return_to_verified(): void
    {
        $this->admin();
        $review = $this->stageReview();
        $verify = $this->verificationPayload($review);
        $this->post($this->decisionUrl($review), $verify)->assertSessionHas('review_success');
        $first = ExternalEmailConsentReviewEvent::query()->sole();
        $firstRaw = DB::table('external_email_consent_review_events')->sole();

        $rejection = [
            'decision' => 'rejected', 'reason' => 'invalidated',
            'acknowledge' => '1', 'expected_event' => '0',
        ];
        // An open form referencing the previous state cannot override new decisions.
        $this->post($this->decisionUrl($review), $rejection)->assertSessionHas('review_error');
        $this->assertDatabaseCount('external_email_consent_review_events', 1);

        $rejection['expected_event'] = (string) $first->id;
        $this->post($this->decisionUrl($review), $rejection)->assertSessionHas('review_success');
        $second = ExternalEmailConsentReviewEvent::query()->latest('id')->firstOrFail();
        $this->assertSame('rejected', $second->decision);
        $this->assertSame('invalidated', $second->reason);
        $this->assertEvidenceCounts(0, 0, 1, 0);
        $this->post($this->decisionUrl($review), array_replace($verify, ['expected_event' => (string) $second->id]))
            ->assertSessionHas('review_error');
        $this->assertDatabaseCount('external_email_consent_review_events', 2);

        $this->post($this->decisionUrl($review), [
            'decision' => 'revoked', 'reason' => 'administrative_prevent',
            'acknowledge' => '1', 'expected_event' => (string) $second->id,
        ])->assertSessionHas('review_success');
        $third = ExternalEmailConsentReviewEvent::query()->latest('id')->firstOrFail();
        $this->assertSame('revoked', $third->decision);
        $this->assertSame('administrative_prevent', $third->reason);
        $this->assertEvidenceCounts(0, 0, 0, 1);
        $this->post($this->decisionUrl($review), array_replace($verify, ['expected_event' => (string) $third->id]))
            ->assertSessionHas('review_error');
        $this->assertDatabaseCount('external_email_consent_review_events', 3);
        $this->assertSame(['verified', 'rejected', 'revoked'], ExternalEmailConsentReviewEvent::query()->orderBy('id')->pluck('decision')->all());
        $this->assertSame((array) $firstRaw, (array) DB::table('external_email_consent_review_events')->where('id', $first->id)->sole());

        $detail = $this->get($this->reviewUrl($review))->assertOk();
        $dom = $this->pageDom((string) $detail->getContent());
        $this->assertSame('revoked', $this->element($dom, '//*[@data-evidence-state]')->getAttribute('data-evidence-state'));
        $history = [];
        foreach ($this->elements($dom, '//*[@data-evidence-decision]') as $row) {
            $history[] = $row->getAttribute('data-evidence-decision');
        }
        $this->assertSame(['revoked', 'rejected', 'verified'], $history);
        $this->assertSame(0, $this->elements($dom, '//form[@action="'.url($this->decisionUrl($review)).'"]')->length);
        $this->assertReviewOnly();
    }

    public function test_dashboard_counts_and_displays_each_individual_review_state_without_enabling_sending(): void
    {
        $this->admin();
        $date = now()->subDays(9)->toDateString();
        $this->upload(self::HEADER
            ."pending@external.example.test,{$date},archive-pending\n"
            ."member@external.example.test,{$date},archive-verified\n"
            ."rejected@external.example.test,{$date},archive-rejected\n"
            ."revoked@external.example.test,{$date},archive-revoked\n")->assertRedirect();
        $records = ExternalEmailConsentReview::query()->orderBy('id')->get();
        $this->assertCount(4, $records);
        $this->post($this->decisionUrl($records[1]), $this->verificationPayload($records[1]))->assertSessionHas('review_success');
        $this->post($this->decisionUrl($records[2]), [
            'decision' => 'rejected', 'reason' => 'not_marketing_consent',
            'acknowledge' => '1', 'expected_event' => '0',
        ])->assertSessionHas('review_success');
        $this->post($this->decisionUrl($records[3]), [
            'decision' => 'revoked', 'reason' => 'administrative_prevent',
            'acknowledge' => '1', 'expected_event' => '0',
        ])->assertSessionHas('review_success');
        $this->assertEvidenceCounts(1, 1, 1, 1);
        $page = $this->get('/admin/email-marketing')->assertOk()
            ->assertDontSee('@external.example.test')
            ->assertDontSee('archive-verified')
            ->assertDontSee('independent-verification-log-001');
        $dom = $this->pageDom((string) $page->getContent());
        foreach (['pending', 'verified', 'rejected', 'revoked'] as $i => $state) {
            $rowPath = '//*[@data-evidence-row="'.$records[$i]->contact_id.'"]';
            $this->element($dom, $rowPath.'//*[@data-evidence-state="'.$state.'"]');
        }
        $this->assertDatabaseCount('external_email_consent_review_events', 3);
        $this->assertReviewOnly();
    }

    public function test_pending_records_may_be_rejected_without_creating_or_claiming_positive_consent(): void
    {
        $this->admin();
        $review = $this->stageReview();
        $this->post($this->decisionUrl($review), [
            'decision' => 'rejected', 'reason' => 'missing_evidence',
            'acknowledge' => '1', 'expected_event' => '0',
        ])->assertSessionHas('review_success');

        $event = ExternalEmailConsentReviewEvent::query()->sole();
        $this->assertSame('rejected', $event->decision);
        $this->assertSame('missing_evidence', $event->reason);
        $this->assertNull($event->source_kind);
        $this->assertNull($event->review_reference);
        $this->assertEvidenceCounts(0, 0, 1, 0);
        $this->assertReviewOnly();
    }

    public function test_recipient_withdrawal_needs_documented_review_pointer_even_before_verification(): void
    {
        $this->admin();
        $review = $this->stageReview();
        $payload = [
            'decision' => 'revoked', 'reason' => 'recipient_withdrawal',
            'acknowledge' => '1', 'expected_event' => '0',
        ];
        $this->post($this->decisionUrl($review), $payload)->assertSessionHas('review_error');
        $this->assertDatabaseCount('external_email_consent_review_events', 0);

        $payload['review_reference'] = 'withdrawal-log-001';
        $this->post($this->decisionUrl($review), $payload)->assertSessionHas('review_success');
        $event = ExternalEmailConsentReviewEvent::query()->sole();
        $this->assertSame('revoked', $event->decision);
        $this->assertSame('recipient_withdrawal', $event->reason);
        $this->assertSame('withdrawal-log-001', $event->review_reference);
        $this->assertStringNotContainsString('withdrawal-log-001', DB::table('external_email_consent_review_events')->sole()->review_reference);
        $this->assertEvidenceCounts(0, 0, 0, 1);
        $this->assertReviewOnly();
    }

    public function test_original_address_can_be_matched_after_key_rotation_only_with_historical_key(): void
    {
        $oldKey = 'base64:'.base64_encode(str_repeat('o', 32));
        $nextKey = 'base64:'.base64_encode(str_repeat('n', 32));
        config(['app.key' => $oldKey, 'app.previous_keys' => []]);
        $this->admin();
        $review = $this->stageReview();
        $payload = $this->verificationPayload($review, ['source_email' => 'MEMBER@EXTERNAL.EXAMPLE.TEST']);

        config(['app.key' => $nextKey, 'app.previous_keys' => []]);
        $this->post($this->decisionUrl($review), $payload)->assertSessionHas('review_error');
        $this->assertDatabaseCount('external_email_consent_review_events', 0);
        config(['app.previous_keys' => [$oldKey]]);
        $this->post($this->decisionUrl($review), $payload)->assertSessionHas('review_success');
        $this->assertSame('verified', ExternalEmailConsentReviewEvent::query()->sole()->decision);
        $this->assertReviewOnly();
    }

    public function test_individual_review_requires_admin_guard_flags_and_csrf_throttle(): void
    {
        $admin = $this->admin();
        $review = $this->stageReview();
        $payload = $this->verificationPayload($review);
        $author = User::factory()->create(['role' => 'author']);
        $this->actingAs($author, 'web');
        $this->get($this->reviewUrl($review))->assertForbidden();
        $this->post($this->decisionUrl($review), $payload)->assertForbidden();

        $this->actingAs($admin, 'web');
        foreach (['email-marketing.enabled', 'email-marketing.evidence_review_enabled'] as $flag) {
            config([$flag => false]);
            $this->get($this->reviewUrl($review))->assertNotFound();
            $this->post($this->decisionUrl($review), $payload)->assertNotFound();
            config([$flag => true]);
        }

        $decisionRoute = Route::getRoutes()->getByName('admin.email-marketing.evidence.decide');
        $this->assertNotNull($decisionRoute);
        $this->assertNotContains(PreventRequestForgery::class, $decisionRoute->excludedMiddleware());
        $this->assertTrue(collect($decisionRoute->gatherMiddleware())->contains(fn (string $middleware): bool => str_starts_with($middleware, 'throttle:')));
        $this->assertSame(['review'], $decisionRoute->parameterNames());
        $this->assertDatabaseCount('external_email_consent_review_events', 0);
        $this->assertReviewOnly();
    }

    public function test_shared_document_links_selected_pending_records_without_approving_any_consent(): void
    {
        $admin = $this->admin();
        $date = now()->subDays(7)->toDateString();
        $this->upload(self::HEADER
            ."member@external.example.test,{$date},archive-original-001\n"
            ."second@external.example.test,{$date},archive-original-002\n"
            ."third@external.example.test,{$date},archive-original-003\n")->assertRedirect();
        $records = ExternalEmailConsentReview::query()->orderBy('id')->get();
        $before = $this->reviewPreimages();

        $page = $this->get('/admin/email-marketing')->assertOk()
            ->assertDontSee('@external.example.test')->assertDontSee('archive-original-001');
        $dom = $this->pageDom((string) $page->getContent());
        $formPath = '//*[@id="evidence"]//form[@id="evidence-document-group"]';
        $form = $this->element($dom, $formPath);
        $this->assertSame(url($this->documentUrl()), $form->getAttribute('action'));
        $this->assertSame('post', strtolower($form->getAttribute('method')));
        $this->assertNotEmpty($this->element($dom, $formPath.'//input[@name="_token" and @type="hidden"]')->getAttribute('value'));
        $this->element($dom, $formPath.'//input[@name="selection" and @value="selected"]');
        $this->element($dom, $formPath.'//input[@name="selection" and @value="all_pending"]');
        $this->assertSame('3', $this->element($dom, $formPath.'//input[@name="expected_count"]')->getAttribute('value'));
        $pointer = $this->element($dom, $formPath.'//input[@name="document_reference"]');
        $this->assertTrue($pointer->hasAttribute('required'));
        $this->assertSame('', $pointer->getAttribute('value'));
        $this->assertSame('160', $pointer->getAttribute('maxlength'));
        $ack = $this->element($dom, $formPath.'//input[@name="acknowledge" and @type="checkbox"]');
        $this->assertTrue($ack->hasAttribute('required'));
        $this->assertSame('1', $ack->getAttribute('value'));
        $this->assertFalse($ack->hasAttribute('checked'));
        foreach ($records as $record) {
            $input = $this->element($dom, '//*[@data-evidence-row="'.$record->contact_id.'"]//input[@name="review_ids[]"]');
            $this->assertSame((string) $record->id, $input->getAttribute('value'));
            $this->assertSame('evidence-document-group', $input->getAttribute('form'));
            $this->assertFalse($input->hasAttribute('checked'));
            $this->assertFalse($input->hasAttribute('disabled'));
        }
        $this->assertSame(0, $this->elements($dom, '//*[@id="evidence"]//form[contains(@action, "/decision")]')->length);

        // One document pointer is shared for two records; the omitted record stays unlinked.
        $ids = [(int) $records[0]->id, (int) $records[2]->id];
        $this->postDocument($this->documentPayload($ids))
            ->assertRedirect(route('admin.email-marketing.index').'#evidence')
            ->assertSessionHas('document_success')->assertSessionMissing('_old_input');
        $raw = DB::table('external_email_consent_document_batches')->sole();
        $this->assertSame(2, (int) $raw->record_count);
        $this->assertSame((int) $admin->id, (int) $raw->created_by);
        $this->assertSame(now()->toDateString(), substr((string) $raw->created_at, 0, 10));
        $this->assertNotEmpty($raw->document_reference);
        $this->assertStringNotContainsString('document-archive-group-001', (string) $raw->document_reference);
        $this->assertStringNotContainsString('@external.example.test', json_encode($raw));
        $this->assertStringNotContainsString('archive-original-001', json_encode($raw));
        $this->assertStringNotContainsString('document-archive-group-001', json_encode(session()->all()));
        $batch = ExternalEmailConsentDocumentBatch::query()->sole();
        $this->assertSame('document-archive-group-001', $batch->document_reference);
        $this->assertArrayNotHasKey('document_reference', $batch->toArray());
        $items = ExternalEmailConsentDocumentItem::query()->orderBy('review_id')->get();
        $this->assertCount(2, $items);
        $this->assertSame($ids, $items->map(fn ($item) => (int) $item->review_id)->all());
        $this->assertSame([(int) $batch->id], $items->map(fn ($item) => (int) $item->batch_id)->unique()->values()->all());

        $this->assertSame($before, $this->reviewPreimages());
        $this->assertEvidenceCounts(3, 0, 0, 0);
        $this->assertDatabaseCount('external_email_consent_review_events', 0);
        $list = $this->get('/admin/email-marketing')->assertOk()
            ->assertDontSee('document-archive-group-001')
            ->assertDontSee('@external.example.test')
            ->assertDontSee('archive-original-001');
        $dom = $this->pageDom((string) $list->getContent());
        $remaining = $this->elements($dom, '//*[@id="evidence"]//input[@name="review_ids[]" and not(@disabled)]');
        $this->assertSame(1, $remaining->length);
        $this->assertSame((string) $records[1]->id, $remaining->item(0)->getAttribute('value'));
        $this->assertSame('1', $this->element($dom, $formPath.'//input[@name="expected_count"]')->getAttribute('value'));

        // The shared pointer is visible only in the detail of its specific linked record.
        $detail = $this->get($this->reviewUrl($records[0]))->assertOk()
            ->assertSee('document-archive-group-001')
            ->assertDontSee('member@external.example.test')->assertDontSee('archive-original-001');
        $detail->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', $detail->headers->get('Cache-Control'));
        $this->get($this->reviewUrl($records[1]))->assertOk()->assertDontSee('document-archive-group-001');
        $detailDom = $this->pageDom((string) $detail->getContent());
        $this->assertSame('pending', $this->element($detailDom, '//*[@data-evidence-state]')->getAttribute('data-evidence-state'));
        $this->element($detailDom, '//*[@data-evidence-audit-empty]');
        $verifyPath = '//form[.//input[@name="decision" and @value="verified"]]';
        foreach (['source_email', 'source_date', 'source_reference', 'review_reference'] as $field) {
            $input = $this->element($detailDom, $verifyPath.'//input[@name="'.$field.'"]');
            $this->assertTrue($input->hasAttribute('required'));
            $this->assertSame('', $input->getAttribute('value'), 'Shared linkage must not prefill '.$field);
        }
        // A group document number cannot replace this recipient's independent proof.
        $this->post($this->decisionUrl($records[0]), [
            'decision' => 'verified', 'expected_event' => '0', 'acknowledge' => '1',
            'review_reference' => 'document-archive-group-001',
        ])->assertSessionHas('review_error')->assertSessionMissing('_old_input');
        $this->post($this->decisionUrl($records[0]), $this->verificationPayload($records[0], [
            'source_reference' => 'document-archive-group-001',
        ]))->assertSessionHas('review_error')->assertSessionMissing('_old_input');
        $this->assertDatabaseCount('external_email_consent_review_events', 0);
        $this->assertSame($before, $this->reviewPreimages());
        $this->assertReviewOnly();
    }

    public function test_all_pending_document_selection_spans_pages_but_excludes_reviewed_or_already_linked_records(): void
    {
        $this->admin();
        $date = now()->subDays(8)->toDateString();
        $csv = self::HEADER."member@external.example.test,{$date},archive-synthetic-1\n";
        for ($i = 2; $i <= 42; $i++) {
            $csv .= "synthetic-{$i}@external.example.test,{$date},archive-synthetic-{$i}\n";
        }
        $this->upload($csv)->assertRedirect();
        $records = ExternalEmailConsentReview::query()->orderBy('id')->get();
        $this->assertCount(42, $records);
        $before = $this->reviewPreimages();
        $this->post($this->decisionUrl($records[0]), $this->verificationPayload($records[0]))->assertSessionHas('review_success');
        $this->post($this->decisionUrl($records[1]), [
            'decision' => 'rejected', 'reason' => 'not_marketing_consent',
            'acknowledge' => '1', 'expected_event' => '0',
        ])->assertSessionHas('review_success');
        $this->post($this->decisionUrl($records[2]), [
            'decision' => 'revoked', 'reason' => 'administrative_prevent',
            'acknowledge' => '1', 'expected_event' => '0',
        ])->assertSessionHas('review_success');
        $this->postDocument($this->documentPayload([$records[3]->id], [
            'document_reference' => 'separate-document-002',
        ]))->assertSessionHas('document_success');
        $originalBatch = ExternalEmailConsentDocumentBatch::query()->sole();
        $priorEvents = DB::table('external_email_consent_review_events')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        // The hidden expected count is the complete eligible set, not the first page.
        $firstPage = $this->get('/admin/email-marketing?evidence_page=1')->assertOk();
        $dom = $this->pageDom((string) $firstPage->getContent());
        $this->assertSame(35, $this->elements($dom, '//*[@id="evidence"]//input[@name="review_ids[]" and not(@disabled)]')->length);
        $this->assertSame('38', $this->element($dom, '//*[@id="evidence-document-group"]//input[@name="expected_count"]')->getAttribute('value'));
        $nextPage = $this->get('/admin/email-marketing?evidence_page=2')->assertOk();
        $dom = $this->pageDom((string) $nextPage->getContent());
        $enabled = $this->elements($dom, '//*[@id="evidence"]//input[@name="review_ids[]" and not(@disabled)]');
        $this->assertSame(3, $enabled->length);
        foreach ($records->take(4) as $ineligible) {
            $this->assertSame(0, $this->elements($dom, '//*[@data-evidence-row="'.$ineligible->contact_id.'"]//input[@name="review_ids[]" and not(@disabled)]')->length);
        }
        $this->assertSame('38', $this->element($dom, '//*[@id="evidence-document-group"]//input[@name="expected_count"]')->getAttribute('value'));

        $this->postDocument([
            'selection' => 'all_pending', 'expected_count' => '38',
            'document_reference' => 'document-archive-all-003', 'acknowledge' => '1',
        ])->assertRedirect(route('admin.email-marketing.index').'#evidence')
            ->assertSessionHas('document_success')->assertSessionMissing('_old_input');
        $this->assertDatabaseCount('external_email_consent_document_batches', 2);
        $this->assertDatabaseCount('external_email_consent_document_items', 39);
        $batch = ExternalEmailConsentDocumentBatch::query()->latest('id')->firstOrFail();
        $this->assertSame('document-archive-all-003', $batch->document_reference);
        $this->assertSame(38, (int) $batch->record_count);
        $linked = ExternalEmailConsentDocumentItem::query()->where('batch_id', $batch->id)->orderBy('review_id')
            ->pluck('review_id')->map(fn ($id) => (int) $id)->all();
        $this->assertSame($records->skip(4)->map(fn ($record) => (int) $record->id)->values()->all(), $linked);
        $this->assertDatabaseHas('external_email_consent_document_items', [
            'batch_id' => $originalBatch->id, 'review_id' => $records[3]->id,
        ]);
        $this->assertSame($before, $this->reviewPreimages());
        $this->assertSame($priorEvents, DB::table('external_email_consent_review_events')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertEvidenceCounts(39, 1, 1, 1);
        $this->assertReviewOnly();
    }

    public function test_shared_document_stale_selections_fail_atomically_and_existing_link_remains_immutable(): void
    {
        $this->admin();
        $date = now()->subDays(7)->toDateString();
        $this->upload(self::HEADER
            ."member@external.example.test,{$date},archive-original-001\n"
            ."second@external.example.test,{$date},archive-original-002\n"
            ."third@external.example.test,{$date},archive-original-003\n")->assertRedirect();
        $records = ExternalEmailConsentReview::query()->orderBy('id')->get();
        $before = $this->reviewPreimages();
        $this->postDocument($this->documentPayload([$records[0]->id]))->assertSessionHas('document_success');
        $originalBatch = (array) DB::table('external_email_consent_document_batches')->sole();
        $originalItem = (array) DB::table('external_email_consent_document_items')->sole();

        $failed = [
            // An input formed before the first linkage may not silently link a different set.
            ['selection' => 'all_pending', 'expected_count' => '3', 'document_reference' => 'other-document-002', 'acknowledge' => '1'],
            $this->documentPayload([$records[0]->id, $records[1]->id], ['document_reference' => 'other-document-002']),
            $this->documentPayload([$records[1]->id, $records[2]->id + 100000], ['document_reference' => 'other-document-002']),
            $this->documentPayload([$records[1]->id, $records[1]->id], ['document_reference' => 'other-document-002']),
        ];
        foreach ($failed as $payload) {
            $this->postDocument($payload)
                ->assertRedirect(route('admin.email-marketing.index').'#evidence')
                ->assertSessionHas('document_error')->assertSessionMissing('_old_input');
            $this->assertStringNotContainsString('other-document-002', json_encode(session()->all()));
            $this->assertSame($originalBatch, (array) DB::table('external_email_consent_document_batches')->sole());
            $this->assertSame($originalItem, (array) DB::table('external_email_consent_document_items')->sole());
        }
        $this->post($this->decisionUrl($records[1]), [
            'decision' => 'rejected', 'reason' => 'missing_evidence',
            'acknowledge' => '1', 'expected_event' => '0',
        ])->assertSessionHas('review_success');
        $this->postDocument($this->documentPayload([$records[1]->id, $records[2]->id]))
            ->assertSessionHas('document_error')->assertSessionMissing('_old_input');
        $this->assertSame($originalBatch, (array) DB::table('external_email_consent_document_batches')->sole());
        $this->assertSame($originalItem, (array) DB::table('external_email_consent_document_items')->sole());

        $batch = ExternalEmailConsentDocumentBatch::query()->sole();
        $item = ExternalEmailConsentDocumentItem::query()->sole();
        try {
            $batch->forceFill(['record_count' => 2])->save();
            $this->fail('A document batch must not overwrite its historical count.');
        } catch (DomainException) {
            $this->assertSame($originalBatch, (array) DB::table('external_email_consent_document_batches')->sole());
        }
        try {
            $batch->fresh()->delete();
            $this->fail('A document batch must not be deleted.');
        } catch (DomainException) {
            $this->assertDatabaseCount('external_email_consent_document_batches', 1);
        }
        try {
            $item->forceFill(['review_id' => $records[2]->id])->save();
            $this->fail('A document association must not be edited.');
        } catch (DomainException) {
            $this->assertSame($originalItem, (array) DB::table('external_email_consent_document_items')->sole());
        }
        try {
            $item->fresh()->delete();
            $this->fail('A document association must not be deleted.');
        } catch (DomainException) {
            $this->assertDatabaseCount('external_email_consent_document_items', 1);
        }
        $this->assertSame($before, $this->reviewPreimages());
        $this->assertDatabaseCount('external_email_consent_review_events', 1);
        $this->assertEvidenceCounts(2, 0, 1, 0);
        $this->assertReviewOnly();
    }

    #[DataProvider('invalidDocumentSelections')]
    public function test_invalid_group_document_input_never_links_records_or_flashes_sensitive_values(array $overrides): void
    {
        $this->admin();
        $review = $this->stageReview();
        $before = $this->reviewPreimages();
        $payload = $this->documentPayload([$review->id], $overrides);
        $this->postDocument($payload)
            ->assertRedirect(route('admin.email-marketing.index').'#evidence')
            ->assertSessionHas('document_error')->assertSessionMissing('_old_input');
        $session = json_encode(session()->all());
        $this->assertStringNotContainsString('document-archive-group-001', $session);
        $this->assertStringNotContainsString('private@external.example.test', $session);
        $this->assertDatabaseCount('external_email_consent_document_batches', 0);
        $this->assertDatabaseCount('external_email_consent_document_items', 0);
        $this->assertDatabaseCount('external_email_consent_review_events', 0);
        $this->assertSame($before, $this->reviewPreimages());
        $this->assertReviewOnly();
    }

    public static function invalidDocumentSelections(): array
    {
        return [
            'missing acknowledgment' => [['acknowledge' => '0']],
            'unknown selection' => [['selection' => 'approve_all']],
            'missing selection' => [['selection' => '']],
            'empty selected list' => [['review_ids' => []]],
            'nonnumeric selected id' => [['review_ids' => ['not-a-number']]],
            'zero selected id' => [['review_ids' => [0]]],
            'too many selected ids' => [['review_ids' => range(1, 1001)]],
            'missing expected total' => [['selection' => 'all_pending', 'expected_count' => null]],
            'invalid expected total' => [['selection' => 'all_pending', 'expected_count' => '0']],
            'oversized expected total' => [['selection' => 'all_pending', 'expected_count' => '1001']],
            'missing document reference' => [['document_reference' => '']],
            'oversized document reference' => [['document_reference' => str_repeat('x', 161)]],
            'email rather than pointer' => [['document_reference' => 'private@external.example.test']],
            'multiline document reference' => [['document_reference' => "archive-line-1\narchive-line-2"]],
            'markup rather than pointer' => [['document_reference' => '<b>archive</b>']],
        ];
    }

    public function test_document_linkage_requires_admin_flags_csrf_and_throttling(): void
    {
        $admin = $this->admin();
        $review = $this->stageReview();
        $payload = $this->documentPayload([$review->id]);
        $author = User::factory()->create(['role' => 'author']);
        $this->actingAs($author, 'web');
        $this->postDocument($payload)->assertForbidden();
        $this->actingAs($admin, 'web');
        foreach (['email-marketing.enabled', 'email-marketing.evidence_review_enabled'] as $flag) {
            config([$flag => false]);
            $this->postDocument($payload)->assertNotFound();
            config([$flag => true]);
        }
        $route = Route::getRoutes()->getByName('admin.email-marketing.evidence.documents');
        $this->assertNotNull($route);
        $this->assertSame([], $route->parameterNames());
        $this->assertContains('POST', $route->methods());
        $this->assertNotContains(PreventRequestForgery::class, $route->excludedMiddleware());
        $this->assertTrue(collect($route->gatherMiddleware())->contains(fn (string $middleware): bool => str_starts_with($middleware, 'throttle:')));
        $this->assertDatabaseCount('external_email_consent_document_batches', 0);
        $this->assertDatabaseCount('external_email_consent_document_items', 0);
        $this->assertDatabaseCount('external_email_consent_review_events', 0);
        $this->assertReviewOnly();
    }

    public function test_shared_document_link_cannot_create_customer_consent_or_reverse_withdrawal(): void
    {
        $new = Customer::create(['email' => 'existing@example.test', 'password' => 'test-password-not-real', 'status' => 'active', 'locale' => 'fa', 'timezone' => 'Asia/Tehran']);
        $new->forceFill(['email_verified_at' => now()])->save();
        $withdrawn = Customer::create(['email' => 'withdrawn@example.test', 'password' => 'test-password-not-real', 'status' => 'active', 'locale' => 'fa', 'timezone' => 'Asia/Tehran']);
        $withdrawn->forceFill(['email_verified_at' => now()])->save();
        $service = app(EmailMarketingPreferences::class);
        $this->actingAs($withdrawn, 'customer');
        $pref = $service->setConsent($withdrawn, false);
        Auth::guard('customer')->logout();
        $previousPrefs = DB::table('email_marketing_preferences')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $previousEvents = DB::table('email_marketing_preference_events')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $customerCount = Customer::count();

        $this->admin();
        $date = now()->subDays(3)->toDateString();
        $this->upload(self::HEADER."existing@example.test,{$date},archive-existing\nwithdrawn@example.test,{$date},archive-withdrawn\n")->assertRedirect();
        $this->postDocument([
            'selection' => 'all_pending', 'expected_count' => '2',
            'document_reference' => 'shared-document-accounts-001', 'acknowledge' => '1',
        ])->assertSessionHas('document_success');
        $this->assertDatabaseCount('external_email_consent_document_items', 2);
        $this->assertDatabaseCount('external_email_consent_review_events', 0);
        $this->assertEvidenceCounts(2, 0, 0, 0);

        $this->assertNull($service->preferenceFor($new));
        $this->assertFalse($service->eligible($new));
        $this->assertSame(EmailMarketingPreference::STATE_WITHDRAWN, $pref->fresh()->state);
        $this->assertFalse($service->eligible($withdrawn));
        $this->assertSame($previousPrefs, DB::table('email_marketing_preferences')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame($previousEvents, DB::table('email_marketing_preference_events')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertDatabaseCount('customers', $customerCount);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_evidence_dashboard_has_an_accessible_review_only_form_and_empty_state(): void
    {
        $this->admin();

        $page = $this->get('/admin/email-marketing')->assertOk()
            ->assertSee(__('email-evidence-review.intro'))
            ->assertSee(__('email-evidence-review.empty'))
            ->assertSee(__('email-marketing.admin_intro'));
        $this->assertStringContainsString('no-store', $page->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $page->headers->get('Referrer-Policy'));
        $this->assertSame('noindex, nofollow', $page->headers->get('X-Robots-Tag'));
        $dom = $this->pageDom((string) $page->getContent());

        $this->element($dom, '//*[@id="evidence"]');
        $this->element($dom, '//form[@action="'.route('admin.email-marketing.stage').'"]');
        $form = $this->element($dom, '//*[@id="evidence"]//form[@id="evidence-review-upload"]');
        $this->assertSame(route('admin.email-marketing.evidence.stage'), $form->getAttribute('action'));
        $this->assertSame('post', strtolower($form->getAttribute('method')));
        $this->assertSame('multipart/form-data', $form->getAttribute('enctype'));
        $formPath = '//form[@id="evidence-review-upload"]';
        $csrf = $this->element($dom, $formPath.'//input[@name="_token" and @type="hidden"]');
        $this->assertNotEmpty($csrf->getAttribute('value'));
        $file = $this->element($dom, $formPath.'//input[@name="file" and @type="file"]');
        $this->assertTrue($file->hasAttribute('required'));
        $this->assertStringContainsString('.csv', $file->getAttribute('accept'));
        $this->assertSame('evidence-review-file', $file->getAttribute('id'));
        $this->assertGreaterThan(0, $this->elements($dom, '//label[@for="evidence-review-file"]')->length);

        $ack = $this->element($dom, $formPath.'//input[@name="acknowledge" and @type="checkbox"]');
        $this->assertSame('1', $ack->getAttribute('value'));
        $this->assertSame('evidence-review-acknowledge', $ack->getAttribute('id'));
        $this->assertTrue($ack->hasAttribute('required'));
        $this->assertFalse($ack->hasAttribute('checked'));
        $this->assertGreaterThan(0, $this->elements($dom, '//label[@for="evidence-review-acknowledge"]')->length);
        $this->element($dom, $formPath.'//button[@type="submit"]');

        $links = [];
        foreach ($this->elements($dom, '//a[@href]') as $link) {
            $links[] = $link->getAttribute('href');
        }
        $this->assertTrue(in_array('#evidence', $links, true)
            || in_array(route('admin.email-marketing.index').'#evidence', $links, true));
        $this->assertContains(route('admin.email-marketing.evidence.sample'), $links);

        $total = $this->element($dom, '//*[@data-evidence-total]');
        $this->assertSame('0', $total->getAttribute('data-evidence-total'));
        $this->assertStringContainsString('0', $total->textContent);
        $this->assertSame('0', $this->element($dom, '//*[@data-evidence-pending]')->getAttribute('data-evidence-pending'));
        $this->assertSame('0', $this->element($dom, '//*[@data-evidence-eligible]')->getAttribute('data-evidence-eligible'));
        $this->element($dom, '//*[@data-evidence-empty]');
        $this->assertSame(0, $this->elements($dom, '//*[@data-evidence-row]')->length);

        $sample = $this->get('/admin/email-marketing/evidence/sample')->assertOk();
        $this->assertStringContainsString('text/csv', $sample->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment;', $sample->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $sample->headers->get('Cache-Control'));
        $this->assertSame("\xEF\xBB\xBF".self::HEADER, $sample->getContent());
        $this->assertDatabaseCount('external_email_consent_reviews', 0);
        $this->assertReviewOnly();
    }

    public function test_legacy_evidence_index_redirects_to_the_integrated_section(): void
    {
        $this->admin();

        $response = $this->get('/admin/email-marketing/evidence')
            ->assertRedirect(route('admin.email-marketing.index').'#evidence');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('/admin/email-marketing/evidence?page=2')
            ->assertRedirect(route('admin.email-marketing.index', ['evidence_page' => 2]).'#evidence');
        $this->assertDatabaseCount('external_email_consent_reviews', 0);
        $this->assertReviewOnly();
    }

    public function test_evidence_upload_assigns_unique_internal_ids_and_preserves_the_supplied_consent_dates(): void
    {
        $admin = $this->admin();
        $firstDate = now()->subDays(20)->toDateString();
        $secondDate = now()->subDays(10)->toDateString();
        $customerCount = Customer::count();

        $sample = $this->get('/admin/email-marketing/evidence/sample')->assertOk();
        $this->assertStringContainsString('no-store', $sample->headers->get('Cache-Control'));
        $this->assertSame("\xEF\xBB\xBF".self::HEADER, $sample->getContent());

        $this->upload(self::HEADER."one@external.example.test,{$firstDate},archive-synthetic-one\ntwo@external.example.test,{$secondDate},archive-synthetic-two\n  ONE@EXTERNAL.EXAMPLE.TEST  ,{$secondDate},archive-synthetic-duplicate\n")
            ->assertRedirect(route('admin.email-marketing.index').'#evidence')->assertSessionHas('evidence_result');

        $rows = ExternalEmailConsentReview::query()->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame((int) $rows[0]->id + 999, (int) $rows[0]->contact_id);
        $this->assertSame((int) $rows[1]->id + 999, (int) $rows[1]->contact_id);
        $this->assertGreaterThanOrEqual(1000, (int) $rows[0]->contact_id);
        $this->assertGreaterThan((int) $rows[0]->contact_id, (int) $rows[1]->contact_id);

        foreach ([[$rows[0], $firstDate, 'archive-synthetic-one', 'one@external.example.test'], [$rows[1], $secondDate, 'archive-synthetic-two', 'two@external.example.test']] as [$record, $date, $reference, $address]) {
            $raw = DB::table('external_email_consent_reviews')->where('id', $record->id)->first();
            $this->assertSame($date, substr((string) $raw->consent_date, 0, 10));
            $this->assertSame(now()->toDateString(), substr((string) $raw->created_at, 0, 10));
            $this->assertSame((int) $admin->id, (int) $raw->imported_by);
            $this->assertSame($reference, $record->evidence_reference);
            $this->assertNotSame($reference, $raw->evidence_reference);
            $this->assertStringNotContainsString($reference, $raw->evidence_reference);
            $this->assertStringNotContainsString($address, json_encode($raw));
            $this->assertArrayNotHasKey('evidence_reference', $record->toArray());
            $this->assertArrayNotHasKey('email_digest', $record->toArray());
            $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $raw->email_digest);
            $this->assertNotEmpty($raw->digest_key_id);
        }

        $summary = session('evidence_result');
        $this->assertSame(3, (int) $summary['rows']);
        $this->assertSame(2, (int) $summary['added']);
        $this->assertSame(1, (int) $summary['duplicates']);
        $this->assertStringNotContainsString('external.example.test', json_encode($summary));
        $this->assertStringNotContainsString('archive-synthetic', json_encode($summary));

        $page = $this->get('/admin/email-marketing')->assertOk()
            ->assertSee((string) $rows[0]->contact_id)
            ->assertSee((string) $rows[1]->contact_id)
            ->assertSee(__('email-evidence-review.review_only'))
            ->assertSee(__('email-evidence-review.no_message'))
            ->assertDontSee('one@external.example.test')->assertDontSee('two@external.example.test')
            ->assertDontSee('archive-synthetic-one')->assertDontSee('archive-synthetic-two');
        $this->assertStringContainsString('no-store', $page->headers->get('Cache-Control'));
        $dom = $this->pageDom((string) $page->getContent());
        $total = $this->element($dom, '//*[@data-evidence-total]');
        $this->assertSame('2', $total->getAttribute('data-evidence-total'));
        $this->assertStringContainsString('2', $total->textContent);
        $this->assertSame('2', $this->element($dom, '//*[@data-evidence-pending]')->getAttribute('data-evidence-pending'));
        $this->assertSame('0', $this->element($dom, '//*[@data-evidence-eligible]')->getAttribute('data-evidence-eligible'));
        $this->assertSame(0, $this->elements($dom, '//*[@data-evidence-empty]')->length);
        $recordNodes = $this->elements($dom, '//*[@data-evidence-row]');
        $this->assertSame(2, $recordNodes->length);
        $displayedIds = [];
        foreach ($recordNodes as $node) {
            $displayedIds[] = (int) $node->getAttribute('data-evidence-row');
        }
        $this->assertSame([(int) $rows[1]->contact_id, (int) $rows[0]->contact_id], $displayedIds);
        foreach (['rows' => '3', 'added' => '2', 'duplicates' => '1'] as $metric => $value) {
            $node = $this->element($dom, '//*[@data-evidence-metric="'.$metric.'"]');
            $this->assertSame($value, trim($node->textContent));
        }
        $this->assertReviewOnly();
        $this->assertDatabaseCount('customers', $customerCount);
    }

    public function test_evidence_pagination_is_independent_of_customer_pagination_and_filter(): void
    {
        $this->admin();
        $date = now()->subDays(3)->toDateString();
        $csv = self::HEADER;
        for ($i = 1; $i <= 36; $i++) {
            $csv .= "synthetic-{$i}@external.example.test,{$date},archive-synthetic-{$i}\n";
        }
        $this->upload($csv)->assertRedirect(route('admin.email-marketing.index').'#evidence');

        $firstPage = $this->get('/admin/email-marketing?state=pending&page=2&evidence_page=1')->assertOk();
        $dom = $this->pageDom((string) $firstPage->getContent());
        $this->assertSame(35, $this->elements($dom, '//*[@data-evidence-row]')->length);
        $this->assertSame('36', $this->element($dom, '//*[@data-evidence-total]')->getAttribute('data-evidence-total'));
        $nextLinkFound = false;
        foreach ($this->elements($dom, '//*[@id="evidence"]//a[@href]') as $link) {
            parse_str((string) parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
            if (($query['evidence_page'] ?? null) === '2') {
                $this->assertSame('2', $query['page'] ?? null);
                $this->assertSame('pending', $query['state'] ?? null);
                $nextLinkFound = true;
            }
        }
        $this->assertTrue($nextLinkFound);

        $secondPage = $this->get('/admin/email-marketing?state=pending&page=2&evidence_page=2')->assertOk();
        $dom = $this->pageDom((string) $secondPage->getContent());
        $this->assertSame(1, $this->elements($dom, '//*[@data-evidence-row]')->length);
        $this->assertSame('36', $this->element($dom, '//*[@data-evidence-total]')->getAttribute('data-evidence-total'));
        $this->assertDatabaseCount('external_email_consent_reviews', 36);
        $this->assertReviewOnly();
    }

    public function test_reuploading_a_normalized_address_never_overwrites_evidence_or_changes_its_id(): void
    {
        $admin = $this->admin();
        $date = now()->subDays(14)->toDateString();
        $otherDate = now()->subDays(7)->toDateString();
        $this->upload(self::HEADER."member@external.example.test,{$date},archive-original\n")->assertRedirect();
        $record = ExternalEmailConsentReview::query()->sole();
        $before = DB::table('external_email_consent_reviews')->where('id', $record->id)->first();

        $this->admin();
        $this->upload(self::HEADER."  MEMBER@EXTERNAL.EXAMPLE.TEST  ,{$otherDate},archive-replacement\n")
            ->assertRedirect()->assertSessionHas('evidence_result');

        $after = DB::table('external_email_consent_reviews')->sole();
        $this->assertSame((array) $before, (array) $after);
        $this->assertSame((int) $record->contact_id, (int) $record->fresh()->contact_id);
        $this->assertSame((int) $admin->id, (int) $after->imported_by);
        $this->assertSame('archive-original', $record->fresh()->evidence_reference);
        $this->assertDatabaseCount('external_email_consent_reviews', 1);
        $this->assertSame(0, (int) session('evidence_result')['added']);
        $this->assertSame(1, (int) session('evidence_result')['duplicates']);
        $this->assertReviewOnly();
    }

    public function test_review_does_not_grant_customer_consent_or_reverse_an_existing_withdrawal(): void
    {
        $new = Customer::create(['email' => 'existing@example.test', 'password' => 'test-password-not-real', 'status' => 'active', 'locale' => 'fa', 'timezone' => 'Asia/Tehran']);
        $new->forceFill(['email_verified_at' => now()])->save();
        $withdrawn = Customer::create(['email' => 'withdrawn@example.test', 'password' => 'test-password-not-real', 'status' => 'active', 'locale' => 'fa', 'timezone' => 'Asia/Tehran']);
        $withdrawn->forceFill(['email_verified_at' => now()])->save();
        $service = app(EmailMarketingPreferences::class);
        $this->actingAs($withdrawn, 'customer');
        $pref = $service->setConsent($withdrawn, false);
        Auth::guard('customer')->logout();
        $eventCount = DB::table('email_marketing_preference_events')->count();
        $customerCount = Customer::count();

        $this->admin();
        $date = now()->subDays(3)->toDateString();
        $this->upload(self::HEADER."existing@example.test,{$date},archive-existing\nwithdrawn@example.test,{$date},archive-withdrawn\n")->assertRedirect();

        // A positive individual evidence review, even if it refers to an
        // existing verified account, is not a recipient opt-in. It must not
        // reverse a previously withdrawn preference either.
        $records = ExternalEmailConsentReview::query()->orderBy('id')->get();
        $this->assertCount(2, $records);
        foreach ([$new, $withdrawn] as $i => $customer) {
            $this->post($this->decisionUrl($records[$i]), $this->verificationPayload($records[$i], [
                'source_email' => $customer->email,
                'review_reference' => 'independent-account-review-'.($i + 1),
            ]))->assertSessionHas('review_success');
        }
        $this->assertDatabaseCount('external_email_consent_review_events', 2);
        $this->assertEvidenceCounts(0, 2, 0, 0);

        $this->assertNull($service->preferenceFor($new));
        $this->assertFalse($service->eligible($new));
        $this->assertSame(EmailMarketingPreference::STATE_WITHDRAWN, $pref->fresh()->state);
        $this->assertFalse($service->eligible($withdrawn));
        $this->assertDatabaseCount('customers', $customerCount);
        $this->assertDatabaseCount('email_marketing_preferences', 1);
        $this->assertDatabaseCount('email_marketing_preference_events', $eventCount);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_key_rotation_keeps_the_existing_id_and_missing_history_blocks_new_intake(): void
    {
        // Synthetic keys only; real installation secrets are never needed.
        $oldKey = 'base64:'.base64_encode(str_repeat('o', 32));
        $nextKey = 'base64:'.base64_encode(str_repeat('n', 32));
        config(['app.key' => $oldKey, 'app.previous_keys' => []]);
        $this->admin();
        $date = now()->subDays(7)->toDateString();
        $this->upload(self::HEADER."member@external.example.test,{$date},archive-original\n")->assertRedirect();
        $before = DB::table('external_email_consent_reviews')->sole();

        config(['app.key' => $nextKey, 'app.previous_keys' => [$oldKey]]);
        $this->upload(self::HEADER."MEMBER@EXTERNAL.EXAMPLE.TEST,{$date},archive-duplicate\n")->assertRedirect();
        $this->assertSame(1, (int) session('evidence_result')['duplicates']);
        $this->assertSame(0, (int) session('evidence_result')['added']);
        $this->assertSame((array) $before, (array) DB::table('external_email_consent_reviews')->sole());

        config(['app.previous_keys' => []]);
        $this->upload(self::HEADER."another@external.example.test,{$date},archive-new\n")->assertSessionHasErrors('file');
        $this->assertSame((array) $before, (array) DB::table('external_email_consent_reviews')->sole());
        $this->assertReviewOnly();
    }

    public function test_non_admins_cannot_access_or_stage_evidence_and_csrf_stays_required(): void
    {
        $author = User::factory()->create(['role' => 'author']);
        $this->actingAs($author, 'web');
        $this->get('/admin/email-marketing')->assertForbidden();
        $this->get('/admin/email-marketing/evidence')->assertForbidden();
        $this->get('/admin/email-marketing/evidence/sample')->assertForbidden();
        $this->upload(self::HEADER."member@external.example.test,2025-01-10,archive-synthetic\n")->assertForbidden();
        $route = Route::getRoutes()->getByName('admin.email-marketing.evidence.stage');
        $this->assertNotNull($route);
        $this->assertNotContains(PreventRequestForgery::class, $route->excludedMiddleware());
        $this->assertDatabaseCount('external_email_consent_reviews', 0);
        $this->assertReviewOnly();
    }

    #[DataProvider('disabledFlags')]
    public function test_both_feature_flags_are_required(string $flag): void
    {
        $this->admin();
        config([$flag => false]);
        $this->get('/admin/email-marketing/evidence')->assertNotFound();
        $this->get('/admin/email-marketing/evidence/sample')->assertNotFound();
        $this->upload(self::HEADER."member@external.example.test,2025-01-10,archive-synthetic\n")->assertNotFound();
        if ($flag === 'email-marketing.evidence_review_enabled') {
            $page = $this->get('/admin/email-marketing')->assertOk();
            $dom = $this->pageDom((string) $page->getContent());
            $this->assertSame(0, $this->elements($dom, '//*[@id="evidence"]')->length);
            $this->assertSame(0, $this->elements($dom, '//form[@id="evidence-review-upload"]')->length);
            $this->element($dom, '//form[@action="'.route('admin.email-marketing.stage').'"]');
        } else {
            $this->get('/admin/email-marketing')->assertNotFound();
        }
        $this->assertDatabaseCount('external_email_consent_reviews', 0);
        $this->assertReviewOnly();
    }

    public static function disabledFlags(): array
    {
        return [
            'main enrollment disabled' => ['email-marketing.enabled'],
            'evidence review disabled' => ['email-marketing.evidence_review_enabled'],
        ];
    }

    public function test_upload_requires_acknowledgment_even_with_complete_evidence_fields(): void
    {
        $this->admin();
        $this->upload(self::HEADER."member@external.example.test,2025-01-10,archive-synthetic\n", false)
            ->assertSessionHasErrors('acknowledge');
        $this->assertDatabaseCount('external_email_consent_reviews', 0);
        $this->assertReviewOnly();
    }

    #[DataProvider('invalidEvidenceFiles')]
    public function test_invalid_evidence_rejects_the_entire_upload(string $contents): void
    {
        $this->admin();
        $this->upload($contents)->assertSessionHasErrors('file');
        $this->assertDatabaseCount('external_email_consent_reviews', 0);
        $this->assertReviewOnly();
    }

    public static function invalidEvidenceFiles(): array
    {
        // An otherwise valid preceding row demonstrates that malformed later
        // evidence never leaves a partially imported review list.
        $valid = self::HEADER."valid@external.example.test,2025-01-10,archive-valid\n";

        return [
            'raw name and address list' => ["name,email\nSynthetic,member@external.example.test\n"],
            'no date column' => ["email,consent_reference\nmember@external.example.test,archive-synthetic\n"],
            'no reference column' => ["email,consent_date\nmember@external.example.test,2025-01-10\n"],
            'missing date' => [$valid."bad@external.example.test,,archive-invalid\n"],
            'missing reference' => [$valid."bad@external.example.test,2025-01-10,\n"],
            'whitespace reference' => [$valid."bad@external.example.test,2025-01-10,   \n"],
            'invalid calendar date' => [$valid."bad@external.example.test,2025-02-30,archive-invalid\n"],
            'future date' => [$valid."bad@external.example.test,9999-12-31,archive-invalid\n"],
            'invalid address' => [$valid."not-an-address,2025-01-10,archive-invalid\n"],
            'unexpected extra field' => [$valid."bad@external.example.test,2025-01-10,archive-invalid,extra\n"],
        ];
    }
}
