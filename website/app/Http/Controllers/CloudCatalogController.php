<?php

namespace App\Http\Controllers;

use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

/**
 * صفحاتِ عمومیِ فروشِ سرورِ مجازی (ابری).
 *
 *   /cloud              فهرستِ همهٔ مکان‌ها گروه‌بندی‌شده بر قاره→کشور + جدولِ پلن‌ها
 *   /cloud/{location}   صفحهٔ اختصاصیِ هر مکان با متنِ سئوی یکتا
 *
 * ═══ قاعده‌های حاکم بر این کنترلر ═══
 *
 * ۱) **سفیدبرچسبی.** هیچ‌جا نامِ زیرساخت خوانده هم نمی‌شود چه رسد به چاپ. تنها
 *    منبعِ دادهٔ عمومی `CloudPlan::offers()` است که ردیف‌های هم‌مشخصات را در یک
 *    اسلاگ می‌کوبد و ارزان‌ترینِ موجود را نمایندهٔ گروه می‌گذارد. مشتری یک گزینه
 *    می‌بیند، نه دو تا از دو تأمین‌کننده. ستون‌های `provider*` در `$hidden` مدل‌اند
 *    و این‌جا هرگز به آرایهٔ ویو راه پیدا نمی‌کنند.
 *
 * ۲) **قیمت فقط از مدل.** `priceLabel()` ارزِ زبانِ جاری را می‌داند (تومان برای fa،
 *    یورو برای en/tr) و `price_irt` عددِ صحیح است. هیچ ضرب و تقسیمِ قیمتی این‌جا
 *    انجام نمی‌شود مگر تبدیلِ تومان→ریال برای JSON-LD (چون schema.org کدِ ISO
 *    می‌خواهد و «تومان» کدِ ISO ندارد).
 *
 * ۳) **کاتالوگِ خالی خطا نیست.** تا وقتی مدیر همگام‌سازی نکرده، جدول‌ها خالی‌اند.
 *    صفحه باید آبرومند بالا بیاید و کاربر را به تماس/راهکارها بفرستد، نه ۵۰۰.
 *
 * ─── دربارهٔ رشته‌های زبان ───
 * قاعدهٔ پروژه می‌گوید رشتهٔ UI از `lang/{fa,en,tr}/ui.php` بیاید، ولی آن سه فایل
 * مرزِ agentِ دیگری هستند و تا اعمال‌شدنِ کلیدها، `__('ui.x')` خودِ «ui.x» را چاپ
 * می‌کند و صفحه خراب دیده می‌شود. پس `strings()` اول از `Lang` می‌پرسد و اگر کلید
 * نبود، متنِ درون‌کدِ همان زبان را می‌دهد. لحظه‌ای که کلیدها به سه فایل اضافه شوند،
 * بی‌هیچ تغییری در کد، ترجمهٔ فایلِ زبان برنده می‌شود.
 */
class CloudCatalogController extends Controller
{
    /**
     * قارهٔ هر کشور. گروه‌بندیِ صفحهٔ فهرست روی همین است، چون «۲۸ شهر» بی‌گروه
     * یک دیوارِ متن است ولی «اروپا: ۱۲ شهر / خاورمیانه: ۴ شهر» اسکن‌پذیر است.
     * کشورِ ناشناس به گروهِ 'xx' («سایر») می‌رود تا هرگز از فهرست نیفتد.
     */
    public const CONTINENT_OF = [
        // اروپا
        'DE' => 'eu', 'FI' => 'eu', 'NL' => 'eu', 'GB' => 'eu', 'FR' => 'eu', 'SE' => 'eu',
        'PL' => 'eu', 'CH' => 'eu', 'AT' => 'eu', 'ES' => 'eu', 'IT' => 'eu', 'CZ' => 'eu',
        'RO' => 'eu', 'BG' => 'eu', 'DK' => 'eu', 'NO' => 'eu', 'IE' => 'eu', 'PT' => 'eu',
        'HU' => 'eu', 'LT' => 'eu', 'LV' => 'eu', 'EE' => 'eu', 'UA' => 'eu', 'MD' => 'eu',
        'RS' => 'eu', 'GR' => 'eu', 'BE' => 'eu', 'LU' => 'eu', 'SK' => 'eu', 'SI' => 'eu',
        'HR' => 'eu', 'IS' => 'eu', 'RU' => 'eu', 'BY' => 'eu', 'CY' => 'eu', 'MT' => 'eu',
        // خاورمیانه و قفقاز
        'IR' => 'me', 'TR' => 'me', 'AE' => 'me', 'SA' => 'me', 'QA' => 'me', 'KW' => 'me',
        'BH' => 'me', 'OM' => 'me', 'IQ' => 'me', 'JO' => 'me', 'LB' => 'me', 'IL' => 'me',
        'AM' => 'me', 'GE' => 'me', 'AZ' => 'me',
        // آسیا
        'SG' => 'as', 'JP' => 'as', 'KZ' => 'as', 'HK' => 'as', 'KR' => 'as', 'IN' => 'as',
        'CN' => 'as', 'TW' => 'as', 'TH' => 'as', 'VN' => 'as', 'MY' => 'as', 'ID' => 'as',
        'PH' => 'as', 'UZ' => 'as', 'KG' => 'as', 'PK' => 'as', 'BD' => 'as', 'LK' => 'as',
        'MN' => 'as', 'NP' => 'as',
        // آمریکای شمالی
        'US' => 'na', 'CA' => 'na', 'MX' => 'na', 'PA' => 'na', 'CR' => 'na',
        // آمریکای جنوبی
        'BR' => 'sa', 'AR' => 'sa', 'CL' => 'sa', 'CO' => 'sa', 'PE' => 'sa', 'UY' => 'sa',
        // آفریقا
        'ZA' => 'af', 'EG' => 'af', 'NG' => 'af', 'KE' => 'af', 'MA' => 'af', 'TN' => 'af',
        'DZ' => 'af', 'GH' => 'af',
        // اقیانوسیه
        'AU' => 'oc', 'NZ' => 'oc',
    ];

    /** ترتیب و برچسبِ قاره‌ها — اروپا اول، چون بیشترِ ظرفیت و بهترین قیمت آن‌جاست */
    public const CONTINENTS = [
        'eu' => ['fa' => 'اروپا',              'en' => 'Europe',                  'tr' => 'Avrupa'],
        'me' => ['fa' => 'خاورمیانه',  'en' => 'Middle East',  'tr' => 'Orta Doğu'],
        'as' => ['fa' => 'آسیا',               'en' => 'Asia',                    'tr' => 'Asya'],
        'na' => ['fa' => 'آمریکای شمالی',      'en' => 'North America',           'tr' => 'Kuzey Amerika'],
        'sa' => ['fa' => 'آمریکای جنوبی',      'en' => 'South America',           'tr' => 'Güney Amerika'],
        'af' => ['fa' => 'آفریقا',             'en' => 'Africa',                  'tr' => 'Afrika'],
        'oc' => ['fa' => 'اقیانوسیه',          'en' => 'Oceania',                 'tr' => 'Okyanusya'],
        'xx' => ['fa' => 'سایر مکان‌ها',        'en' => 'Other locations',         'tr' => 'Diğer konumlar'],
    ];

    /**
     * تأخیرِ **تقریبی** بر حسبِ میلی‌ثانیه: [به تهران, به مرکزِ اروپا].
     *
     * عمداً «تقریبی» گفته می‌شود و عددِ گرد است: تأخیرِ واقعی به اپراتورِ کاربر و
     * مسیرِ ترانزیت بستگی دارد و وعدهٔ دقیق دادن، تیکتِ شکایت می‌سازد.
     */
    public const LATENCY = [
        'IR' => [8, 95],   'TR' => [38, 42],  'AE' => [28, 115], 'AM' => [45, 62],
        'GE' => [40, 58],  'KZ' => [72, 92],  'RU' => [68, 42],  'DE' => [95, 8],
        'NL' => [98, 12],  'FI' => [105, 30], 'SE' => [110, 28], 'PL' => [92, 20],
        'GB' => [102, 18], 'FR' => [100, 15], 'CH' => [95, 10],  'AT' => [92, 12],
        'ES' => [110, 25], 'IT' => [98, 20],  'CZ' => [95, 12],  'UA' => [80, 30],
        'US' => [185, 95], 'CA' => [190, 100], 'SG' => [150, 165], 'JP' => [205, 230],
        'HK' => [160, 175], 'IN' => [110, 130], 'AU' => [300, 290], 'BR' => [280, 200],
        'ZA' => [180, 150], 'EG' => [90, 75],
    ];

