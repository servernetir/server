<?php

namespace App\Services\Growth;

use App\Models\Post;
use App\Models\Setting;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class GoogleDiscovery
{
    public function enabled(): bool
    {
        return Setting::get('google_discovery_enabled') === '1';
    }

    public function status(): array
    {
        $rows = DB::table('growth_automation_runs')->where('run_key', 'like', 'google-discovery:%')->latest('id')->limit(30)->get();

        return ['enabled' => $this->enabled(), 'connection' => app(SearchConsoleClient::class)->status(),
            'receipts' => $rows->map(fn ($r) => json_decode($r->summary, true))->all()];
    }

    public function enqueue(Post $post): ?array
    {
        if (! $this->enabled()) {
            return null;
        }
        $snapshot = $this->snapshot($post);
        if (! $snapshot) {
            return null;
        }
        $key = 'google-discovery:'.$post->id.':'.substr($snapshot['revision'], 0, 32);
        $state = ['post_id' => $post->id, 'revision' => $snapshot['revision'], 'urls' => $snapshot['urls'],
            'stage' => 'queued', 'attempts' => 0, 'inspection_round' => 0, 'created_at' => now()->toIso8601String(),
            'next_at' => now()->toIso8601String(), 'sitemap' => SearchConsoleClient::SITEMAP];
        DB::table('growth_automation_runs')->insertOrIgnore(['run_key' => $key,
            'summary' => json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'created_at' => now()]);

        return json_decode(DB::table('growth_automation_runs')->where('run_key', $key)->value('summary'), true);
    }

    public function run(int $limit = 1): array
    {
        if (! $this->enabled()) {
            return ['stage' => 'paused'];
        }
        $lock = Cache::lock('google-discovery-run', 300);
        if (! $lock->get()) {
            return ['stage' => 'worker_busy'];
        }
        try {
            $since = Setting::get('google_discovery_started_at');
            if ($since) {
                $since = Carbon::parse($since);
                foreach (Post::where('type', 'blog')->where('status', 'published')->where(function ($q) use ($since) {
                    $q->where('published_at', '>=', $since)->orWhere('updated_at', '>=', $since)
                        ->orWhereHas('translations', fn ($t) => $t->where('updated_at', '>=', $since));
                })->with('translations')->latest('id')->limit(50)->get() as $post) {
                    $this->enqueue($post);
                }
            }
            $processed = [];
            foreach (DB::table('growth_automation_runs')->where('run_key', 'like', 'google-discovery:%')->orderBy('id')->cursor() as $row) {
                $s = json_decode($row->summary, true, 64, JSON_THROW_ON_ERROR);
                if (in_array($s['stage'], ['complete', 'blocked', 'superseded'], true) || Carbon::parse($s['next_at'])->isFuture()) {
                    continue;
                }
                $post = Post::with('translations')->find($s['post_id']);
                $current = $post ? $this->snapshot($post) : null;
                if (! $current || ! hash_equals($current['revision'], $s['revision'])) {
                    $s['stage'] = 'superseded';
                } elseif (! app(SearchConsoleClient::class)->status()['connected']) {
                    $s['stage'] = 'waiting_authorization';
                    $s['code'] = 'google_connection_required';
                    $s['next_at'] = now()->addMinutes(5)->toIso8601String();
                } else {
                    try {
                        if (empty($s['sitemap_accepted_at'])) {
                            $this->verifySitemap($s['urls']);
                            app(SearchConsoleClient::class)->submitSitemap();
                            $s['sitemap_accepted_at'] = now()->toIso8601String();
                            $s['stage'] = 'sitemap_submitted';
                            $s['next_at'] = now()->addHours(6)->toIso8601String();
                            $s['code'] = null;
                            GrowthAudit::record('google_discovery.sitemap_submitted', $row->run_key, null, ['post_id' => $post->id, 'urls' => $s['urls']]);
                        } else {
                            foreach ($s['urls'] as $locale => $url) {
                                $s['inspection'][$locale] = app(SearchConsoleClient::class)->inspect($url) + ['checked_at' => now()->toIso8601String()];
                                // Save partial inspections so a later failure never hides already observed results.
                                $this->save($row->run_key, $s);
                            }
                            $s['inspection_round']++;
                            $passing = count(array_filter($s['inspection'], fn ($i) => ($i['verdict'] ?? '') === 'PASS')) === 3;
                            $s['stage'] = $passing || $s['inspection_round'] >= 4 ? 'complete' : 'monitoring';
                            $s['all_indexed'] = $passing;
                            $s['next_at'] = now()->addHours($s['inspection_round'] === 1 ? 18 : 24)->toIso8601String();
                            $s['code'] = null;
                        }
                    } catch (\Throwable $e) {
                        $code = $e instanceof \RuntimeException && preg_match('/^[a-z_]{3,80}$/D', $e->getMessage())
                            ? $e->getMessage() : 'google_or_network_unavailable';
                        $s['code'] = $code;
                        if (in_array($code, ['google_connection_required', 'google_reauthorization_required', 'google_write_scope_missing', 'google_connection_changed'], true)) {
                            $s['stage'] = 'waiting_authorization';
                            $s['next_at'] = now()->addMinutes(5)->toIso8601String();
                        } else {
                            $s['attempts']++;
                            $s['stage'] = $s['attempts'] >= 6 ? 'blocked' : 'retry_wait';
                            $s['next_at'] = now()->addMinutes(min(360, 5 * (2 ** min(6, $s['attempts']))))->toIso8601String();
                        }
                    }
                }
                $this->save($row->run_key, $s);
                $processed[] = ['post_id' => $s['post_id'], 'stage' => $s['stage'], 'code' => $s['code'] ?? null];
                if (count($processed) >= max(1, min(3, $limit))) {
                    break;
                }
            }

            return ['stage' => 'checked', 'processed' => $processed];
        } finally {
            $lock->release();
        }
    }

    private function snapshot(Post $post): ?array
    {
        if ($post->type !== 'blog' || $post->status !== 'published' || ! $post->published_at || $post->published_at->isFuture()) {
            return null;
        }
        $post->loadMissing('translations');
        $revision = [$post->id, $post->slug, $post->published_at?->toIso8601String()];
        $urls = [];
        foreach (['fa', 'en', 'tr'] as $locale) {
            $tr = $post->translations->firstWhere('locale', $locale);
            $url = 'https://servernet.cloud'.($locale === 'fa' ? '' : '/'.$locale).'/blog/'.$post->slug;
            if (! $tr || blank($tr->title) || blank($tr->content) || ! SearchConsoleClient::allowedUrl($url)) {
                return null;
            }
            $urls[$locale] = $url;
            $revision[] = [$locale, $tr->title, $tr->excerpt, $tr->content, $tr->updated_at?->toIso8601String()];
        }

        return ['urls' => $urls, 'revision' => hash('sha256', json_encode($revision, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))];
    }

    private function verifySitemap(array $urls): void
    {
        $response = Http::timeout(15)->connectTimeout(4)->withoutRedirecting()->withOptions(['stream' => true])->get(SearchConsoleClient::SITEMAP);
        if (! $response->ok()) {
            throw new \RuntimeException('published_sitemap_unavailable');
        }
        $stream = $response->toPsrResponse()->getBody();
        $xml = '';
        while (! $stream->eof() && strlen($xml) <= 2_000_000) {
            $part = $stream->read(min(65536, 2_000_001 - strlen($xml)));
            if ($part === '') {
                break;
            }
            $xml .= $part;
        }
        if (strlen($xml) > 2_000_000 || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            throw new \RuntimeException('sitemap_response_rejected');
        }
        $doc = new DOMDocument;
        if (! @$doc->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw new \RuntimeException('invalid_sitemap');
        }
        $xp = new DOMXPath($doc);
        $found = [];
        foreach ($xp->query('//*[local-name()="url"]') as $node) {
            $loc = trim($xp->evaluate('string(./*[local-name()="loc"])', $node));
            if (in_array($loc, $urls, true)) {
                $lastmod = trim($xp->evaluate('string(./*[local-name()="lastmod"])', $node));
                if ($lastmod === '' || Carbon::parse($lastmod)->gt(now()->endOfDay())) {
                    throw new \RuntimeException('sitemap_lastmod_unverified');
                }
                $found[] = $loc;
            }
        }
        if (count(array_unique($found)) !== 3) {
            throw new \RuntimeException('three_language_sitemap_entries_missing');
        }
    }

    private function save(string $key, array $state): void
    {
        DB::table('growth_automation_runs')->where('run_key', $key)->update([
            'summary' => json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
    }
}
