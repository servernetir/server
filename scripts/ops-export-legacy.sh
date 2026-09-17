#!/usr/bin/env bash
#
# خروجیِ فقط‌خواندنی برای ساختِ نقشهٔ ریدایرکتِ آدرس‌های قدیمی.
#
#   K=<کلید> bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/<SHA>/scripts/ops-export-legacy.sh)
#
# 🟢 فقط SELECT و خواندنِ فایل. هیچ نوشتنی در دیتابیس یا اپ.
#    دو چیز بیرون می‌آید: فهرستِ پست‌ها (شناسه، نامک، نوع، عنوانِ سه زبان) و
#    ردیف‌های ردیابِ ۴۰۴ (فقط آدرس، ارجاع‌دهنده و زمان — بی IP و بی کاربر).
#    خروجی gzip + AES-256 با کلیدِ تصادفی، با نامِ تصادفی در public_html
#    می‌نشیند تا برداشته شود؛ بعد باید پاک شود (دستورش چاپ می‌شود).
#
set -u
APP="$HOME/servernet_app"
[ -f "$APP/artisan" ] && [ -d "$APP/vendor" ] || { echo "🔴 نصبِ لاراول نیست — کاربرِ درست: servernetcloud"; exit 1; }
[ -n "${K:-}" ] || { echo "🔴 کلیدِ K داده نشده"; exit 1; }
PHPBIN=/opt/cpanel/ea-php84/root/usr/bin/php; [ -x "$PHPBIN" ] || PHPBIN=$(command -v php)
OUT="$HOME/.exp-$$.json"; PROBE="$HOME/.exp-$$.php"
trap 'rm -f "$PROBE" "$OUT" "$OUT.gz"' EXIT

cat > "$PROBE" << 'PHPEOF'
<?php
$root = getcwd();
require $root.'/vendor/autoload.php';
$app = require_once $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$posts = [];
foreach (\App\Models\Post::query()->with('translations')->orderBy('id')->get() as $p) {
    $t = [];
    foreach ($p->translations as $tr) {
        $t[(string) $tr->locale] = (string) $tr->title;
    }
    $posts[] = ['id' => $p->id, 'slug' => $p->slug, 'type' => $p->type, 'cat' => $p->category,
        'status' => $p->status, 'pub' => (string) $p->published_at, 't' => $t];
}

$nf = [];
$f = storage_path('logs/tracker-404.jsonl');
$keys = [];
if (is_file($f)) {
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $r = json_decode($line, true);
        if (! is_array($r)) { continue; }
        $keys += array_flip(array_keys($r));
        $nf[] = ['url' => $r['url'] ?? null, 'ref' => $r['referer'] ?? $r['ref'] ?? null,
            'at' => $r['at'] ?? $r['time'] ?? $r['ts'] ?? null, 'm' => $r['method'] ?? null];
    }
}

echo json_encode(['posts' => $posts, 'notfound' => $nf, 'nf_keys' => array_keys($keys),
    'generated' => (string) now()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
PHPEOF

cd "$APP" || exit 1
"$PHPBIN" "$PROBE" > "$OUT" || { echo "🔴 خروجی ساخته نشد"; exit 1; }
gzip -9 -c "$OUT" > "$OUT.gz"
NAME="exp-$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n').bin"
openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt -pass env:K -in "$OUT.gz" -out "$HOME/public_html/$NAME" || { echo "🔴 رمزنگاری نشد"; exit 1; }
chmod 644 "$HOME/public_html/$NAME"
echo "READY $NAME  ($(wc -c < "$OUT") bytes)"
echo "بعد از دانلود:  rm -f ~/public_html/$NAME"