    /** پشتیبانِ تأخیر وقتی کشور در جدولِ بالا نیست — بر پایهٔ قاره */
    public const LATENCY_BY_CONTINENT = [
        'eu' => [100, 18], 'me' => [45, 70], 'as' => [155, 175], 'na' => [190, 100],
        'sa' => [280, 200], 'af' => [140, 90], 'oc' => [300, 290], 'xx' => [150, 120],
    ];

    /**
     * «مناسبِ چه کاری» — سه گزارهٔ کوتاه به ازای هر قاره.
     * (چرا قاره و نه کشور: متن باید برای مکانِ تازه‌ای که فردا سینک می‌شود هم
     *  معنی‌دار باشد، بی‌آنکه کسی یادش برود این‌جا را به‌روز کند.)
     */
    public const GOOD_FOR = [
        'eu' => [
            'fa' => ['وب‌سایت، فروشگاه و اپلیکیشنِ با مخاطبِ اروپایی', 'میزبانیِ API و سرویسِ داکری با بهترین نسبتِ کارایی به قیمت', 'پایگاه‌داده و نسخهٔ پشتیبانِ خارج از کشور'],
            'en' => ['Websites, shops and apps with a European audience', 'API and Docker workloads at the best price-performance', 'Databases and off-site backups'],
            'tr' => ['Avrupa kitlesine hitap eden site, mağaza ve uygulamalar', 'En iyi fiyat/performansla API ve Docker yükleri', 'Veritabanı ve yurt dışı yedekleri'],
        ],
        'me' => [
            'fa' => ['مخاطبِ ایرانی و کشورهای همسایه با کم‌ترین تأخیر', 'بازی، تماسِ تصویری و هر کاربردِ حساس به پینگ', 'سرویس‌های نیازمندِ نزدیکیِ جغرافیایی به کاربر'],
            'en' => ['Audiences in Iran and neighbouring countries with the lowest latency', 'Gaming, voice/video and any ping-sensitive workload', 'Services that must sit close to the user'],
            'tr' => ['İran ve komşu ülkelerdeki kullanıcılar için en düşük gecikme', 'Oyun, sesli/görüntülü ve ping duyarlı iş yükleri', 'Kullanıcıya yakın olması gereken servisler'],
        ],
        'as' => [
            'fa' => ['بازارِ آسیا و شرقِ دور', 'نودِ شبکه و سرویسِ توزیع‌شده در آسیا', 'خزشِ داده و کارهای پس‌زمینه با مقصدِ آسیایی'],
            'en' => ['Asian and Far-East markets', 'Network nodes and distributed services across Asia', 'Crawling and background jobs targeting Asia'],
            'tr' => ['Asya ve Uzak Doğu pazarları', 'Asya genelinde ağ düğümleri ve dağıtık servisler', 'Asya hedefli tarama ve arka plan işleri'],
        ],
        'na' => [
            'fa' => ['مخاطبِ آمریکای شمالی و سئوی محلیِ آن بازار', 'یکپارچگی با سرویس‌های ابریِ آمریکایی', 'اپلیکیشنِ SaaS با کاربرِ آمریکایی'],
            'en' => ['North-American audiences and local SEO', 'Integration with US cloud services', 'SaaS apps with US users'],
            'tr' => ['Kuzey Amerika kitlesi ve yerel SEO', 'ABD bulut servisleriyle entegrasyon', 'ABD kullanıcılı SaaS uygulamaları'],
        ],
        'sa' => [
            'fa' => ['بازارِ آمریکای لاتین', 'سرویسِ منطقه‌ای با کاربرِ برزیل و آرژانتین', 'نودِ توزیع‌شدهٔ نیم‌کرهٔ جنوبی'],
            'en' => ['Latin-American markets', 'Regional services for Brazil and Argentina', 'Southern-hemisphere distributed nodes'],
            'tr' => ['Latin Amerika pazarları', 'Brezilya ve Arjantin için bölgesel servisler', 'Güney yarımküre dağıtık düğümleri'],
        ],
        'af' => [
            'fa' => ['مخاطبِ آفریقا و شمالِ آفریقا', 'سرویسِ منطقه‌ای با تأخیرِ پایین به قارهٔ آفریقا', 'نقطهٔ حضورِ محلی برای بازارِ در حالِ رشد'],
            'en' => ['African and North-African audiences', 'Regional services with low latency inside Africa', 'A local point of presence for a growing market'],
            'tr' => ['Afrika ve Kuzey Afrika kitlesi', 'Afrika içinde düşük gecikmeli bölgesel servisler', 'Büyüyen pazar için yerel varlık noktası'],
        ],
        'oc' => [
            'fa' => ['مخاطبِ استرالیا و نیوزیلند', 'سرویسِ منطقه‌ای اقیانوسیه', 'نقطهٔ پایشِ شبکه در نیم‌کرهٔ جنوبی'],
            'en' => ['Australian and New-Zealand audiences', 'Oceania regional services', 'Network monitoring in the southern hemisphere'],
            'tr' => ['Avustralya ve Yeni Zelanda kitlesi', 'Okyanusya bölgesel servisleri', 'Güney yarımkürede ağ izleme'],
        ],
        'xx' => [
            'fa' => ['سرویسِ عمومیِ وب و اپلیکیشن', 'محیطِ آزمایش و توسعه', 'نسخهٔ پشتیبانِ خارج از مرکزِ داده اصلی'],
            'en' => ['General web and application workloads', 'Staging and development environments', 'Off-site backups away from your main datacentre'],
            'tr' => ['Genel web ve uygulama iş yükleri', 'Test ve geliştirme ortamları', 'Ana veri merkezi dışında yedekleme'],
        ],
    ];

