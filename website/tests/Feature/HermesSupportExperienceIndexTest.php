<?php

namespace Tests\Feature;

use App\Services\Hermes\HermesOperationsStore;
use App\Services\Hermes\HermesSupportExperience;
use App\Services\Hermes\HermesSupportExperienceIndex;
use Tests\TestCase;

require_once dirname(__DIR__, 3).'/infra/hermes/support-learning-20261028/HermesOperationsStore.php';
require_once dirname(__DIR__, 3).'/infra/hermes/support-firewall-20261028/HermesSupportPolicy.php';

class HermesSupportExperienceIndexTest extends TestCase
{
    private function owner(): array { return ['user_id' => '100', 'chat_id' => '200']; }

    private function directory(): string
    {
        return storage_path('framework/testing/hermes-index-'.bin2hex(random_bytes(8)));
    }

    private function row(int $id): array
    {
        return ['ticket_id' => $id, 'customer_id' => 10, 'department' => 'technical',
            'reply_message_id' => $id, 'reply_at' => '2026-01-01',
            'source_digest' => hash('sha256', 'source-'.$id), 'topics' => ['network'],
            'staff_reported_tags' => ['guest_verification_remains_separate'],
            'outcome' => 'awaiting_customer_confirmation', 'reference_only' => true,
            'closing_acknowledgement' => false, 'body' => 'private-prose-never-persists'];
    }

    private function publish(HermesSupportExperienceIndex $index, int $count, array $previous = [], int $time = 10000): array
    {
        return $index->publish($this->owner(), function (callable $append) use ($count, $time): array {
            for ($id = 1; $id <= $count; $id++) { $append([$this->row($id)]); }
            return ['version' => 3, 'observed_at' => $time, 'source' => 'published_staff_replies_and_customer_followups',
                'source_scope' => 'all_departments_public_history', 'source_ticket_count' => $count,
                'source_message_count' => $count, 'bounded_inventory_complete' => true,
                'topic_counts' => ['network' => $count], 'department_counts' => ['technical' => $count],
                'confirmed_case_count' => 0, 'grants_permissions' => false,
                'snapshot_max_ticket_id' => $count, 'snapshot_max_message_id' => $count,
                'compiler_version' => 'support-experience-20261102-paged-v1'];
        }, $previous);
    }

    private function generationPath(string $base, array $summary): string
    {
        return $base.'/'.$summary['index_ref']['owner_hash'].'/'.$summary['index_ref']['generation'];
    }

    public function test_history_above_operations_limit_is_paged_complete_and_retrievable_from_last_page(): void
    {
        $base = $this->directory(); $index = new HermesSupportExperienceIndex($base);
        $bytes = 0;
        for ($id = 1; $id <= 45000; $id++) { $bytes += strlen(json_encode($this->row($id))); }
        $this->assertGreaterThan(12 * 1024 * 1024, $bytes);
        $summary = $this->publish($index, 45000);
        $this->assertArrayNotHasKey('cases', $summary);
        $this->assertLessThan(65536, strlen(json_encode($summary)));
        $store = new HermesOperationsStore($this->directory());
        $store->change($this->owner(), function (&$state) use ($summary): void {
            $state['rules']['keep'] = ['text' => 'preserve'];
            $state['support_experience'] = $summary;
        });
        $saved = $store->read($this->owner());
        $this->assertSame($summary, $saved['support_experience']);
        $this->assertSame('preserve', $saved['rules']['keep']['text']);
        $context = HermesSupportExperience::retrieve($summary, 99999, 10, 'UDP', $index->cases($this->owner(), $summary['index_ref']));
        $this->assertSame(45000, $context['learned_case_count']);
        $this->assertSame(45000, $context['same_customer_history'][0]['reply_message_id']);
        $this->assertCount(3, $context['same_customer_history']);
        $this->assertSame(45000, $index->verify($this->owner(), $summary['index_ref'])['case_count']);
        $this->assertStringNotContainsString('private-prose', json_encode($context));
        $files = glob($this->generationPath($base, $summary).'/*.enc');
        $this->assertGreaterThan(1, count($files));
        foreach ($files as $file) {
            $encoded = file_get_contents($file);
            $this->assertStringNotContainsString('private-prose', $encoded);
            $this->assertStringNotContainsString('ticket_id', $encoded);
        }
        foreach ($index->cases($this->owner(), $summary['index_ref']) as $case) {
            $this->assertArrayNotHasKey('body', $case);
            break;
        }
    }

