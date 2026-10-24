<?php

namespace App\Services\Shop;

use App\Models\PhysicalServer;
use App\Models\ServerPart;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class HardwareCatalogImport
{
    public function apply(string $manifest, string $receipt, bool $write = false): array
    {
        $records = json_decode(file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
        if (!Schema::hasTable('physical_servers') || !Schema::hasTable('server_parts')) {
            throw new RuntimeException('The shop tables must already exist; no automatic migrations are allowed.');
        }
        if ($write && file_exists($receipt)) {
            throw new RuntimeException('An import receipt already exists. Inspect it before any further action.');
        }
        $seen = [];
        $plan = [];
        foreach ($records as $record) {
            $slug = $record['slug'] ?? '';
            if (!is_string($slug) || preg_match('/^[a-z0-9-]{1,230}$/D', $slug) !== 1 || isset($seen[$slug])) {
                throw new RuntimeException('Invalid or duplicate catalog identity.');
            }
            $seen[$slug] = true;
            $class = match ($record['kind'] ?? '') {
                'server' => PhysicalServer::class, 'part' => ServerPart::class,
                default => throw new RuntimeException('Unsupported catalog model.'),
            };
            $model = $class::where('slug', $slug)->first();
            if (isset($record['existing_id'])) {
                if (!$model || $model->id !== $record['existing_id']) {
                    throw new RuntimeException('Existing product identity changed: '.$slug);
                }
                $patch = $record['patch'] ?? [];
                if (array_diff(array_keys($patch), ['gallery', 'body', 'attrs', 'specs']) !== []) {
                    throw new RuntimeException('Existing-product updates are restricted to reviewed content.');
                }
                foreach ($patch as $field => $value) {
                    if ($model->{$field} != ($record['before'][$field] ?? null)) {
                        throw new RuntimeException('Concurrent product content change: '.$slug.'/'.$field);
                    }
                }
                if ($patch !== []) $plan[] = ['class' => $class, 'record' => $record, 'existing' => true];
            } else {
                if ($model) throw new RuntimeException('A new catalog identity already exists: '.$slug);
                $data = $record['data'] ?? [];
                if (($data['condition'] ?? null) !== 'catalog' || ($data['active'] ?? null) !== true
                    || ($data['price_contact'] ?? null) !== true || isset($data['price_irt']) || isset($data['price_eur'])
                    || ($class === ServerPart::class && (($data['in_stock'] ?? null) !== false || !array_key_exists($data['category'] ?? '', ServerPart::CATEGORIES)))) {
                    throw new RuntimeException('New products must be quote-only catalog entries.');
                }
                foreach (['fa', 'en', 'tr'] as $locale) {
                    if (empty($data['name'][$locale]) || empty($data['body'][$locale])) {
                        throw new RuntimeException('Missing localized content: '.$slug);
                    }
                }
                $plan[] = ['class' => $class, 'record' => $record, 'existing' => false];
            }
            $images = $record['patch']['gallery'] ?? $record['data']['gallery'] ?? [];
            foreach ($images as $image) {
                if (!is_string($image) || !str_starts_with($image, '/assets/hardware/')
                    || str_contains($image, '..') || !public_asset_path(ltrim($image, '/'))) {
                    throw new RuntimeException('A reviewed product asset is missing: '.$slug);
                }
            }
        }
        $summary = ['records' => count($records), 'insert' => count(array_filter($plan, fn ($p) => !$p['existing'])), 'enrich' => count(array_filter($plan, fn ($p) => $p['existing'])), 'applied' => $write];
        if (!$write) return $summary;
        if (!is_dir(dirname($receipt)) || is_link($receipt)) throw new RuntimeException('A private receipt directory is required.');

        $changes = [];
        DB::transaction(function () use ($plan, &$changes, $receipt, $manifest): void {
            foreach ($plan as $item) {
                $record = $item['record'];
                $class = $item['class'];
                if ($item['existing']) {
                    $model = $class::where('slug', $record['slug'])->lockForUpdate()->firstOrFail();
                    foreach ($record['patch'] as $field => $value) {
                        if ($model->{$field} != ($record['before'][$field] ?? null)) throw new RuntimeException('Content drift during import: '.$record['slug']);
                    }
                    $before = array_intersect_key($model->toArray(), $record['patch']);
                    $model->fill($record['patch'])->saveQuietly();
                    $after = array_intersect_key($model->fresh()->toArray(), $record['patch']);
                    $changes[] = ['kind' => $record['kind'], 'slug' => $record['slug'], 'id' => $model->id, 'inserted' => false, 'before' => $before, 'after' => $after];
                } else {
                    if ($class::where('slug', $record['slug'])->lockForUpdate()->exists()) throw new RuntimeException('Concurrent product creation: '.$record['slug']);
                    $model = new $class(['slug' => $record['slug']] + $record['data']);
                    $model->saveQuietly();
                    $changes[] = ['kind' => $record['kind'], 'slug' => $record['slug'], 'id' => $model->id, 'inserted' => true, 'fingerprint' => self::fingerprint($model->fresh()->toArray())];
                }
            }
            self::receipt($receipt, ['status' => 'prepared', 'manifest_sha256' => hash_file('sha256', $manifest), 'changes' => $changes]);
        });
        self::receipt($receipt, ['status' => 'installed', 'manifest_sha256' => hash_file('sha256', $manifest), 'installed_at' => now()->toAtomString(), 'changes' => $changes]);
        app(PartsCatalog::class)->flush();
        \App\Http\Middleware\PageCache::purge();
        return $summary;
    }

    public function rollback(string $receipt): array
    {
        $data = json_decode(file_get_contents($receipt), true, 512, JSON_THROW_ON_ERROR);
        if (($data['status'] ?? '') !== 'installed') throw new RuntimeException('Only a verified installed receipt can be rolled back.');
        DB::transaction(function () use ($data): void {
            $models = [];
            foreach ($data['changes'] as $change) {
                $class = $change['kind'] === 'server' ? PhysicalServer::class : ServerPart::class;
                $model = $class::where('slug', $change['slug'])->lockForUpdate()->firstOrFail();
                if ($model->id !== $change['id']) throw new RuntimeException('Product identity drift prevents rollback.');
                if ($change['inserted']) {
                    if (self::fingerprint($model->toArray()) !== $change['fingerprint']) throw new RuntimeException('Newer edits prevent product rollback: '.$change['slug']);
                } else {
                    foreach ($change['after'] as $field => $value) if ($model->{$field} != $value) throw new RuntimeException('Newer content prevents rollback: '.$change['slug']);
                }
                $models[] = [$model, $change];
            }
            foreach (array_reverse($models) as [$model, $change]) {
                if ($change['inserted']) $model->deleteQuietly();
                else $model->fill($change['before'])->saveQuietly();
            }
        });
        $data['status'] = 'rolled_back';
        $data['rolled_back_at'] = now()->toAtomString();
        self::receipt($receipt, $data);
        app(PartsCatalog::class)->flush();
        \App\Http\Middleware\PageCache::purge();
        return ['rolled_back' => count($data['changes'])];
    }

    private static function receipt(string $path, array $data): void
    {
        $temporary = $path.'.tmp';
        if (file_put_contents($temporary, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX) === false) throw new RuntimeException('Receipt write failed.');
        chmod($temporary, 0600);
        if (!rename($temporary, $path)) throw new RuntimeException('Receipt installation failed.');
    }

    private static function fingerprint(array $data): string
    {
        $sort = function (&$value) use (&$sort): void {
            if (!is_array($value)) return;
            if (!array_is_list($value)) ksort($value);
            foreach ($value as &$child) $sort($child);
        };
        $sort($data);
        return hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