    /**
     * یک جملهٔ ویژهٔ هر کشور. این همان چیزی است که صفحهٔ هر مکان را از نظرِ گوگل
     * «یکتا» می‌کند؛ بی‌آن، ده صفحه با متنِ قالبیِ یکسان می‌ساختیم که مصداقِ
     * محتوای نازک (thin content) است.
     */
    public const COUNTRY_NOTE = [
        'DE' => [
            'fa' => 'آلمان بزرگ‌ترین گرهِ اینترنتِ اروپا را دارد و ارزان‌ترین نسبتِ کارایی به قیمت در کلِ کاتالوگ معمولاً همین‌جاست.',
            'en' => 'Germany hosts Europe’s largest internet exchange and usually offers the best price-performance in our whole catalogue.',
            'tr' => 'Almanya, Avrupa’nın en büyük internet değişim noktasına sahiptir ve katalogdaki en iyi fiyat/performans genellikle buradadır.',
        ],
        'FI' => [
            'fa' => 'فینلاند با برقِ ارزان و هوای سرد، مرکزِ دادهٔ کم‌مصرف و پایدار دارد؛ گزینهٔ خوبِ کارهای طولانی و پردازشِ سنگین.',
            'en' => 'Finland combines cheap electricity with a cool climate, which makes it a solid pick for long-running, compute-heavy jobs.',
            'tr' => 'Finlandiya ucuz elektrik ve serin iklimi birleştirir; uzun süreli, yoğun işlem gerektiren işler için iyi bir seçimdir.',
        ],
        'NL' => [
            'fa' => 'هلند نقطهٔ اتصالِ اصلیِ ترانزیتِ اروپا به بریتانیا و آمریکاست و مسیرهای بسیار متنوعی دارد.',
            'en' => 'The Netherlands is a major transit hub towards the UK and the US, with unusually diverse routing.',
            'tr' => 'Hollanda, Birleşik Krallık ve ABD yönünde önemli bir transit merkezidir ve çok çeşitli yönlendirme sunar.',
        ],
        'GB' => [
            'fa' => 'بریتانیا برای مخاطبِ انگلیسی‌زبانِ اروپا و سرویس‌هایی که به لندن نزدیک باشند مناسب است.',
            'en' => 'The UK suits English-speaking European audiences and services that need to sit close to London.',
            'tr' => 'Birleşik Krallık, İngilizce konuşan Avrupa kitlesi ve Londra’ya yakın olması gereken servisler için uygundur.',
        ],
        'FR' => [
            'fa' => 'فرانسه مسیرِ خوبی به جنوبِ اروپا و شمالِ آفریقا دارد.',
            'en' => 'France routes well towards southern Europe and North Africa.',
            'tr' => 'Fransa, Güney Avrupa ve Kuzey Afrika’ya iyi yönlendirme sağlar.',
        ],
        'SE' => [
            'fa' => 'سوئد برای مخاطبِ اسکاندیناوی و کارهای نیازمندِ انرژیِ پاک انتخابِ خوبی است.',
            'en' => 'Sweden is a good choice for Scandinavian audiences and green-energy workloads.',
            'tr' => 'İsveç, İskandinav kitlesi ve yeşil enerji iş yükleri için iyi bir seçimdir.',
        ],
        'PL' => [
            'fa' => 'پلند از نظرِ مسیر بین اروپای غربی و شرق قرار می‌گیرد و به ایران کمی نزدیک‌تر از هلند و بریتانیاست.',
            'en' => 'Poland sits between western and eastern Europe on the network map and is slightly closer to Iran than the Netherlands or the UK.',
            'tr' => 'Polonya ağ haritasında Batı ve Doğu Avrupa arasında yer alır ve İran’a Hollanda veya Birleşik Krallık’tan biraz daha yakındır.',
        ],
        'CH' => [
            'fa' => 'سوئیس برای پروژه‌هایی که به حاکمیتِ دادهٔ سخت‌گیرانه اهمیت می‌دهند انتخاب می‌شود.',
            'en' => 'Switzerland is picked by projects that care about strict data-sovereignty rules.',
            'tr' => 'İsviçre, katı veri egemenliği kurallarına önem veren projeler tarafından seçilir.',
        ],
        'AT' => [
            'fa' => 'اتریش هم به اروپای مرکزی نزدیک است هم مسیرِ خوبی به بالکان دارد.',
            'en' => 'Austria is close to central Europe and routes well into the Balkans.',
            'tr' => 'Avusturya, Orta Avrupa’ya yakındır ve Balkanlar’a iyi yönlendirme sağlar.',
        ],
        'TR' => [
            'fa' => 'ترکیه نزدیک‌ترین مکانِ «تقریباً اروپایی» به ایران است: هم تأخیرِ پایین، هم دسترسیِ خوب به اروپا.',
            'en' => 'Türkiye is the closest almost-European location to Iran: low latency plus decent European reach.',
            'tr' => 'Türkiye, İran’a en yakın “neredeyse Avrupa” konumudur: düşük gecikme ve iyi Avrupa erişimi.',
        ],
        'AE' => [
            'fa' => 'امارات کم‌ترین تأخیر را به کاربرِ ایرانی می‌دهد و برای سرویس‌های حساس به پینگ انتخابِ نخست است.',
            'en' => 'The UAE gives the lowest latency to Iranian users and is the first pick for ping-sensitive services.',
            'tr' => 'BAE, İranlı kullanıcılara en düşük gecikmeyi sunar ve ping duyarlı servisler için ilk seçenektir.',
        ],
        'AM' => [
            'fa' => 'ارمنستان همسایهٔ زمینیِ ایران است و مسیرِ فیبرِ مستقیم دارد.',
            'en' => 'Armenia is a land neighbour of Iran with direct fibre routes.',
            'tr' => 'Ermenistan, İran’ın kara komşusudur ve doğrudan fiber yolları vardır.',
        ],
        'GE' => [
            'fa' => 'گرجستان علاوه بر نزدیکی، مسیرِ جایگزینی برای زمانِ اختلالِ ترانزیت است.',
            'en' => 'Georgia is not only close but also a useful alternative route when transit is disrupted.',
            'tr' => 'Gürcistan yalnızca yakın değil, transit kesintilerinde alternatif bir yol da sunar.',
        ],
        'KZ' => [
            'fa' => 'قزاقستان برای مخاطبِ آسیای مرکزی و روسیه مناسب است.',
            'en' => 'Kazakhstan suits Central-Asian and Russian audiences.',
            'tr' => 'Kazakistan, Orta Asya ve Rusya kitlesi için uygundur.',
        ],
        'RU' => [
            'fa' => 'روسیه برای مخاطبِ روس‌زبان و سرویس‌های منطقه‌ای گزینهٔ نزدیکی است.',
            'en' => 'Russia is a nearby option for Russian-speaking audiences and regional services.',
            'tr' => 'Rusya, Rusça konuşan kitle ve bölgesel servisler için yakın bir seçenektir.',
        ],
        'US' => [
            'fa' => 'آمریکا برای مخاطبِ آمریکایی و اتصال به سرویس‌های ابریِ آن‌جا لازم است؛ تأخیرش به ایران بالاست و برای کاربرِ ایرانی توصیه نمی‌شود.',
            'en' => 'The US is needed for American audiences and US cloud integrations; latency to Iran is high, so it is not the pick for Iranian users.',
            'tr' => 'ABD, Amerikan kitlesi ve ABD bulut entegrasyonları için gereklidir; İran’a gecikmesi yüksektir, İranlı kullanıcılar için önerilmez.',
        ],
        'SG' => [
            'fa' => 'سنگاپور دروازهٔ جنوبِ شرقیِ آسیاست و مسیرهای بسیار پرظرفیتی دارد.',
            'en' => 'Singapore is the gateway to South-East Asia with very high-capacity routes.',
            'tr' => 'Singapur, Güneydoğu Asya’nın kapısıdır ve çok yüksek kapasiteli yollara sahiptir.',
        ],
        'JP' => [
            'fa' => 'ژاپن برای مخاطبِ شرقِ آسیا و سرویس‌های ژاپنی مناسب است.',
            'en' => 'Japan suits East-Asian audiences and Japan-facing services.',
            'tr' => 'Japonya, Doğu Asya kitlesi ve Japonya odaklı servisler için uygundur.',
        ],
        'IR' => [
            'fa' => 'مکانِ داخلی کم‌ترین تأخیر را به کاربرِ ایرانی می‌دهد و برای سرویس‌هایی که باید داخلِ کشور بمانند لازم است.',
            'en' => 'A domestic location gives the lowest latency to Iranian users and is required for services that must stay inside the country.',
            'tr' => 'Yerel konum, İranlı kullanıcılara en düşük gecikmeyi verir ve ülke içinde kalması gereken servisler için gereklidir.',
        ],
    ];

