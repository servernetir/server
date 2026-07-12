<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * دستیار هوشمند سرورنت (fa / en / tr).
 *
 * منبع اصلی پاسخ: ورک‌فلوی n8n «ServerNet AI Assistant» (ایجنت LLM با
 * حافظه مکالمه و ثبت سرنخ فروش). اگر n8n در دسترس نبود یا خطا داد،
 * پاسخ‌های rule-based همین کلاس به‌عنوان fallback برمی‌گردند.
 */
class ChatController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|max:1000',
            'session' => 'nullable|string|max:64',
        ]);

        $locale = in_array(app()->getLocale(), ['fa', 'en', 'tr']) ? app()->getLocale() : 'en';

        // ۱) دستیار هوشمند n8n
        if ($webhook = config('services.n8n.chat_webhook')) {
            $reply = $this->askN8n($webhook, $validated['message'], $locale, $validated['session'] ?? null);
            if (is_string($reply) && trim($reply) !== '') {
                return response()->json(['reply' => $reply, 'actions' => []]);
            }
        }

        // ۲) fallback: پاسخ‌های آماده
        [$reply, $actions] = $this->reply(Str::lower(trim($validated['message'])), $locale);

        return response()->json(['reply' => $reply, 'actions' => $actions]);
    }

    private function askN8n(string $url, string $message, string $locale, ?string $session): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode([
                'message' => $message,
                'locale'  => $locale,
                'session' => $session ?: 'anon-'.substr(md5($message.microtime()), 0, 12),
            ], JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 50,
            CURLOPT_CONNECTTIMEOUT => 15, // اتصال اولیه از پشت VPN گاهی کند است
        ]);
        $raw = curl_exec($ch);

        if ($raw === false) {
            Log::warning('n8n chat webhook: '.curl_error($ch));

            return null;
        }

        $json = json_decode($raw, true);

        return is_array($json) ? ($json['reply'] ?? null) : null;
    }

    private function reply(string $msg, string $loc): array
    {
        $c = config('servernet.contact');

        $rules = [
            'vps|سرور مجازی|مجازی|sanal sunucu|sanal' => [
                'msg' => [
                    'fa' => "سرورهای مجازی ما با هارد NVMe و تحویل آنی در لوکیشن‌های ایران و اروپا ارائه می‌شوند.\nمی‌توانید همین حالا پلن‌ها را ببینید:",
                    'en' => 'Our VPS servers ship with NVMe storage and instant deployment in Iran & Europe.',
                    'tr' => 'VPS sunucularımız NVMe depolama ile İran ve Avrupa lokasyonlarında anında kurulur.',
                ],
                'actions' => [['label' => ['fa' => 'مشاهده پلن‌های VPS', 'en' => 'View VPS plans', 'tr' => 'VPS paketlerini gör'], 'url' => '#pricing']],
            ],
            'اختصاصی|dedicated|fiziksel' => [
                'msg' => [
                    'fa' => 'سرور اختصاصی در ایران، آلمان، فرانسه، کانادا و هلند داریم و معمولاً ظرف ۲۴ ساعت تحویل می‌شود. برای مشاوره و کانفیگ دلخواه با تیم فروش صحبت کنید:',
                    'en' => 'We offer dedicated servers in Iran, Germany, France, Canada & the Netherlands, typically delivered within 24 hours.',
                    'tr' => "İran, Almanya, Fransa, Kanada ve Hollanda'da fiziksel sunucularımız var; genellikle 24 saat içinde teslim edilir.",
                ],
                'actions' => [['label' => ['fa' => 'تماس با فروش', 'en' => 'Contact sales', 'tr' => 'Satışla iletişim'], 'url' => 'mailto:'.$c['sales_email']]],
            ],
            'هاست|هاستینگ|hosting|وردپرس|wordpress' => [
                'msg' => [
                    'fa' => 'هاست لینوکس NVMe و هاست وردپرس بهینه‌شده داریم — هر دو با SSL رایگان و بکاپ روزانه.',
                    'en' => 'NVMe Linux hosting and optimized WordPress hosting — both with free SSL and daily backups.',
                    'tr' => 'NVMe Linux hosting ve optimize WordPress hosting — ikisi de ücretsiz SSL ve günlük yedeklemeyle.',
                ],
                'actions' => [['label' => ['fa' => 'مشاهده سرویس‌های هاست', 'en' => 'See hosting plans', 'tr' => 'Hosting paketlerini gör'], 'url' => '#products']],
            ],
            'دامنه|domain|alan ad' => [
                'msg' => [
                    'fa' => 'بیش از ۴۰۰ پسوند دامنه با مدیریت DNS رایگان ثبت می‌کنیم. همین بالا در باکس جستجو، دامنه دلخواهتان را چک کنید!',
                    'en' => 'We register 400+ TLDs with free DNS management. Try the domain search box above!',
                    'tr' => "400'den fazla uzantıyı ücretsiz DNS yönetimiyle kaydediyoruz. Yukarıdaki arama kutusunu deneyin!",
                ],
                'actions' => [],
            ],
            'قیمت|تعرفه|price|cost|fiyat|ücret' => [
                'msg' => [
                    'fa' => 'همه‌ی قیمت‌ها شفاف روی سایت هستند و مستقیم از سیستم فروش ما خوانده می‌شوند. کاربران ایرانی به ریال و بین‌المللی به یورو پرداخت می‌کنند.',
                    'en' => 'All pricing is transparent on the site, read live from our billing system. Pay in EUR internationally.',
                    'tr' => 'Tüm fiyatlar sitede şeffaftır ve faturalama sistemimizden canlı okunur. Uluslararası ödemeler EUR iledir.',
                ],
                'actions' => [['label' => ['fa' => 'مشاهده تعرفه‌ها', 'en' => 'View pricing', 'tr' => 'Fiyatları gör'], 'url' => '#pricing']],
            ],
            'انتقال|مهاجرت|migrat|taşı' => [
                'msg' => [
                    'fa' => 'انتقال سایت و سرور از هر شرکت دیگری، کاملاً رایگان و بدون قطعی توسط تیم ما انجام می‌شود. فقط کافی است تیکت بزنید.',
                    'en' => 'We migrate your sites and servers from any provider — free of charge with zero-downtime planning.',
                    'tr' => 'Sitelerinizi ve sunucularınızı her sağlayıcıdan ücretsiz ve kesintisiz taşıyoruz. Bir destek talebi açmanız yeterli.',
                ],
                'actions' => [['label' => ['fa' => 'ارسال تیکت', 'en' => 'Open a ticket', 'tr' => 'Destek talebi aç'], 'url' => whmcs_url('submitticket.php')]],
            ],
            'پشتیبانی|تماس|support|contact|شماره|تلفن|destek|iletişim|telefon' => [
                'msg' => [
                    'fa' => "تیم پشتیبانی ما ۲۴ ساعته و ۷ روز هفته پاسخگوست:\n📞 {$c['phone']}\n✉️ {$c['email']}",
                    'en' => "Our support team is available 24/7:\n📞 {$c['phone']}\n✉️ {$c['email']}",
                    'tr' => "Destek ekibimiz 7/24 hizmetinizde:\n📞 {$c['phone']}\n✉️ {$c['email']}",
                ],
                'actions' => [
                    ['label' => ['fa' => 'تماس تلفنی', 'en' => 'Call us', 'tr' => 'Bizi arayın'], 'url' => 'tel:'.$c['phone_link']],
                    ['label' => ['fa' => 'ارسال ایمیل', 'en' => 'Email us', 'tr' => 'E-posta gönderin'], 'url' => 'mailto:'.$c['email']],
                ],
            ],
            'سلام|درود|hi|hello|hey|merhaba|selam' => [
                'msg' => [
                    'fa' => 'سلام! 👋 من دستیار هوشمند سرورنت هستم. درباره هاست، سرور مجازی و اختصاصی، دامنه یا راهکارهای سازمانی هر سوالی دارید بپرسید.',
                    'en' => "Hi! 👋 I'm the ServerNet AI assistant. Ask me anything about hosting, VPS, dedicated servers, domains or enterprise solutions.",
                    'tr' => 'Merhaba! 👋 Ben ServerNet AI asistanıyım. Hosting, VPS, fiziksel sunucular, alan adları veya kurumsal çözümler hakkında sorabilirsiniz.',
                ],
                'actions' => [],
            ],
        ];

        foreach ($rules as $pattern => $rule) {
            if (preg_match('/'.$pattern.'/ui', $msg)) {
                return [
                    $rule['msg'][$loc] ?? $rule['msg']['en'],
                    array_map(fn ($a) => ['label' => $a['label'][$loc] ?? $a['label']['en'], 'url' => $a['url']], $rule['actions']),
                ];
            }
        }

        $fallback = [
            'fa' => "سوال خوبی است! برای پاسخ دقیق‌تر، همکاران ما در پشتیبانی ۲۴/۷ آماده‌اند:\n📞 {$c['phone']}\n✉️ {$c['email']}\nیا می‌توانید درباره هاست، سرور، دامنه و قیمت‌ها از من بپرسید.",
            'en' => "Good question! For a detailed answer our 24/7 support team is ready:\n📞 {$c['phone']}\n✉️ {$c['email']}\nOr ask me about hosting, servers, domains and pricing.",
            'tr' => "Güzel soru! Daha ayrıntılı yanıt için 7/24 destek ekibimiz hazır:\n📞 {$c['phone']}\n✉️ {$c['email']}\nVeya bana hosting, sunucular, alan adları ve fiyatlar hakkında sorabilirsiniz.",
        ];
        $panelLabel = ['fa' => 'ناحیه کاربری', 'en' => 'Client area', 'tr' => 'Müşteri paneli'];

        return [
            $fallback[$loc] ?? $fallback['en'],
            [['label' => $panelLabel[$loc] ?? $panelLabel['en'], 'url' => whmcs_url('clientarea.php')]],
        ];
    }
}
