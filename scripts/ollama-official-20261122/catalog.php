<?php

// Invoked only by the owner-approved scoped release, from the private release directory.
use App\Models\CloudImage;
use App\Models\Setting;
use App\Services\Cloud\SaladClient;
use App\Services\Cloud\SaladOllamaImage;
use Illuminate\Support\Facades\DB;

$root = $argv[1] ?? '';
$backup = $argv[2] ?? '';
if (! is_dir($root) || ! is_file($root.'/artisan') || ! is_dir($backup) || is_link($backup)) {
    throw new RuntimeException('invalid_release_paths');
}
chdir($root);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$approved = app(SaladOllamaImage::class);
if (! preg_match('/\Aollama\/ollama:\d+\.\d+\.\d+(?:@sha256:[a-f0-9]{64})?\z/', $approved->ref())
    || version_compare($approved->tag(), SaladOllamaImage::FLOOR, '<')) {
    throw new RuntimeException('approved_runtime_not_loaded');
}
$result = DB::transaction(function () use ($backup) {
    $rows = CloudImage::where('provider', 'salad')->where('key', 'gpu-ollama')->lockForUpdate()->get();
    $legacy = $rows->filter(fn ($row) => SaladOllamaImage::isOurs($row->provider_ref));
    $source = $legacy->firstWhere('is_active', true) ?? $legacy->first();
    if ($source === null) { throw new RuntimeException('existing_ollama_catalog_required'); }
    $snapshot = ['setting' => Setting::get(SaladOllamaImage::SETTING),
        'stable_release' => Setting::get(SaladOllamaImage::RELEASE_SETTING),
        'checked_at' => Setting::get('salad_ollama_stable_checked_at'), 'images' => $legacy->map(fn ($row) => [
        'id' => $row->id, 'provider_ref' => $row->provider_ref, 'is_active' => $row->is_active, 'version' => $row->version,
    ])->all()];
    $snapshotPath = $backup.'/ollama-catalog-before.json';
    $handle = fopen($snapshotPath, 'x');
    if ($handle === false) { throw new RuntimeException('snapshot_exists_or_unwritable'); }
    chmod($snapshotPath, 0600);
    fwrite($handle, json_encode($snapshot, JSON_THROW_ON_ERROR));
    fclose($handle);
    $ref = app(SaladOllamaImage::class)->ref();
    $image = $legacy->firstWhere('provider_ref', $ref);
    if ($image === null) {
        $image = $source->replicate();
        $image->provider_ref = $ref;
    }
    $image->is_active = $legacy->contains(fn ($row) => $row->is_active) && ! SaladClient::salesPaused('gpu-ollama');
    $image->version = app(SaladOllamaImage::class)->tag();
    $image->save();
    foreach ($legacy as $old) {
        if ($old->id !== $image->id && $old->is_active) { $old->update(['is_active' => false]); }
    }
    app(SaladOllamaImage::class)->refresh();
    return ['image_id' => $image->id, 'image' => $ref, 'active' => $image->is_active,
        'existing_groups_modified' => 0];
});
echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
