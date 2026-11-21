<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\EmailMarketingPreference;
use App\Models\ExternalEmailConsentReview;
use App\Models\User;
use App\Services\Marketing\EmailMarketingPreferences;
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

    public function test_evidence_dashboard_has_an_accessible_review_only_form_and_empty_state(): void
    {
        $this->admin();

        $page = $this->get('/admin/email-marketing/evidence')->assertOk()
            ->assertSee(__('email-evidence-review.intro'))
            ->assertSee(__('email-evidence-review.empty'));
        $this->assertStringContainsString('no-store', $page->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $page->headers->get('Referrer-Policy'));
        $this->assertSame('noindex, nofollow', $page->headers->get('X-Robots-Tag'));
        $dom = $this->pageDom((string) $page->getContent());

        $form = $this->element($dom, '//form[@id="evidence-review-upload"]');
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
        $this->assertContains(route('admin.email-marketing.index'), $links);
        $this->assertContains(route('admin.email-marketing.evidence.sample'), $links);

        $total = $this->element($dom, '//*[@data-evidence-total]');
        $this->assertSame('0', $total->getAttribute('data-evidence-total'));
        $this->assertStringContainsString('0', $total->textContent);
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
            ->assertRedirect()->assertSessionHas('evidence_result');

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

        $page = $this->get('/admin/email-marketing/evidence')->assertOk()
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