    /**
     * رشته‌های صفحه به سه زبان.
     *
     * کلیدها همان‌هایی هستند که برای `lang/{fa,en,tr}/ui.php` تحویل داده می‌شوند؛
     * تا وقتی آن‌جا نباشند، همین متن‌ها رندر می‌شوند.
     */
    public const STRINGS = [
        'cloud_meta_t' => [
            'fa' => 'خرید سرور مجازی (VPS) | ایران و ده‌ها کشور، تحویل آنی | سرورنت',
            'en' => 'Buy a Cloud VPS | Iran and dozens of countries, instant delivery | ServerNet',
            'tr' => 'Bulut VPS satın al | İran ve onlarca ülke, anında teslim | ServerNet',
        ],
        'cloud_meta_d' => [
            'fa' => 'خرید سرور مجازی با دیسک NVMe، آی‌پی اختصاصی و تحویلِ خودکار در ایران و ده‌ها شهرِ اروپا، خاورمیانه، آسیا و آمریکا. قیمتِ شفاف، پرداختِ ریالی، انتخابِ پرداختِ ساعتی یا ماهانه و مدیریتِ کامل از پنل.',
            'en' => 'Buy a cloud VPS with NVMe storage, a dedicated IP and automatic delivery in Iran and dozens of cities across Europe, the Middle East, Asia and America. Transparent pricing, hourly or monthly billing, full panel control.',
            'tr' => 'İran ile Avrupa, Orta Doğu, Asya ve Amerika’daki onlarca şehirde NVMe diskli, özel IP’li ve otomatik teslimli bulut VPS. Şeffaf fiyat, saatlik veya aylık ödeme, panelden tam kontrol.',
        ],
        'cloud_badge' => ['fa' => 'سرور مجازی ابری', 'en' => 'Cloud VPS', 'tr' => 'Bulut VPS'],
        'cloud_h1' => [
            'fa' => 'سرور مجازی، در هر کشوری که بخواهید',
            'en' => 'A cloud server in any country you need',
            'tr' => 'İstediğiniz her ülkede bulut sunucu',
        ],
        'cloud_lead' => [
            'fa' => 'یک پلن را انتخاب کنید، مکانش را خودتان بگذارید و چند دقیقه بعد سرور تحویل داده می‌شود. همهٔ سرورها دیسکِ NVMe، آی‌پیِ اختصاصی و کنسولِ مدیریت دارند و از پنلِ سرورنت روشن، خاموش، نصبِ دوباره و پایش می‌شوند.',
            'en' => 'Pick a plan, pick its location, and the server is delivered within minutes. Every server ships with NVMe storage, a dedicated IPv4 and a management console — power, rebuild and monitoring all live in your ServerNet panel.',
            'tr' => 'Bir paket seçin, konumunu belirleyin; sunucu dakikalar içinde teslim edilir. Tüm sunucular NVMe disk, özel IPv4 ve yönetim konsolu ile gelir; güç, yeniden kurulum ve izleme ServerNet panelinizde.',
        ],
        'cloud_stat_loc' => ['fa' => 'مکان', 'en' => 'locations', 'tr' => 'konum'],
        'cloud_stat_country' => ['fa' => 'کشور', 'en' => 'countries', 'tr' => 'ülke'],
        'cloud_stat_plan' => ['fa' => 'پلن', 'en' => 'plans', 'tr' => 'paket'],
        'cloud_map_t' => ['fa' => 'مکان‌ها بر اساس قاره و کشور', 'en' => 'Locations by continent and country', 'tr' => 'Kıta ve ülkeye göre konumlar'],
        'cloud_map_d' => [
            'fa' => 'هر مکان صفحهٔ خودش را دارد؛ روی نامِ شهر بزنید تا پلن‌ها، تأخیرِ تقریبی و توضیحِ کاملِ همان مکان را ببینید.',
            'en' => 'Every location has its own page — click a city to see its plans, its approximate latency and what it is good for.',
            'tr' => 'Her konumun kendi sayfası var — planları, yaklaşık gecikmeyi ve neye uygun olduğunu görmek için bir şehre tıklayın.',
        ],
        'cloud_n_plans' => ['fa' => ':n پلن', 'en' => ':n plans', 'tr' => ':n paket'],
        'cloud_n_cities' => ['fa' => ':n شهر', 'en' => ':n cities', 'tr' => ':n şehir'],
        'cloud_table_t' => ['fa' => 'همهٔ پلن‌ها', 'en' => 'All plans', 'tr' => 'Tüm paketler'],
        'cloud_table_d' => [
            'fa' => 'با فیلترهای زیر کشور، تعدادِ هسته و مقدارِ رم را محدود کنید و بر اساسِ قیمت مرتب کنید. قیمت‌ها ماهانه و بدونِ هزینهٔ راه‌اندازی است.',
            'en' => 'Filter by country, core count and memory, then sort by price. All prices are monthly with no setup fee.',
            'tr' => 'Ülke, çekirdek sayısı ve bellek ile filtreleyin, sonra fiyata göre sıralayın. Tüm fiyatlar aylıktır, kurulum ücreti yoktur.',
        ],
        'cloud_f_country' => ['fa' => 'کشور', 'en' => 'Country', 'tr' => 'Ülke'],
        'cloud_f_cpu' => ['fa' => 'هسته', 'en' => 'Cores', 'tr' => 'Çekirdek'],
        'cloud_f_ram' => ['fa' => 'رم', 'en' => 'Memory', 'tr' => 'Bellek'],
        'cloud_f_sort' => ['fa' => 'مرتب‌سازی', 'en' => 'Sort by', 'tr' => 'Sıralama'],
        'cloud_f_all' => ['fa' => 'همه', 'en' => 'All', 'tr' => 'Tümü'],
        'cloud_sort_price_asc' => ['fa' => 'ارزان‌ترین', 'en' => 'Cheapest first', 'tr' => 'En ucuz'],
        'cloud_sort_price_desc' => ['fa' => 'گران‌ترین', 'en' => 'Most expensive first', 'tr' => 'En pahalı'],
        'cloud_sort_cpu' => ['fa' => 'بیشترین هسته', 'en' => 'Most cores', 'tr' => 'En çok çekirdek'],
        'cloud_sort_ram' => ['fa' => 'بیشترین رم', 'en' => 'Most memory', 'tr' => 'En çok bellek'],
        'cloud_th_plan' => ['fa' => 'پلن', 'en' => 'Plan', 'tr' => 'Paket'],
        'cloud_th_cpu' => ['fa' => 'پردازنده', 'en' => 'CPU', 'tr' => 'İşlemci'],
        'cloud_th_ram' => ['fa' => 'رم', 'en' => 'RAM', 'tr' => 'RAM'],
        'cloud_th_disk' => ['fa' => 'دیسک', 'en' => 'Disk', 'tr' => 'Disk'],
        'cloud_th_loc' => ['fa' => 'مکان', 'en' => 'Location', 'tr' => 'Konum'],
        'cloud_th_price' => ['fa' => 'قیمت ماهانه', 'en' => 'Monthly price', 'tr' => 'Aylık fiyat'],
        'cloud_shown_n' => ['fa' => ':n پلن نمایش داده می‌شود', 'en' => ':n plans shown', 'tr' => ':n paket gösteriliyor'],
        'cloud_nomatch' => [
            'fa' => 'با این فیلترها پلنی پیدا نشد. یکی از فیلترها را روی «همه» بگذارید.',
            'en' => 'No plan matches these filters. Set one of them back to “All”.',
            'tr' => 'Bu filtrelerle paket bulunamadı. Birini “Tümü” yapın.',
        ],
        'cloud_buy' => ['fa' => 'خرید', 'en' => 'Buy', 'tr' => 'Satın al'],
        'cloud_empty_t' => [
            'fa' => 'کاتالوگِ سرورِ ابری در حالِ آماده‌سازی است',
            'en' => 'The cloud catalogue is being prepared',
            'tr' => 'Bulut kataloğu hazırlanıyor',
        ],
        'cloud_empty_d' => [
            'fa' => 'مکان‌ها و پلن‌ها به‌زودی روی همین صفحه منتشر می‌شوند. اگر همین حالا سرور می‌خواهید، با ما تماس بگیرید تا دستی برایتان راه‌اندازی کنیم.',
            'en' => 'Locations and plans will be published on this page shortly. If you need a server right now, contact us and we will set it up for you manually.',
            'tr' => 'Konumlar ve paketler kısa süre içinde bu sayfada yayınlanacak. Hemen sunucuya ihtiyacınız varsa bize ulaşın, manuel olarak kuralım.',
        ],
        'cloud_feat_1' => ['fa' => 'دیسکِ NVMe', 'en' => 'NVMe storage', 'tr' => 'NVMe disk'],
        'cloud_feat_2' => ['fa' => 'آی‌پیِ اختصاصی', 'en' => 'Dedicated IPv4', 'tr' => 'Özel IPv4'],
        'cloud_feat_3' => ['fa' => 'تحویلِ خودکار', 'en' => 'Automatic delivery', 'tr' => 'Otomatik teslim'],
        'cloud_feat_4' => ['fa' => 'کنسول و نصبِ دوباره', 'en' => 'Console and rebuild', 'tr' => 'Konsol ve yeniden kurulum'],
        'cloud_faq_q1' => ['fa' => 'سرور چه زمانی تحویل داده می‌شود؟', 'en' => 'How fast is the server delivered?', 'tr' => 'Sunucu ne kadar hızlı teslim edilir?'],
        'cloud_faq_a1' => [
            'fa' => 'پس از تأییدِ پرداخت، ساختِ سرور خودکار شروع می‌شود و معمولاً در چند دقیقه آماده است. نامِ کاربری، رمز و آی‌پی در پنلِ کاربریِ شما نمایش داده می‌شود.',
            'en' => 'Provisioning starts automatically once your payment is confirmed and the server is usually ready within a few minutes. Username, password and IP appear in your panel.',
            'tr' => 'Ödemeniz onaylandıktan sonra kurulum otomatik başlar ve sunucu genellikle birkaç dakika içinde hazırdır. Kullanıcı adı, şifre ve IP panelinizde görünür.',
        ],
        'cloud_faq_q2' => ['fa' => 'می‌توانم بعداً پلن را ارتقا دهم؟', 'en' => 'Can I upgrade the plan later?', 'tr' => 'Paketi sonra yükseltebilir miyim?'],
        'cloud_faq_a2' => [
            'fa' => 'بله. ارتقای هسته، رم و دیسک از پنل درخواست می‌شود و تفاوتِ قیمت به‌نسبتِ روزهای باقی‌ماندهٔ دوره حساب می‌شود.',
            'en' => 'Yes. CPU, memory and disk upgrades are requested from the panel and the price difference is prorated over the remaining days of the period.',
            'tr' => 'Evet. CPU, bellek ve disk yükseltmeleri panelden talep edilir; fiyat farkı dönemin kalan günlerine göre hesaplanır.',
        ],
        'cloud_faq_q3' => ['fa' => 'سیستم‌عاملش را خودم انتخاب می‌کنم؟', 'en' => 'Do I choose the operating system?', 'tr' => 'İşletim sistemini ben mi seçerim?'],
        'cloud_faq_a3' => [
            'fa' => 'بله. اوبونتو، دبیان، آلما، راکی و چند نرم‌افزارِ آماده (مثلِ داکر) در لحظهٔ خرید انتخاب می‌شوند و هر زمان بخواهید می‌توانید سرور را با سیستم‌عاملِ دیگری از نو نصب کنید.',
            'en' => 'Yes. Ubuntu, Debian, AlmaLinux, Rocky and a few ready-made app images (such as Docker) are selected at checkout, and you can rebuild with a different OS whenever you like.',
            'tr' => 'Evet. Ubuntu, Debian, AlmaLinux, Rocky ve Docker gibi hazır imajlar satın alırken seçilir; istediğiniz zaman başka bir işletim sistemiyle yeniden kurabilirsiniz.',
        ],
        'cloud_faq_q4' => ['fa' => 'کدام مکان را انتخاب کنم؟', 'en' => 'Which location should I choose?', 'tr' => 'Hangi konumu seçmeliyim?'],
        'cloud_faq_a4' => [
            'fa' => 'مکان را نزدیکِ کاربرانتان بگذارید، نه نزدیکِ خودتان. برای مخاطبِ ایرانی، خاورمیانه و اروپای شرقی کم‌ترین تأخیر را می‌دهند؛ برای مخاطبِ اروپایی، آلمان و هلند؛ و برای مخاطبِ آمریکایی، آمریکا. در صفحهٔ هر مکان تأخیرِ تقریبی نوشته شده است.',
            'en' => 'Put the server near your users, not near yourself. For Iranian visitors the Middle East and eastern Europe give the lowest latency; for European visitors Germany and the Netherlands; for American visitors the US. Each location page lists its approximate latency.',
            'tr' => 'Sunucuyu kendinize değil kullanıcılarınıza yakın konumlandırın. İranlı ziyaretçiler için Orta Doğu ve Doğu Avrupa en düşük gecikmeyi verir; Avrupalılar için Almanya ve Hollanda; Amerikalılar için ABD. Her konum sayfasında yaklaşık gecikme yazılıdır.',
        ],
        'cloud_faq_q5' => ['fa' => 'ترافیک چطور حساب می‌شود؟', 'en' => 'How is traffic counted?', 'tr' => 'Trafik nasıl hesaplanır?'],
        'cloud_faq_a5' => [
            'fa' => 'هر پلن ترافیکِ ماهانهٔ خودش را دارد که در جدول نوشته شده؛ جایی که «مصرفِ منصفانه» نوشته شده یعنی سقفِ عددی اعلام نمی‌شود و تنها مصرفِ غیرعادی محدود می‌گردد.',
            'en' => 'Each plan includes its own monthly traffic allowance, shown in the table. Where it says “Fair use”, no hard number is published and only abnormal usage is limited.',
            'tr' => 'Her paketin tabloda gösterilen kendi aylık trafik kotası vardır. “Adil kullanım” yazan yerlerde sabit bir sayı yayınlanmaz, yalnızca anormal kullanım sınırlanır.',
        ],
        'cloud_cross_t' => ['fa' => 'مسیرهای مرتبط', 'en' => 'Related pages', 'tr' => 'İlgili sayfalar'],
        // ───────────── صفحهٔ یک مکان ─────────────
        'cloud_loc_meta_t' => [
            'fa' => 'سرور مجازی :loc — تحویل آنی | سرورنت',
            'en' => 'Cloud VPS in :loc — instant delivery | ServerNet',
            'tr' => ':loc bulut VPS — anında teslim | ServerNet',
        ],
        'cloud_loc_meta_d' => [
            'fa' => 'خرید سرور مجازی در :loc با دیسک NVMe، آی‌پی اختصاصی و تحویلِ خودکار. :n پلن با قیمتِ شفافِ ماهانه، از :price.',
            'en' => 'Buy a cloud VPS in :loc with NVMe storage, a dedicated IP and automatic delivery. :n plans with transparent monthly pricing, from :price.',
            'tr' => ':loc konumunda NVMe diskli, özel IP’li ve otomatik teslimli bulut VPS satın alın. Şeffaf aylık fiyatla :n paket, :price başlangıç.',
        ],
        'cloud_loc_h1' => ['fa' => 'سرور مجازی :loc', 'en' => 'Cloud VPS in :loc', 'tr' => ':loc bulut VPS'],
        'cloud_loc_why' => ['fa' => 'چرا :city؟', 'en' => 'Why :city?', 'tr' => 'Neden :city?'],
        'cloud_loc_lat_t' => ['fa' => 'تأخیرِ تقریبی', 'en' => 'Approximate latency', 'tr' => 'Yaklaşık gecikme'],
        'cloud_loc_lat_ir' => ['fa' => 'به ایران', 'en' => 'to Iran', 'tr' => 'İran’a'],
        'cloud_loc_lat_eu' => ['fa' => 'به اروپا', 'en' => 'to Europe', 'tr' => 'Avrupa’ya'],
        'cloud_loc_lat_note' => [
            'fa' => 'اعداد تقریبی و بر پایهٔ مسیرهای رایج‌اند؛ تأخیرِ واقعی به اپراتور و مسیرِ ترانزیتِ شما بستگی دارد.',
            'en' => 'These are rough figures based on common routes; your real latency depends on your ISP and transit path.',
            'tr' => 'Bu değerler yaygın yollara göre yaklaşıktır; gerçek gecikme operatörünüze ve transit yolunuza bağlıdır.',
        ],
        'cloud_loc_good_t' => ['fa' => 'مناسبِ چه کاری است؟', 'en' => 'What is it good for?', 'tr' => 'Ne için uygundur?'],
        'cloud_loc_plans_t' => ['fa' => 'پلن‌های :city', 'en' => 'Plans in :city', 'tr' => ':city paketleri'],
        'cloud_loc_empty' => [
            'fa' => 'در این لحظه پلنِ آماده‌ای در این مکان نداریم. مکان‌های دیگر را ببینید یا با ما تماس بگیرید.',
            'en' => 'We have no ready plan in this location right now. Have a look at the other locations or contact us.',
            'tr' => 'Şu anda bu konumda hazır paketimiz yok. Diğer konumlara bakın veya bize ulaşın.',
        ],
        'cloud_loc_all' => ['fa' => 'همهٔ مکان‌ها', 'en' => 'All locations', 'tr' => 'Tüm konumlar'],
        'cloud_loc_near_t' => ['fa' => 'مکان‌های نزدیک', 'en' => 'Nearby locations', 'tr' => 'Yakın konumlar'],
    ];

