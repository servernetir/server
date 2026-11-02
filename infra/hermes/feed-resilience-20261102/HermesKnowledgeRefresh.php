<?php

namespace App\Console\Commands;

use App\Services\Bale\Admin\HermesMiniAppStore;
use App\Services\Hermes\HermesConversationMemory;
use App\Services\Hermes\HermesKnowledge;
use App\Services\Hermes\HermesOperations;
use App\Services\Hermes\HermesOperationsStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use App\Support\ErrorTracker;

final class HermesKnowledgeRefresh extends Command
{
    protected $signature = 'hermes:knowledge-refresh';
    protected $description = 'Refresh owner knowledge from recorded operations and selected official feeds';

    public function handle(HermesOperations $operations, HermesOperationsStore $store, HermesMiniAppStore $chat): int
    {
        $stage = 'preconditions'; $lock = null; $locked = false;
        try {
            if (! $operations->enabled() || ! ($owner = $operations->owner())) { $this->line('disabled'); return self::SUCCESS; }
            $stage = 'lock';
            $lock = Cache::lock('hermes:knowledge-refresh', 600);
            if (! $lock->get()) { $this->line('busy'); return self::SUCCESS; }
            $locked = true;
            $stage = 'owner_memory';
            // Retained owner statements only; customer messages and assistant guesses are not owner facts.
            $chat->transact($owner, static function (array &$state): void { HermesConversationMemory::observe($state); });
            $now = time();
            $stage = 'internal_knowledge';
            $store->change($owner, static function (array &$state) use ($now): void {
                $knowledge = $state['knowledge'] ?? [];
                HermesKnowledge::observe($knowledge, $state, $now);
                $state['knowledge'] = $knowledge;
            });
            $stage = 'knowledge_read';
            $knowledge = $store->read($owner)['knowledge'];
            $summary = ['internal_updated' => true, 'feeds_ok' => 0, 'feeds_unavailable' => 0, 'feeds_skipped' => 0, 'source_results' => []];
            foreach (HermesKnowledge::SOURCES as $key => $source) {
                if (! HermesKnowledge::due($knowledge, $key, $now)) {
                    $summary['feeds_skipped']++;
                    $summary['source_results'][$key] = ['status' => $knowledge['sources'][$key]['status'] ?? 'not_checked',
                        'skipped' => true, 'next_attempt_at' => $knowledge['sources'][$key]['next_attempt_at'] ?? null];
                    continue;
                }
                $previousSource = $knowledge['sources'][$key] ?? [];
                $items = null; $stream = null; $failure = null; $httpStatus = null; $phase = 'fetch';
                $stage = 'official_feed';
                try {
                    // Fixed HTTPS destinations only. No redirects, article fetching, cookies or credentials.
                    $response = Http::withHeaders(['User-Agent' => 'ServerNet-Hermes-Knowledge/1.0',
                        'Accept' => 'application/atom+xml, application/rss+xml, application/xml, text/xml'])
                        ->connectTimeout(5)->timeout(15)->withOptions(['allow_redirects' => false, 'stream' => true])->get($source['url']);
                    $httpStatus = $response->status();
                    if (! $response->successful()) { throw new \RuntimeException('Feed HTTP unavailable'); }
                    $phase = 'read';
                    $stream = $response->toPsrResponse()->getBody();
                    $body = '';
                    while (! $stream->eof() && strlen($body) <= 1048576) {
                        $chunk = $stream->read(min(65536, 1048577 - strlen($body)));
                        if ($chunk === '' && ! $stream->eof()) { throw new \RuntimeException('Incomplete feed'); }
                        $body .= $chunk;
                    }
                    $phase = 'parse';
                    $items = HermesKnowledge::parse($key, $body, $now);
                } catch (\Throwable $error) {
                    $reason = $httpStatus !== null && ($httpStatus < 200 || $httpStatus >= 300) ? 'http_error'
                        : ($phase === 'parse' ? 'invalid_feed' : ($phase === 'read' ? 'read_error' : 'connection_error'));
                    $failure = ['reason' => $reason, 'http_status' => $httpStatus, 'exception_class' => $error::class];
                    // Never publish the exception message, response body or URL arguments.
                }
                finally { if ($stream) { $stream->close(); } }
                $stage = 'source_record';
                $knowledge = $store->change($owner, static function (array &$state) use ($key, $items, $now, $failure): array {
                    HermesKnowledge::record($state['knowledge'], $key, $items, $now);
                    if ($failure !== null) { $state['knowledge']['sources'][$key]['failure'] = $failure; }
                    return $state['knowledge'];
                });
                if ($failure !== null) {
                    $signature = implode('|', [$failure['reason'], $failure['http_status'] ?? 0, $failure['exception_class']]);
                    if (ErrorTracker::throttlePassed('hermes-feed-'.$key, 21600, $signature)) {
                        ErrorTracker::note('hermes_knowledge', 'Official feed unavailable: '.$key.' ('.$failure['reason'].'_'.($httpStatus ?? 0).')',
                            ['source' => $key] + $failure);
                    }
                } elseif (($previousSource['status'] ?? '') === 'unavailable') {
                    // Reset the incident gate so a later relapse is a new event.
                    ErrorTracker::throttlePassed('hermes-feed-'.$key, 0, 'recovered');
                    ErrorTracker::note('hermes_knowledge', 'Official feed recovered: '.$key, ['source' => $key]);
                }
                $summary['source_results'][$key] = ['status' => $items === null ? 'unavailable' : 'ok'] + ($failure ?? []);
                $summary[$items === null ? 'feeds_unavailable' : 'feeds_ok']++;
            }
            $stage = 'refresh_record';
            // SUCCESS means this refresh completed and retained usable verified
            // observations, not that every optional external feed is healthy.
            $finished = time();
            $summary += HermesKnowledge::availability($knowledge, $finished);
            $summary['finished_at'] = $finished;
            $store->change($owner, static function (array &$state) use ($summary): void {
                $state['knowledge']['refresh'] = $summary;
            });
            $this->line(json_encode($summary, JSON_THROW_ON_ERROR));
            return $summary['status'] === 'unavailable' ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $error) {
            ErrorTracker::noteOnce('hermes_knowledge', 'Knowledge refresh unavailable at '.$stage, 3600,
                ['stage' => $stage, 'exception_class' => $error::class]);
            $this->error('Knowledge refresh unavailable; previous dated observations retained'); return self::FAILURE;
        } finally {
            if ($locked) {
                try { $lock->release(); }
                catch (\Throwable $error) { ErrorTracker::noteOnce('hermes_knowledge', 'Knowledge refresh lock release unavailable', 3600, ['exception_class' => $error::class]); }
            }
        }
    }
}
