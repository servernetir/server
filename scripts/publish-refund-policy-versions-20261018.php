<?php
// Append current terms only. Do not re-run the broad seeder or rewrite acceptances.
require '/home/servernetcloud/servernet_app/vendor/autoload.php';
$app = require '/home/servernetcloud/servernet_app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$packet = '/home/servernetcloud/refund-policy-20261018';
if (is_file($packet.'/LEGAL-PUBLISHED.json')) {
    throw new RuntimeException('Existing publication receipt: inspect before continuing');
}
$db = Illuminate\Support\Facades\DB::class;
$old = $db::table('legal_documents')->where('kind', 'terms')->orderBy('id')->get();
file_put_contents($packet.'/legal-terms-before.json', json_encode($old, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
chmod($packet.'/legal-terms-before.json', 0600);
$acceptanceCount = $db::table('legal_acceptances')->count();
$page = config('pages.terms');
$new = [];
$db::transaction(function () use ($db, $page, &$new) {
    foreach (['fa', 'en', 'tr'] as $locale) {
        $parts = [];
        foreach (['h1a', 'h1b', 'lead'] as $key) {
            if (filled($page[$locale][$key] ?? null)) $parts[] = trim($page[$locale][$key]);
        }
        foreach ($page['sections'] as $section) {
            foreach (['t', 'b'] as $key) {
                if (filled($section[$locale][$key] ?? null)) $parts[] = trim($section[$locale][$key]);
            }
        }
        $body = trim(implode("\n\n", array_filter($parts)));
        $sha = hash('sha256', $body);
        $key = ['kind' => 'terms', 'version' => substr($sha, 0, 12), 'locale' => $locale];
        $existing = $db::table('legal_documents')->where($key)->first();
        if ($existing && $existing->sha256 !== $sha) throw new RuntimeException('Version collision');
        if (! $existing) {
            $db::table('legal_documents')->insert($key + ['body' => $body, 'sha256' => $sha,
                'published_at' => now(), 'updated_at' => now(), 'created_at' => now()]);
        }
        $row = $db::table('legal_documents')->where($key)->first();
        $new[] = ['id' => $row->id, 'locale' => $locale, 'version' => $row->version, 'inserted' => ! $existing];
    }
});
foreach ($old as $row) {
    $after = $db::table('legal_documents')->where('id', $row->id)->first();
    if (json_encode($row) !== json_encode($after)) throw new RuntimeException('Historical document changed');
}
$receipt = ['documents' => $new, 'old_documents_unchanged' => count($old),
    'acceptances_before' => $acceptanceCount, 'acceptances_after' => $db::table('legal_acceptances')->count()];
file_put_contents($packet.'/LEGAL-PUBLISHED.json', json_encode($receipt, JSON_PRETTY_PRINT));
chmod($packet.'/LEGAL-PUBLISHED.json', 0600);
echo json_encode($receipt, JSON_UNESCAPED_UNICODE).PHP_EOL;
