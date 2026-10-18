<?php
require '/home/servernetcloud/servernet_app/vendor/autoload.php';
$app = require '/home/servernetcloud/servernet_app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$pattern = '/بازگشت\s*(?:و?جه|پول)|عودت\s*(?:وجه|مبلغ)|money[ -]back|14[ -]day|14 gün|para iade garantisi/iu';
$hits = [];
$posts = 0;
foreach (App\Models\Post::published()->with('translations')->get() as $post) {
    $posts++;
    foreach ($post->translations as $translation) {
        $text = strip_tags($translation->title.' '.$translation->excerpt.' '.$translation->content);
        if (preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE)) {
            $offset = max(0, $match[0][1] - 160);
            $hits[] = ['kind' => 'post', 'slug' => $post->slug, 'type' => $post->type,
                'locale' => $translation->locale, 'context' => mb_strcut($text, $offset, 700, 'UTF-8')];
        }
    }
}
$products = App\Models\Product::where('is_active', true)->whereIn('category', ['vps', 'dedicated'])->get();
foreach ($products as $product) {
    $text = strip_tags((string) $product->description).' '.json_encode($product->specs, JSON_UNESCAPED_UNICODE);
    if (preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE)) {
        $hits[] = ['kind' => 'product', 'slug' => $product->slug,
            'context' => mb_strcut($text, max(0, $match[0][1] - 160), 700, 'UTF-8')];
    }
}
$report = ['published_posts' => $posts, 'active_server_products' => count($products), 'hits' => $hits];
file_put_contents(__DIR__.'/PUBLIC-CONTENT-AUDIT.json', json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
chmod(__DIR__.'/PUBLIC-CONTENT-AUDIT.json', 0600);
echo json_encode($report, JSON_UNESCAPED_UNICODE).PHP_EOL;