    // ═══════════════════════════ صفحه‌ها ═══════════════════════════

    /** /cloud — فهرستِ مکان‌ها (قاره → کشور → شهر) + جدولِ فیلترشدنیِ پلن‌ها */
    public function index(): View
    {
        $t = $this->strings();
        $offers = CloudPlan::offers();
        $locations = $this->activeLocations();

        $tree = $this->continentTree($offers, $locations);
        $rows = $this->planRows($offers, $locations);

        // شمارشِ خلاصه برای نوارِ آمار — همه از `$rows` می‌آیند، یعنی از همان
        // چیزی که کاربر واقعاً در جدول می‌بیند. اگر از `$offers` می‌شمردیم،
        // پلنِ متصل به مکانِ غیرفعال هم شمرده می‌شد و عددی تبلیغ می‌کردیم که در
        // فهرست پیدا نمی‌شود.
        $countries = [];
        $locs = [];
        $min = null;
        foreach ($rows as $r) {
            $countries[$r['country']] = true;
            $locs[$r['loc_code']] = true;
            if ($min === null || $r['price_n'] < $min['price_n']) {
                $min = $r;
            }
        }

        return view('pages.cloud', [
            't' => $t,
            'tree' => $tree,
            'rows' => $rows,
            'facets' => $this->facets($rows),
            'statLoc' => count($locs),
            'statCountry' => count($countries),
            'statPlan' => count($rows),
            'fromLabel' => $min['price'] ?? null,
            'faq' => $this->faq($t),
            'ld' => $this->indexLd($t, $rows),
        ]);
    }

