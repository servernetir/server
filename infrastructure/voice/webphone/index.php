<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
try {
    $context = sn_voice_bootstrap();
    $extensions = sn_voice_owned_extensions($context);
    $csrf = (new token)->create(SN_VOICE_TOKEN_KEY);
} catch (Throwable $error) {
    http_response_code(503);
    exit('Temporarily unavailable');
}
$lang = is_string($_GET['lang'] ?? null) && in_array($_GET['lang'], ['fa', 'en', 'tr'], true) ? $_GET['lang'] : 'fa';
$labels = [
    'fa' => ['title'=>'تلفن ابری سرورنت','eyebrow'=>'فضای تماس شما','intro'=>'با داخلی خود از همین مرورگر تماس بگیرید.','extension'=>'داخلی من','connect'=>'اتصال','disconnect'=>'قطع اتصال','offline'=>'آفلاین','ready'=>'آمادهٔ تماس','number'=>'شمارهٔ مقصد','placeholder'=>'داخلی یا شمارهٔ تلفن','call'=>'تماس','hangup'=>'پایان تماس','mute'=>'بی‌صدا','answer'=>'پاسخ','reject'=>'رد تماس','keypad'=>'شماره‌گیر','note'=>'مجوز میکروفون هنگام برقراری تماس یا پاسخ درخواست می‌شود. برای دریافت تماس، این صفحه و اتصال را باز نگه دارید.','privacy'=>'اطلاعات اتصال فقط در حافظهٔ همین صفحه نگهداری می‌شود.','empty'=>'داخلی فعالی به حساب شما متصل نیست.','back'=>'پنل تلفن','incoming'=>'تماس‌ها و وضعیت اتصال'],
    'en' => ['title'=>'ServerNet Voice','eyebrow'=>'Your calling space','intro'=>'Call from this browser using your own extension.','extension'=>'My extension','connect'=>'Connect','disconnect'=>'Disconnect','offline'=>'Offline','ready'=>'Ready to call','number'=>'Destination','placeholder'=>'Extension or phone number','call'=>'Call','hangup'=>'Hang up','mute'=>'Mute','answer'=>'Answer','reject'=>'Reject','keypad'=>'Dial pad','note'=>'Microphone permission is requested when you call or answer. Keep this page connected to receive calls.','privacy'=>'Connection credentials remain in this page’s memory only.','empty'=>'No active extension is linked to your account.','back'=>'Phone panel','incoming'=>'Calls and connection status'],
    'tr' => ['title'=>'ServerNet Voice','eyebrow'=>'Arama alanınız','intro'=>'Kendi dahili numaranızla bu tarayıcıdan arayın.','extension'=>'Dahili numaram','connect'=>'Bağlan','disconnect'=>'Bağlantıyı kes','offline'=>'Çevrimdışı','ready'=>'Aramaya hazır','number'=>'Hedef numara','placeholder'=>'Dahili veya telefon numarası','call'=>'Ara','hangup'=>'Aramayı bitir','mute'=>'Sessiz','answer'=>'Yanıtla','reject'=>'Reddet','keypad'=>'Tuş takımı','note'=>'Arama veya yanıtlama sırasında mikrofon izni istenir. Aramaları almak için bu sayfayı bağlı tutun.','privacy'=>'Bağlantı bilgileri yalnızca bu sayfanın belleğinde tutulur.','empty'=>'Hesabınıza bağlı etkin bir dahili numara yok.','back'=>'Telefon paneli','incoming'=>'Aramalar ve bağlantı durumu'],
][$lang];
function sn_voice_escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function sn_voice_label(string $key): string { global $labels; return sn_voice_escape($labels[$key]); }
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="<?= $lang ?>" dir="<?= $lang === 'fa' ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title><?= sn_voice_label('title') ?></title>
    <link rel="stylesheet" href="phone.css">
    <script src="vendor/jssip-3.10.0.min.js" integrity="sha256-BtNmGQf0P9Js7XcUm8u9bUFqco2uRiE6HBDM7oqJaYs=" defer></script>
    <script src="phone.js" defer></script>
