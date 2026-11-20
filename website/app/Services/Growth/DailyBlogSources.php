<?php

namespace App\Services\Growth;

use App\Models\Post;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class DailyBlogSources
{
    public function discover(): array
    {
        $used = Post::whereNotNull('source')->pluck('source')->all();
        foreach (DB::table('growth_automation_runs')->where('run_key', 'like', 'daily-blog:%')->get() as $run) {
            $url = json_decode($run->summary, true)['source']['url'] ?? null;
            if ($url) {
                $used[] = $url;
            }
        }
        $candidates = [];
        foreach (config('daily_blog.feeds', []) as $feed) {
            try {
                $xml = $this->fetch($feed);
                if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
                    continue;
                }
                $doc = new DOMDocument;
                if (! @$doc->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                    continue;
                }
                $xp = new DOMXPath($doc);
                foreach ($xp->query('//*[local-name()="item" or local-name()="entry"]') as $node) {
                    $value = fn ($name) => trim($xp->evaluate('string(./*[local-name()="'.$name.'"][1])', $node));
                    $url = $value('link');
                    if (! $url) {
                        $url = trim($xp->evaluate('string(./*[local-name()="link"][@rel="alternate" or not(@rel)][1]/@href)', $node));
                    }
                    if (! $this->allowed($url, $feed) || in_array($url, $used, true)) {
                        continue;
                    }
                    $rawDate = $value('pubDate') ?: ($value('published') ?: $value('updated'));
                    if (! $rawDate) {
                        continue;
                    }
                    try {
                        $date = Carbon::parse($rawDate);
                    } catch (\Throwable) {
                        continue;
                    }
                    if ($date->lt(now()->subDays(7)) || $date->gt(now()->addMinutes(5))) {
                        continue;
                    }
                    $title = mb_substr(strip_tags($value('title')), 0, 180);
                    $summary = strip_tags($value('description') ?: $value('summary'));
                    $hits = preg_match_all('/\b(?:gpu|cuda|inference|model|ai|cloud|server|kubernetes|security|developer|linux|hosting|performance|memory|backup|database)\b/i', $title.' '.$summary);
                    if ($hits < 1) {
                        continue;
                    }
                    $candidates[$url] = ['url' => $url, 'title' => $title, 'published_at' => $date->toIso8601String(),
                        'score' => min(8, $hits) * 10 + max(0, 72 - $date->diffInHours(now(), true))];
                }
            } catch (\Throwable) {
                // One unavailable feed does not stop the other official sources.
            }
        }
        usort($candidates, fn ($a, $b) => $b['score'] <=> $a['score']);
        $shortlist = array_slice($candidates, 0, 8);
        foreach ($shortlist as &$candidate) {
            $candidate['trend'] = $this->trend($candidate['url']);
            $candidate['score'] += $candidate['trend']['score'];
        }
        unset($candidate);
        usort($shortlist, fn ($a, $b) => $b['score'] <=> $a['score']);
        foreach ($shortlist as $candidate) {
            try {
                $html = $this->fetch($candidate['url']);
                $doc = new DOMDocument;
                if (! @$doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                    continue;
                }
                $xp = new DOMXPath($doc);
                foreach ($xp->query('//script|//style|//nav|//header|//footer|//aside|//form|//noscript') as $n) {
                    $n->parentNode?->removeChild($n);
                }
                $main = $xp->query('//article|//main')->item(0) ?? $doc->documentElement;
                $text = trim(preg_replace('/\s+/u', ' ', $main->textContent));
                if (mb_strlen($text) < 500) {
                    continue;
                }

                return $candidate + ['text' => mb_substr($text, 0, 14000), 'sha256' => hash('sha256', $text),
                    'fetched_at' => now()->toIso8601String(), 'candidates_considered' => count($candidates)];
            } catch (\Throwable) {
            }
        }
        throw new \RuntimeException('no_fresh_official_source');
    }

    /** Public discussion evidence, never a claim of global trending or a fetch target. */
    private function trend(string $url): array
    {
        $missing = ['provider' => 'hacker_news', 'status' => 'unavailable', 'score' => 0];
        if (! config('daily_blog.trend_signals', false)) {
            return $missing + ['reason' => 'disabled'];
        }
        try {
            return Cache::remember('daily-blog-trend:'.hash('sha256', $url), 900, function () use ($url, $missing) {
                $endpoint = 'https://hn.algolia.com/api/v1/search?'.http_build_query([
                    'query' => $url, 'restrictSearchableAttributes' => 'url', 'tags' => 'story', 'hitsPerPage' => 10,
                ]);
                $data = json_decode($this->fetch($endpoint, 3), true, 32, JSON_THROW_ON_ERROR);
                if (! is_array($data['hits'] ?? null)) {
                    return $missing;
                }
                $best = ['provider' => 'hacker_news', 'status' => 'no_matching_discussion', 'score' => 0,
                    'checked_at' => now()->toIso8601String()];
                foreach ($data['hits'] as $hit) {
                    if (! is_array($hit) || rtrim((string) ($hit['url'] ?? ''), '/') !== rtrim($url, '/')
                        || ! is_numeric($hit['created_at_i'] ?? null) || (int) $hit['created_at_i'] < now()->subDays(7)->timestamp
                        || (int) $hit['created_at_i'] > now()->addMinutes(5)->timestamp
                        || ! ctype_digit((string) ($hit['objectID'] ?? ''))) {
                        continue;
                    }
                    $points = max(0, min(100000, (int) ($hit['points'] ?? 0)));
                    $comments = max(0, min(100000, (int) ($hit['num_comments'] ?? 0)));
                    $score = min(80, (int) round(12 * log(1 + $points) + 6 * log(1 + $comments)));
                    if ($score > $best['score']) {
                        $best = ['provider' => 'hacker_news', 'status' => 'observed', 'score' => $score,
                            'points' => $points, 'comments' => $comments,
                            'evidence_url' => 'https://news.ycombinator.com/item?id='.$hit['objectID'],
                            'checked_at' => now()->toIso8601String()];
                    }
                }

                return $best;
            });
        } catch (\Throwable) {
            return $missing;
        }
    }

    private function allowed(string $url, string $feed): bool
    {
        $p = parse_url($url);

        return $p && ($p['scheme'] ?? '') === 'https' && ($p['host'] ?? '') === parse_url($feed, PHP_URL_HOST)
            && ! isset($p['user']) && ! isset($p['pass']) && ! isset($p['port']) && ! isset($p['query']) && ! isset($p['fragment']);
    }

    private function fetch(string $url, int $timeout = 12): string
    {
        $r = Http::timeout($timeout)->connectTimeout(min(4, $timeout))->withoutRedirecting()->withUserAgent('ServerNetEditorial/1.0 (+https://servernet.cloud)')
            ->withOptions(['stream' => true])->get($url);
        if (! $r->ok()) {
            throw new \RuntimeException('official_source_unavailable');
        }
        $stream = $r->toPsrResponse()->getBody();
        $body = '';
        while (! $stream->eof() && strlen($body) <= 2_000_000) {
            $part = $stream->read(min(65536, 2_000_001 - strlen($body)));
            if ($part === '') {
                break;
            }
            $body .= $part;
        }
        if (strlen($body) > 2_000_000) {
            throw new \RuntimeException('official_source_too_large');
        }

        return $body;
    }
}
