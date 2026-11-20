<?php

namespace App\Services\Legal;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** The accepted text must match the public page, including its language. */
class LegalDocuments
{
    public function body(string $kind, string $locale): string
    {
        $page = config('pages.'.$kind);
        if (! in_array($locale, ['fa', 'en', 'tr'], true) || ! is_array($page)) {
            throw new RuntimeException('Legal document configuration unavailable.');
        }

        $parts = [];
        foreach (['h1a', 'h1b', 'lead'] as $key) {
            $parts[] = trim((string) ($page[$locale][$key] ?? ''));
        }
        foreach ($page['sections'] ?? [] as $section) {
            foreach (['t', 'b'] as $key) {
                $parts[] = trim((string) ($section[$locale][$key] ?? ''));
            }
        }
        $body = implode("\n\n", array_filter($parts, fn ($part) => $part !== ''));
        if (mb_strlen($body) < 80 || empty($page['sections'])) {
            throw new RuntimeException('Legal document text unavailable.');
        }

        return $body;
    }

    /** Append missing versions; never rewrite historical text or timestamps. */
    public function publishCurrent(string $locale): Collection
    {
        return DB::transaction(function () use ($locale) {
            $documents = collect();
            foreach (['terms', 'privacy'] as $kind) {
                $body = $this->body($kind, $locale);
                $sha = hash('sha256', $body);
                $key = ['kind' => $kind, 'locale' => $locale, 'version' => substr($sha, 0, 12)];
                DB::table('legal_documents')->insertOrIgnore($key + [
                    'body' => $body, 'sha256' => $sha, 'published_at' => now(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $document = DB::table('legal_documents')->where($key)->first();
                if (! $document || ! $document->published_at || $document->body !== $body
                    || ! hash_equals($sha, $document->sha256)) {
                    throw new RuntimeException('Legal document version could not be verified.');
                }
                $documents->push($document);
            }

            return $documents;
        });
    }
}