</head>
<body>
<main id="voice-app" class="voice-shell" data-lang="<?= $lang ?>" data-token-name="<?= sn_voice_escape($csrf['name']) ?>" data-token-hash="<?= sn_voice_escape($csrf['hash']) ?>">
    <header class="voice-header">
        <a class="voice-brand" href="index.php"><span class="voice-logo" aria-hidden="true">S</span><span>ServerNet <strong>Voice</strong></span></a>
        <nav aria-label="Language"><a href="?lang=fa" lang="fa">فارسی</a><a href="?lang=en" lang="en">EN</a><a href="?lang=tr" lang="tr">TR</a><a href="../../"><?= sn_voice_label('back') ?></a></nav>
    </header>
    <div class="voice-intro"><p class="voice-kicker"><?= sn_voice_label('eyebrow') ?></p><h1><?= sn_voice_label('title') ?></h1><p><?= sn_voice_label('intro') ?></p></div>
    <div class="voice-layout">
        <section class="voice-card" aria-labelledby="connection-title">
            <div class="voice-card-top"><h2 id="connection-title"><?= sn_voice_label('extension') ?></h2><span id="status" class="voice-badge" role="status" aria-live="polite"><?= sn_voice_label('offline') ?></span></div>
            <label for="extension"><?= sn_voice_label('extension') ?></label>
            <select id="extension" autocomplete="off">
                <?php foreach ($extensions as $extension): ?>
                <option value="<?= sn_voice_escape($extension['extension_uuid']) ?>"><?= sn_voice_escape($extension['extension']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (!$extensions): ?><p class="voice-note"><?= sn_voice_label('empty') ?></p><?php endif; ?>
            <div class="voice-control-row"><button id="connect" type="button" <?= !$extensions ? 'disabled' : '' ?>><?= sn_voice_label('connect') ?></button><button id="disconnect" type="button" class="secondary" disabled><?= sn_voice_label('disconnect') ?></button></div>
            <p class="voice-note"><?= sn_voice_label('privacy') ?></p>
        </section>
        <section class="voice-card voice-call-card" aria-labelledby="call-title">
            <div class="voice-card-top"><h2 id="call-title"><?= sn_voice_label('incoming') ?></h2><span id="call-status" role="status" aria-live="polite"><?= sn_voice_label('ready') ?></span></div>
            <p id="remote-number" dir="ltr" aria-live="polite"></p>
            <label for="destination"><?= sn_voice_label('number') ?></label>
            <input id="destination" type="tel" inputmode="tel" autocomplete="off" maxlength="24" dir="ltr" placeholder="<?= sn_voice_label('placeholder') ?>">
            <div class="voice-control-row"><button id="call" type="button" disabled><?= sn_voice_label('call') ?></button><button id="hangup" type="button" class="danger" disabled><?= sn_voice_label('hangup') ?></button><button id="mute" type="button" class="secondary" aria-pressed="false" disabled><?= sn_voice_label('mute') ?></button></div>
            <div class="voice-control-row"><button id="answer" type="button" hidden><?= sn_voice_label('answer') ?></button><button id="reject" type="button" class="danger" hidden><?= sn_voice_label('reject') ?></button></div>
            <div id="dtmf" class="voice-dialpad" role="group" aria-label="<?= sn_voice_label('keypad') ?>">
                <?php foreach (['1','2','3','4','5','6','7','8','9','*','0','#'] as $tone): ?><button type="button" data-tone="<?= $tone ?>" disabled><?= $tone ?></button><?php endforeach; ?>
            </div>
        </section>
    </div>
    <footer class="voice-note"><?= sn_voice_label('note') ?></footer>
    <audio id="remote-audio" autoplay playsinline></audio>
</main>
</body>
</html>