    /** /cloud/{location} — صفحهٔ یک مکان با متنِ سئوی یکتا */
    public function location(string $location): View
    {
        $loc = CloudLocation::query()
            ->where('code', $location)
            ->where('is_active', true)
            ->first();

        if ($loc === null) {
            return $this->legacyCloudPage($location);
        }

        $t = $this->strings();
        $offers = CloudPlan::offers($loc->code);
        $cheapest = $offers->sortBy('price_irt')->first();

        $locLabel = $loc->label();
        $cityLabel = $loc->cityLabel() !== '' ? $loc->cityLabel() : $loc->countryLabel();

        $rows = $this->planRows($offers, collect([$loc->code => $loc]));

        return view('pages.cloud-location', [
            't' => $t,
            'cloudUrl' => $this->indexUrl(),
            'loc' => $loc,
            'locLabel' => $locLabel,
            'cityLabel' => $cityLabel,
            'rows' => $rows,
            'seo' => $this->locationSeo($loc),
            'nearby' => $this->nearby($loc),
            'fromLabel' => $cheapest?->priceLabel(),
            'faq' => $this->faq($t),
            'ld' => $this->locationLd($t, $locLabel, $rows),
        ]);
    }

    /**
     * نشانیِ `/cloud/{...}` از قبل مالِ ما نبود.
     *
     * ⚠️ این متد یک رگرسیونِ واقعی را می‌بندد. صفحاتِ بازاریابیِ کاتالوگ روی
     * روتِ فراگیرِ `/{category}/{slug}` می‌نشینند و شاملِ `/cloud/iaas`،
     * `/cloud/ai-infrastructure`، `/cloud/object-storage` و … هستند. روتِ
     * `/cloud/{location}` **باید** بالاتر از آن روت ثبت شود (وگرنه کاتالوگ
     * مسیرِ مکان‌ها را می‌قاپد)، ولی همین بالا بودن یعنی آن نشانی‌های قدیمی هم
     * از این‌جا رد می‌شوند. اگر ساده `abort(404)` می‌کردیم، چند صفحهٔ ایندکس‌شدهٔ
     * موجود بی‌صدا ۴۰۴ می‌شدند — دقیقاً همان اشتباهی که بعدها با «چرا رتبهٔ آن
     * صفحه رفت؟» کشف می‌شود.
     *
     * پس اگر کدِ مکان نبود ولی اسلاگِ کاتالوگ بود، همان صفحهٔ کاتالوگ رندر
     * می‌شود؛ و اگر هیچ‌کدام نبود، ۴۰۴ واقعی.
     */
    private function legacyCloudPage(string $slug): View
    {
        $catalog = (array) config('catalog.cloud', []);

        abort_unless(isset($catalog[$slug]), 404);

        return app(CatalogController::class)->show('cloud', $slug);
    }

    // ═══════════════════════════ داده ═══════════════════════════

    /** @return Collection<string, CloudLocation> */
    private function activeLocations(): Collection
    {
        return CloudLocation::query()
            ->where('is_active', true)
            ->orderBy('sort')
            ->orderBy('code')
            ->get()
            ->keyBy('code');
    }

    /**
     * درختِ قاره → کشور → مکان، همراه با «تعدادِ پلن» و «ارزان‌ترین قیمت» در هر
     * سطح. مرتب‌سازی بر ارزان‌ترین قیمت است (نه الفبا): هم برای فروش بهتر است، هم
     * قطعی و تکرارپذیر — الفبای فارسی با strcmp نتیجهٔ درستی نمی‌دهد.
     *
     * @param  Collection<string, CloudPlan>  $offers
     * @param  Collection<string, CloudLocation>  $locations
     * @return array<int, array<string, mixed>>
     */
    private function continentTree(Collection $offers, Collection $locations): array
    {
        $byLoc = $offers->groupBy('location_code');
        $tree = [];

        foreach ($byLoc as $code => $plans) {
            $loc = $locations->get($code);
            if ($loc === null) {
                continue;                       // مکانِ غیرفعال یا حذف‌شده
            }

            $country = strtoupper((string) $loc->country);
            $cont = self::CONTINENT_OF[$country] ?? 'xx';
            $cheapest = $plans->sortBy('price_irt')->first();

            $tree[$cont] ??= ['key' => $cont, 'label' => $this->pick(self::CONTINENTS[$cont] ?? self::CONTINENTS['xx']), 'countries' => [], 'plans' => 0, 'cities' => 0, 'min' => PHP_INT_MAX, 'from' => null];
            $tree[$cont]['countries'][$country] ??= [
                'code' => $country,
                'label' => $loc->countryLabel(),
                'flag' => $loc->flagEmoji(),
                'flag_svg' => $loc->flagSvg(),
                // کارت به **صفحهٔ کشور** می‌رود، نه صفحهٔ شهر: مشتری «سرور
                // آلمان» می‌خواهد و شهر را وقتی انتخاب می‌کند که پلن‌ها را
                // کنار هم ببیند.
                'url' => \App\Services\Cloud\CloudCountry::url($country),
                'locations' => [],
                'cities' => [],
                'plans' => 0,
                'min' => PHP_INT_MAX,
                'from' => null,
            ];

            $cityLabel = $loc->cityLabel() !== '' ? $loc->cityLabel() : $loc->countryLabel();

            $tree[$cont]['countries'][$country]['locations'][] = [
                'code' => $code,
                'city' => $cityLabel,
                'label' => $loc->label(),
                'flag' => $loc->flagEmoji(),
                'flag_svg' => $loc->flagSvg(),
                'plans' => $plans->count(),
                'min' => (int) $cheapest->price_irt,
                'from' => $cheapest->priceLabel(),
                'url' => $this->locUrl($code),
            ];

            // ⚠️ کلیدِ نام: چند مکانِ متفاوت می‌توانند یک برچسبِ شهر بدهند —
            // مثلاً وقتی زیرساخت شهر نمی‌دهد و هر دو به پایتخت برمی‌گردند.
            // بدونِ یکتاسازی، کارتِ کشور «برلین، برلین» نشان می‌داد.
            $tree[$cont]['countries'][$country]['cities'][$cityLabel] = true;

            $tree[$cont]['plans'] += $plans->count();
            $tree[$cont]['cities']++;
            $tree[$cont]['countries'][$country]['plans'] += $plans->count();

            if ((int) $cheapest->price_irt < $tree[$cont]['min']) {
                $tree[$cont]['min'] = (int) $cheapest->price_irt;
                $tree[$cont]['from'] = $cheapest->priceLabel();
            }
            if ((int) $cheapest->price_irt < $tree[$cont]['countries'][$country]['min']) {
                $tree[$cont]['countries'][$country]['min'] = (int) $cheapest->price_irt;
                $tree[$cont]['countries'][$country]['from'] = $cheapest->priceLabel();
            }
        }

        // ترتیبِ قاره‌ها از CONTINENTS می‌آید (اروپا اول)، درونِ هر قاره کشورها و
        // شهرها بر پایهٔ ارزان‌ترین قیمت.
        $ordered = [];
        foreach (array_keys(self::CONTINENTS) as $key) {
            if (! isset($tree[$key])) {
                continue;
            }
            $node = $tree[$key];
            usort($node['countries'], fn ($a, $b) => $a['min'] <=> $b['min'] ?: strcmp($a['code'], $b['code']));
            foreach ($node['countries'] as $i => $c) {
                usort($c['locations'], fn ($a, $b) => $a['min'] <=> $b['min'] ?: strcmp($a['code'], $b['code']));

                // نامِ شهرها به ترتیبِ همان مکان‌ها (ارزان‌ترین اول) و بی‌تکرار.
                // سقفِ ۴ تا: کارت باید در یک نگاه خوانده شود، نه فهرستِ ۱۲ شهر.
                $c['cities'] = array_slice(array_column($c['locations'], 'city'), 0, 4);
                $c['cities'] = array_values(array_unique($c['cities']));

                $node['countries'][$i] = $c;
            }
            $ordered[] = $node;
        }

        return $ordered;
    }

