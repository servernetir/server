<?php

namespace App\Services\Growth;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class DailyBlogSources
{
    public function discover(): array
    {
        $used = [];
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
        foreach (array_slice($candidates, 0, 8) as $candidate) {
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

    private function allowed(string $url, string $feed): bool
    {
        $p = parse_url($url);

        return $p && ($p['scheme'] ?? '') === 'https' && ($p['host'] ?? '') === parse_url($feed, PHP_URL_HOST)
            && ! isset($p['user']) && ! isset($p['pass']) && ! isset($p['port']) && ! isset($p['query']) && ! isset($p['fragment']);
    }

    private function fetch(string $url): string
    {
        $r = Http::timeout(12)->connectTimeout(4)->withoutRedirecting()->withUserAgent('ServerNetEditorial/1.0 (+https://servernet.cloud)')
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
