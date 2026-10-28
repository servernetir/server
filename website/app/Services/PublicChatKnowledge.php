<?php

namespace App\Services;

use App\Models\Post;

/** Public website facts only. Never reads Hermes memory, tickets or customer services. */
final class PublicChatKnowledge
{
    public function forMessage(string $message, string $locale): array
    {
        $locale = in_array($locale, ['fa', 'en', 'tr'], true) ? $locale : 'fa';
        $base = 'https://servernet.cloud';
        $prefix = $locale === 'fa' ? '' : '/'.$locale;
        $contact = site_contact($locale);
        $products = [];
        foreach (['hosting', 'vps', 'dedicated', 'cloud', 'domain', 'services'] as $category) {
            $items = $category === 'hosting' ? config('hosting.products', []) : config('catalog.'.$category, []);
            foreach ((array) $items as $slug => $item) {
                if (! preg_match('/^[a-z0-9-]+$/D', (string) $slug)) { continue; }
                $title = $item[$locale]['t'] ?? $item['fa']['t'] ?? $item['en']['t'] ?? $slug;
                if (! is_string($title)) { continue; }
                $products[] = ['title' => self::clean($title, 120), 'url' => $base.$prefix.'/'.$category.'/'.$slug];
            }
        }
        $pages = [
            'account' => 'https://console.servernet.cloud',
            'support_tickets' => 'https://console.servernet.cloud/account/tickets',
            'contact' => $base.$prefix.'/contact', 'documentation' => $base.$prefix.'/docs',
            'cloud' => $base.$prefix.'/cloud', 'hourly_vps' => $base.$prefix.'/vps/hourly',
            'gpu' => $base.$prefix.'/gpu', 'domains' => $base.$prefix.'/domains',
            'ai_gateway' => $base.$prefix.'/ai', 'ai_models' => $base.$prefix.'/ai/models',
            'ai_api_docs' => $base.$prefix.'/ai/docs',
            'transactional_email' => $base.$prefix.'/transactional-email',
            'physical_servers' => $base.$prefix.'/servers', 'server_parts' => $base.$prefix.'/parts',
            'service_status' => $base.$prefix.'/status', 'terms' => $base.$prefix.'/terms',
            'acceptable_use' => $base.$prefix.'/aup',
        ];
        $documents = [];
        $available = true;
        try {
            $candidates = [];
            foreach (Post::query()->where('type', 'kb')->where('status', 'published')
                ->where(fn ($q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()))
                ->with('translations')->orderByDesc('updated_at')->limit(200)->get() as $post) {
                if (! preg_match('/^[a-z0-9-]+$/D', (string) $post->slug)) { continue; }
                $translation = $post->tr($locale);
                if (! $translation) { continue; }
                $candidates[] = ['title' => self::clean((string) $translation->title, 180),
                    'text' => self::clean((string) $translation->content, 12000),
                    'url' => $base.$prefix.'/docs/'.$post->slug];
            }
            $documents = self::relevant($message, $candidates);
        } catch (\Throwable) {
            $available = false;
        }
        return [
            'source' => 'ServerNet published website catalogue, public contact and published documentation',
            'as_of' => now()->toIso8601String(), 'locale' => $locale,
            'contact' => array_intersect_key($contact, array_flip(['phone', 'phone_link', 'email', 'sales_email'])),
            'pages' => $pages, 'products' => array_slice($products, 0, 90),
            'documentation_available' => $available, 'documents' => $documents,
            'limitations' => 'Public product descriptions are not proof of current stock, exact price or customer service health. No private account, ticket, invoice, server or Hermes owner memory is included.',
        ];
    }

    public static function clean(string $text, int $limit): string
    {
        $text = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', ' ', $text) ?? '';
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('~-----BEGIN [^-]*PRIVATE KEY-----.*?-----END [^-]*PRIVATE KEY-----~s', '[redacted]', $text) ?? '';
        $text = preg_replace('~(?:vless|vmess|trojan|ss)://\S+~iu', '[redacted]', $text) ?? '';
        $text = preg_replace('~\b(?:Bearer\s+\S+|sn_[a-f0-9]{48}|sk-[a-zA-Z0-9_-]{16,})~i', '[redacted]', $text) ?? '';
        $text = preg_replace('~(?:password|passwd|api[_ -]?key|token|secret|رمز|گذرواژه|توکن)\s*[:=]\s*\S+~iu', '[redacted]', $text) ?? '';
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $text) ?? ''), 0, $limit);
    }

    public static function relevant(string $query, array $documents): array
    {
        $normalize = static fn (string $s): string => str_replace(['ي', 'ك', "\u{200C}"], ['ی', 'ک', ' '], mb_strtolower($s));
        $terms = array_values(array_unique(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $normalize($query)) ?: [],
            static fn ($s) => mb_strlen($s) >= 3 && ! in_array($s, ['سلام','برای','چطور','چگونه','دارم','لطفا','لطفاً','این','است','the','with','what','how','please','have','bir','için'], true))));
        $terms = array_slice($terms, 0, 16);
        preg_match_all('/\b(?:n8n|smtp|rdp|ssh|dns|ssl|tls|cpanel|whm|plesk|docker|wordpress|kubernetes)\b/iu', $query, $anchors);
        $anchors = array_unique(array_map($normalize, $anchors[0]));
        $ranked = [];
        foreach ($documents as $document) {
            $title = $normalize((string) ($document['title'] ?? ''));
            $text = $normalize((string) ($document['text'] ?? ''));
            if ($anchors !== [] && ! array_filter($anchors, static fn ($anchor) => preg_match('/(?<![a-z0-9])'.preg_quote($anchor, '/').'(?![a-z0-9])/u', $title.' '.$text))) {
                continue;
            }
            $score = 0; $first = null;
            foreach ($terms as $term) {
                if (mb_strpos($title, $term) !== false) { $score += 4; }
                if (($pos = mb_strpos($text, $term)) !== false) { $score++; $first ??= $pos; }
            }
            if ($score < 2) { continue; }
            $ranked[] = ['score' => $score, 'title' => $document['title'], 'url' => $document['url'],
                'text' => self::clean(mb_substr((string) $document['text'], max(0, ($first ?? 0) - 180), 1500), 1500)];
        }
        usort($ranked, static fn ($a, $b) => ($b['score'] <=> $a['score']) ?: strcmp($a['url'], $b['url']));
        return array_map(static function ($row) { unset($row['score']); return $row; }, array_slice($ranked, 0, 3));
    }
}