    /**
     * ردیف‌های جدولِ پلن — آمادهٔ نمایش و آمادهٔ فیلترِ سمتِ کاربر.
     *
     * هر ردیف عددهای خامِ لازم برای فیلتر/مرتب‌سازی را هم دارد (vcpu, ram, price)
     * تا جاوااسکریپت مجبور نباشد متنِ فارسی را پارس کند.
     *
     * @param  Collection<string, CloudPlan>  $offers
     * @param  Collection<string, CloudLocation>  $locations
     * @return array<int, array<string, mixed>>
     */
    private function planRows(Collection $offers, Collection $locations): array
    {
        $rows = [];

        foreach ($offers as $p) {
            $loc = $locations->get($p->location_code);
            if ($loc === null) {
                continue;
            }

            $rows[] = [
                'slug' => (string) $p->slug,
                'name' => (string) $p->public_name,
                'vcpu' => (int) $p->vcpu,
                'ram_mb' => (int) $p->ram_mb,
                'ram' => $p->ramLabel(),
                'disk' => $p->diskLabel(),
                'traffic' => $p->trafficLabel(),
                'cpu_kind' => $p->cpuKindLabel(),
                'price' => $p->priceLabel(),
                'price_n' => (int) $p->price_irt,
                'eur' => (int) $p->price_eur_cents,
                'loc_code' => (string) $p->location_code,
                'loc' => $loc->label(),
                'city' => $loc->cityLabel() !== '' ? $loc->cityLabel() : $loc->countryLabel(),
                'country' => strtoupper((string) $loc->country),
                'country_label' => $loc->countryLabel(),
                'flag' => $loc->flagEmoji(),
                'flag_svg' => $loc->flagSvg(),
                'loc_url' => $this->locUrl((string) $p->location_code),
                'buy_url' => $this->buyUrl((string) $p->location_code, (string) $p->slug),
            ];
        }

        // پیش‌فرضِ نمایش = ارزان‌ترین اول، همان چیزی که فیلترِ JS هم اول اعمال
        // می‌کند؛ اگر ترتیبِ سرور با ترتیبِ اولِ JS یکی نباشد، جدول در لحظهٔ
        // بارگذاری می‌پرد.
        usort($rows, fn ($a, $b) => $a['price_n'] <=> $b['price_n']
            ?: $a['vcpu'] <=> $b['vcpu']
            ?: strcmp($a['loc_code'], $b['loc_code']));

        return $rows;
    }

    /**
     * گزینه‌های فیلتر. فقط چیزی که واقعاً در جدول هست — فیلترِ بی‌نتیجه بدترین
     * تجربه است.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{countries: array<int, array<string, mixed>>, vcpu: array<int, int>, ram: array<int, array{mb: int, label: string}>}
     */
    private function facets(array $rows): array
    {
        $countries = [];
        $vcpu = [];
        $ram = [];

        foreach ($rows as $r) {
            $countries[$r['country']] ??= ['code' => $r['country'], 'label' => $r['country_label'], 'flag' => $r['flag'], 'n' => 0];
            $countries[$r['country']]['n']++;
            $vcpu[$r['vcpu']] = true;
            $ram[$r['ram_mb']] = $r['ram'];
        }

        usort($countries, fn ($a, $b) => $b['n'] <=> $a['n'] ?: strcmp($a['code'], $b['code']));

        $vcpuList = array_keys($vcpu);
        sort($vcpuList);

        ksort($ram);
        $ramList = [];
        foreach ($ram as $mb => $label) {
            $ramList[] = ['mb' => (int) $mb, 'label' => $label];
        }

        return ['countries' => $countries, 'vcpu' => $vcpuList, 'ram' => $ramList];
    }

    /**
     * متنِ سئوی یکتای هر مکان: چرا این‌جا، تأخیرِ تقریبی، مناسبِ چه کاری.
     *
     * @return array{why: string, note: ?string, lat_ir: string, lat_eu: string, good: array<int, string>}
     */
    private function locationSeo(CloudLocation $loc): array
    {
        $country = strtoupper((string) $loc->country);
        $cont = self::CONTINENT_OF[$country] ?? 'xx';
        [$ir, $eu] = self::LATENCY[$country] ?? self::LATENCY_BY_CONTINENT[$cont];

        $city = $loc->cityLabel() !== '' ? $loc->cityLabel() : $loc->countryLabel();
        $countryLabel = $loc->countryLabel();
        $contLabel = $this->pick(self::CONTINENTS[$cont] ?? self::CONTINENTS['xx']);

        $why = match (app()->getLocale()) {
            'en' => 'Our :city location sits in :country (:cont) and is delivered from a carrier-grade datacentre with NVMe storage and a dedicated IPv4. Latency from Iran is roughly :ir ms and from central Europe roughly :eu ms, so it is the right pick whenever your visitors — or the services you talk to — are on that side of the map.',
            'tr' => ':city konumumuz :country (:cont) içinde yer alır ve NVMe disk ile özel IPv4 sunan operatör sınıfı bir veri merkezinden teslim edilir. İran’dan gecikme yaklaşık :ir ms, Orta Avrupa’dan yaklaşık :eu ms’dir; ziyaretçileriniz veya konuştuğunuz servisler haritanın o tarafındaysa doğru seçimdir.',
            default => 'مکانِ :city در :country (:cont) قرار دارد و از مرکزِ دادهٔ عملیاتی با دیسکِ NVMe و آی‌پیِ اختصاصی تحویل داده می‌شود. تأخیر از ایران حدودِ :ir میلی‌ثانیه و از مرکزِ اروپا حدودِ :eu میلی‌ثانیه است؛ پس هرجا مخاطبِ شما — یا سرویسی که با آن حرف می‌زنید — آن سمتِ نقشه باشد، انتخابِ درستی است.',
        };

        return [
            'why' => strtr($why, [
                ':city' => $city,
                ':country' => $countryLabel,
                ':cont' => $contLabel,
                ':ir' => fa_num((string) $ir),
                ':eu' => fa_num((string) $eu),
            ]),
            'note' => isset(self::COUNTRY_NOTE[$country]) ? $this->pick(self::COUNTRY_NOTE[$country]) : null,
            'lat_ir' => fa_num((string) $ir),
            'lat_eu' => fa_num((string) $eu),
            'good' => $this->pick(self::GOOD_FOR[$cont] ?? self::GOOD_FOR['xx']),
        ];
    }

