<?php

namespace App\Services\Ai;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Http;

final class OpenAiCatalog
{
    public const CONTEXT_LIMIT = 272_000;

    private const MODELS = [
        'gpt-5.5' => 'GPT-5.5',
        'gpt-5.4' => 'GPT-5.4',
        'gpt-5.4-mini' => 'GPT-5.4 mini',
    ];

    public function discoverPrices(array $ids): array
    {
        $rows = [];
        foreach (array_intersect_key(self::MODELS, $ids) as $id => $name) {
            $url = 'https://developers.openai.com/api/docs/models/'.$id;
            $markdown = Http::accept('text/markdown')->withoutRedirecting()->connectTimeout(5)->timeout(15)
                ->get($url.'.md')->throw()->body();

            $rows[$id] = [
                'name' => $name,
                'category' => 'chat',
                'vendor' => 'OpenAI',
                'docs_url' => $url,
                'rates' => self::chatRates($id, $markdown),
            ];
        }

        return $rows;
    }

    private static function chatRates(string $id, string $markdown): array
    {
        $markdown = str_replace("\r\n", "\n", $markdown);
        if (! preg_match('/^Model ID: `'.preg_quote($id, '/').'`$/m', $markdown)) {
            throw new \RuntimeException('Unexpected OpenAI model document.');
        }
        if (preg_match('/^Status: Deprecated$/mi', $markdown)
            || ! str_contains($markdown, '| Chat Completions | `v1/chat/completions` | Supported |')) {
            return [];
        }
        if (! preg_match('/^## Pricing\n(.*?)^## /ms', $markdown, $section)
            || preg_match('/cache writ(?:e|es|ing)|cache creation/i', $section[1])
            || ! preg_match('/^### Text tokens\n\n((?:\|[^\n]+\|\n)+)/m', $section[1], $table)) {
            throw new \RuntimeException('Unsupported OpenAI price format.');
        }

        $units = ['Input' => 'input', 'Cached input' => 'cached_input', 'Output' => 'output'];
        $lines = explode("\n", rtrim($table[1], "\n"));
        if (count($lines) !== 5 || $lines[0] !== '| Metric | Price | Unit |') {
            throw new \RuntimeException('Unexpected OpenAI price table.');
        }
        $rates = [];
        foreach (array_slice($lines, 2) as $line) {
            if (! preg_match('/^\| (Input|Cached input|Output) \| \$([0-9]+(?:\.[0-9]{1,6})?) \| 1M tokens \|$/', $line, $match)
                || isset($rates[$units[$match[1]]])) {
                throw new \RuntimeException('Invalid or duplicate OpenAI token rate.');
            }
            $rate = BigDecimal::of($match[2])->multipliedBy(1_000_000)->toInt();
            if ($rate < 1 || $rate > PriceBook::MAX_SELL_RATE_MICROS) {
                throw new \RuntimeException('OpenAI token rate is outside the supported range.');
            }
            $rates[$units[$match[1]]] = $rate;
        }
        if ($rates['cached_input'] > $rates['input']) {
            throw new \RuntimeException('Invalid OpenAI cached input rate.');
        }

        return $rates;
    }
}
