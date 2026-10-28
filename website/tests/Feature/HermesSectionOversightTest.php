<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\MailboxMessage;
use App\Models\Post;
use App\Models\PostTranslation;
use App\Services\Growth\HermesSectionOversight;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HermesSectionOversightTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 28)->setTime(8, 30));
    }

    public function test_all_six_sections_have_stable_work_keys_and_no_claimed_execution(): void
    {
        $first = (new HermesSectionOversight)->snapshot();
        $second = (new HermesSectionOversight)->snapshot();
        $this->assertSame(['ai_growth', 'blog', 'kb', 'comments', 'marketing', 'mail'], array_keys($first['sections']));
        $this->assertSame(6, $first['coverage_count']);
        foreach ($first['sections'] as $section => $item) {
            $this->assertSame($item['work_key'], $second['sections'][$section]['work_key']);
            $this->assertFalse($item['execution_verified']);
            $this->assertNull($item['completion_evidence']);
            $this->assertNotEmpty($item['next_action']);
        }
        $this->assertFalse($first['financial_operations_enabled']);
        $this->assertFalse($first['learning_assessed']);
        $this->assertSame([], $first['executed_actions']);
    }

    private function mail(string $account, string $category): void
    {
        $hash = hash('sha256', $account.'-'.$category);
        MailboxMessage::create(['account' => $account, 'uid_hash' => $hash, 'message_id' => $hash,
            'from_email' => 'private@example.invalid', 'from_name' => 'Private Customer',
            'subject' => 'Private subject', 'snippet' => 'private-fixture-token',
            'summary' => 'Private summary', 'received_at' => now()->subDays(2),
            'is_system' => false, 'category' => $category, 'needs_reply' => true]);
    }

    public function test_personal_billing_and_legal_mail_are_excluded_and_private_data_never_leaves(): void
    {
        $this->mail('support', 'sales');
        $this->mail('info', 'support');
        $this->mail('gmail', 'sales');
        $this->mail('billing', 'billing');
        $this->mail('ceo', 'legal');
        $this->mail('ceo', 'personal');

        $snapshot = (new HermesSectionOversight)->snapshot();
        $counts = $snapshot['sections']['mail']['observation']['counts'];
        $this->assertSame(2, $counts['business_threads_needing_reply']);
        $this->assertSame(2, $counts['business_threads_over_one_day']);
        $this->assertSame('mail', $snapshot['priority'][0]);
        $json = json_encode($snapshot, JSON_THROW_ON_ERROR);
        foreach (['private@example.invalid', 'Private Customer', 'Private subject', 'private-fixture-token'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
    }

    public function test_blog_and_kb_are_independent_and_age_only_triggers_review(): void
    {
        $blog = Post::create(['slug' => 'fixture-blog', 'type' => 'blog', 'category' => 'tutorial', 'status' => 'published']);
        $kb = Post::create(['slug' => 'fixture-kb', 'type' => 'kb', 'category' => 'tutorial', 'status' => 'draft']);
        DB::table('posts')->where('id', $blog->id)->update(['updated_at' => now()->subDays(91)]);
        PostTranslation::create(['post_id' => $blog->id, 'locale' => 'en', 'title' => 'Fixture title', 'excerpt' => '', 'content' => 'Fixture article']);
        PostTranslation::create(['post_id' => $kb->id, 'locale' => 'en', 'title' => 'KB fixture', 'excerpt' => 'Useful excerpt', 'content' => 'Fixture instructions']);
        $sections = (new HermesSectionOversight)->snapshot()['sections'];
        $this->assertSame(1, $sections['blog']['observation']['counts']['published']);
        $this->assertSame(1, $sections['blog']['observation']['counts']['review_due_by_age']);
        $this->assertSame(1, $sections['blog']['observation']['counts']['missing_title_or_excerpt']);
        $this->assertSame(1, $sections['kb']['observation']['counts']['unpublished']);
        $this->assertSame(0, $sections['kb']['observation']['counts']['missing_title_or_excerpt']);
        $this->assertSame('published', $blog->fresh()->status);
    }

    public function test_pending_comment_is_observed_without_approval_or_body_disclosure(): void
    {
        $comment = Comment::create(['post_slug' => 'fixture', 'name' => 'Private Name',
            'email' => 'comment@example.invalid', 'body' => 'private-comment-body', 'locale' => 'en', 'approved' => false]);
        $snapshot = (new HermesSectionOversight)->snapshot();
        $this->assertSame(1, $snapshot['sections']['comments']['observation']['counts']['pending_moderation']);
        $this->assertFalse($comment->fresh()->approved);
        $this->assertStringNotContainsString('private-comment-body', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    public function test_missing_section_schema_is_unavailable_never_a_healthy_zero(): void
    {
        Schema::rename('mailbox_messages', 'mailbox_messages_fixture_unavailable');
        try {
            $mail = (new HermesSectionOversight)->snapshot()['sections']['mail'];
            $this->assertSame('unavailable', $mail['observation']['state']);
            $this->assertNull($mail['observation']['counts']);
            $this->assertSame('restore_section_evidence', $mail['next_action']);
        } finally {
            Schema::rename('mailbox_messages_fixture_unavailable', 'mailbox_messages');
        }
    }

    public function test_snapshot_performs_no_writes_and_does_not_consume_a_daily_slot(): void
    {
        $writes = [];
        DB::listen(function (QueryExecuted $event) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $event->sql)) {
                $writes[] = $event->sql;
            }
        });
        (new HermesSectionOversight)->snapshot();
        $this->assertSame([], $writes);
        $this->assertDatabaseCount('growth_automation_runs', 0);
    }

    public function test_uncertain_marketing_result_takes_priority_without_resend_or_state_change(): void
    {
        $lead = \App\Models\CrmLead::create(['domain_hash' => hash('sha256', 'fixture.example'),
            'company' => 'Fixture', 'stage' => 'replied']);
        $message = \App\Models\CrmMessage::create(['lead_id' => $lead->id,
            'direction' => 'out', 'channel' => 'email', 'status' => 'sending',
            'body' => 'private-message-body', 'sequence' => 0]);
        DB::table('crm_messages')->where('id', $message->id)->update(['updated_at' => now()->subHour()]);
        $snapshot = (new HermesSectionOversight)->snapshot();
        $this->assertSame('marketing', $snapshot['priority'][0]);
        $this->assertSame(1, $snapshot['sections']['marketing']['observation']['counts']['results_requiring_reconciliation']);
        $this->assertSame('sending', $message->fresh()->status);
        $this->assertStringNotContainsString('private-message-body', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    public function test_incomplete_curriculum_is_unavailable_instead_of_silently_covering_zero_sections(): void
    {
        config(['hermes_growth.sections' => []]);
        $snapshot = (new HermesSectionOversight)->snapshot();
        $this->assertSame('unavailable', $snapshot['state']);
        $this->assertSame('curriculum_unavailable', $snapshot['reason']);
        $this->assertSame(0, $snapshot['coverage_count']);
        $this->assertTrue($snapshot['sources_need_review']);
    }

    public function test_source_freshness_is_not_silently_assumed_forever(): void
    {
        $this->travel(31)->days();
        $this->assertTrue((new HermesSectionOversight)->snapshot()['sources_need_review']);
    }
}