    /**
     * مکان‌های نزدیک برای لینک‌سازیِ داخلی: اول هم‌کشور، بعد هم‌قاره.
     *
     * @return array<int, array<string, mixed>>
     */
    private function nearby(CloudLocation $loc): array
    {
        $offers = CloudPlan::offers();
        if ($offers->isEmpty()) {
            return [];
        }

        $countLoc = $offers->pluck('location_code')->unique()->all();
        $locations = $this->activeLocations();
        $country = strtoupper((string) $loc->country);
        $cont = self::CONTINENT_OF[$country] ?? 'xx';

        $out = [];
        foreach ($countLoc as $code) {
            if ($code === $loc->code) {
                continue;
            }
            $other = $locations->get($code);
            if ($other === null) {
                continue;
            }
            $oc = strtoupper((string) $other->country);
            $rank = $oc === $country ? 0 : ((self::CONTINENT_OF[$oc] ?? 'xx') === $cont ? 1 : 2);

            $out[] = [
                'rank' => $rank,
                'code' => $code,
                'label' => $other->label(),
                'flag' => $other->flagEmoji(),
                'flag_svg' => $other->flagSvg(),
                'url' => $this->locUrl($code),
            ];
        }

        usort($out, fn ($a, $b) => $a['rank'] <=> $b['rank'] ?: strcmp($a['code'], $b['code']));

        return array_slice($out, 0, 12);
    }

    // ═══════════════════════════ JSON-LD ═══════════════════════════

    /**
     * دادهٔ ساختاریافتهٔ صفحهٔ فهرست.
     *
     * ⚠️ کلیدهای تودرتوی '@type' این‌جا (در فایلِ PHP) امن‌اند؛ همین آرایه اگر
     * درون‌خطی در Blade نوشته شود، پارسر را می‌شکند. ویو فقط `schema_ld()` را
     * صدا می‌زند.
     *
     * @param  array<string, string>  $t
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function indexLd(array $t, array $rows): array
    {
        return [
            'crumbs' => ['itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => __('ui.brand'), 'item' => url($this->localePath('/'))],
                ['@type' => 'ListItem', 'position' => 2, 'name' => $t['cloud_badge'], 'item' => url()->current()],
            ]],
            'list' => [
                'name' => $t['cloud_table_t'],
                'numberOfItems' => count($rows),
                'itemListElement' => $this->productList(array_slice($rows, 0, 24)),
            ],
            'faq' => $this->faqLd($t),
        ];
    }

    /**
     * @param  array<string, string>  $t
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function locationLd(array $t, string $locLabel, array $rows): array
    {
        return [
            'crumbs' => ['itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => __('ui.brand'), 'item' => url($this->localePath('/'))],
                ['@type' => 'ListItem', 'position' => 2, 'name' => $t['cloud_badge'], 'item' => $this->indexUrl()],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $locLabel, 'item' => url()->current()],
            ]],
            'list' => [
                'name' => strtr($t['cloud_loc_plans_t'], [':city' => $locLabel]),
                'numberOfItems' => count($rows),
                'itemListElement' => $this->productList(array_slice($rows, 0, 40)),
            ],
            'faq' => $this->faqLd($t),
        ];
    }

    /**
     * Product + Offer برای هر پلن.
     *
     * ارز: schema.org کدِ ISO-4217 می‌خواهد و «تومان» کدِ ISO ندارد، پس برای
     * فارسی IRR اعلام می‌شود و مبلغ ×۱۰ (ریال). محاسبه با عددِ صحیح انجام
     * می‌شود، بی‌هیچ float.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function productList(array $rows): array
    {
        $fa = app()->getLocale() === 'fa';
        $out = [];

        foreach ($rows as $i => $r) {
            $price = $fa
                ? (string) ((int) $r['price_n'] * 10)
                : number_format((int) $r['eur'] / 100, 2, '.', '');

            $out[] = [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'item' => [
                    '@type' => 'Product',
                    'name' => $r['name'].' — '.$r['loc'],
                    'description' => $r['vcpu'].' vCPU · '.$r['ram'].' RAM · '.$r['disk'].' · '.$r['loc'],
                    'category' => 'Cloud VPS',
                    // image + schema_offer_extras: رفعِ خطا/هشدارهای Merchant
                    // listings در Search Console (ممیزی ۲۴ اوت ۲۰۲۶).
                    'image' => [asset('assets/img/og.png')],
                    'brand' => ['@type' => 'Brand', 'name' => __('ui.brand')],
                    'offers' => schema_offer_extras($fa ? 'IRR' : 'EUR') + [
                        '@type' => 'Offer',
                        'url' => $r['loc_url'],
                        'price' => $price,
                        'priceCurrency' => $fa ? 'IRR' : 'EUR',
                        'availability' => 'https://schema.org/InStock',
                        'priceValidUntil' => now()->addDays(30)->toDateString(),
                    ],
                ],
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $t
     * @return array<int, array{q: string, a: string}>
     */
    private function faq(array $t): array
    {
        $out = [];
        for ($i = 1; $i <= 5; $i++) {
            $q = $t['cloud_faq_q'.$i] ?? '';
            $a = $t['cloud_faq_a'.$i] ?? '';
            if ($q !== '' && $a !== '') {
                $out[] = ['q' => $q, 'a' => $a];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $t
     * @return array<string, mixed>
     */
    private function faqLd(array $t): array
    {
        $items = [];
        foreach ($this->faq($t) as $row) {
            $items[] = [
                '@type' => 'Question',
                'name' => $row['q'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $row['a']],
            ];
        }

        return ['mainEntity' => $items];
    }

    // ═══════════════════════════ کمکی ═══════════════════════════

    /**
     * رشته‌های زبانِ جاری: اول `ui.*`، بعد متنِ درون‌کد.
     *
     * @return array<string, string>
     */
    private function strings(): array
    {
        $out = [];
        foreach (self::STRINGS as $key => $tr) {
            $out[$key] = Lang::has('ui.'.$key)
                ? (string) __('ui.'.$key)
                : $this->pick($tr);
        }

        return $out;
    }

    /**
     * مقدارِ زبانِ جاری از آرایهٔ سه‌زبانه، با پشتیبانِ en و بعد fa تا هیچ صفحه‌ای
     * خالی نماند.
     *
     * @param  array<string, mixed>  $tr
     */
    private function pick(array $tr): mixed
    {
        return $tr[app()->getLocale()] ?? $tr['en'] ?? $tr['fa'] ?? '';
    }

    /** مسیرِ زبانی: fa بی‌پیشوند، بقیه با /en و /tr */
    private function localePath(string $path): string
    {
        $locale = app()->getLocale();

        return ($locale === 'fa' ? '' : '/'.$locale).($path === '/' ? '' : $path) ?: '/';
    }

    /**
     * نشانیِ صفحهٔ یک مکان.
     *
     * اگر روت هنوز در `routes/web.php` ثبت نشده باشد (این کنترلر و ثبتِ روت دو
     * تغییرِ جدا هستند)، `route()` استثنا می‌دهد و کلِ صفحه ۵۰۰ می‌شود. پس اول
     * وجودِ روت پرسیده می‌شود و در نبودش مسیر دستی ساخته می‌شود — همان مسیری که
     * قطعهٔ روتِ تحویل‌داده‌شده می‌سازد.
     */
    private function locUrl(string $code): string
    {
        $prefix = AppServiceProvider::LOCALES[app()->getLocale()] ?? '';

        return Route::has($prefix.'cloud.location')
            ? route($prefix.'cloud.location', ['location' => $code])
            : url($this->localePath('/cloud/'.$code));
    }

    /** نشانیِ صفحهٔ فهرست — با همان محافظِ «روتِ هنوز ثبت‌نشده» */
    private function indexUrl(): string
    {
        $prefix = AppServiceProvider::LOCALES[app()->getLocale()] ?? '';

        return Route::has($prefix.'cloud.index')
            ? route($prefix.'cloud.index')
            : url($this->localePath('/cloud'));
    }

    /**
     * لینکِ خرید → سازندهٔ سرورِ پنل.
     *
     * مسیر عمداً نسبی است: روی دامنهٔ اصلی، میدل‌ورِ ConsoleHost هر مسیرِ
     * `account/*` را ۳۰۱ به console.servernet.cloud می‌برد، پس نه میزبان را
     * سخت‌کد می‌کنیم و نه در توسعهٔ محلی به دامنهٔ تولید پرت می‌شویم.
     */
    private function buyUrl(string $locationCode, ?string $slug = null): string
    {
        $query = array_filter(['location' => $locationCode, 'plan' => $slug]);

        return url($this->localePath('/account/cloud-store')).'?'.http_build_query($query);
    }
}