    public function test_unchanged_history_reuses_generation_and_corrupt_previous_page_is_repaired(): void
    {
        $base = $this->directory(); $index = new HermesSupportExperienceIndex($base);
        $first = $this->publish($index, 300);
        $same = $this->publish($index, 300, $first, 10001);
        $this->assertSame($first['index_ref'], $same['index_ref']);
        $this->assertSame(10001, $same['checked_at']);
        file_put_contents($this->generationPath($base, $same).'/page-00000001.enc', 'damaged');
        $repaired = $this->publish($index, 300, $same, 10002);
        $this->assertNotSame($same['index_ref']['generation'], $repaired['index_ref']['generation']);
        $this->assertSame(300, $index->verify($this->owner(), $repaired['index_ref'])['case_count']);
    }

    public function test_wrong_owner_reference_and_manifest_tampering_fail_closed(): void
    {
        $base = $this->directory(); $index = new HermesSupportExperienceIndex($base);
        $summary = $this->publish($index, 2);
        try {
            iterator_to_array($index->cases(['user_id' => '101', 'chat_id' => '201'], $summary['index_ref']));
            $this->fail('Cross-owner index was accepted');
        } catch (\RuntimeException $error) { $this->assertStringContainsString('reference', $error->getMessage()); }
        file_put_contents($this->generationPath($base, $summary).'/manifest.enc', 'tampered');
        $this->expectException(\RuntimeException::class);
        $index->summary($this->owner(), $summary['index_ref']);
    }

    public function test_late_corrupt_page_never_returns_a_partial_complete_context(): void
    {
        $base = $this->directory(); $index = new HermesSupportExperienceIndex($base);
        $summary = $this->publish($index, 300);
        file_put_contents($this->generationPath($base, $summary).'/page-00000001.enc', 'tampered');
        $this->expectException(\RuntimeException::class);
        HermesSupportExperience::retrieve($summary, 99999, 10, 'UDP', $index->cases($this->owner(), $summary['index_ref']));
    }

    public function test_interrupted_build_preserves_previous_reference_and_retention_keeps_current_and_previous(): void
    {
        $base = $this->directory(); $index = new HermesSupportExperienceIndex($base);
        $first = $this->publish($index, 2);
        try {
            $index->publish($this->owner(), function (callable $append): array {
                for ($id = 1; $id <= 300; $id++) { $append([$this->row($id)]); }
                throw new \RuntimeException('synthetic interruption');
            }, $first);
            $this->fail('Interrupted publish returned a reference');
        } catch (\RuntimeException $error) { $this->assertSame('synthetic interruption', $error->getMessage()); }
        $this->assertSame(2, $index->verify($this->owner(), $first['index_ref'])['case_count']);
        $second = $this->publish($index, 3, $first);
        $third = $this->publish($index, 4, $second);
        $index->collect($this->owner(), [$third['index_ref'], $second['index_ref']], time() + 3600);
        $this->assertDirectoryDoesNotExist($this->generationPath($base, $first));
        $this->assertSame(3, $index->verify($this->owner(), $second['index_ref'])['case_count']);
        $this->assertSame(4, $index->verify($this->owner(), $third['index_ref'])['case_count']);
    }

    public function test_legacy_inline_version_two_remains_readable_without_a_paged_reference(): void
    {
        $case = $this->row(1); unset($case['body'], $case['closing_acknowledgement']);
        $legacy = ['version' => 2, 'cases' => [$case], 'observed_at' => 10000,
            'confirmed_case_count' => 0, 'bounded_inventory_complete' => true];
        $store = new HermesOperationsStore($this->directory());
        $learner = new HermesSupportExperience($store, new HermesSupportExperienceIndex($this->directory()));
        $context = HermesSupportExperience::retrieve($legacy, 99, 10, 'UDP', $learner->cases($this->owner(), $legacy));
        $this->assertSame(1, $context['learned_case_count']);
        $this->assertSame(1, $context['same_customer_history'][0]['reply_message_id']);
    }
}
