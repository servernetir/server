<?php

namespace Database\Seeders;

use App\Models\ServerPart;
use Illuminate\Database\Seeder;

/**
 * کاتالوگ قطعاتِ سرور HPE ProLiant — نسل ۸ تا ۱۲.
 *
 * ═══ منبعِ داده ═══
 *
 * مشخصات فنی از QuickSpecsِ رسمی HPE و صفحهٔ محصولِ Intel درآمده، نه از حدس.
 * قیمت‌ها **میانگینِ بازارِ بازسازی‌شدهٔ اروپا به یورو** (مرداد ۱۴۰۵ / اوت
 * ۲۰۲۶) است — عمداً یورو، چون قطعهٔ سرور از همان بازار خریده می‌شود.
 *
 * ⚠️ قیمتِ تومانی این‌جا **ذخیره نمی‌شود**. `part_price()` با نرخِ زندهٔ یوروی
 * تنظیماتِ سایت تبدیل می‌کند. اگر عددِ تومانی ذخیره می‌شد، با هر جهشِ ارز کلِ
 * کاتالوگ باید دستی به‌روز می‌شد — که در عمل نمی‌شود — و فروشگاه بی‌سروصدا
 * زیرِ قیمتِ خرید می‌فروخت.
 *
 * 🔴 `firstOrCreate` و نه `updateOrCreate`.
 *
 * قیمت و موجودی را مدیر در `/admin/parts` ویرایش می‌کند. با `updateOrCreate`
 * اجرای دوبارهٔ سیدر — که در هر دیپلوی ممکن است — همهٔ آن ویرایش‌ها را به
 * عددهای این فایل برمی‌گرداند، بی‌هیچ خطایی و بی‌آنکه کسی بفهمد. سیدر فقط
 * ردیفِ **نبوده** را می‌سازد.
 */
class ServerPartSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->catalogue() as $row) {
            ServerPart::firstOrCreate(['slug' => $row['slug']], $row);
        }
    }

    /** @return list<array<string, mixed>> */
    private function catalogue(): array
    {
        return [
            [
                'slug' => 'xeon-e5-2630-v2',
                'category' => 'cpu',
                'brand' => 'Intel',
                'compat_gens' => [
                    'gen8',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 1680,
                'in_stock' => true,
                'popular' => false,
                'sort' => 10,
                'name' => [
                    'fa' => 'Intel Xeon E5-2630 v2',
                    'en' => 'Intel Xeon E5-2630 v2',
                    'tr' => 'Intel Xeon E5-2630 v2',
                ],
                'tagline' => [
                    'fa' => '6 هسته، 12 رشته، تا 3.1 گیگاهرتز',
                    'en' => '6 cores, 12 threads, up to 3.1 GHz',
                    'tr' => '6 çekirdek, 12 iş parçacığı, 3.1 GHz’e kadar',
                ],
                'summary' => [
                    'fa' => 'پردازندهٔ Ivy Bridge-EP روی سوکت LGA2011 برای سرورهای Gen8؛ 6 هستهٔ فیزیکی، 15 مگابایت کش L3 و توان طراحی حرارتی 80 وات.',
                    'en' => 'Ivy Bridge-EP processor on socket LGA2011 for Gen8 servers: 6 physical cores, 15 MB of L3 cache and a 80 W thermal design power.',
                    'tr' => 'Gen8 sunucular için LGA2011 soketinde Ivy Bridge-EP işlemci: 6 fiziksel çekirdek, 15 MB L3 önbellek ve 80 W termal tasarım gücü.',
                ],
                'body' => [
                    'fa' => 'Intel Xeon E5-2630 v2 پردازنده‌ای از خانوادهٔ Ivy Bridge-EP است که روی سوکت LGA2011 می‌نشیند و در سرورهای HPE ProLiant Gen8 استفاده می‌شود. 6 هستهٔ فیزیکی و 12 رشتهٔ پردازشی دارد، از فرکانس پایهٔ 2.6 گیگاهرتز شروع می‌کند و در بار سبک تا 3.1 گیگاهرتز بالا می‌رود. 15 مگابایت کش L3 و توان طراحی حرارتی 80 وات.

برای میزبانی وب، سرور فایل، محیط آزمایشگاهی و مجازی‌سازی سبک انتخاب اقتصادی‌ای است. هستهٔ زیاد با قیمت پایین، به قیمت مصرف برق بالاتر نسبت به نسل‌های جدید.

پیش از سفارش دو نکته را چک کنید: اول اینکه در پیکربندی دو پردازنده‌ای، هر دو سوکت باید مدل یکسان داشته باشند — سرور با دو مدل متفاوت بالا نمی‌آید. دوم اینکه برای توان بالای ۱۳۵ وات، هیت‌سینک پرکارایی و کیت فن لازم است؛ با هیت‌سینک استاندارد سرور خودش را throttle می‌کند و کارایی‌ای که خریده‌اید را نمی‌گیرید.

هر پردازنده پیش از ارسال روی همان نسل سرور بوت و تست می‌شود.',
                    'en' => 'The Intel Xeon E5-2630 v2 is a Ivy Bridge-EP processor for socket LGA2011, used in HPE ProLiant Gen8 servers. It has 6 physical cores and 12 threads, starts at a 2.6 GHz base clock and boosts to 3.1 GHz under light load. It carries 15 MB of L3 cache and a 80 W thermal design power.

An economical choice for web hosting, file servers, lab environments and light virtualisation: plenty of cores for very little money, at the cost of higher power draw than newer generations.

Two things to check before ordering. First, in a dual-socket configuration both sockets must hold the identical model — a server with two different SKUs will not post. Second, anything above 135 W needs the high-performance heatsink and fan kit; with the standard heatsink the server throttles itself and you never get the performance you paid for.

Every processor is booted and tested on the same server generation before it ships.',
                    'tr' => 'Intel Xeon E5-2630 v2, LGA2011 soketi için bir Ivy Bridge-EP işlemcisidir ve HPE ProLiant Gen8 sunucularında kullanılır. 6 fiziksel çekirdek ve 12 iş parçacığına sahiptir, 2.6 GHz temel hızda başlar ve hafif yükte 3.1 GHz’e çıkar. 15 MB L3 önbellek ve 80 W termal tasarım gücü taşır.

Web barındırma, dosya sunucusu, laboratuvar ortamı ve hafif sanallaştırma için ekonomik bir seçim: çok az paraya bol çekirdek, karşılığında yeni nesillere göre daha yüksek güç tüketimi.

Sipariş öncesi iki nokta: Birincisi, çift soketli yapılandırmada her iki soket de aynı modeli taşımalıdır — iki farklı model ile sunucu açılmaz. İkincisi, 135 W üzerindeki her işlemci yüksek performanslı soğutucu ve fan kiti ister; standart soğutucuyla sunucu kendini kısar ve ödediğiniz performansı alamazsınız.

Her işlemci sevkiyattan önce aynı sunucu neslinde başlatılıp test edilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'خانوادهٔ پردازنده',
                            'en' => 'Processor family',
                            'tr' => 'İşlemci ailesi',
                        ],
                        'value' => [
                            'fa' => 'Ivy Bridge-EP',
                            'en' => 'Ivy Bridge-EP',
                            'tr' => 'Ivy Bridge-EP',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سوکت',
                            'en' => 'Socket',
                            'tr' => 'Soket',
                        ],
                        'value' => [
                            'fa' => 'LGA2011',
                            'en' => 'LGA2011',
                            'tr' => 'LGA2011',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'هسته / رشته',
                            'en' => 'Cores / threads',
                            'tr' => 'Çekirdek / iş parçacığı',
                        ],
                        'value' => [
                            'fa' => '6 / 12',
                            'en' => '6 / 12',
                            'tr' => '6 / 12',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس پایه',
                            'en' => 'Base clock',
                            'tr' => 'Temel hız',
                        ],
                        'value' => [
                            'fa' => '2.6 GHz',
                            'en' => '2.6 GHz',
                            'tr' => '2.6 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس توربو',
                            'en' => 'Max turbo',
                            'tr' => 'Maks. turbo',
                        ],
                        'value' => [
                            'fa' => '3.1 GHz',
                            'en' => '3.1 GHz',
                            'tr' => '3.1 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کش L3',
                            'en' => 'L3 cache',
                            'tr' => 'L3 önbellek',
                        ],
                        'value' => [
                            'fa' => '15 MB',
                            'en' => '15 MB',
                            'tr' => '15 MB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'توان طراحی حرارتی',
                            'en' => 'TDP',
                            'tr' => 'TDP',
                        ],
                        'value' => [
                            'fa' => '80 W',
                            'en' => '80 W',
                            'tr' => '80 W',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen8',
                            'en' => 'Gen8',
                            'tr' => 'Gen8',
                        ],
                    ],
                ],
                'attrs' => [
                    'cores' => 6,
                    'threads' => 12,
                    'ghz' => 2.6,
                    'ghz_turbo' => 3.1,
                    'cache_mb' => 15,
                    'tdp' => 80,
                ],
            ],
            [
                'slug' => 'xeon-e5-2650-v2',
                'category' => 'cpu',
                'brand' => 'Intel',
                'compat_gens' => [
                    'gen8',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 1800,
                'in_stock' => true,
                'popular' => true,
                'sort' => 20,
                'name' => [
                    'fa' => 'Intel Xeon E5-2650 v2',
                    'en' => 'Intel Xeon E5-2650 v2',
                    'tr' => 'Intel Xeon E5-2650 v2',
                ],
                'tagline' => [
                    'fa' => '8 هسته، 16 رشته، تا 3.4 گیگاهرتز',
                    'en' => '8 cores, 16 threads, up to 3.4 GHz',
                    'tr' => '8 çekirdek, 16 iş parçacığı, 3.4 GHz’e kadar',
                ],
                'summary' => [
                    'fa' => 'پردازندهٔ Ivy Bridge-EP روی سوکت LGA2011 برای سرورهای Gen8؛ 8 هستهٔ فیزیکی، 20 مگابایت کش L3 و توان طراحی حرارتی 95 وات.',
                    'en' => 'Ivy Bridge-EP processor on socket LGA2011 for Gen8 servers: 8 physical cores, 20 MB of L3 cache and a 95 W thermal design power.',
                    'tr' => 'Gen8 sunucular için LGA2011 soketinde Ivy Bridge-EP işlemci: 8 fiziksel çekirdek, 20 MB L3 önbellek ve 95 W termal tasarım gücü.',
                ],
                'body' => [
                    'fa' => 'Intel Xeon E5-2650 v2 پردازنده‌ای از خانوادهٔ Ivy Bridge-EP است که روی سوکت LGA2011 می‌نشیند و در سرورهای HPE ProLiant Gen8 استفاده می‌شود. 8 هستهٔ فیزیکی و 16 رشتهٔ پردازشی دارد، از فرکانس پایهٔ 2.6 گیگاهرتز شروع می‌کند و در بار سبک تا 3.4 گیگاهرتز بالا می‌رود. 20 مگابایت کش L3 و توان طراحی حرارتی 95 وات.

برای میزبانی وب، سرور فایل، محیط آزمایشگاهی و مجازی‌سازی سبک انتخاب اقتصادی‌ای است. هستهٔ زیاد با قیمت پایین، به قیمت مصرف برق بالاتر نسبت به نسل‌های جدید.

پیش از سفارش دو نکته را چک کنید: اول اینکه در پیکربندی دو پردازنده‌ای، هر دو سوکت باید مدل یکسان داشته باشند — سرور با دو مدل متفاوت بالا نمی‌آید. دوم اینکه برای توان بالای ۱۳۵ وات، هیت‌سینک پرکارایی و کیت فن لازم است؛ با هیت‌سینک استاندارد سرور خودش را throttle می‌کند و کارایی‌ای که خریده‌اید را نمی‌گیرید.

هر پردازنده پیش از ارسال روی همان نسل سرور بوت و تست می‌شود.',
                    'en' => 'The Intel Xeon E5-2650 v2 is a Ivy Bridge-EP processor for socket LGA2011, used in HPE ProLiant Gen8 servers. It has 8 physical cores and 16 threads, starts at a 2.6 GHz base clock and boosts to 3.4 GHz under light load. It carries 20 MB of L3 cache and a 95 W thermal design power.

An economical choice for web hosting, file servers, lab environments and light virtualisation: plenty of cores for very little money, at the cost of higher power draw than newer generations.

Two things to check before ordering. First, in a dual-socket configuration both sockets must hold the identical model — a server with two different SKUs will not post. Second, anything above 135 W needs the high-performance heatsink and fan kit; with the standard heatsink the server throttles itself and you never get the performance you paid for.

Every processor is booted and tested on the same server generation before it ships.',
                    'tr' => 'Intel Xeon E5-2650 v2, LGA2011 soketi için bir Ivy Bridge-EP işlemcisidir ve HPE ProLiant Gen8 sunucularında kullanılır. 8 fiziksel çekirdek ve 16 iş parçacığına sahiptir, 2.6 GHz temel hızda başlar ve hafif yükte 3.4 GHz’e çıkar. 20 MB L3 önbellek ve 95 W termal tasarım gücü taşır.

Web barındırma, dosya sunucusu, laboratuvar ortamı ve hafif sanallaştırma için ekonomik bir seçim: çok az paraya bol çekirdek, karşılığında yeni nesillere göre daha yüksek güç tüketimi.

Sipariş öncesi iki nokta: Birincisi, çift soketli yapılandırmada her iki soket de aynı modeli taşımalıdır — iki farklı model ile sunucu açılmaz. İkincisi, 135 W üzerindeki her işlemci yüksek performanslı soğutucu ve fan kiti ister; standart soğutucuyla sunucu kendini kısar ve ödediğiniz performansı alamazsınız.

Her işlemci sevkiyattan önce aynı sunucu neslinde başlatılıp test edilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'خانوادهٔ پردازنده',
                            'en' => 'Processor family',
                            'tr' => 'İşlemci ailesi',
                        ],
                        'value' => [
                            'fa' => 'Ivy Bridge-EP',
                            'en' => 'Ivy Bridge-EP',
                            'tr' => 'Ivy Bridge-EP',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سوکت',
                            'en' => 'Socket',
                            'tr' => 'Soket',
                        ],
                        'value' => [
                            'fa' => 'LGA2011',
                            'en' => 'LGA2011',
                            'tr' => 'LGA2011',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'هسته / رشته',
                            'en' => 'Cores / threads',
                            'tr' => 'Çekirdek / iş parçacığı',
                        ],
                        'value' => [
                            'fa' => '8 / 16',
                            'en' => '8 / 16',
                            'tr' => '8 / 16',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس پایه',
                            'en' => 'Base clock',
                            'tr' => 'Temel hız',
                        ],
                        'value' => [
                            'fa' => '2.6 GHz',
                            'en' => '2.6 GHz',
                            'tr' => '2.6 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس توربو',
                            'en' => 'Max turbo',
                            'tr' => 'Maks. turbo',
                        ],
                        'value' => [
                            'fa' => '3.4 GHz',
                            'en' => '3.4 GHz',
                            'tr' => '3.4 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کش L3',
                            'en' => 'L3 cache',
                            'tr' => 'L3 önbellek',
                        ],
                        'value' => [
                            'fa' => '20 MB',
                            'en' => '20 MB',
                            'tr' => '20 MB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'توان طراحی حرارتی',
                            'en' => 'TDP',
                            'tr' => 'TDP',
                        ],
                        'value' => [
                            'fa' => '95 W',
                            'en' => '95 W',
                            'tr' => '95 W',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen8',
                            'en' => 'Gen8',
                            'tr' => 'Gen8',
                        ],
                    ],
                ],
                'attrs' => [
                    'cores' => 8,
                    'threads' => 16,
                    'ghz' => 2.6,
                    'ghz_turbo' => 3.4,
                    'cache_mb' => 20,
                    'tdp' => 95,
                ],
            ],
            [
                'slug' => 'xeon-e5-2660-v2',
                'category' => 'cpu',
                'brand' => 'Intel',
                'compat_gens' => [
                    'gen8',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 2000,
                'in_stock' => true,
                'popular' => false,
                'sort' => 30,
                'name' => [
                    'fa' => 'Intel Xeon E5-2660 v2',
                    'en' => 'Intel Xeon E5-2660 v2',
                    'tr' => 'Intel Xeon E5-2660 v2',
                ],
                'tagline' => [
                    'fa' => '10 هسته، 20 رشته، تا 3.0 گیگاهرتز',
                    'en' => '10 cores, 20 threads, up to 3.0 GHz',
                    'tr' => '10 çekirdek, 20 iş parçacığı, 3.0 GHz’e kadar',
                ],
                'summary' => [
                    'fa' => 'پردازندهٔ Ivy Bridge-EP روی سوکت LGA2011 برای سرورهای Gen8؛ 10 هستهٔ فیزیکی، 25 مگابایت کش L3 و توان طراحی حرارتی 95 وات.',
                    'en' => 'Ivy Bridge-EP processor on socket LGA2011 for Gen8 servers: 10 physical cores, 25 MB of L3 cache and a 95 W thermal design power.',
                    'tr' => 'Gen8 sunucular için LGA2011 soketinde Ivy Bridge-EP işlemci: 10 fiziksel çekirdek, 25 MB L3 önbellek ve 95 W termal tasarım gücü.',
                ],
                'body' => [
                    'fa' => 'Intel Xeon E5-2660 v2 پردازنده‌ای از خانوادهٔ Ivy Bridge-EP است که روی سوکت LGA2011 می‌نشیند و در سرورهای HPE ProLiant Gen8 استفاده می‌شود. 10 هستهٔ فیزیکی و 20 رشتهٔ پردازشی دارد، از فرکانس پایهٔ 2.2 گیگاهرتز شروع می‌کند و در بار سبک تا 3.0 گیگاهرتز بالا می‌رود. 25 مگابایت کش L3 و توان طراحی حرارتی 95 وات.

برای میزبانی وب، سرور فایل، محیط آزمایشگاهی و مجازی‌سازی سبک انتخاب اقتصادی‌ای است. هستهٔ زیاد با قیمت پایین، به قیمت مصرف برق بالاتر نسبت به نسل‌های جدید.

پیش از سفارش دو نکته را چک کنید: اول اینکه در پیکربندی دو پردازنده‌ای، هر دو سوکت باید مدل یکسان داشته باشند — سرور با دو مدل متفاوت بالا نمی‌آید. دوم اینکه برای توان بالای ۱۳۵ وات، هیت‌سینک پرکارایی و کیت فن لازم است؛ با هیت‌سینک استاندارد سرور خودش را throttle می‌کند و کارایی‌ای که خریده‌اید را نمی‌گیرید.

هر پردازنده پیش از ارسال روی همان نسل سرور بوت و تست می‌شود.',
                    'en' => 'The Intel Xeon E5-2660 v2 is a Ivy Bridge-EP processor for socket LGA2011, used in HPE ProLiant Gen8 servers. It has 10 physical cores and 20 threads, starts at a 2.2 GHz base clock and boosts to 3.0 GHz under light load. It carries 25 MB of L3 cache and a 95 W thermal design power.

An economical choice for web hosting, file servers, lab environments and light virtualisation: plenty of cores for very little money, at the cost of higher power draw than newer generations.

Two things to check before ordering. First, in a dual-socket configuration both sockets must hold the identical model — a server with two different SKUs will not post. Second, anything above 135 W needs the high-performance heatsink and fan kit; with the standard heatsink the server throttles itself and you never get the performance you paid for.

Every processor is booted and tested on the same server generation before it ships.',
                    'tr' => 'Intel Xeon E5-2660 v2, LGA2011 soketi için bir Ivy Bridge-EP işlemcisidir ve HPE ProLiant Gen8 sunucularında kullanılır. 10 fiziksel çekirdek ve 20 iş parçacığına sahiptir, 2.2 GHz temel hızda başlar ve hafif yükte 3.0 GHz’e çıkar. 25 MB L3 önbellek ve 95 W termal tasarım gücü taşır.

Web barındırma, dosya sunucusu, laboratuvar ortamı ve hafif sanallaştırma için ekonomik bir seçim: çok az paraya bol çekirdek, karşılığında yeni nesillere göre daha yüksek güç tüketimi.

Sipariş öncesi iki nokta: Birincisi, çift soketli yapılandırmada her iki soket de aynı modeli taşımalıdır — iki farklı model ile sunucu açılmaz. İkincisi, 135 W üzerindeki her işlemci yüksek performanslı soğutucu ve fan kiti ister; standart soğutucuyla sunucu kendini kısar ve ödediğiniz performansı alamazsınız.

Her işlemci sevkiyattan önce aynı sunucu neslinde başlatılıp test edilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'خانوادهٔ پردازنده',
                            'en' => 'Processor family',
                            'tr' => 'İşlemci ailesi',
                        ],
                        'value' => [
                            'fa' => 'Ivy Bridge-EP',
                            'en' => 'Ivy Bridge-EP',
                            'tr' => 'Ivy Bridge-EP',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سوکت',
                            'en' => 'Socket',
                            'tr' => 'Soket',
                        ],
                        'value' => [
                            'fa' => 'LGA2011',
                            'en' => 'LGA2011',
                            'tr' => 'LGA2011',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'هسته / رشته',
                            'en' => 'Cores / threads',
                            'tr' => 'Çekirdek / iş parçacığı',
                        ],
                        'value' => [
                            'fa' => '10 / 20',
                            'en' => '10 / 20',
                            'tr' => '10 / 20',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس پایه',
                            'en' => 'Base clock',
                            'tr' => 'Temel hız',
                        ],
                        'value' => [
                            'fa' => '2.2 GHz',
                            'en' => '2.2 GHz',
                            'tr' => '2.2 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس توربو',
                            'en' => 'Max turbo',
                            'tr' => 'Maks. turbo',
                        ],
                        'value' => [
                            'fa' => '3.0 GHz',
                            'en' => '3.0 GHz',
                            'tr' => '3.0 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کش L3',
                            'en' => 'L3 cache',
                            'tr' => 'L3 önbellek',
                        ],
                        'value' => [
                            'fa' => '25 MB',
                            'en' => '25 MB',
                            'tr' => '25 MB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'توان طراحی حرارتی',
                            'en' => 'TDP',
                            'tr' => 'TDP',
                        ],
                        'value' => [
                            'fa' => '95 W',
                            'en' => '95 W',
                            'tr' => '95 W',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen8',
                            'en' => 'Gen8',
                            'tr' => 'Gen8',
                        ],
                    ],
                ],
                'attrs' => [
                    'cores' => 10,
                    'threads' => 20,
                    'ghz' => 2.2,
                    'ghz_turbo' => 3.0,
                    'cache_mb' => 25,
                    'tdp' => 95,
                ],
            ],
            [
                'slug' => 'xeon-e5-2670-v2',
                'category' => 'cpu',
                'brand' => 'Intel',
                'compat_gens' => [
                    'gen8',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 2500,
                'in_stock' => true,
                'popular' => true,
                'sort' => 40,
                'name' => [
                    'fa' => 'Intel Xeon E5-2670 v2',
                    'en' => 'Intel Xeon E5-2670 v2',
                    'tr' => 'Intel Xeon E5-2670 v2',
                ],
                'tagline' => [
                    'fa' => '10 هسته، 20 رشته، تا 3.3 گیگاهرتز',
                    'en' => '10 cores, 20 threads, up to 3.3 GHz',
                    'tr' => '10 çekirdek, 20 iş parçacığı, 3.3 GHz’e kadar',
                ],
                'summary' => [
                    'fa' => 'پردازندهٔ Ivy Bridge-EP روی سوکت LGA2011 برای سرورهای Gen8؛ 10 هستهٔ فیزیکی، 25 مگابایت کش L3 و توان طراحی حرارتی 115 وات.',
                    'en' => 'Ivy Bridge-EP processor on socket LGA2011 for Gen8 servers: 10 physical cores, 25 MB of L3 cache and a 115 W thermal design power.',
                    'tr' => 'Gen8 sunucular için LGA2011 soketinde Ivy Bridge-EP işlemci: 10 fiziksel çekirdek, 25 MB L3 önbellek ve 115 W termal tasarım gücü.',
                ],
                'body' => [
                    'fa' => 'Intel Xeon E5-2670 v2 پردازنده‌ای از خانوادهٔ Ivy Bridge-EP است که روی سوکت LGA2011 می‌نشیند و در سرورهای HPE ProLiant Gen8 استفاده می‌شود. 10 هستهٔ فیزیکی و 20 رشتهٔ پردازشی دارد، از فرکانس پایهٔ 2.5 گیگاهرتز شروع می‌کند و در بار سبک تا 3.3 گیگاهرتز بالا می‌رود. 25 مگابایت کش L3 و توان طراحی حرارتی 115 وات.

برای میزبانی وب، سرور فایل، محیط آزمایشگاهی و مجازی‌سازی سبک انتخاب اقتصادی‌ای است. هستهٔ زیاد با قیمت پایین، به قیمت مصرف برق بالاتر نسبت به نسل‌های جدید.

پیش از سفارش دو نکته را چک کنید: اول اینکه در پیکربندی دو پردازنده‌ای، هر دو سوکت باید مدل یکسان داشته باشند — سرور با دو مدل متفاوت بالا نمی‌آید. دوم اینکه برای توان بالای ۱۳۵ وات، هیت‌سینک پرکارایی و کیت فن لازم است؛ با هیت‌سینک استاندارد سرور خودش را throttle می‌کند و کارایی‌ای که خریده‌اید را نمی‌گیرید.

هر پردازنده پیش از ارسال روی همان نسل سرور بوت و تست می‌شود.',
                    'en' => 'The Intel Xeon E5-2670 v2 is a Ivy Bridge-EP processor for socket LGA2011, used in HPE ProLiant Gen8 servers. It has 10 physical cores and 20 threads, starts at a 2.5 GHz base clock and boosts to 3.3 GHz under light load. It carries 25 MB of L3 cache and a 115 W thermal design power.

An economical choice for web hosting, file servers, lab environments and light virtualisation: plenty of cores for very little money, at the cost of higher power draw than newer generations.

Two things to check before ordering. First, in a dual-socket configuration both sockets must hold the identical model — a server with two different SKUs will not post. Second, anything above 135 W needs the high-performance heatsink and fan kit; with the standard heatsink the server throttles itself and you never get the performance you paid for.

Every processor is booted and tested on the same server generation before it ships.',
                    'tr' => 'Intel Xeon E5-2670 v2, LGA2011 soketi için bir Ivy Bridge-EP işlemcisidir ve HPE ProLiant Gen8 sunucularında kullanılır. 10 fiziksel çekirdek ve 20 iş parçacığına sahiptir, 2.5 GHz temel hızda başlar ve hafif yükte 3.3 GHz’e çıkar. 25 MB L3 önbellek ve 115 W termal tasarım gücü taşır.

Web barındırma, dosya sunucusu, laboratuvar ortamı ve hafif sanallaştırma için ekonomik bir seçim: çok az paraya bol çekirdek, karşılığında yeni nesillere göre daha yüksek güç tüketimi.

Sipariş öncesi iki nokta: Birincisi, çift soketli yapılandırmada her iki soket de aynı modeli taşımalıdır — iki farklı model ile sunucu açılmaz. İkincisi, 135 W üzerindeki her işlemci yüksek performanslı soğutucu ve fan kiti ister; standart soğutucuyla sunucu kendini kısar ve ödediğiniz performansı alamazsınız.

Her işlemci sevkiyattan önce aynı sunucu neslinde başlatılıp test edilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'خانوادهٔ پردازنده',
                            'en' => 'Processor family',
                            'tr' => 'İşlemci ailesi',
                        ],
                        'value' => [
                            'fa' => 'Ivy Bridge-EP',
                            'en' => 'Ivy Bridge-EP',
                            'tr' => 'Ivy Bridge-EP',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سوکت',
                            'en' => 'Socket',
                            'tr' => 'Soket',
                        ],
                        'value' => [
                            'fa' => 'LGA2011',
                            'en' => 'LGA2011',
                            'tr' => 'LGA2011',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'هسته / رشته',
                            'en' => 'Cores / threads',
                            'tr' => 'Çekirdek / iş parçacığı',
                        ],
                        'value' => [
                            'fa' => '10 / 20',
                            'en' => '10 / 20',
                            'tr' => '10 / 20',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس پایه',
                            'en' => 'Base clock',
                            'tr' => 'Temel hız',
                        ],
                        'value' => [
                            'fa' => '2.5 GHz',
                            'en' => '2.5 GHz',
                            'tr' => '2.5 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس توربو',
                            'en' => 'Max turbo',
                            'tr' => 'Maks. turbo',
                        ],
                        'value' => [
                            'fa' => '3.3 GHz',
                            'en' => '3.3 GHz',
                            'tr' => '3.3 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کش L3',
                            'en' => 'L3 cache',
                            'tr' => 'L3 önbellek',
                        ],
                        'value' => [
                            'fa' => '25 MB',
                            'en' => '25 MB',
                            'tr' => '25 MB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'توان طراحی حرارتی',
                            'en' => 'TDP',
                            'tr' => 'TDP',
                        ],
                        'value' => [
                            'fa' => '115 W',
                            'en' => '115 W',
                            'tr' => '115 W',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen8',
                            'en' => 'Gen8',
                            'tr' => 'Gen8',
                        ],
                    ],
                ],
                'attrs' => [
                    'cores' => 10,
                    'threads' => 20,
                    'ghz' => 2.5,
                    'ghz_turbo' => 3.3,
                    'cache_mb' => 25,
                    'tdp' => 115,
                ],
            ],
            [
                'slug' => 'xeon-e5-2690-v2',
                'category' => 'cpu',
                'brand' => 'Intel',
                'compat_gens' => [
                    'gen8',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 3400,
                'in_stock' => true,
                'popular' => false,
                'sort' => 50,
                'name' => [
                    'fa' => 'Intel Xeon E5-2690 v2',
                    'en' => 'Intel Xeon E5-2690 v2',
                    'tr' => 'Intel Xeon E5-2690 v2',
                ],
                'tagline' => [
                    'fa' => '10 هسته، 20 رشته، تا 3.6 گیگاهرتز',
                    'en' => '10 cores, 20 threads, up to 3.6 GHz',
                    'tr' => '10 çekirdek, 20 iş parçacığı, 3.6 GHz’e kadar',
                ],
                'summary' => [
                    'fa' => 'پردازندهٔ Ivy Bridge-EP روی سوکت LGA2011 برای سرورهای Gen8؛ 10 هستهٔ فیزیکی، 25 مگابایت کش L3 و توان طراحی حرارتی 130 وات.',
                    'en' => 'Ivy Bridge-EP processor on socket LGA2011 for Gen8 servers: 10 physical cores, 25 MB of L3 cache and a 130 W thermal design power.',
                    'tr' => 'Gen8 sunucular için LGA2011 soketinde Ivy Bridge-EP işlemci: 10 fiziksel çekirdek, 25 MB L3 önbellek ve 130 W termal tasarım gücü.',
                ],
                'body' => [
                    'fa' => 'Intel Xeon E5-2690 v2 پردازنده‌ای از خانوادهٔ Ivy Bridge-EP است که روی سوکت LGA2011 می‌نشیند و در سرورهای HPE ProLiant Gen8 استفاده می‌شود. 10 هستهٔ فیزیکی و 20 رشتهٔ پردازشی دارد، از فرکانس پایهٔ 3.0 گیگاهرتز شروع می‌کند و در بار سبک تا 3.6 گیگاهرتز بالا می‌رود. 25 مگابایت کش L3 و توان طراحی حرارتی 130 وات.

برای میزبانی وب، سرور فایل، محیط آزمایشگاهی و مجازی‌سازی سبک انتخاب اقتصادی‌ای است. هستهٔ زیاد با قیمت پایین، به قیمت مصرف برق بالاتر نسبت به نسل‌های جدید.

پیش از سفارش دو نکته را چک کنید: اول اینکه در پیکربندی دو پردازنده‌ای، هر دو سوکت باید مدل یکسان داشته باشند — سرور با دو مدل متفاوت بالا نمی‌آید. دوم اینکه برای توان بالای ۱۳۵ وات، هیت‌سینک پرکارایی و کیت فن لازم است؛ با هیت‌سینک استاندارد سرور خودش را throttle می‌کند و کارایی‌ای که خریده‌اید را نمی‌گیرید.

هر پردازنده پیش از ارسال روی همان نسل سرور بوت و تست می‌شود.',
                    'en' => 'The Intel Xeon E5-2690 v2 is a Ivy Bridge-EP processor for socket LGA2011, used in HPE ProLiant Gen8 servers. It has 10 physical cores and 20 threads, starts at a 3.0 GHz base clock and boosts to 3.6 GHz under light load. It carries 25 MB of L3 cache and a 130 W thermal design power.

An economical choice for web hosting, file servers, lab environments and light virtualisation: plenty of cores for very little money, at the cost of higher power draw than newer generations.

Two things to check before ordering. First, in a dual-socket configuration both sockets must hold the identical model — a server with two different SKUs will not post. Second, anything above 135 W needs the high-performance heatsink and fan kit; with the standard heatsink the server throttles itself and you never get the performance you paid for.

Every processor is booted and tested on the same server generation before it ships.',
                    'tr' => 'Intel Xeon E5-2690 v2, LGA2011 soketi için bir Ivy Bridge-EP işlemcisidir ve HPE ProLiant Gen8 sunucularında kullanılır. 10 fiziksel çekirdek ve 20 iş parçacığına sahiptir, 3.0 GHz temel hızda başlar ve hafif yükte 3.6 GHz’e çıkar. 25 MB L3 önbellek ve 130 W termal tasarım gücü taşır.

Web barındırma, dosya sunucusu, laboratuvar ortamı ve hafif sanallaştırma için ekonomik bir seçim: çok az paraya bol çekirdek, karşılığında yeni nesillere göre daha yüksek güç tüketimi.

Sipariş öncesi iki nokta: Birincisi, çift soketli yapılandırmada her iki soket de aynı modeli taşımalıdır — iki farklı model ile sunucu açılmaz. İkincisi, 135 W üzerindeki her işlemci yüksek performanslı soğutucu ve fan kiti ister; standart soğutucuyla sunucu kendini kısar ve ödediğiniz performansı alamazsınız.

Her işlemci sevkiyattan önce aynı sunucu neslinde başlatılıp test edilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'خانوادهٔ پردازنده',
                            'en' => 'Processor family',
                            'tr' => 'İşlemci ailesi',
                        ],
                        'value' => [
                            'fa' => 'Ivy Bridge-EP',
                            'en' => 'Ivy Bridge-EP',
                            'tr' => 'Ivy Bridge-EP',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سوکت',
                            'en' => 'Socket',
                            'tr' => 'Soket',
                        ],
                        'value' => [
                            'fa' => 'LGA2011',
                            'en' => 'LGA2011',
                            'tr' => 'LGA2011',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'هسته / رشته',
                            'en' => 'Cores / threads',
                            'tr' => 'Çekirdek / iş parçacığı',
                        ],
                        'value' => [
                            'fa' => '10 / 20',
                            'en' => '10 / 20',
                            'tr' => '10 / 20',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس پایه',
                            'en' => 'Base clock',
                            'tr' => 'Temel hız',
                        ],
                        'value' => [
                            'fa' => '3.0 GHz',
                            'en' => '3.0 GHz',
                            'tr' => '3.0 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس توربو',
                            'en' => 'Max turbo',
                            'tr' => 'Maks. turbo',
                        ],
                        'value' => [
                            'fa' => '3.6 GHz',
                            'en' => '3.6 GHz',
                            'tr' => '3.6 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کش L3',
                            'en' => 'L3 cache',
                            'tr' => 'L3 önbellek',
                        ],
                        'value' => [
                            'fa' => '25 MB',
                            'en' => '25 MB',
                            'tr' => '25 MB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'توان طراحی حرارتی',
                            'en' => 'TDP',
                            'tr' => 'TDP',
                        ],
                        'value' => [
                            'fa' => '130 W',
                            'en' => '130 W',
                            'tr' => '130 W',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen8',
                            'en' => 'Gen8',
                            'tr' => 'Gen8',
                        ],
                    ],
                ],
                'attrs' => [
                    'cores' => 10,
                    'threads' => 20,
                    'ghz' => 3.0,
                    'ghz_turbo' => 3.6,
                    'cache_mb' => 25,
                    'tdp' => 130,
                ],
            ],
            [
                'slug' => 'xeon-e5-2697-v2',
                'category' => 'cpu',
                'brand' => 'Intel',
                'compat_gens' => [
                    'gen8',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 4500,
                'in_stock' => true,
                'popular' => false,
                'sort' => 60,
                'name' => [
                    'fa' => 'Intel Xeon E5-2697 v2',
                    'en' => 'Intel Xeon E5-2697 v2',
                    'tr' => 'Intel Xeon E5-2697 v2',
                ],
                'tagline' => [
                    'fa' => '12 هسته، 24 رشته، تا 3.5 گیگاهرتز',
                    'en' => '12 cores, 24 threads, up to 3.5 GHz',
                    'tr' => '12 çekirdek, 24 iş parçacığı, 3.5 GHz’e kadar',
                ],
                'summary' => [
                    'fa' => 'پردازندهٔ Ivy Bridge-EP روی سوکت LGA2011 برای سرورهای Gen8؛ 12 هستهٔ فیزیکی، 30 مگابایت کش L3 و توان طراحی حرارتی 130 وات.',
                    'en' => 'Ivy Bridge-EP processor on socket LGA2011 for Gen8 servers: 12 physical cores, 30 MB of L3 cache and a 130 W thermal design power.',
                    'tr' => 'Gen8 sunucular için LGA2011 soketinde Ivy Bridge-EP işlemci: 12 fiziksel çekirdek, 30 MB L3 önbellek ve 130 W termal tasarım gücü.',
                ],
                'body' => [
                    'fa' => 'Intel Xeon E5-2697 v2 پردازنده‌ای از خانوادهٔ Ivy Bridge-EP است که روی سوکت LGA2011 می‌نشیند و در سرورهای HPE ProLiant Gen8 استفاده می‌شود. 12 هستهٔ فیزیکی و 24 رشتهٔ پردازشی دارد، از فرکانس پایهٔ 2.7 گیگاهرتز شروع می‌کند و در بار سبک تا 3.5 گیگاهرتز بالا می‌رود. 30 مگابایت کش L3 و توان طراحی حرارتی 130 وات.

برای میزبانی وب، سرور فایل، محیط آزمایشگاهی و مجازی‌سازی سبک انتخاب اقتصادی‌ای است. هستهٔ زیاد با قیمت پایین، به قیمت مصرف برق بالاتر نسبت به نسل‌های جدید.

پیش از سفارش دو نکته را چک کنید: اول اینکه در پیکربندی دو پردازنده‌ای، هر دو سوکت باید مدل یکسان داشته باشند — سرور با دو مدل متفاوت بالا نمی‌آید. دوم اینکه برای توان بالای ۱۳۵ وات، هیت‌سینک پرکارایی و کیت فن لازم است؛ با هیت‌سینک استاندارد سرور خودش را throttle می‌کند و کارایی‌ای که خریده‌اید را نمی‌گیرید.

هر پردازنده پیش از ارسال روی همان نسل سرور بوت و تست می‌شود.',
                    'en' => 'The Intel Xeon E5-2697 v2 is a Ivy Bridge-EP processor for socket LGA2011, used in HPE ProLiant Gen8 servers. It has 12 physical cores and 24 threads, starts at a 2.7 GHz base clock and boosts to 3.5 GHz under light load. It carries 30 MB of L3 cache and a 130 W thermal design power.

An economical choice for web hosting, file servers, lab environments and light virtualisation: plenty of cores for very little money, at the cost of higher power draw than newer generations.

Two things to check before ordering. First, in a dual-socket configuration both sockets must hold the identical model — a server with two different SKUs will not post. Second, anything above 135 W needs the high-performance heatsink and fan kit; with the standard heatsink the server throttles itself and you never get the performance you paid for.

Every processor is booted and tested on the same server generation before it ships.',
                    'tr' => 'Intel Xeon E5-2697 v2, LGA2011 soketi için bir Ivy Bridge-EP işlemcisidir ve HPE ProLiant Gen8 sunucularında kullanılır. 12 fiziksel çekirdek ve 24 iş parçacığına sahiptir, 2.7 GHz temel hızda başlar ve hafif yükte 3.5 GHz’e çıkar. 30 MB L3 önbellek ve 130 W termal tasarım gücü taşır.

Web barındırma, dosya sunucusu, laboratuvar ortamı ve hafif sanallaştırma için ekonomik bir seçim: çok az paraya bol çekirdek, karşılığında yeni nesillere göre daha yüksek güç tüketimi.

Sipariş öncesi iki nokta: Birincisi, çift soketli yapılandırmada her iki soket de aynı modeli taşımalıdır — iki farklı model ile sunucu açılmaz. İkincisi, 135 W üzerindeki her işlemci yüksek performanslı soğutucu ve fan kiti ister; standart soğutucuyla sunucu kendini kısar ve ödediğiniz performansı alamazsınız.

Her işlemci sevkiyattan önce aynı sunucu neslinde başlatılıp test edilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'خانوادهٔ پردازنده',
                            'en' => 'Processor family',
                            'tr' => 'İşlemci ailesi',
                        ],
                        'value' => [
                            'fa' => 'Ivy Bridge-EP',
                            'en' => 'Ivy Bridge-EP',
                            'tr' => 'Ivy Bridge-EP',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سوکت',
                            'en' => 'Socket',
                            'tr' => 'Soket',
                        ],
                        'value' => [
                            'fa' => 'LGA2011',
                            'en' => 'LGA2011',
                            'tr' => 'LGA2011',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'هسته / رشته',
                            'en' => 'Cores / threads',
                            'tr' => 'Çekirdek / iş parçacığı',
                        ],
                        'value' => [
                            'fa' => '12 / 24',
                            'en' => '12 / 24',
                            'tr' => '12 / 24',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس پایه',
                            'en' => 'Base clock',
                            'tr' => 'Temel hız',
                        ],
                        'value' => [
                            'fa' => '2.7 GHz',
                            'en' => '2.7 GHz',
                            'tr' => '2.7 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس توربو',
                            'en' => 'Max turbo',
                            'tr' => 'Maks. turbo',
                        ],
                        'value' => [
                            'fa' => '3.5 GHz',
                            'en' => '3.5 GHz',
                            'tr' => '3.5 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کش L3',
                            'en' => 'L3 cache',
                            'tr' => 'L3 önbellek',
                        ],
                        'value' => [
                            'fa' => '30 MB',
                            'en' => '30 MB',
                            'tr' => '30 MB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'توان طراحی حرارتی',
                            'en' => 'TDP',
                            'tr' => 'TDP',
                        ],
                        'value' => [
                            'fa' => '130 W',
                            'en' => '130 W',
                            'tr' => '130 W',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen8',
                            'en' => 'Gen8',
                            'tr' => 'Gen8',
                        ],
                    ],
                ],
                'attrs' => [
                    'cores' => 12,
                    'threads' => 24,
                    'ghz' => 2.7,
                    'ghz_turbo' => 3.5,
                    'cache_mb' => 30,
                    'tdp' => 130,
                ],
            ],
            [
                'slug' => 'xeon-e5-2620-v4',
                'category' => 'cpu',
                'brand' => 'Intel',
                'compat_gens' => [
                    'gen9',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 1800,
                'in_stock' => true,
                'popular' => false,
                'sort' => 70,
                'name' => [
                    'fa' => 'Intel Xeon E5-2620 v4',
                    'en' => 'Intel Xeon E5-2620 v4',
                    'tr' => 'Intel Xeon E5-2620 v4',
                ],
                'tagline' => [
                    'fa' => '8 هسته، 16 رشته، تا 3.0 گیگاهرتز',
                    'en' => '8 cores, 16 threads, up to 3.0 GHz',
                    'tr' => '8 çekirdek, 16 iş parçacığı, 3.0 GHz’e kadar',
                ],
                'summary' => [
                    'fa' => 'پردازندهٔ Broadwell-EP روی سوکت LGA2011-3 برای سرورهای Gen9؛ 8 هستهٔ فیزیکی، 20 مگابایت کش L3 و توان طراحی حرارتی 85 وات.',
                    'en' => 'Broadwell-EP processor on socket LGA2011-3 for Gen9 servers: 8 physical cores, 20 MB of L3 cache and a 85 W thermal design power.',
                    'tr' => 'Gen9 sunucular için LGA2011-3 soketinde Broadwell-EP işlemci: 8 fiziksel çekirdek, 20 MB L3 önbellek ve 85 W termal tasarım gücü.',
                ],
                'body' => [
                    'fa' => 'Intel Xeon E5-2620 v4 پردازنده‌ای از خانوادهٔ Broadwell-EP است که روی سوکت LGA2011-3 می‌نشیند و در سرورهای HPE ProLiant Gen9 استفاده می‌شود. 8 هستهٔ فیزیکی و 16 رشتهٔ پردازشی دارد، از فرکانس پایهٔ 2.1 گیگاهرتز شروع می‌کند و در بار سبک تا 3.0 گیگاهرتز بالا می‌رود. 20 مگابایت کش L3 و توان طراحی حرارتی 85 وات.

نقطهٔ شیرین بازار دست دوم است: DDR4، مصرف معقول و قیمتی که هنوز پایین مانده. برای مجازی‌سازی، پایگاه داده و میزبانی اشتراکی گزینهٔ پیش‌فرض ماست.

پیش از سفارش دو نکته را چک کنید: اول اینکه در پیکربندی دو پردازنده‌ای، هر دو سوکت باید مدل یکسان داشته باشند — سرور با دو مدل متفاوت بالا نمی‌آید. دوم اینکه برای توان بالای ۱۳۵ وات، هیت‌سینک پرکارایی و کیت فن لازم است؛ با هیت‌سینک استاندارد سرور خودش را throttle می‌کند و کارایی‌ای که خریده‌اید را نمی‌گیرید.

هر پردازنده پیش از ارسال روی همان نسل سرور بوت و تست می‌شود.',
                    'en' => 'The Intel Xeon E5-2620 v4 is a Broadwell-EP processor for socket LGA2011-3, used in HPE ProLiant Gen9 servers. It has 8 physical cores and 16 threads, starts at a 2.1 GHz base clock and boosts to 3.0 GHz under light load. It carries 20 MB of L3 cache and a 85 W thermal design power.

The sweet spot of the second-hand market: DDR4, sensible power draw and a price that has stayed low. Our default recommendation for virtualisation, databases and shared hosting.

Two things to check before ordering. First, in a dual-socket configuration both sockets must hold the identical model — a server with two different SKUs will not post. Second, anything above 135 W needs the high-performance heatsink and fan kit; with the standard heatsink the server throttles itself and you never get the performance you paid for.

Every processor is booted and tested on the same server generation before it ships.',
                    'tr' => 'Intel Xeon E5-2620 v4, LGA2011-3 soketi için bir Broadwell-EP işlemcisidir ve HPE ProLiant Gen9 sunucularında kullanılır. 8 fiziksel çekirdek ve 16 iş parçacığına sahiptir, 2.1 GHz temel hızda başlar ve hafif yükte 3.0 GHz’e çıkar. 20 MB L3 önbellek ve 85 W termal tasarım gücü taşır.

İkinci el pazarının tatlı noktası: DDR4, makul güç tüketimi ve hâlâ düşük kalmış bir fiyat. Sanallaştırma, veritabanı ve paylaşımlı barındırma için varsayılan önerimiz.

Sipariş öncesi iki nokta: Birincisi, çift soketli yapılandırmada her iki soket de aynı modeli taşımalıdır — iki farklı model ile sunucu açılmaz. İkincisi, 135 W üzerindeki her işlemci yüksek performanslı soğutucu ve fan kiti ister; standart soğutucuyla sunucu kendini kısar ve ödediğiniz performansı alamazsınız.

Her işlemci sevkiyattan önce aynı sunucu neslinde başlatılıp test edilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'خانوادهٔ پردازنده',
                            'en' => 'Processor family',
                            'tr' => 'İşlemci ailesi',
                        ],
                        'value' => [
                            'fa' => 'Broadwell-EP',
                            'en' => 'Broadwell-EP',
                            'tr' => 'Broadwell-EP',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سوکت',
                            'en' => 'Socket',
                            'tr' => 'Soket',
                        ],
                        'value' => [
                            'fa' => 'LGA2011-3',
                            'en' => 'LGA2011-3',
                            'tr' => 'LGA2011-3',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'هسته / رشته',
                            'en' => 'Cores / threads',
                            'tr' => 'Çekirdek / iş parçacığı',
                        ],
                        'value' => [
                            'fa' => '8 / 16',
                            'en' => '8 / 16',
                            'tr' => '8 / 16',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس پایه',
                            'en' => 'Base clock',
                            'tr' => 'Temel hız',
                        ],
                        'value' => [
                            'fa' => '2.1 GHz',
                            'en' => '2.1 GHz',
                            'tr' => '2.1 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس توربو',
                            'en' => 'Max turbo',
                            'tr' => 'Maks. turbo',
                        ],
                        'value' => [
                            'fa' => '3.0 GHz',
                            'en' => '3.0 GHz',
                            'tr' => '3.0 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کش L3',
                            'en' => 'L3 cache',
                            'tr' => 'L3 önbellek',
                        ],
                        'value' => [
                            'fa' => '20 MB',
                            'en' => '20 MB',
                            'tr' => '20 MB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'توان طراحی حرارتی',
                            'en' => 'TDP',
                            'tr' => 'TDP',
                        ],
                        'value' => [
                            'fa' => '85 W',
                            'en' => '85 W',
                            'tr' => '85 W',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen9',
                            'en' => 'Gen9',
                            'tr' => 'Gen9',
                        ],
                    ],
                ],
                'attrs' => [
                    'cores' => 8,
                    'threads' => 16,
                    'ghz' => 2.1,
                    'ghz_turbo' => 3.0,
                    'cache_mb' => 20,
                    'tdp' => 85,
                ],
            ],
            [
                'slug' => 'xeon-e5-2630-v4',
                'category' => 'cpu',
                'brand' => 'Intel',
                'compat_gens' => [
                    'gen9',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 2100,
                'in_stock' => true,
                'popular' => true,
                'sort' => 80,
                'name' => [
                    'fa' => 'Intel Xeon E5-2630 v4',
                    'en' => 'Intel Xeon E5-2630 v4',
                    'tr' => 'Intel Xeon E5-2630 v4',
                ],
                'tagline' => [
                    'fa' => '10 هسته، 20 رشته، تا 3.1 گیگاهرتز',
                    'en' => '10 cores, 20 threads, up to 3.1 GHz',
                    'tr' => '10 çekirdek, 20 iş parçacığı, 3.1 GHz’e kadar',
                ],
                'summary' => [
                    'fa' => 'پردازندهٔ Broadwell-EP روی سوکت LGA2011-3 برای سرورهای Gen9؛ 10 هستهٔ فیزیکی، 25 مگابایت کش L3 و توان طراحی حرارتی 85 وات.',
                    'en' => 'Broadwell-EP processor on socket LGA2011-3 for Gen9 servers: 10 physical cores, 25 MB of L3 cache and a 85 W thermal design power.',
                    'tr' => 'Gen9 sunucular için LGA2011-3 soketinde Broadwell-EP işlemci: 10 fiziksel çekirdek, 25 MB L3 önbellek ve 85 W termal tasarım gücü.',
                ],
                'body' => [
                    'fa' => 'Intel Xeon E5-2630 v4 پردازنده‌ای از خانوادهٔ Broadwell-EP است که روی سوکت LGA2011-3 می‌نشیند و در سرورهای HPE ProLiant Gen9 استفاده می‌شود. 10 هستهٔ فیزیکی و 20 رشتهٔ پردازشی دارد، از فرکانس پایهٔ 2.2 گیگاهرتز شروع می‌کند و در بار سبک تا 3.1 گیگاهرتز بالا می‌رود. 25 مگابایت کش L3 و توان طراحی حرارتی 85 وات.

نقطهٔ شیرین بازار دست دوم است: DDR4، مصرف معقول و قیمتی که هنوز پایین مانده. برای مجازی‌سازی، پایگاه داده و میزبانی اشتراکی گزینهٔ پیش‌فرض ماست.

پیش از سفارش دو نکته را چک کنید: اول اینکه در پیکربندی دو پردازنده‌ای، هر دو سوکت باید مدل یکسان داشته باشند — سرور با دو مدل متفاوت بالا نمی‌آید. دوم اینکه برای توان بالای ۱۳۵ وات، هیت‌سینک پرکارایی و کیت فن لازم است؛ با هیت‌سینک استاندارد سرور خودش را throttle می‌کند و کارایی‌ای که خریده‌اید را نمی‌گیرید.

هر پردازنده پیش از ارسال روی همان نسل سرور بوت و تست می‌شود.',
                    'en' => 'The Intel Xeon E5-2630 v4 is a Broadwell-EP processor for socket LGA2011-3, used in HPE ProLiant Gen9 servers. It has 10 physical cores and 20 threads, starts at a 2.2 GHz base clock and boosts to 3.1 GHz under light load. It carries 25 MB of L3 cache and a 85 W thermal design power.

The sweet spot of the second-hand market: DDR4, sensible power draw and a price that has stayed low. Our default recommendation for virtualisation, databases and shared hosting.

Two things to check before ordering. First, in a dual-socket configuration both sockets must hold the identical model — a server with two different SKUs will not post. Second, anything above 135 W needs the high-performance heatsink and fan kit; with the standard heatsink the server throttles itself and you never get the performance you paid for.

Every processor is booted and tested on the same server generation before it ships.',
                    'tr' => 'Intel Xeon E5-2630 v4, LGA2011-3 soketi için bir Broadwell-EP işlemcisidir ve HPE ProLiant Gen9 sunucularında kullanılır. 10 fiziksel çekirdek ve 20 iş parçacığına sahiptir, 2.2 GHz temel hızda başlar ve hafif yükte 3.1 GHz’e çıkar. 25 MB L3 önbellek ve 85 W termal tasarım gücü taşır.

İkinci el pazarının tatlı noktası: DDR4, makul güç tüketimi ve hâlâ düşük kalmış bir fiyat. Sanallaştırma, veritabanı ve paylaşımlı barındırma için varsayılan önerimiz.

Sipariş öncesi iki nokta: Birincisi, çift soketli yapılandırmada her iki soket de aynı modeli taşımalıdır — iki farklı model ile sunucu açılmaz. İkincisi, 135 W üzerindeki her işlemci yüksek performanslı soğutucu ve fan kiti ister; standart soğutucuyla sunucu kendini kısar ve ödediğiniz performansı alamazsınız.

Her işlemci sevkiyattan önce aynı sunucu neslinde başlatılıp test edilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'خانوادهٔ پردازنده',
                            'en' => 'Processor family',
                            'tr' => 'İşlemci ailesi',
                        ],
                        'value' => [
                            'fa' => 'Broadwell-EP',
                            'en' => 'Broadwell-EP',
                            'tr' => 'Broadwell-EP',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سوکت',
                            'en' => 'Socket',
                            'tr' => 'Soket',
                        ],
                        'value' => [
                            'fa' => 'LGA2011-3',
                            'en' => 'LGA2011-3',
                            'tr' => 'LGA2011-3',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'هسته / رشته',
                            'en' => 'Cores / threads',
                            'tr' => 'Çekirdek / iş parçacığı',
                        ],
                        'value' => [
                            'fa' => '10 / 20',
                            'en' => '10 / 20',
                            'tr' => '10 / 20',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس پایه',
                            'en' => 'Base clock',
                            'tr' => 'Temel hız',
                        ],
                        'value' => [
                            'fa' => '2.2 GHz',
                            'en' => '2.2 GHz',
                            'tr' => '2.2 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس توربو',
                            'en' => 'Max turbo',
                            'tr' => 'Maks. turbo',
                        ],
                        'value' => [
                            'fa' => '3.1 GHz',
                            'en' => '3.1 GHz',
                            'tr' => '3.1 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کش L3',
                            'en' => 'L3 cache',
                            'tr' => 'L3 önbellek',
                        ],
                        'value' => [
                            'fa' => '25 MB',
                            'en' => '25 MB',
                            'tr' => '25 MB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'توان طراحی حرارتی',
                            'en' => 'TDP',
                            'tr' => 'TDP',
                        ],
                        'value' => [
                            'fa' => '85 W',
                            'en' => '85 W',
                            'tr' => '85 W',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen9',
                            'en' => 'Gen9',
                            'tr' => 'Gen9',
                        ],
                    ],
                ],
                'attrs' => [
                    'cores' => 10,
                    'threads' => 20,
                    'ghz' => 2.2,
                    'ghz_turbo' => 3.1,
                    'cache_mb' => 25,
                    'tdp' => 85,
                ],
            ],
            [
                'slug' => 'xeon-e5-2650-v4',
                'category' => 'cpu',
                'brand' => 'Intel',
                'compat_gens' => [
                    'gen9',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 2500,
                'in_stock' => true,
                'popular' => true,
                'sort' => 90,
                'name' => [
                    'fa' => 'Intel Xeon E5-2650 v4',
                    'en' => 'Intel Xeon E5-2650 v4',
                    'tr' => 'Intel Xeon E5-2650 v4',
                ],
                'tagline' => [
                    'fa' => '12 هسته، 24 رشته، تا 2.9 گیگاهرتز',
                    'en' => '12 cores, 24 threads, up to 2.9 GHz',
                    'tr' => '12 çekirdek, 24 iş parçacığı, 2.9 GHz’e kadar',
                ],
                'summary' => [
                    'fa' => 'پردازندهٔ Broadwell-EP روی سوکت LGA2011-3 برای سرورهای Gen9؛ 12 هستهٔ فیزیکی، 30 مگابایت کش L3 و توان طراحی حرارتی 105 وات.',
                    'en' => 'Broadwell-EP processor on socket LGA2011-3 for Gen9 servers: 12 physical cores, 30 MB of L3 cache and a 105 W thermal design power.',
                    'tr' => 'Gen9 sunucular için LGA2011-3 soketinde Broadwell-EP işlemci: 12 fiziksel çekirdek, 30 MB L3 önbellek ve 105 W termal tasarım gücü.',
                ],
                'body' => [
                    'fa' => 'Intel Xeon E5-2650 v4 پردازنده‌ای از خانوادهٔ Broadwell-EP است که روی سوکت LGA2011-3 می‌نشیند و در سرورهای HPE ProLiant Gen9 استفاده می‌شود. 12 هستهٔ فیزیکی و 24 رشتهٔ پردازشی دارد، از فرکانس پایهٔ 2.2 گیگاهرتز شروع می‌کند و در بار سبک تا 2.9 گیگاهرتز بالا می‌رود. 30 مگابایت کش L3 و توان طراحی حرارتی 105 وات.

نقطهٔ شیرین بازار دست دوم است: DDR4، مصرف معقول و قیمتی که هنوز پایین مانده. برای مجازی‌سازی، پایگاه داده و میزبانی اشتراکی گزینهٔ پیش‌فرض ماست.

پیش از سفارش دو نکته را چک کنید: اول اینکه در پیکربندی دو پردازنده‌ای، هر دو سوکت باید مدل یکسان داشته باشند — سرور با دو مدل متفاوت بالا نمی‌آید. دوم اینکه برای توان بالای ۱۳۵ وات، هیت‌سینک پرکارایی و کیت فن لازم است؛ با هیت‌سینک استاندارد سرور خودش را throttle می‌کند و کارایی‌ای که خریده‌اید را نمی‌گیرید.

هر پردازنده پیش از ارسال روی همان نسل سرور بوت و تست می‌شود.',
                    'en' => 'The Intel Xeon E5-2650 v4 is a Broadwell-EP processor for socket LGA2011-3, used in HPE ProLiant Gen9 servers. It has 12 physical cores and 24 threads, starts at a 2.2 GHz base clock and boosts to 2.9 GHz under light load. It carries 30 MB of L3 cache and a 105 W thermal design power.

The sweet spot of the second-hand market: DDR4, sensible power draw and a price that has stayed low. Our default recommendation for virtualisation, databases and shared hosting.

Two things to check before ordering. First, in a dual-socket configuration both sockets must hold the identical model — a server with two different SKUs will not post. Second, anything above 135 W needs the high-performance heatsink and fan kit; with the standard heatsink the server throttles itself and you never get the performance you paid for.

Every processor is booted and tested on the same server generation before it ships.',
                    'tr' => 'Intel Xeon E5-2650 v4, LGA2011-3 soketi için bir Broadwell-EP işlemcisidir ve HPE ProLiant Gen9 sunucularında kullanılır. 12 fiziksel çekirdek ve 24 iş parçacığına sahiptir, 2.2 GHz temel hızda başlar ve hafif yükte 2.9 GHz’e çıkar. 30 MB L3 önbellek ve 105 W termal tasarım gücü taşır.

İkinci el pazarının tatlı noktası: DDR4, makul güç tüketimi ve hâlâ düşük kalmış bir fiyat. Sanallaştırma, veritabanı ve paylaşımlı barındırma için varsayılan önerimiz.

Sipariş öncesi iki nokta: Birincisi, çift soketli yapılandırmada her iki soket de aynı modeli taşımalıdır — iki farklı model ile sunucu açılmaz. İkincisi, 135 W üzerindeki her işlemci yüksek performanslı soğutucu ve fan kiti ister; standart soğutucuyla sunucu kendini kısar ve ödediğiniz performansı alamazsınız.

Her işlemci sevkiyattan önce aynı sunucu neslinde başlatılıp test edilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'خانوادهٔ پردازنده',
                            'en' => 'Processor family',
                            'tr' => 'İşlemci ailesi',
                        ],
                        'value' => [
                            'fa' => 'Broadwell-EP',
                            'en' => 'Broadwell-EP',
                            'tr' => 'Broadwell-EP',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سوکت',
                            'en' => 'Socket',
                            'tr' => 'Soket',
                        ],
                        'value' => [
                            'fa' => 'LGA2011-3',
                            'en' => 'LGA2011-3',
                            'tr' => 'LGA2011-3',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'هسته / رشته',
                            'en' => 'Cores / threads',
                            'tr' => 'Çekirdek / iş parçacığı',
                        ],
                        'value' => [
                            'fa' => '12 / 24',
                            'en' => '12 / 24',
                            'tr' => '12 / 24',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس پایه',
                            'en' => 'Base clock',
                            'tr' => 'Temel hız',
                        ],
                        'value' => [
                            'fa' => '2.2 GHz',
                            'en' => '2.2 GHz',
                            'tr' => '2.2 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس توربو',
                            'en' => 'Max turbo',
                            'tr' => 'Maks. turbo',
                        ],
                        'value' => [
                            'fa' => '2.9 GHz',
                            'en' => '2.9 GHz',
                            'tr' => '2.9 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کش L3',
                            'en' => 'L3 cache',
                            'tr' => 'L3 önbellek',
                        ],
                        'value' => [
                            'fa' => '30 MB',
                            'en' => '30 MB',
                            'tr' => '30 MB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'توان طراحی حرارتی',
                            'en' => 'TDP',
                            'tr' => 'TDP',
                        ],
                        'value' => [
                            'fa' => '105 W',
                            'en' => '105 W',
                            'tr' => '105 W',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen9',
                            'en' => 'Gen9',
                            'tr' => 'Gen9',
                        ],
                    ],
                ],
                'attrs' => [
                    'cores' => 12,
                    'threads' => 24,
                    'ghz' => 2.2,
                    'ghz_turbo' => 2.9,
                    'cache_mb' => 30,
                    'tdp' => 105,
                ],
            ],
            [
                'slug' => 'xeon-e5-2660-v4',
                'category' => 'cpu',
                'brand' => 'Intel',
                'compat_gens' => [
                    'gen9',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 2100,
                'in_stock' => true,
                'popular' => false,
                'sort' => 100,
                'name' => [
                    'fa' => 'Intel Xeon E5-2660 v4',
                    'en' => 'Intel Xeon E5-2660 v4',
                    'tr' => 'Intel Xeon E5-2660 v4',
                ],
                'tagline' => [
                    'fa' => '14 هسته، 28 رشته، تا 3.2 گیگاهرتز',
                    'en' => '14 cores, 28 threads, up to 3.2 GHz',
                    'tr' => '14 çekirdek, 28 iş parçacığı, 3.2 GHz’e kadar',
                ],
                'summary' => [
                    'fa' => 'پردازندهٔ Broadwell-EP روی سوکت LGA2011-3 برای سرورهای Gen9؛ 14 هستهٔ فیزیکی، 35 مگابایت کش L3 و توان طراحی حرارتی 105 وات.',
                    'en' => 'Broadwell-EP processor on socket LGA2011-3 for Gen9 servers: 14 physical cores, 35 MB of L3 cache and a 105 W thermal design power.',
                    'tr' => 'Gen9 sunucular için LGA2011-3 soketinde Broadwell-EP işlemci: 14 fiziksel çekirdek, 35 MB L3 önbellek ve 105 W termal tasarım gücü.',
                ],
                'body' => [
                    'fa' => 'Intel Xeon E5-2660 v4 پردازنده‌ای از خانوادهٔ Broadwell-EP است که روی سوکت LGA2011-3 می‌نشیند و در سرورهای HPE ProLiant Gen9 استفاده می‌شود. 14 هستهٔ فیزیکی و 28 رشتهٔ پردازشی دارد، از فرکانس پایهٔ 2.0 گیگاهرتز شروع می‌کند و در بار سبک تا 3.2 گیگاهرتز بالا می‌رود. 35 مگابایت کش L3 و توان طراحی حرارتی 105 وات.

نقطهٔ شیرین بازار دست دوم است: DDR4، مصرف معقول و قیمتی که هنوز پایین مانده. برای مجازی‌سازی، پایگاه داده و میزبانی اشتراکی گزینهٔ پیش‌فرض ماست.

پیش از سفارش دو نکته را چک کنید: اول اینکه در پیکربندی دو پردازنده‌ای، هر دو سوکت باید مدل یکسان داشته باشند — سرور با دو مدل متفاوت بالا نمی‌آید. دوم اینکه برای توان بالای ۱۳۵ وات، هیت‌سینک پرکارایی و کیت فن لازم است؛ با هیت‌سینک استاندارد سرور خودش را throttle می‌کند و کارایی‌ای که خریده‌اید را نمی‌گیرید.

هر پردازنده پیش از ارسال روی همان نسل سرور بوت و تست می‌شود.',
                    'en' => 'The Intel Xeon E5-2660 v4 is a Broadwell-EP processor for socket LGA2011-3, used in HPE ProLiant Gen9 servers. It has 14 physical cores and 28 threads, starts at a 2.0 GHz base clock and boosts to 3.2 GHz under light load. It carries 35 MB of L3 cache and a 105 W thermal design power.

The sweet spot of the second-hand market: DDR4, sensible power draw and a price that has stayed low. Our default recommendation for virtualisation, databases and shared hosting.

Two things to check before ordering. First, in a dual-socket configuration both sockets must hold the identical model — a server with two different SKUs will not post. Second, anything above 135 W needs the high-performance heatsink and fan kit; with the standard heatsink the server throttles itself and you never get the performance you paid for.

Every processor is booted and tested on the same server generation before it ships.',
                    'tr' => 'Intel Xeon E5-2660 v4, LGA2011-3 soketi için bir Broadwell-EP işlemcisidir ve HPE ProLiant Gen9 sunucularında kullanılır. 14 fiziksel çekirdek ve 28 iş parçacığına sahiptir, 2.0 GHz temel hızda başlar ve hafif yükte 3.2 GHz’e çıkar. 35 MB L3 önbellek ve 105 W termal tasarım gücü taşır.

İkinci el pazarının tatlı noktası: DDR4, makul güç tüketimi ve hâlâ düşük kalmış bir fiyat. Sanallaştırma, veritabanı ve paylaşımlı barındırma için varsayılan önerimiz.

Sipariş öncesi iki nokta: Birincisi, çift soketli yapılandırmada her iki soket de aynı modeli taşımalıdır — iki farklı model ile sunucu açılmaz. İkincisi, 135 W üzerindeki her işlemci yüksek performanslı soğutucu ve fan kiti ister; standart soğutucuyla sunucu kendini kısar ve ödediğiniz performansı alamazsınız.

Her işlemci sevkiyattan önce aynı sunucu neslinde başlatılıp test edilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'خانوادهٔ پردازنده',
                            'en' => 'Processor family',
                            'tr' => 'İşlemci ailesi',
                        ],
                        'value' => [
                            'fa' => 'Broadwell-EP',
                            'en' => 'Broadwell-EP',
                            'tr' => 'Broadwell-EP',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سوکت',
                            'en' => 'Socket',
                            'tr' => 'Soket',
                        ],
                        'value' => [
                            'fa' => 'LGA2011-3',
                            'en' => 'LGA2011-3',
                            'tr' => 'LGA2011-3',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'هسته / رشته',
                            'en' => 'Cores / threads',
                            'tr' => 'Çekirdek / iş parçacığı',
                        ],
                        'value' => [
                            'fa' => '14 / 28',
                            'en' => '14 / 28',
                            'tr' => '14 / 28',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس پایه',
                            'en' => 'Base clock',
                            'tr' => 'Temel hız',
                        ],
                        'value' => [
                            'fa' => '2.0 GHz',
                            'en' => '2.0 GHz',
                            'tr' => '2.0 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس توربو',
                            'en' => 'Max turbo',
                            'tr' => 'Maks. turbo',
                        ],
                        'value' => [
                            'fa' => '3.2 GHz',
                            'en' => '3.2 GHz',
                            'tr' => '3.2 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کش L3',
                            'en' => 'L3 cache',
                            'tr' => 'L3 önbellek',
                        ],
                        'value' => [
                            'fa' => '35 MB',
                            'en' => '35 MB',
                            'tr' => '35 MB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'توان طراحی حرارتی',
                            'en' => 'TDP',
                            'tr' => 'TDP',
                        ],
                        'value' => [
                            'fa' => '105 W',
                            'en' => '105 W',
                            'tr' => '105 W',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen9',
                            'en' => 'Gen9',
                            'tr' => 'Gen9',
                        ],
                    ],
                ],
                'attrs' => [
                    'cores' => 14,
                    'threads' => 28,
                    'ghz' => 2.0,
                    'ghz_turbo' => 3.2,
                    'cache_mb' => 35,
                    'tdp' => 105,
                ],
            ],
            [
                'slug' => 'xeon-e5-2667-v4',
                'category' => 'cpu',
                'brand' => 'Intel',
                'compat_gens' => [
                    'gen9',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 4621,
                'in_stock' => true,
                'popular' => false,
                'sort' => 110,
                'name' => [
                    'fa' => 'Intel Xeon E5-2667 v4',
                    'en' => 'Intel Xeon E5-2667 v4',
                    'tr' => 'Intel Xeon E5-2667 v4',
                ],
                'tagline' => [
                    'fa' => '8 هسته، 16 رشته، تا 3.6 گیگاهرتز',
                    'en' => '8 cores, 16 threads, up to 3.6 GHz',
                    'tr' => '8 çekirdek, 16 iş parçacığı, 3.6 GHz’e kadar',
                ],
                'summary' => [
                    'fa' => 'پردازندهٔ Broadwell-EP روی سوکت LGA2011-3 برای سرورهای Gen9؛ 8 هستهٔ فیزیکی، 25 مگابایت کش L3 و توان طراحی حرارتی 135 وات.',
                    'en' => 'Broadwell-EP processor on socket LGA2011-3 for Gen9 servers: 8 physical cores, 25 MB of L3 cache and a 135 W thermal design power.',
                    'tr' => 'Gen9 sunucular için LGA2011-3 soketinde Broadwell-EP işlemci: 8 fiziksel çekirdek, 25 MB L3 önbellek ve 135 W termal tasarım gücü.',
                ],
                'body' => [
                    'fa' => 'Intel Xeon E5-2667 v4 پردازنده‌ای از خانوادهٔ Broadwell-EP است که روی سوکت LGA2011-3 می‌نشیند و در سرورهای HPE ProLiant Gen9 استفاده می‌شود. 8 هستهٔ فیزیکی و 16 رشتهٔ پردازشی دارد، از فرکانس پایهٔ 3.2 گیگاهرتز شروع می‌کند و در بار سبک تا 3.6 گیگاهرتز بالا می‌رود. 25 مگابایت کش L3 و توان طراحی حرارتی 135 وات.

نقطهٔ شیرین بازار دست دوم است: DDR4، مصرف معقول و قیمتی که هنوز پایین مانده. برای مجازی‌سازی، پایگاه داده و میزبانی اشتراکی گزینهٔ پیش‌فرض ماست.

پیش از سفارش دو نکته را چک کنید: اول اینکه در پیکربندی دو پردازنده‌ای، هر دو سوکت باید مدل یکسان داشته باشند — سرور با دو مدل متفاوت بالا نمی‌آید. دوم اینکه برای توان بالای ۱۳۵ وات، هیت‌سینک پرکارایی و کیت فن لازم است؛ با هیت‌سینک استاندارد سرور خودش را throttle می‌کند و کارایی‌ای که خریده‌اید را نمی‌گیرید.

هر پردازنده پیش از ارسال روی همان نسل سرور بوت و تست می‌شود.',
                    'en' => 'The Intel Xeon E5-2667 v4 is a Broadwell-EP processor for socket LGA2011-3, used in HPE ProLiant Gen9 servers. It has 8 physical cores and 16 threads, starts at a 3.2 GHz base clock and boosts to 3.6 GHz under light load. It carries 25 MB of L3 cache and a 135 W thermal design power.

The sweet spot of the second-hand market: DDR4, sensible power draw and a price that has stayed low. Our default recommendation for virtualisation, databases and shared hosting.

Two things to check before ordering. First, in a dual-socket configuration both sockets must hold the identical model — a server with two different SKUs will not post. Second, anything above 135 W needs the high-performance heatsink and fan kit; with the standard heatsink the server throttles itself and you never get the performance you paid for.

Every processor is booted and tested on the same server generation before it ships.',
                    'tr' => 'Intel Xeon E5-2667 v4, LGA2011-3 soketi için bir Broadwell-EP işlemcisidir ve HPE ProLiant Gen9 sunucularında kullanılır. 8 fiziksel çekirdek ve 16 iş parçacığına sahiptir, 3.2 GHz temel hızda başlar ve hafif yükte 3.6 GHz’e çıkar. 25 MB L3 önbellek ve 135 W termal tasarım gücü taşır.

İkinci el pazarının tatlı noktası: DDR4, makul güç tüketimi ve hâlâ düşük kalmış bir fiyat. Sanallaştırma, veritabanı ve paylaşımlı barındırma için varsayılan önerimiz.

Sipariş öncesi iki nokta: Birincisi, çift soketli yapılandırmada her iki soket de aynı modeli taşımalıdır — iki farklı model ile sunucu açılmaz. İkincisi, 135 W üzerindeki her işlemci yüksek performanslı soğutucu ve fan kiti ister; standart soğutucuyla sunucu kendini kısar ve ödediğiniz performansı alamazsınız.

Her işlemci sevkiyattan önce aynı sunucu neslinde başlatılıp test edilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'خانوادهٔ پردازنده',
                            'en' => 'Processor family',
                            'tr' => 'İşlemci ailesi',
                        ],
                        'value' => [
                            'fa' => 'Broadwell-EP',
                            'en' => 'Broadwell-EP',
                            'tr' => 'Broadwell-EP',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سوکت',
                            'en' => 'Socket',
                            'tr' => 'Soket',
                        ],
                        'value' => [
                            'fa' => 'LGA2011-3',
                            'en' => 'LGA2011-3',
                            'tr' => 'LGA2011-3',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'هسته / رشته',
                            'en' => 'Cores / threads',
                            'tr' => 'Çekirdek / iş parçacığı',
                        ],
                        'value' => [
                            'fa' => '8 / 16',
                            'en' => '8 / 16',
                            'tr' => '8 / 16',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس پایه',
                            'en' => 'Base clock',
                            'tr' => 'Temel hız',
                        ],
                        'value' => [
                            'fa' => '3.2 GHz',
                            'en' => '3.2 GHz',
                            'tr' => '3.2 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس توربو',
                            'en' => 'Max turbo',
                            'tr' => 'Maks. turbo',
                        ],
                        'value' => [
                            'fa' => '3.6 GHz',
                            'en' => '3.6 GHz',
                            'tr' => '3.6 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کش L3',
                            'en' => 'L3 cache',
                            'tr' => 'L3 önbellek',
                        ],
                        'value' => [
                            'fa' => '25 MB',
                            'en' => '25 MB',
                            'tr' => '25 MB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'توان طراحی حرارتی',
                            'en' => 'TDP',
                            'tr' => 'TDP',
                        ],
                        'value' => [
                            'fa' => '135 W',
                            'en' => '135 W',
                            'tr' => '135 W',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen9',
                            'en' => 'Gen9',
                            'tr' => 'Gen9',
                        ],
                    ],
                ],
                'attrs' => [
                    'cores' => 8,
                    'threads' => 16,
                    'ghz' => 3.2,
                    'ghz_turbo' => 3.6,
                    'cache_mb' => 25,
                    'tdp' => 135,
                ],
            ],
            [
                'slug' => 'xeon-e5-2680-v4',
                'category' => 'cpu',
                'brand' => 'Intel',
                'compat_gens' => [
                    'gen9',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 3400,
                'in_stock' => true,
                'popular' => true,
                'sort' => 120,
                'name' => [
                    'fa' => 'Intel Xeon E5-2680 v4',
                    'en' => 'Intel Xeon E5-2680 v4',
                    'tr' => 'Intel Xeon E5-2680 v4',
                ],
                'tagline' => [
                    'fa' => '14 هسته، 28 رشته، تا 3.3 گیگاهرتز',
                    'en' => '14 cores, 28 threads, up to 3.3 GHz',
                    'tr' => '14 çekirdek, 28 iş parçacığı, 3.3 GHz’e kadar',
                ],
                'summary' => [
                    'fa' => 'پردازندهٔ Broadwell-EP روی سوکت LGA2011-3 برای سرورهای Gen9؛ 14 هستهٔ فیزیکی، 35 مگابایت کش L3 و توان طراحی حرارتی 120 وات.',
                    'en' => 'Broadwell-EP processor on socket LGA2011-3 for Gen9 servers: 14 physical cores, 35 MB of L3 cache and a 120 W thermal design power.',
                    'tr' => 'Gen9 sunucular için LGA2011-3 soketinde Broadwell-EP işlemci: 14 fiziksel çekirdek, 35 MB L3 önbellek ve 120 W termal tasarım gücü.',
                ],
                'body' => [
                    'fa' => 'Intel Xeon E5-2680 v4 پردازنده‌ای از خانوادهٔ Broadwell-EP است که روی سوکت LGA2011-3 می‌نشیند و در سرورهای HPE ProLiant Gen9 استفاده می‌شود. 14 هستهٔ فیزیکی و 28 رشتهٔ پردازشی دارد، از فرکانس پایهٔ 2.4 گیگاهرتز شروع می‌کند و در بار سبک تا 3.3 گیگاهرتز بالا می‌رود. 35 مگابایت کش L3 و توان طراحی حرارتی 120 وات.

نقطهٔ شیرین بازار دست دوم است: DDR4، مصرف معقول و قیمتی که هنوز پایین مانده. برای مجازی‌سازی، پایگاه داده و میزبانی اشتراکی گزینهٔ پیش‌فرض ماست.

پیش از سفارش دو نکته را چک کنید: اول اینکه در پیکربندی دو پردازنده‌ای، هر دو سوکت باید مدل یکسان داشته باشند — سرور با دو مدل متفاوت بالا نمی‌آید. دوم اینکه برای توان بالای ۱۳۵ وات، هیت‌سینک پرکارایی و کیت فن لازم است؛ با هیت‌سینک استاندارد سرور خودش را throttle می‌کند و کارایی‌ای که خریده‌اید را نمی‌گیرید.

هر پردازنده پیش از ارسال روی همان نسل سرور بوت و تست می‌شود.',
                    'en' => 'The Intel Xeon E5-2680 v4 is a Broadwell-EP processor for socket LGA2011-3, used in HPE ProLiant Gen9 servers. It has 14 physical cores and 28 threads, starts at a 2.4 GHz base clock and boosts to 3.3 GHz under light load. It carries 35 MB of L3 cache and a 120 W thermal design power.

The sweet spot of the second-hand market: DDR4, sensible power draw and a price that has stayed low. Our default recommendation for virtualisation, databases and shared hosting.

Two things to check before ordering. First, in a dual-socket configuration both sockets must hold the identical model — a server with two different SKUs will not post. Second, anything above 135 W needs the high-performance heatsink and fan kit; with the standard heatsink the server throttles itself and you never get the performance you paid for.

Every processor is booted and tested on the same server generation before it ships.',
                    'tr' => 'Intel Xeon E5-2680 v4, LGA2011-3 soketi için bir Broadwell-EP işlemcisidir ve HPE ProLiant Gen9 sunucularında kullanılır. 14 fiziksel çekirdek ve 28 iş parçacığına sahiptir, 2.4 GHz temel hızda başlar ve hafif yükte 3.3 GHz’e çıkar. 35 MB L3 önbellek ve 120 W termal tasarım gücü taşır.

İkinci el pazarının tatlı noktası: DDR4, makul güç tüketimi ve hâlâ düşük kalmış bir fiyat. Sanallaştırma, veritabanı ve paylaşımlı barındırma için varsayılan önerimiz.

Sipariş öncesi iki nokta: Birincisi, çift soketli yapılandırmada her iki soket de aynı modeli taşımalıdır — iki farklı model ile sunucu açılmaz. İkincisi, 135 W üzerindeki her işlemci yüksek performanslı soğutucu ve fan kiti ister; standart soğutucuyla sunucu kendini kısar ve ödediğiniz performansı alamazsınız.

Her işlemci sevkiyattan önce aynı sunucu neslinde başlatılıp test edilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'خانوادهٔ پردازنده',
                            'en' => 'Processor family',
                            'tr' => 'İşlemci ailesi',
                        ],
                        'value' => [
                            'fa' => 'Broadwell-EP',
                            'en' => 'Broadwell-EP',
                            'tr' => 'Broadwell-EP',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سوکت',
                            'en' => 'Socket',
                            'tr' => 'Soket',
                        ],
                        'value' => [
                            'fa' => 'LGA2011-3',
                            'en' => 'LGA2011-3',
                            'tr' => 'LGA2011-3',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'هسته / رشته',
                            'en' => 'Cores / threads',
                            'tr' => 'Çekirdek / iş parçacığı',
                        ],
                        'value' => [
                            'fa' => '14 / 28',
                            'en' => '14 / 28',
                            'tr' => '14 / 28',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس پایه',
                            'en' => 'Base clock',
                            'tr' => 'Temel hız',
                        ],
                        'value' => [
                            'fa' => '2.4 GHz',
                            'en' => '2.4 GHz',
                            'tr' => '2.4 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس توربو',
                            'en' => 'Max turbo',
                            'tr' => 'Maks. turbo',
                        ],
                        'value' => [
                            'fa' => '3.3 GHz',
                            'en' => '3.3 GHz',
                            'tr' => '3.3 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کش L3',
                            'en' => 'L3 cache',
                            'tr' => 'L3 önbellek',
                        ],
                        'value' => [
                            'fa' => '35 MB',
                            'en' => '35 MB',
                            'tr' => '35 MB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'توان طراحی حرارتی',
                            'en' => 'TDP',
                            'tr' => 'TDP',
                        ],
                        'value' => [
                            'fa' => '120 W',
                            'en' => '120 W',
                            'tr' => '120 W',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen9',
                            'en' => 'Gen9',
                            'tr' => 'Gen9',
                        ],
                    ],
                ],
                'attrs' => [
                    'cores' => 14,
                    'threads' => 28,
                    'ghz' => 2.4,
                    'ghz_turbo' => 3.3,
                    'cache_mb' => 35,
                    'tdp' => 120,
                ],
            ],
            [
                'slug' => 'xeon-e5-2690-v4',
                'category' => 'cpu',
                'brand' => 'Intel',
                'compat_gens' => [
                    'gen9',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 5000,
                'in_stock' => true,
                'popular' => false,
                'sort' => 130,
                'name' => [
                    'fa' => 'Intel Xeon E5-2690 v4',
                    'en' => 'Intel Xeon E5-2690 v4',
                    'tr' => 'Intel Xeon E5-2690 v4',
                ],
                'tagline' => [
                    'fa' => '14 هسته، 28 رشته، تا 3.5 گیگاهرتز',
                    'en' => '14 cores, 28 threads, up to 3.5 GHz',
                    'tr' => '14 çekirdek, 28 iş parçacığı, 3.5 GHz’e kadar',
                ],
                'summary' => [
                    'fa' => 'پردازندهٔ Broadwell-EP روی سوکت LGA2011-3 برای سرورهای Gen9؛ 14 هستهٔ فیزیکی، 35 مگابایت کش L3 و توان طراحی حرارتی 135 وات.',
                    'en' => 'Broadwell-EP processor on socket LGA2011-3 for Gen9 servers: 14 physical cores, 35 MB of L3 cache and a 135 W thermal design power.',
                    'tr' => 'Gen9 sunucular için LGA2011-3 soketinde Broadwell-EP işlemci: 14 fiziksel çekirdek, 35 MB L3 önbellek ve 135 W termal tasarım gücü.',
                ],
                'body' => [
                    'fa' => 'Intel Xeon E5-2690 v4 پردازنده‌ای از خانوادهٔ Broadwell-EP است که روی سوکت LGA2011-3 می‌نشیند و در سرورهای HPE ProLiant Gen9 استفاده می‌شود. 14 هستهٔ فیزیکی و 28 رشتهٔ پردازشی دارد، از فرکانس پایهٔ 2.6 گیگاهرتز شروع می‌کند و در بار سبک تا 3.5 گیگاهرتز بالا می‌رود. 35 مگابایت کش L3 و توان طراحی حرارتی 135 وات.

نقطهٔ شیرین بازار دست دوم است: DDR4، مصرف معقول و قیمتی که هنوز پایین مانده. برای مجازی‌سازی، پایگاه داده و میزبانی اشتراکی گزینهٔ پیش‌فرض ماست.

پیش از سفارش دو نکته را چک کنید: اول اینکه در پیکربندی دو پردازنده‌ای، هر دو سوکت باید مدل یکسان داشته باشند — سرور با دو مدل متفاوت بالا نمی‌آید. دوم اینکه برای توان بالای ۱۳۵ وات، هیت‌سینک پرکارایی و کیت فن لازم است؛ با هیت‌سینک استاندارد سرور خودش را throttle می‌کند و کارایی‌ای که خریده‌اید را نمی‌گیرید.

هر پردازنده پیش از ارسال روی همان نسل سرور بوت و تست می‌شود.',
                    'en' => 'The Intel Xeon E5-2690 v4 is a Broadwell-EP processor for socket LGA2011-3, used in HPE ProLiant Gen9 servers. It has 14 physical cores and 28 threads, starts at a 2.6 GHz base clock and boosts to 3.5 GHz under light load. It carries 35 MB of L3 cache and a 135 W thermal design power.

The sweet spot of the second-hand market: DDR4, sensible power draw and a price that has stayed low. Our default recommendation for virtualisation, databases and shared hosting.

Two things to check before ordering. First, in a dual-socket configuration both sockets must hold the identical model — a server with two different SKUs will not post. Second, anything above 135 W needs the high-performance heatsink and fan kit; with the standard heatsink the server throttles itself and you never get the performance you paid for.

Every processor is booted and tested on the same server generation before it ships.',
                    'tr' => 'Intel Xeon E5-2690 v4, LGA2011-3 soketi için bir Broadwell-EP işlemcisidir ve HPE ProLiant Gen9 sunucularında kullanılır. 14 fiziksel çekirdek ve 28 iş parçacığına sahiptir, 2.6 GHz temel hızda başlar ve hafif yükte 3.5 GHz’e çıkar. 35 MB L3 önbellek ve 135 W termal tasarım gücü taşır.

İkinci el pazarının tatlı noktası: DDR4, makul güç tüketimi ve hâlâ düşük kalmış bir fiyat. Sanallaştırma, veritabanı ve paylaşımlı barındırma için varsayılan önerimiz.

Sipariş öncesi iki nokta: Birincisi, çift soketli yapılandırmada her iki soket de aynı modeli taşımalıdır — iki farklı model ile sunucu açılmaz. İkincisi, 135 W üzerindeki her işlemci yüksek performanslı soğutucu ve fan kiti ister; standart soğutucuyla sunucu kendini kısar ve ödediğiniz performansı alamazsınız.

Her işlemci sevkiyattan önce aynı sunucu neslinde başlatılıp test edilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'خانوادهٔ پردازنده',
                            'en' => 'Processor family',
                            'tr' => 'İşlemci ailesi',
                        ],
                        'value' => [
                            'fa' => 'Broadwell-EP',
                            'en' => 'Broadwell-EP',
                            'tr' => 'Broadwell-EP',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سوکت',
                            'en' => 'Socket',
                            'tr' => 'Soket',
                        ],
                        'value' => [
                            'fa' => 'LGA2011-3',
                            'en' => 'LGA2011-3',
                            'tr' => 'LGA2011-3',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'هسته / رشته',
                            'en' => 'Cores / threads',
                            'tr' => 'Çekirdek / iş parçacığı',
                        ],
                        'value' => [
                            'fa' => '14 / 28',
                            'en' => '14 / 28',
                            'tr' => '14 / 28',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس پایه',
                            'en' => 'Base clock',
                            'tr' => 'Temel hız',
                        ],
                        'value' => [
                            'fa' => '2.6 GHz',
                            'en' => '2.6 GHz',
                            'tr' => '2.6 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس توربو',
                            'en' => 'Max turbo',
                            'tr' => 'Maks. turbo',
                        ],
                        'value' => [
                            'fa' => '3.5 GHz',
                            'en' => '3.5 GHz',
                            'tr' => '3.5 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کش L3',
                            'en' => 'L3 cache',
                            'tr' => 'L3 önbellek',
                        ],
                        'value' => [
                            'fa' => '35 MB',
                            'en' => '35 MB',
                            'tr' => '35 MB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'توان طراحی حرارتی',
                            'en' => 'TDP',
                            'tr' => 'TDP',
                        ],
                        'value' => [
                            'fa' => '135 W',
                            'en' => '135 W',
                            'tr' => '135 W',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen9',
                            'en' => 'Gen9',
                            'tr' => 'Gen9',
                        ],
                    ],
                ],
                'attrs' => [
                    'cores' => 14,
                    'threads' => 28,
                    'ghz' => 2.6,
                    'ghz_turbo' => 3.5,
                    'cache_mb' => 35,
                    'tdp' => 135,
                ],
            ],
            [
                'slug' => 'xeon-e5-2697a-v4',
                'category' => 'cpu',
                'brand' => 'Intel',
                'compat_gens' => [
                    'gen9',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 6302,
                'in_stock' => true,
                'popular' => false,
                'sort' => 140,
                'name' => [
                    'fa' => 'Intel Xeon E5-2697A v4',
                    'en' => 'Intel Xeon E5-2697A v4',
                    'tr' => 'Intel Xeon E5-2697A v4',
                ],
                'tagline' => [
                    'fa' => '16 هسته، 32 رشته، تا 3.6 گیگاهرتز',
                    'en' => '16 cores, 32 threads, up to 3.6 GHz',
                    'tr' => '16 çekirdek, 32 iş parçacığı, 3.6 GHz’e kadar',
                ],
                'summary' => [
                    'fa' => 'پردازندهٔ Broadwell-EP روی سوکت LGA2011-3 برای سرورهای Gen9؛ 16 هستهٔ فیزیکی، 40 مگابایت کش L3 و توان طراحی حرارتی 145 وات.',
                    'en' => 'Broadwell-EP processor on socket LGA2011-3 for Gen9 servers: 16 physical cores, 40 MB of L3 cache and a 145 W thermal design power.',
                    'tr' => 'Gen9 sunucular için LGA2011-3 soketinde Broadwell-EP işlemci: 16 fiziksel çekirdek, 40 MB L3 önbellek ve 145 W termal tasarım gücü.',
                ],
                'body' => [
                    'fa' => 'Intel Xeon E5-2697A v4 پردازنده‌ای از خانوادهٔ Broadwell-EP است که روی سوکت LGA2011-3 می‌نشیند و در سرورهای HPE ProLiant Gen9 استفاده می‌شود. 16 هستهٔ فیزیکی و 32 رشتهٔ پردازشی دارد، از فرکانس پایهٔ 2.6 گیگاهرتز شروع می‌کند و در بار سبک تا 3.6 گیگاهرتز بالا می‌رود. 40 مگابایت کش L3 و توان طراحی حرارتی 145 وات.

نقطهٔ شیرین بازار دست دوم است: DDR4، مصرف معقول و قیمتی که هنوز پایین مانده. برای مجازی‌سازی، پایگاه داده و میزبانی اشتراکی گزینهٔ پیش‌فرض ماست.

پیش از سفارش دو نکته را چک کنید: اول اینکه در پیکربندی دو پردازنده‌ای، هر دو سوکت باید مدل یکسان داشته باشند — سرور با دو مدل متفاوت بالا نمی‌آید. دوم اینکه برای توان بالای ۱۳۵ وات، هیت‌سینک پرکارایی و کیت فن لازم است؛ با هیت‌سینک استاندارد سرور خودش را throttle می‌کند و کارایی‌ای که خریده‌اید را نمی‌گیرید.

هر پردازنده پیش از ارسال روی همان نسل سرور بوت و تست می‌شود.',
                    'en' => 'The Intel Xeon E5-2697A v4 is a Broadwell-EP processor for socket LGA2011-3, used in HPE ProLiant Gen9 servers. It has 16 physical cores and 32 threads, starts at a 2.6 GHz base clock and boosts to 3.6 GHz under light load. It carries 40 MB of L3 cache and a 145 W thermal design power.

The sweet spot of the second-hand market: DDR4, sensible power draw and a price that has stayed low. Our default recommendation for virtualisation, databases and shared hosting.

Two things to check before ordering. First, in a dual-socket configuration both sockets must hold the identical model — a server with two different SKUs will not post. Second, anything above 135 W needs the high-performance heatsink and fan kit; with the standard heatsink the server throttles itself and you never get the performance you paid for.

Every processor is booted and tested on the same server generation before it ships.',
                    'tr' => 'Intel Xeon E5-2697A v4, LGA2011-3 soketi için bir Broadwell-EP işlemcisidir ve HPE ProLiant Gen9 sunucularında kullanılır. 16 fiziksel çekirdek ve 32 iş parçacığına sahiptir, 2.6 GHz temel hızda başlar ve hafif yükte 3.6 GHz’e çıkar. 40 MB L3 önbellek ve 145 W termal tasarım gücü taşır.

İkinci el pazarının tatlı noktası: DDR4, makul güç tüketimi ve hâlâ düşük kalmış bir fiyat. Sanallaştırma, veritabanı ve paylaşımlı barındırma için varsayılan önerimiz.

Sipariş öncesi iki nokta: Birincisi, çift soketli yapılandırmada her iki soket de aynı modeli taşımalıdır — iki farklı model ile sunucu açılmaz. İkincisi, 135 W üzerindeki her işlemci yüksek performanslı soğutucu ve fan kiti ister; standart soğutucuyla sunucu kendini kısar ve ödediğiniz performansı alamazsınız.

Her işlemci sevkiyattan önce aynı sunucu neslinde başlatılıp test edilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'خانوادهٔ پردازنده',
                            'en' => 'Processor family',
                            'tr' => 'İşlemci ailesi',
                        ],
                        'value' => [
                            'fa' => 'Broadwell-EP',
                            'en' => 'Broadwell-EP',
                            'tr' => 'Broadwell-EP',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سوکت',
                            'en' => 'Socket',
                            'tr' => 'Soket',
                        ],
                        'value' => [
                            'fa' => 'LGA2011-3',
                            'en' => 'LGA2011-3',
                            'tr' => 'LGA2011-3',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'هسته / رشته',
                            'en' => 'Cores / threads',
                            'tr' => 'Çekirdek / iş parçacığı',
                        ],
                        'value' => [
                            'fa' => '16 / 32',
                            'en' => '16 / 32',
                            'tr' => '16 / 32',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس پایه',
                            'en' => 'Base clock',
                            'tr' => 'Temel hız',
                        ],
                        'value' => [
                            'fa' => '2.6 GHz',
                            'en' => '2.6 GHz',
                            'tr' => '2.6 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس توربو',
                            'en' => 'Max turbo',
                            'tr' => 'Maks. turbo',
                        ],
                        'value' => [
                            'fa' => '3.6 GHz',
                            'en' => '3.6 GHz',
                            'tr' => '3.6 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کش L3',
                            'en' => 'L3 cache',
                            'tr' => 'L3 önbellek',
                        ],
                        'value' => [
                            'fa' => '40 MB',
                            'en' => '40 MB',
                            'tr' => '40 MB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'توان طراحی حرارتی',
                            'en' => 'TDP',
                            'tr' => 'TDP',
                        ],
                        'value' => [
                            'fa' => '145 W',
                            'en' => '145 W',
                            'tr' => '145 W',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen9',
                            'en' => 'Gen9',
                            'tr' => 'Gen9',
                        ],
                    ],
                ],
                'attrs' => [
                    'cores' => 16,
                    'threads' => 32,
                    'ghz' => 2.6,
                    'ghz_turbo' => 3.6,
                    'cache_mb' => 40,
                    'tdp' => 145,
                ],
            ],
            [
                'slug' => 'xeon-e5-2696-v4',
                'category' => 'cpu',
                'brand' => 'Intel',
                'compat_gens' => [
                    'gen9',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 10924,
                'in_stock' => true,
                'popular' => false,
                'sort' => 150,
                'name' => [
                    'fa' => 'Intel Xeon E5-2696 v4',
                    'en' => 'Intel Xeon E5-2696 v4',
                    'tr' => 'Intel Xeon E5-2696 v4',
                ],
                'tagline' => [
                    'fa' => '22 هسته، 44 رشته، تا 3.6 گیگاهرتز',
                    'en' => '22 cores, 44 threads, up to 3.6 GHz',
                    'tr' => '22 çekirdek, 44 iş parçacığı, 3.6 GHz’e kadar',
                ],
                'summary' => [
                    'fa' => 'پردازندهٔ Broadwell-EP روی سوکت LGA2011-3 برای سرورهای Gen9؛ 22 هستهٔ فیزیکی، 55 مگابایت کش L3 و توان طراحی حرارتی 150 وات.',
                    'en' => 'Broadwell-EP processor on socket LGA2011-3 for Gen9 servers: 22 physical cores, 55 MB of L3 cache and a 150 W thermal design power.',
                    'tr' => 'Gen9 sunucular için LGA2011-3 soketinde Broadwell-EP işlemci: 22 fiziksel çekirdek, 55 MB L3 önbellek ve 150 W termal tasarım gücü.',
                ],
                'body' => [
                    'fa' => 'Intel Xeon E5-2696 v4 پردازنده‌ای از خانوادهٔ Broadwell-EP است که روی سوکت LGA2011-3 می‌نشیند و در سرورهای HPE ProLiant Gen9 استفاده می‌شود. 22 هستهٔ فیزیکی و 44 رشتهٔ پردازشی دارد، از فرکانس پایهٔ 2.2 گیگاهرتز شروع می‌کند و در بار سبک تا 3.6 گیگاهرتز بالا می‌رود. 55 مگابایت کش L3 و توان طراحی حرارتی 150 وات.

نقطهٔ شیرین بازار دست دوم است: DDR4، مصرف معقول و قیمتی که هنوز پایین مانده. برای مجازی‌سازی، پایگاه داده و میزبانی اشتراکی گزینهٔ پیش‌فرض ماست.

پیش از سفارش دو نکته را چک کنید: اول اینکه در پیکربندی دو پردازنده‌ای، هر دو سوکت باید مدل یکسان داشته باشند — سرور با دو مدل متفاوت بالا نمی‌آید. دوم اینکه برای توان بالای ۱۳۵ وات، هیت‌سینک پرکارایی و کیت فن لازم است؛ با هیت‌سینک استاندارد سرور خودش را throttle می‌کند و کارایی‌ای که خریده‌اید را نمی‌گیرید.

هر پردازنده پیش از ارسال روی همان نسل سرور بوت و تست می‌شود.',
                    'en' => 'The Intel Xeon E5-2696 v4 is a Broadwell-EP processor for socket LGA2011-3, used in HPE ProLiant Gen9 servers. It has 22 physical cores and 44 threads, starts at a 2.2 GHz base clock and boosts to 3.6 GHz under light load. It carries 55 MB of L3 cache and a 150 W thermal design power.

The sweet spot of the second-hand market: DDR4, sensible power draw and a price that has stayed low. Our default recommendation for virtualisation, databases and shared hosting.

Two things to check before ordering. First, in a dual-socket configuration both sockets must hold the identical model — a server with two different SKUs will not post. Second, anything above 135 W needs the high-performance heatsink and fan kit; with the standard heatsink the server throttles itself and you never get the performance you paid for.

Every processor is booted and tested on the same server generation before it ships.',
                    'tr' => 'Intel Xeon E5-2696 v4, LGA2011-3 soketi için bir Broadwell-EP işlemcisidir ve HPE ProLiant Gen9 sunucularında kullanılır. 22 fiziksel çekirdek ve 44 iş parçacığına sahiptir, 2.2 GHz temel hızda başlar ve hafif yükte 3.6 GHz’e çıkar. 55 MB L3 önbellek ve 150 W termal tasarım gücü taşır.

İkinci el pazarının tatlı noktası: DDR4, makul güç tüketimi ve hâlâ düşük kalmış bir fiyat. Sanallaştırma, veritabanı ve paylaşımlı barındırma için varsayılan önerimiz.

Sipariş öncesi iki nokta: Birincisi, çift soketli yapılandırmada her iki soket de aynı modeli taşımalıdır — iki farklı model ile sunucu açılmaz. İkincisi, 135 W üzerindeki her işlemci yüksek performanslı soğutucu ve fan kiti ister; standart soğutucuyla sunucu kendini kısar ve ödediğiniz performansı alamazsınız.

Her işlemci sevkiyattan önce aynı sunucu neslinde başlatılıp test edilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'خانوادهٔ پردازنده',
                            'en' => 'Processor family',
                            'tr' => 'İşlemci ailesi',
                        ],
                        'value' => [
                            'fa' => 'Broadwell-EP',
                            'en' => 'Broadwell-EP',
                            'tr' => 'Broadwell-EP',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سوکت',
                            'en' => 'Socket',
                            'tr' => 'Soket',
                        ],
                        'value' => [
                            'fa' => 'LGA2011-3',
                            'en' => 'LGA2011-3',
                            'tr' => 'LGA2011-3',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'هسته / رشته',
                            'en' => 'Cores / threads',
                            'tr' => 'Çekirdek / iş parçacığı',
                        ],
                        'value' => [
                            'fa' => '22 / 44',
                            'en' => '22 / 44',
                            'tr' => '22 / 44',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس پایه',
                            'en' => 'Base clock',
                            'tr' => 'Temel hız',
                        ],
                        'value' => [
                            'fa' => '2.2 GHz',
                            'en' => '2.2 GHz',
                            'tr' => '2.2 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس توربو',
                            'en' => 'Max turbo',
                            'tr' => 'Maks. turbo',
                        ],
                        'value' => [
                            'fa' => '3.6 GHz',
                            'en' => '3.6 GHz',
                            'tr' => '3.6 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کش L3',
                            'en' => 'L3 cache',
                            'tr' => 'L3 önbellek',
                        ],
                        'value' => [
                            'fa' => '55 MB',
                            'en' => '55 MB',
                            'tr' => '55 MB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'توان طراحی حرارتی',
                            'en' => 'TDP',
                            'tr' => 'TDP',
                        ],
                        'value' => [
                            'fa' => '150 W',
                            'en' => '150 W',
                            'tr' => '150 W',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen9',
                            'en' => 'Gen9',
                            'tr' => 'Gen9',
                        ],
                    ],
                ],
                'attrs' => [
                    'cores' => 22,
                    'threads' => 44,
                    'ghz' => 2.2,
                    'ghz_turbo' => 3.6,
                    'cache_mb' => 55,
                    'tdp' => 150,
                ],
            ],
            [
                'slug' => 'xeon-e5-2699-v4',
                'category' => 'cpu',
                'brand' => 'Intel',
                'compat_gens' => [
                    'gen9',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 11800,
                'in_stock' => true,
                'popular' => false,
                'sort' => 160,
                'name' => [
                    'fa' => 'Intel Xeon E5-2699 v4',
                    'en' => 'Intel Xeon E5-2699 v4',
                    'tr' => 'Intel Xeon E5-2699 v4',
                ],
                'tagline' => [
                    'fa' => '22 هسته، 44 رشته، تا 3.6 گیگاهرتز',
                    'en' => '22 cores, 44 threads, up to 3.6 GHz',
                    'tr' => '22 çekirdek, 44 iş parçacığı, 3.6 GHz’e kadar',
                ],
                'summary' => [
                    'fa' => 'پردازندهٔ Broadwell-EP روی سوکت LGA2011-3 برای سرورهای Gen9؛ 22 هستهٔ فیزیکی، 55 مگابایت کش L3 و توان طراحی حرارتی 145 وات.',
                    'en' => 'Broadwell-EP processor on socket LGA2011-3 for Gen9 servers: 22 physical cores, 55 MB of L3 cache and a 145 W thermal design power.',
                    'tr' => 'Gen9 sunucular için LGA2011-3 soketinde Broadwell-EP işlemci: 22 fiziksel çekirdek, 55 MB L3 önbellek ve 145 W termal tasarım gücü.',
                ],
                'body' => [
                    'fa' => 'Intel Xeon E5-2699 v4 پردازنده‌ای از خانوادهٔ Broadwell-EP است که روی سوکت LGA2011-3 می‌نشیند و در سرورهای HPE ProLiant Gen9 استفاده می‌شود. 22 هستهٔ فیزیکی و 44 رشتهٔ پردازشی دارد، از فرکانس پایهٔ 2.2 گیگاهرتز شروع می‌کند و در بار سبک تا 3.6 گیگاهرتز بالا می‌رود. 55 مگابایت کش L3 و توان طراحی حرارتی 145 وات.

نقطهٔ شیرین بازار دست دوم است: DDR4، مصرف معقول و قیمتی که هنوز پایین مانده. برای مجازی‌سازی، پایگاه داده و میزبانی اشتراکی گزینهٔ پیش‌فرض ماست.

پیش از سفارش دو نکته را چک کنید: اول اینکه در پیکربندی دو پردازنده‌ای، هر دو سوکت باید مدل یکسان داشته باشند — سرور با دو مدل متفاوت بالا نمی‌آید. دوم اینکه برای توان بالای ۱۳۵ وات، هیت‌سینک پرکارایی و کیت فن لازم است؛ با هیت‌سینک استاندارد سرور خودش را throttle می‌کند و کارایی‌ای که خریده‌اید را نمی‌گیرید.

هر پردازنده پیش از ارسال روی همان نسل سرور بوت و تست می‌شود.',
                    'en' => 'The Intel Xeon E5-2699 v4 is a Broadwell-EP processor for socket LGA2011-3, used in HPE ProLiant Gen9 servers. It has 22 physical cores and 44 threads, starts at a 2.2 GHz base clock and boosts to 3.6 GHz under light load. It carries 55 MB of L3 cache and a 145 W thermal design power.

The sweet spot of the second-hand market: DDR4, sensible power draw and a price that has stayed low. Our default recommendation for virtualisation, databases and shared hosting.

Two things to check before ordering. First, in a dual-socket configuration both sockets must hold the identical model — a server with two different SKUs will not post. Second, anything above 135 W needs the high-performance heatsink and fan kit; with the standard heatsink the server throttles itself and you never get the performance you paid for.

Every processor is booted and tested on the same server generation before it ships.',
                    'tr' => 'Intel Xeon E5-2699 v4, LGA2011-3 soketi için bir Broadwell-EP işlemcisidir ve HPE ProLiant Gen9 sunucularında kullanılır. 22 fiziksel çekirdek ve 44 iş parçacığına sahiptir, 2.2 GHz temel hızda başlar ve hafif yükte 3.6 GHz’e çıkar. 55 MB L3 önbellek ve 145 W termal tasarım gücü taşır.

İkinci el pazarının tatlı noktası: DDR4, makul güç tüketimi ve hâlâ düşük kalmış bir fiyat. Sanallaştırma, veritabanı ve paylaşımlı barındırma için varsayılan önerimiz.

Sipariş öncesi iki nokta: Birincisi, çift soketli yapılandırmada her iki soket de aynı modeli taşımalıdır — iki farklı model ile sunucu açılmaz. İkincisi, 135 W üzerindeki her işlemci yüksek performanslı soğutucu ve fan kiti ister; standart soğutucuyla sunucu kendini kısar ve ödediğiniz performansı alamazsınız.

Her işlemci sevkiyattan önce aynı sunucu neslinde başlatılıp test edilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'خانوادهٔ پردازنده',
                            'en' => 'Processor family',
                            'tr' => 'İşlemci ailesi',
                        ],
                        'value' => [
                            'fa' => 'Broadwell-EP',
                            'en' => 'Broadwell-EP',
                            'tr' => 'Broadwell-EP',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سوکت',
                            'en' => 'Socket',
                            'tr' => 'Soket',
                        ],
                        'value' => [
                            'fa' => 'LGA2011-3',
                            'en' => 'LGA2011-3',
                            'tr' => 'LGA2011-3',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'هسته / رشته',
                            'en' => 'Cores / threads',
                            'tr' => 'Çekirdek / iş parçacığı',
                        ],
                        'value' => [
                            'fa' => '22 / 44',
                            'en' => '22 / 44',
                            'tr' => '22 / 44',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس پایه',
                            'en' => 'Base clock',
                            'tr' => 'Temel hız',
                        ],
                        'value' => [
                            'fa' => '2.2 GHz',
                            'en' => '2.2 GHz',
                            'tr' => '2.2 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس توربو',
                            'en' => 'Max turbo',
                            'tr' => 'Maks. turbo',
                        ],
                        'value' => [
                            'fa' => '3.6 GHz',
                            'en' => '3.6 GHz',
                            'tr' => '3.6 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کش L3',
                            'en' => 'L3 cache',
                            'tr' => 'L3 önbellek',
                        ],
                        'value' => [
                            'fa' => '55 MB',
                            'en' => '55 MB',
                            'tr' => '55 MB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'توان طراحی حرارتی',
                            'en' => 'TDP',
                            'tr' => 'TDP',
                        ],
                        'value' => [
                            'fa' => '145 W',
                            'en' => '145 W',
                            'tr' => '145 W',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen9',
                            'en' => 'Gen9',
                            'tr' => 'Gen9',
                        ],
                    ],
                ],
                'attrs' => [
                    'cores' => 22,
                    'threads' => 44,
                    'ghz' => 2.2,
                    'ghz_turbo' => 3.6,
                    'cache_mb' => 55,
                    'tdp' => 145,
                ],
            ],
            [
                'slug' => 'xeon-silver-4210',
                'category' => 'cpu',
                'brand' => 'Intel',
                'compat_gens' => [
                    'gen10',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 5500,
                'in_stock' => true,
                'popular' => false,
                'sort' => 170,
                'name' => [
                    'fa' => 'Intel Xeon Silver 4210',
                    'en' => 'Intel Xeon Silver 4210',
                    'tr' => 'Intel Xeon Silver 4210',
                ],
                'tagline' => [
                    'fa' => '10 هسته، 20 رشته، تا 3.2 گیگاهرتز',
                    'en' => '10 cores, 20 threads, up to 3.2 GHz',
                    'tr' => '10 çekirdek, 20 iş parçacığı, 3.2 GHz’e kadar',
                ],
                'summary' => [
                    'fa' => 'پردازندهٔ Cascade Lake-SP روی سوکت LGA3647 برای سرورهای Gen10؛ 10 هستهٔ فیزیکی، 13.75 مگابایت کش L3 و توان طراحی حرارتی 85 وات.',
                    'en' => 'Cascade Lake-SP processor on socket LGA3647 for Gen10 servers: 10 physical cores, 13.75 MB of L3 cache and a 85 W thermal design power.',
                    'tr' => 'Gen10 sunucular için LGA3647 soketinde Cascade Lake-SP işlemci: 10 fiziksel çekirdek, 13.75 MB L3 önbellek ve 85 W termal tasarım gücü.',
                ],
                'body' => [
                    'fa' => 'Intel Xeon Silver 4210 پردازنده‌ای از خانوادهٔ Cascade Lake-SP است که روی سوکت LGA3647 می‌نشیند و در سرورهای HPE ProLiant Gen10 استفاده می‌شود. 10 هستهٔ فیزیکی و 20 رشتهٔ پردازشی دارد، از فرکانس پایهٔ 2.2 گیگاهرتز شروع می‌کند و در بار سبک تا 3.2 گیگاهرتز بالا می‌رود. 13.75 مگابایت کش L3 و توان طراحی حرارتی 85 وات.

همان معماری Skylake با فرکانس بالاتر، پشتیبانی از DDR4-2933 و اصلاح سخت‌افزاری چند آسیب‌پذیری اجرای گمانه‌زنانه. برای سرور تولیدی امروز، منطقی‌ترین نسل دست دوم.

پیش از سفارش دو نکته را چک کنید: اول اینکه در پیکربندی دو پردازنده‌ای، هر دو سوکت باید مدل یکسان داشته باشند — سرور با دو مدل متفاوت بالا نمی‌آید. دوم اینکه برای توان بالای ۱۳۵ وات، هیت‌سینک پرکارایی و کیت فن لازم است؛ با هیت‌سینک استاندارد سرور خودش را throttle می‌کند و کارایی‌ای که خریده‌اید را نمی‌گیرید.

هر پردازنده پیش از ارسال روی همان نسل سرور بوت و تست می‌شود.',
                    'en' => 'The Intel Xeon Silver 4210 is a Cascade Lake-SP processor for socket LGA3647, used in HPE ProLiant Gen10 servers. It has 10 physical cores and 20 threads, starts at a 2.2 GHz base clock and boosts to 3.2 GHz under light load. It carries 13.75 MB of L3 cache and a 85 W thermal design power.

The Skylake architecture at higher clocks, with DDR4-2933 support and hardware mitigation for several speculative-execution vulnerabilities. The most sensible second-hand generation for a production server today.

Two things to check before ordering. First, in a dual-socket configuration both sockets must hold the identical model — a server with two different SKUs will not post. Second, anything above 135 W needs the high-performance heatsink and fan kit; with the standard heatsink the server throttles itself and you never get the performance you paid for.

Every processor is booted and tested on the same server generation before it ships.',
                    'tr' => 'Intel Xeon Silver 4210, LGA3647 soketi için bir Cascade Lake-SP işlemcisidir ve HPE ProLiant Gen10 sunucularında kullanılır. 10 fiziksel çekirdek ve 20 iş parçacığına sahiptir, 2.2 GHz temel hızda başlar ve hafif yükte 3.2 GHz’e çıkar. 13.75 MB L3 önbellek ve 85 W termal tasarım gücü taşır.

Daha yüksek hızlarda Skylake mimarisi, DDR4-2933 desteği ve birkaç spekülatif yürütme açığı için donanımsal önlem. Bugün üretim sunucusu için en mantıklı ikinci el nesil.

Sipariş öncesi iki nokta: Birincisi, çift soketli yapılandırmada her iki soket de aynı modeli taşımalıdır — iki farklı model ile sunucu açılmaz. İkincisi, 135 W üzerindeki her işlemci yüksek performanslı soğutucu ve fan kiti ister; standart soğutucuyla sunucu kendini kısar ve ödediğiniz performansı alamazsınız.

Her işlemci sevkiyattan önce aynı sunucu neslinde başlatılıp test edilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'خانوادهٔ پردازنده',
                            'en' => 'Processor family',
                            'tr' => 'İşlemci ailesi',
                        ],
                        'value' => [
                            'fa' => 'Cascade Lake-SP',
                            'en' => 'Cascade Lake-SP',
                            'tr' => 'Cascade Lake-SP',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سوکت',
                            'en' => 'Socket',
                            'tr' => 'Soket',
                        ],
                        'value' => [
                            'fa' => 'LGA3647',
                            'en' => 'LGA3647',
                            'tr' => 'LGA3647',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'هسته / رشته',
                            'en' => 'Cores / threads',
                            'tr' => 'Çekirdek / iş parçacığı',
                        ],
                        'value' => [
                            'fa' => '10 / 20',
                            'en' => '10 / 20',
                            'tr' => '10 / 20',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس پایه',
                            'en' => 'Base clock',
                            'tr' => 'Temel hız',
                        ],
                        'value' => [
                            'fa' => '2.2 GHz',
                            'en' => '2.2 GHz',
                            'tr' => '2.2 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس توربو',
                            'en' => 'Max turbo',
                            'tr' => 'Maks. turbo',
                        ],
                        'value' => [
                            'fa' => '3.2 GHz',
                            'en' => '3.2 GHz',
                            'tr' => '3.2 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کش L3',
                            'en' => 'L3 cache',
                            'tr' => 'L3 önbellek',
                        ],
                        'value' => [
                            'fa' => '13.75 MB',
                            'en' => '13.75 MB',
                            'tr' => '13.75 MB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'توان طراحی حرارتی',
                            'en' => 'TDP',
                            'tr' => 'TDP',
                        ],
                        'value' => [
                            'fa' => '85 W',
                            'en' => '85 W',
                            'tr' => '85 W',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen10',
                            'en' => 'Gen10',
                            'tr' => 'Gen10',
                        ],
                    ],
                ],
                'attrs' => [
                    'cores' => 10,
                    'threads' => 20,
                    'ghz' => 2.2,
                    'ghz_turbo' => 3.2,
                    'cache_mb' => 13.75,
                    'tdp' => 85,
                ],
            ],
            [
                'slug' => 'xeon-gold-6134',
                'category' => 'cpu',
                'brand' => 'Intel',
                'compat_gens' => [
                    'gen10',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 8403,
                'in_stock' => true,
                'popular' => false,
                'sort' => 180,
                'name' => [
                    'fa' => 'Intel Xeon Gold 6134',
                    'en' => 'Intel Xeon Gold 6134',
                    'tr' => 'Intel Xeon Gold 6134',
                ],
                'tagline' => [
                    'fa' => '8 هسته، 16 رشته، تا 3.7 گیگاهرتز',
                    'en' => '8 cores, 16 threads, up to 3.7 GHz',
                    'tr' => '8 çekirdek, 16 iş parçacığı, 3.7 GHz’e kadar',
                ],
                'summary' => [
                    'fa' => 'پردازندهٔ Skylake-SP روی سوکت LGA3647 برای سرورهای Gen10؛ 8 هستهٔ فیزیکی، 24.75 مگابایت کش L3 و توان طراحی حرارتی 130 وات.',
                    'en' => 'Skylake-SP processor on socket LGA3647 for Gen10 servers: 8 physical cores, 24.75 MB of L3 cache and a 130 W thermal design power.',
                    'tr' => 'Gen10 sunucular için LGA3647 soketinde Skylake-SP işlemci: 8 fiziksel çekirdek, 24.75 MB L3 önbellek ve 130 W termal tasarım gücü.',
                ],
                'body' => [
                    'fa' => 'Intel Xeon Gold 6134 پردازنده‌ای از خانوادهٔ Skylake-SP است که روی سوکت LGA3647 می‌نشیند و در سرورهای HPE ProLiant Gen10 استفاده می‌شود. 8 هستهٔ فیزیکی و 16 رشتهٔ پردازشی دارد، از فرکانس پایهٔ 3.2 گیگاهرتز شروع می‌کند و در بار سبک تا 3.7 گیگاهرتز بالا می‌رود. 24.75 مگابایت کش L3 و توان طراحی حرارتی 130 وات.

شش کانال حافظه و AVX-512 را می‌آورد. برای بارهای محاسباتی و پایگاه دادهٔ درون‌حافظه‌ای تفاوت محسوسی با نسل قبل دارد.

پیش از سفارش دو نکته را چک کنید: اول اینکه در پیکربندی دو پردازنده‌ای، هر دو سوکت باید مدل یکسان داشته باشند — سرور با دو مدل متفاوت بالا نمی‌آید. دوم اینکه برای توان بالای ۱۳۵ وات، هیت‌سینک پرکارایی و کیت فن لازم است؛ با هیت‌سینک استاندارد سرور خودش را throttle می‌کند و کارایی‌ای که خریده‌اید را نمی‌گیرید.

هر پردازنده پیش از ارسال روی همان نسل سرور بوت و تست می‌شود.',
                    'en' => 'The Intel Xeon Gold 6134 is a Skylake-SP processor for socket LGA3647, used in HPE ProLiant Gen10 servers. It has 8 physical cores and 16 threads, starts at a 3.2 GHz base clock and boosts to 3.7 GHz under light load. It carries 24.75 MB of L3 cache and a 130 W thermal design power.

Brings six memory channels and AVX-512. A noticeable step up from the previous generation for compute-heavy work and in-memory databases.

Two things to check before ordering. First, in a dual-socket configuration both sockets must hold the identical model — a server with two different SKUs will not post. Second, anything above 135 W needs the high-performance heatsink and fan kit; with the standard heatsink the server throttles itself and you never get the performance you paid for.

Every processor is booted and tested on the same server generation before it ships.',
                    'tr' => 'Intel Xeon Gold 6134, LGA3647 soketi için bir Skylake-SP işlemcisidir ve HPE ProLiant Gen10 sunucularında kullanılır. 8 fiziksel çekirdek ve 16 iş parçacığına sahiptir, 3.2 GHz temel hızda başlar ve hafif yükte 3.7 GHz’e çıkar. 24.75 MB L3 önbellek ve 130 W termal tasarım gücü taşır.

Altı bellek kanalı ve AVX-512 getirir. Hesaplama yoğun işler ve bellek içi veritabanları için önceki nesle göre belirgin bir sıçrama.

Sipariş öncesi iki nokta: Birincisi, çift soketli yapılandırmada her iki soket de aynı modeli taşımalıdır — iki farklı model ile sunucu açılmaz. İkincisi, 135 W üzerindeki her işlemci yüksek performanslı soğutucu ve fan kiti ister; standart soğutucuyla sunucu kendini kısar ve ödediğiniz performansı alamazsınız.

Her işlemci sevkiyattan önce aynı sunucu neslinde başlatılıp test edilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'خانوادهٔ پردازنده',
                            'en' => 'Processor family',
                            'tr' => 'İşlemci ailesi',
                        ],
                        'value' => [
                            'fa' => 'Skylake-SP',
                            'en' => 'Skylake-SP',
                            'tr' => 'Skylake-SP',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سوکت',
                            'en' => 'Socket',
                            'tr' => 'Soket',
                        ],
                        'value' => [
                            'fa' => 'LGA3647',
                            'en' => 'LGA3647',
                            'tr' => 'LGA3647',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'هسته / رشته',
                            'en' => 'Cores / threads',
                            'tr' => 'Çekirdek / iş parçacığı',
                        ],
                        'value' => [
                            'fa' => '8 / 16',
                            'en' => '8 / 16',
                            'tr' => '8 / 16',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس پایه',
                            'en' => 'Base clock',
                            'tr' => 'Temel hız',
                        ],
                        'value' => [
                            'fa' => '3.2 GHz',
                            'en' => '3.2 GHz',
                            'tr' => '3.2 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس توربو',
                            'en' => 'Max turbo',
                            'tr' => 'Maks. turbo',
                        ],
                        'value' => [
                            'fa' => '3.7 GHz',
                            'en' => '3.7 GHz',
                            'tr' => '3.7 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کش L3',
                            'en' => 'L3 cache',
                            'tr' => 'L3 önbellek',
                        ],
                        'value' => [
                            'fa' => '24.75 MB',
                            'en' => '24.75 MB',
                            'tr' => '24.75 MB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'توان طراحی حرارتی',
                            'en' => 'TDP',
                            'tr' => 'TDP',
                        ],
                        'value' => [
                            'fa' => '130 W',
                            'en' => '130 W',
                            'tr' => '130 W',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen10',
                            'en' => 'Gen10',
                            'tr' => 'Gen10',
                        ],
                    ],
                ],
                'attrs' => [
                    'cores' => 8,
                    'threads' => 16,
                    'ghz' => 3.2,
                    'ghz_turbo' => 3.7,
                    'cache_mb' => 24.75,
                    'tdp' => 130,
                ],
            ],
            [
                'slug' => 'xeon-gold-6152',
                'category' => 'cpu',
                'brand' => 'Intel',
                'compat_gens' => [
                    'gen10',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 8403,
                'in_stock' => true,
                'popular' => false,
                'sort' => 190,
                'name' => [
                    'fa' => 'Intel Xeon Gold 6152',
                    'en' => 'Intel Xeon Gold 6152',
                    'tr' => 'Intel Xeon Gold 6152',
                ],
                'tagline' => [
                    'fa' => '22 هسته، 44 رشته، تا 3.7 گیگاهرتز',
                    'en' => '22 cores, 44 threads, up to 3.7 GHz',
                    'tr' => '22 çekirdek, 44 iş parçacığı, 3.7 GHz’e kadar',
                ],
                'summary' => [
                    'fa' => 'پردازندهٔ Skylake-SP روی سوکت LGA3647 برای سرورهای Gen10؛ 22 هستهٔ فیزیکی، 30.25 مگابایت کش L3 و توان طراحی حرارتی 140 وات.',
                    'en' => 'Skylake-SP processor on socket LGA3647 for Gen10 servers: 22 physical cores, 30.25 MB of L3 cache and a 140 W thermal design power.',
                    'tr' => 'Gen10 sunucular için LGA3647 soketinde Skylake-SP işlemci: 22 fiziksel çekirdek, 30.25 MB L3 önbellek ve 140 W termal tasarım gücü.',
                ],
                'body' => [
                    'fa' => 'Intel Xeon Gold 6152 پردازنده‌ای از خانوادهٔ Skylake-SP است که روی سوکت LGA3647 می‌نشیند و در سرورهای HPE ProLiant Gen10 استفاده می‌شود. 22 هستهٔ فیزیکی و 44 رشتهٔ پردازشی دارد، از فرکانس پایهٔ 2.1 گیگاهرتز شروع می‌کند و در بار سبک تا 3.7 گیگاهرتز بالا می‌رود. 30.25 مگابایت کش L3 و توان طراحی حرارتی 140 وات.

شش کانال حافظه و AVX-512 را می‌آورد. برای بارهای محاسباتی و پایگاه دادهٔ درون‌حافظه‌ای تفاوت محسوسی با نسل قبل دارد.

پیش از سفارش دو نکته را چک کنید: اول اینکه در پیکربندی دو پردازنده‌ای، هر دو سوکت باید مدل یکسان داشته باشند — سرور با دو مدل متفاوت بالا نمی‌آید. دوم اینکه برای توان بالای ۱۳۵ وات، هیت‌سینک پرکارایی و کیت فن لازم است؛ با هیت‌سینک استاندارد سرور خودش را throttle می‌کند و کارایی‌ای که خریده‌اید را نمی‌گیرید.

هر پردازنده پیش از ارسال روی همان نسل سرور بوت و تست می‌شود.',
                    'en' => 'The Intel Xeon Gold 6152 is a Skylake-SP processor for socket LGA3647, used in HPE ProLiant Gen10 servers. It has 22 physical cores and 44 threads, starts at a 2.1 GHz base clock and boosts to 3.7 GHz under light load. It carries 30.25 MB of L3 cache and a 140 W thermal design power.

Brings six memory channels and AVX-512. A noticeable step up from the previous generation for compute-heavy work and in-memory databases.

Two things to check before ordering. First, in a dual-socket configuration both sockets must hold the identical model — a server with two different SKUs will not post. Second, anything above 135 W needs the high-performance heatsink and fan kit; with the standard heatsink the server throttles itself and you never get the performance you paid for.

Every processor is booted and tested on the same server generation before it ships.',
                    'tr' => 'Intel Xeon Gold 6152, LGA3647 soketi için bir Skylake-SP işlemcisidir ve HPE ProLiant Gen10 sunucularında kullanılır. 22 fiziksel çekirdek ve 44 iş parçacığına sahiptir, 2.1 GHz temel hızda başlar ve hafif yükte 3.7 GHz’e çıkar. 30.25 MB L3 önbellek ve 140 W termal tasarım gücü taşır.

Altı bellek kanalı ve AVX-512 getirir. Hesaplama yoğun işler ve bellek içi veritabanları için önceki nesle göre belirgin bir sıçrama.

Sipariş öncesi iki nokta: Birincisi, çift soketli yapılandırmada her iki soket de aynı modeli taşımalıdır — iki farklı model ile sunucu açılmaz. İkincisi, 135 W üzerindeki her işlemci yüksek performanslı soğutucu ve fan kiti ister; standart soğutucuyla sunucu kendini kısar ve ödediğiniz performansı alamazsınız.

Her işlemci sevkiyattan önce aynı sunucu neslinde başlatılıp test edilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'خانوادهٔ پردازنده',
                            'en' => 'Processor family',
                            'tr' => 'İşlemci ailesi',
                        ],
                        'value' => [
                            'fa' => 'Skylake-SP',
                            'en' => 'Skylake-SP',
                            'tr' => 'Skylake-SP',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سوکت',
                            'en' => 'Socket',
                            'tr' => 'Soket',
                        ],
                        'value' => [
                            'fa' => 'LGA3647',
                            'en' => 'LGA3647',
                            'tr' => 'LGA3647',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'هسته / رشته',
                            'en' => 'Cores / threads',
                            'tr' => 'Çekirdek / iş parçacığı',
                        ],
                        'value' => [
                            'fa' => '22 / 44',
                            'en' => '22 / 44',
                            'tr' => '22 / 44',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس پایه',
                            'en' => 'Base clock',
                            'tr' => 'Temel hız',
                        ],
                        'value' => [
                            'fa' => '2.1 GHz',
                            'en' => '2.1 GHz',
                            'tr' => '2.1 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس توربو',
                            'en' => 'Max turbo',
                            'tr' => 'Maks. turbo',
                        ],
                        'value' => [
                            'fa' => '3.7 GHz',
                            'en' => '3.7 GHz',
                            'tr' => '3.7 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کش L3',
                            'en' => 'L3 cache',
                            'tr' => 'L3 önbellek',
                        ],
                        'value' => [
                            'fa' => '30.25 MB',
                            'en' => '30.25 MB',
                            'tr' => '30.25 MB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'توان طراحی حرارتی',
                            'en' => 'TDP',
                            'tr' => 'TDP',
                        ],
                        'value' => [
                            'fa' => '140 W',
                            'en' => '140 W',
                            'tr' => '140 W',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen10',
                            'en' => 'Gen10',
                            'tr' => 'Gen10',
                        ],
                    ],
                ],
                'attrs' => [
                    'cores' => 22,
                    'threads' => 44,
                    'ghz' => 2.1,
                    'ghz_turbo' => 3.7,
                    'cache_mb' => 30.25,
                    'tdp' => 140,
                ],
            ],
            [
                'slug' => 'xeon-gold-5218',
                'category' => 'cpu',
                'brand' => 'Intel',
                'compat_gens' => [
                    'gen10',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 9200,
                'in_stock' => true,
                'popular' => true,
                'sort' => 200,
                'name' => [
                    'fa' => 'Intel Xeon Gold 5218',
                    'en' => 'Intel Xeon Gold 5218',
                    'tr' => 'Intel Xeon Gold 5218',
                ],
                'tagline' => [
                    'fa' => '16 هسته، 32 رشته، تا 3.9 گیگاهرتز',
                    'en' => '16 cores, 32 threads, up to 3.9 GHz',
                    'tr' => '16 çekirdek, 32 iş parçacığı, 3.9 GHz’e kadar',
                ],
                'summary' => [
                    'fa' => 'پردازندهٔ Cascade Lake-SP روی سوکت LGA3647 برای سرورهای Gen10؛ 16 هستهٔ فیزیکی، 22 مگابایت کش L3 و توان طراحی حرارتی 125 وات.',
                    'en' => 'Cascade Lake-SP processor on socket LGA3647 for Gen10 servers: 16 physical cores, 22 MB of L3 cache and a 125 W thermal design power.',
                    'tr' => 'Gen10 sunucular için LGA3647 soketinde Cascade Lake-SP işlemci: 16 fiziksel çekirdek, 22 MB L3 önbellek ve 125 W termal tasarım gücü.',
                ],
                'body' => [
                    'fa' => 'Intel Xeon Gold 5218 پردازنده‌ای از خانوادهٔ Cascade Lake-SP است که روی سوکت LGA3647 می‌نشیند و در سرورهای HPE ProLiant Gen10 استفاده می‌شود. 16 هستهٔ فیزیکی و 32 رشتهٔ پردازشی دارد، از فرکانس پایهٔ 2.3 گیگاهرتز شروع می‌کند و در بار سبک تا 3.9 گیگاهرتز بالا می‌رود. 22 مگابایت کش L3 و توان طراحی حرارتی 125 وات.

همان معماری Skylake با فرکانس بالاتر، پشتیبانی از DDR4-2933 و اصلاح سخت‌افزاری چند آسیب‌پذیری اجرای گمانه‌زنانه. برای سرور تولیدی امروز، منطقی‌ترین نسل دست دوم.

پیش از سفارش دو نکته را چک کنید: اول اینکه در پیکربندی دو پردازنده‌ای، هر دو سوکت باید مدل یکسان داشته باشند — سرور با دو مدل متفاوت بالا نمی‌آید. دوم اینکه برای توان بالای ۱۳۵ وات، هیت‌سینک پرکارایی و کیت فن لازم است؛ با هیت‌سینک استاندارد سرور خودش را throttle می‌کند و کارایی‌ای که خریده‌اید را نمی‌گیرید.

هر پردازنده پیش از ارسال روی همان نسل سرور بوت و تست می‌شود.',
                    'en' => 'The Intel Xeon Gold 5218 is a Cascade Lake-SP processor for socket LGA3647, used in HPE ProLiant Gen10 servers. It has 16 physical cores and 32 threads, starts at a 2.3 GHz base clock and boosts to 3.9 GHz under light load. It carries 22 MB of L3 cache and a 125 W thermal design power.

The Skylake architecture at higher clocks, with DDR4-2933 support and hardware mitigation for several speculative-execution vulnerabilities. The most sensible second-hand generation for a production server today.

Two things to check before ordering. First, in a dual-socket configuration both sockets must hold the identical model — a server with two different SKUs will not post. Second, anything above 135 W needs the high-performance heatsink and fan kit; with the standard heatsink the server throttles itself and you never get the performance you paid for.

Every processor is booted and tested on the same server generation before it ships.',
                    'tr' => 'Intel Xeon Gold 5218, LGA3647 soketi için bir Cascade Lake-SP işlemcisidir ve HPE ProLiant Gen10 sunucularında kullanılır. 16 fiziksel çekirdek ve 32 iş parçacığına sahiptir, 2.3 GHz temel hızda başlar ve hafif yükte 3.9 GHz’e çıkar. 22 MB L3 önbellek ve 125 W termal tasarım gücü taşır.

Daha yüksek hızlarda Skylake mimarisi, DDR4-2933 desteği ve birkaç spekülatif yürütme açığı için donanımsal önlem. Bugün üretim sunucusu için en mantıklı ikinci el nesil.

Sipariş öncesi iki nokta: Birincisi, çift soketli yapılandırmada her iki soket de aynı modeli taşımalıdır — iki farklı model ile sunucu açılmaz. İkincisi, 135 W üzerindeki her işlemci yüksek performanslı soğutucu ve fan kiti ister; standart soğutucuyla sunucu kendini kısar ve ödediğiniz performansı alamazsınız.

Her işlemci sevkiyattan önce aynı sunucu neslinde başlatılıp test edilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'خانوادهٔ پردازنده',
                            'en' => 'Processor family',
                            'tr' => 'İşlemci ailesi',
                        ],
                        'value' => [
                            'fa' => 'Cascade Lake-SP',
                            'en' => 'Cascade Lake-SP',
                            'tr' => 'Cascade Lake-SP',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سوکت',
                            'en' => 'Socket',
                            'tr' => 'Soket',
                        ],
                        'value' => [
                            'fa' => 'LGA3647',
                            'en' => 'LGA3647',
                            'tr' => 'LGA3647',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'هسته / رشته',
                            'en' => 'Cores / threads',
                            'tr' => 'Çekirdek / iş parçacığı',
                        ],
                        'value' => [
                            'fa' => '16 / 32',
                            'en' => '16 / 32',
                            'tr' => '16 / 32',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس پایه',
                            'en' => 'Base clock',
                            'tr' => 'Temel hız',
                        ],
                        'value' => [
                            'fa' => '2.3 GHz',
                            'en' => '2.3 GHz',
                            'tr' => '2.3 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس توربو',
                            'en' => 'Max turbo',
                            'tr' => 'Maks. turbo',
                        ],
                        'value' => [
                            'fa' => '3.9 GHz',
                            'en' => '3.9 GHz',
                            'tr' => '3.9 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کش L3',
                            'en' => 'L3 cache',
                            'tr' => 'L3 önbellek',
                        ],
                        'value' => [
                            'fa' => '22 MB',
                            'en' => '22 MB',
                            'tr' => '22 MB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'توان طراحی حرارتی',
                            'en' => 'TDP',
                            'tr' => 'TDP',
                        ],
                        'value' => [
                            'fa' => '125 W',
                            'en' => '125 W',
                            'tr' => '125 W',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen10',
                            'en' => 'Gen10',
                            'tr' => 'Gen10',
                        ],
                    ],
                ],
                'attrs' => [
                    'cores' => 16,
                    'threads' => 32,
                    'ghz' => 2.3,
                    'ghz_turbo' => 3.9,
                    'cache_mb' => 22,
                    'tdp' => 125,
                ],
            ],
            [
                'slug' => 'xeon-gold-6230',
                'category' => 'cpu',
                'brand' => 'Intel',
                'compat_gens' => [
                    'gen10',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 7562,
                'in_stock' => true,
                'popular' => true,
                'sort' => 210,
                'name' => [
                    'fa' => 'Intel Xeon Gold 6230',
                    'en' => 'Intel Xeon Gold 6230',
                    'tr' => 'Intel Xeon Gold 6230',
                ],
                'tagline' => [
                    'fa' => '20 هسته، 40 رشته، تا 3.9 گیگاهرتز',
                    'en' => '20 cores, 40 threads, up to 3.9 GHz',
                    'tr' => '20 çekirdek, 40 iş parçacığı, 3.9 GHz’e kadar',
                ],
                'summary' => [
                    'fa' => 'پردازندهٔ Cascade Lake-SP روی سوکت LGA3647 برای سرورهای Gen10؛ 20 هستهٔ فیزیکی، 27.5 مگابایت کش L3 و توان طراحی حرارتی 125 وات.',
                    'en' => 'Cascade Lake-SP processor on socket LGA3647 for Gen10 servers: 20 physical cores, 27.5 MB of L3 cache and a 125 W thermal design power.',
                    'tr' => 'Gen10 sunucular için LGA3647 soketinde Cascade Lake-SP işlemci: 20 fiziksel çekirdek, 27.5 MB L3 önbellek ve 125 W termal tasarım gücü.',
                ],
                'body' => [
                    'fa' => 'Intel Xeon Gold 6230 پردازنده‌ای از خانوادهٔ Cascade Lake-SP است که روی سوکت LGA3647 می‌نشیند و در سرورهای HPE ProLiant Gen10 استفاده می‌شود. 20 هستهٔ فیزیکی و 40 رشتهٔ پردازشی دارد، از فرکانس پایهٔ 2.1 گیگاهرتز شروع می‌کند و در بار سبک تا 3.9 گیگاهرتز بالا می‌رود. 27.5 مگابایت کش L3 و توان طراحی حرارتی 125 وات.

همان معماری Skylake با فرکانس بالاتر، پشتیبانی از DDR4-2933 و اصلاح سخت‌افزاری چند آسیب‌پذیری اجرای گمانه‌زنانه. برای سرور تولیدی امروز، منطقی‌ترین نسل دست دوم.

پیش از سفارش دو نکته را چک کنید: اول اینکه در پیکربندی دو پردازنده‌ای، هر دو سوکت باید مدل یکسان داشته باشند — سرور با دو مدل متفاوت بالا نمی‌آید. دوم اینکه برای توان بالای ۱۳۵ وات، هیت‌سینک پرکارایی و کیت فن لازم است؛ با هیت‌سینک استاندارد سرور خودش را throttle می‌کند و کارایی‌ای که خریده‌اید را نمی‌گیرید.

هر پردازنده پیش از ارسال روی همان نسل سرور بوت و تست می‌شود.',
                    'en' => 'The Intel Xeon Gold 6230 is a Cascade Lake-SP processor for socket LGA3647, used in HPE ProLiant Gen10 servers. It has 20 physical cores and 40 threads, starts at a 2.1 GHz base clock and boosts to 3.9 GHz under light load. It carries 27.5 MB of L3 cache and a 125 W thermal design power.

The Skylake architecture at higher clocks, with DDR4-2933 support and hardware mitigation for several speculative-execution vulnerabilities. The most sensible second-hand generation for a production server today.

Two things to check before ordering. First, in a dual-socket configuration both sockets must hold the identical model — a server with two different SKUs will not post. Second, anything above 135 W needs the high-performance heatsink and fan kit; with the standard heatsink the server throttles itself and you never get the performance you paid for.

Every processor is booted and tested on the same server generation before it ships.',
                    'tr' => 'Intel Xeon Gold 6230, LGA3647 soketi için bir Cascade Lake-SP işlemcisidir ve HPE ProLiant Gen10 sunucularında kullanılır. 20 fiziksel çekirdek ve 40 iş parçacığına sahiptir, 2.1 GHz temel hızda başlar ve hafif yükte 3.9 GHz’e çıkar. 27.5 MB L3 önbellek ve 125 W termal tasarım gücü taşır.

Daha yüksek hızlarda Skylake mimarisi, DDR4-2933 desteği ve birkaç spekülatif yürütme açığı için donanımsal önlem. Bugün üretim sunucusu için en mantıklı ikinci el nesil.

Sipariş öncesi iki nokta: Birincisi, çift soketli yapılandırmada her iki soket de aynı modeli taşımalıdır — iki farklı model ile sunucu açılmaz. İkincisi, 135 W üzerindeki her işlemci yüksek performanslı soğutucu ve fan kiti ister; standart soğutucuyla sunucu kendini kısar ve ödediğiniz performansı alamazsınız.

Her işlemci sevkiyattan önce aynı sunucu neslinde başlatılıp test edilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'خانوادهٔ پردازنده',
                            'en' => 'Processor family',
                            'tr' => 'İşlemci ailesi',
                        ],
                        'value' => [
                            'fa' => 'Cascade Lake-SP',
                            'en' => 'Cascade Lake-SP',
                            'tr' => 'Cascade Lake-SP',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سوکت',
                            'en' => 'Socket',
                            'tr' => 'Soket',
                        ],
                        'value' => [
                            'fa' => 'LGA3647',
                            'en' => 'LGA3647',
                            'tr' => 'LGA3647',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'هسته / رشته',
                            'en' => 'Cores / threads',
                            'tr' => 'Çekirdek / iş parçacığı',
                        ],
                        'value' => [
                            'fa' => '20 / 40',
                            'en' => '20 / 40',
                            'tr' => '20 / 40',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس پایه',
                            'en' => 'Base clock',
                            'tr' => 'Temel hız',
                        ],
                        'value' => [
                            'fa' => '2.1 GHz',
                            'en' => '2.1 GHz',
                            'tr' => '2.1 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس توربو',
                            'en' => 'Max turbo',
                            'tr' => 'Maks. turbo',
                        ],
                        'value' => [
                            'fa' => '3.9 GHz',
                            'en' => '3.9 GHz',
                            'tr' => '3.9 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کش L3',
                            'en' => 'L3 cache',
                            'tr' => 'L3 önbellek',
                        ],
                        'value' => [
                            'fa' => '27.5 MB',
                            'en' => '27.5 MB',
                            'tr' => '27.5 MB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'توان طراحی حرارتی',
                            'en' => 'TDP',
                            'tr' => 'TDP',
                        ],
                        'value' => [
                            'fa' => '125 W',
                            'en' => '125 W',
                            'tr' => '125 W',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen10',
                            'en' => 'Gen10',
                            'tr' => 'Gen10',
                        ],
                    ],
                ],
                'attrs' => [
                    'cores' => 20,
                    'threads' => 40,
                    'ghz' => 2.1,
                    'ghz_turbo' => 3.9,
                    'cache_mb' => 27.5,
                    'tdp' => 125,
                ],
            ],
            [
                'slug' => 'xeon-gold-6248',
                'category' => 'cpu',
                'brand' => 'Intel',
                'compat_gens' => [
                    'gen10',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 16806,
                'in_stock' => true,
                'popular' => false,
                'sort' => 220,
                'name' => [
                    'fa' => 'Intel Xeon Gold 6248',
                    'en' => 'Intel Xeon Gold 6248',
                    'tr' => 'Intel Xeon Gold 6248',
                ],
                'tagline' => [
                    'fa' => '20 هسته، 40 رشته، تا 3.9 گیگاهرتز',
                    'en' => '20 cores, 40 threads, up to 3.9 GHz',
                    'tr' => '20 çekirdek, 40 iş parçacığı, 3.9 GHz’e kadar',
                ],
                'summary' => [
                    'fa' => 'پردازندهٔ Cascade Lake-SP روی سوکت LGA3647 برای سرورهای Gen10؛ 20 هستهٔ فیزیکی، 27.5 مگابایت کش L3 و توان طراحی حرارتی 150 وات.',
                    'en' => 'Cascade Lake-SP processor on socket LGA3647 for Gen10 servers: 20 physical cores, 27.5 MB of L3 cache and a 150 W thermal design power.',
                    'tr' => 'Gen10 sunucular için LGA3647 soketinde Cascade Lake-SP işlemci: 20 fiziksel çekirdek, 27.5 MB L3 önbellek ve 150 W termal tasarım gücü.',
                ],
                'body' => [
                    'fa' => 'Intel Xeon Gold 6248 پردازنده‌ای از خانوادهٔ Cascade Lake-SP است که روی سوکت LGA3647 می‌نشیند و در سرورهای HPE ProLiant Gen10 استفاده می‌شود. 20 هستهٔ فیزیکی و 40 رشتهٔ پردازشی دارد، از فرکانس پایهٔ 2.5 گیگاهرتز شروع می‌کند و در بار سبک تا 3.9 گیگاهرتز بالا می‌رود. 27.5 مگابایت کش L3 و توان طراحی حرارتی 150 وات.

همان معماری Skylake با فرکانس بالاتر، پشتیبانی از DDR4-2933 و اصلاح سخت‌افزاری چند آسیب‌پذیری اجرای گمانه‌زنانه. برای سرور تولیدی امروز، منطقی‌ترین نسل دست دوم.

پیش از سفارش دو نکته را چک کنید: اول اینکه در پیکربندی دو پردازنده‌ای، هر دو سوکت باید مدل یکسان داشته باشند — سرور با دو مدل متفاوت بالا نمی‌آید. دوم اینکه برای توان بالای ۱۳۵ وات، هیت‌سینک پرکارایی و کیت فن لازم است؛ با هیت‌سینک استاندارد سرور خودش را throttle می‌کند و کارایی‌ای که خریده‌اید را نمی‌گیرید.

هر پردازنده پیش از ارسال روی همان نسل سرور بوت و تست می‌شود.',
                    'en' => 'The Intel Xeon Gold 6248 is a Cascade Lake-SP processor for socket LGA3647, used in HPE ProLiant Gen10 servers. It has 20 physical cores and 40 threads, starts at a 2.5 GHz base clock and boosts to 3.9 GHz under light load. It carries 27.5 MB of L3 cache and a 150 W thermal design power.

The Skylake architecture at higher clocks, with DDR4-2933 support and hardware mitigation for several speculative-execution vulnerabilities. The most sensible second-hand generation for a production server today.

Two things to check before ordering. First, in a dual-socket configuration both sockets must hold the identical model — a server with two different SKUs will not post. Second, anything above 135 W needs the high-performance heatsink and fan kit; with the standard heatsink the server throttles itself and you never get the performance you paid for.

Every processor is booted and tested on the same server generation before it ships.',
                    'tr' => 'Intel Xeon Gold 6248, LGA3647 soketi için bir Cascade Lake-SP işlemcisidir ve HPE ProLiant Gen10 sunucularında kullanılır. 20 fiziksel çekirdek ve 40 iş parçacığına sahiptir, 2.5 GHz temel hızda başlar ve hafif yükte 3.9 GHz’e çıkar. 27.5 MB L3 önbellek ve 150 W termal tasarım gücü taşır.

Daha yüksek hızlarda Skylake mimarisi, DDR4-2933 desteği ve birkaç spekülatif yürütme açığı için donanımsal önlem. Bugün üretim sunucusu için en mantıklı ikinci el nesil.

Sipariş öncesi iki nokta: Birincisi, çift soketli yapılandırmada her iki soket de aynı modeli taşımalıdır — iki farklı model ile sunucu açılmaz. İkincisi, 135 W üzerindeki her işlemci yüksek performanslı soğutucu ve fan kiti ister; standart soğutucuyla sunucu kendini kısar ve ödediğiniz performansı alamazsınız.

Her işlemci sevkiyattan önce aynı sunucu neslinde başlatılıp test edilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'خانوادهٔ پردازنده',
                            'en' => 'Processor family',
                            'tr' => 'İşlemci ailesi',
                        ],
                        'value' => [
                            'fa' => 'Cascade Lake-SP',
                            'en' => 'Cascade Lake-SP',
                            'tr' => 'Cascade Lake-SP',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سوکت',
                            'en' => 'Socket',
                            'tr' => 'Soket',
                        ],
                        'value' => [
                            'fa' => 'LGA3647',
                            'en' => 'LGA3647',
                            'tr' => 'LGA3647',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'هسته / رشته',
                            'en' => 'Cores / threads',
                            'tr' => 'Çekirdek / iş parçacığı',
                        ],
                        'value' => [
                            'fa' => '20 / 40',
                            'en' => '20 / 40',
                            'tr' => '20 / 40',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس پایه',
                            'en' => 'Base clock',
                            'tr' => 'Temel hız',
                        ],
                        'value' => [
                            'fa' => '2.5 GHz',
                            'en' => '2.5 GHz',
                            'tr' => '2.5 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس توربو',
                            'en' => 'Max turbo',
                            'tr' => 'Maks. turbo',
                        ],
                        'value' => [
                            'fa' => '3.9 GHz',
                            'en' => '3.9 GHz',
                            'tr' => '3.9 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کش L3',
                            'en' => 'L3 cache',
                            'tr' => 'L3 önbellek',
                        ],
                        'value' => [
                            'fa' => '27.5 MB',
                            'en' => '27.5 MB',
                            'tr' => '27.5 MB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'توان طراحی حرارتی',
                            'en' => 'TDP',
                            'tr' => 'TDP',
                        ],
                        'value' => [
                            'fa' => '150 W',
                            'en' => '150 W',
                            'tr' => '150 W',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen10',
                            'en' => 'Gen10',
                            'tr' => 'Gen10',
                        ],
                    ],
                ],
                'attrs' => [
                    'cores' => 20,
                    'threads' => 40,
                    'ghz' => 2.5,
                    'ghz_turbo' => 3.9,
                    'cache_mb' => 27.5,
                    'tdp' => 150,
                ],
            ],
            [
                'slug' => 'xeon-gold-6240r',
                'category' => 'cpu',
                'brand' => 'Intel',
                'compat_gens' => [
                    'gen10',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 16806,
                'in_stock' => true,
                'popular' => false,
                'sort' => 230,
                'name' => [
                    'fa' => 'Intel Xeon Gold 6240R',
                    'en' => 'Intel Xeon Gold 6240R',
                    'tr' => 'Intel Xeon Gold 6240R',
                ],
                'tagline' => [
                    'fa' => '24 هسته، 48 رشته، تا 4.0 گیگاهرتز',
                    'en' => '24 cores, 48 threads, up to 4.0 GHz',
                    'tr' => '24 çekirdek, 48 iş parçacığı, 4.0 GHz’e kadar',
                ],
                'summary' => [
                    'fa' => 'پردازندهٔ Cascade Lake-SP روی سوکت LGA3647 برای سرورهای Gen10؛ 24 هستهٔ فیزیکی، 35.75 مگابایت کش L3 و توان طراحی حرارتی 165 وات.',
                    'en' => 'Cascade Lake-SP processor on socket LGA3647 for Gen10 servers: 24 physical cores, 35.75 MB of L3 cache and a 165 W thermal design power.',
                    'tr' => 'Gen10 sunucular için LGA3647 soketinde Cascade Lake-SP işlemci: 24 fiziksel çekirdek, 35.75 MB L3 önbellek ve 165 W termal tasarım gücü.',
                ],
                'body' => [
                    'fa' => 'Intel Xeon Gold 6240R پردازنده‌ای از خانوادهٔ Cascade Lake-SP است که روی سوکت LGA3647 می‌نشیند و در سرورهای HPE ProLiant Gen10 استفاده می‌شود. 24 هستهٔ فیزیکی و 48 رشتهٔ پردازشی دارد، از فرکانس پایهٔ 2.4 گیگاهرتز شروع می‌کند و در بار سبک تا 4.0 گیگاهرتز بالا می‌رود. 35.75 مگابایت کش L3 و توان طراحی حرارتی 165 وات.

همان معماری Skylake با فرکانس بالاتر، پشتیبانی از DDR4-2933 و اصلاح سخت‌افزاری چند آسیب‌پذیری اجرای گمانه‌زنانه. برای سرور تولیدی امروز، منطقی‌ترین نسل دست دوم.

پیش از سفارش دو نکته را چک کنید: اول اینکه در پیکربندی دو پردازنده‌ای، هر دو سوکت باید مدل یکسان داشته باشند — سرور با دو مدل متفاوت بالا نمی‌آید. دوم اینکه برای توان بالای ۱۳۵ وات، هیت‌سینک پرکارایی و کیت فن لازم است؛ با هیت‌سینک استاندارد سرور خودش را throttle می‌کند و کارایی‌ای که خریده‌اید را نمی‌گیرید.

هر پردازنده پیش از ارسال روی همان نسل سرور بوت و تست می‌شود.',
                    'en' => 'The Intel Xeon Gold 6240R is a Cascade Lake-SP processor for socket LGA3647, used in HPE ProLiant Gen10 servers. It has 24 physical cores and 48 threads, starts at a 2.4 GHz base clock and boosts to 4.0 GHz under light load. It carries 35.75 MB of L3 cache and a 165 W thermal design power.

The Skylake architecture at higher clocks, with DDR4-2933 support and hardware mitigation for several speculative-execution vulnerabilities. The most sensible second-hand generation for a production server today.

Two things to check before ordering. First, in a dual-socket configuration both sockets must hold the identical model — a server with two different SKUs will not post. Second, anything above 135 W needs the high-performance heatsink and fan kit; with the standard heatsink the server throttles itself and you never get the performance you paid for.

Every processor is booted and tested on the same server generation before it ships.',
                    'tr' => 'Intel Xeon Gold 6240R, LGA3647 soketi için bir Cascade Lake-SP işlemcisidir ve HPE ProLiant Gen10 sunucularında kullanılır. 24 fiziksel çekirdek ve 48 iş parçacığına sahiptir, 2.4 GHz temel hızda başlar ve hafif yükte 4.0 GHz’e çıkar. 35.75 MB L3 önbellek ve 165 W termal tasarım gücü taşır.

Daha yüksek hızlarda Skylake mimarisi, DDR4-2933 desteği ve birkaç spekülatif yürütme açığı için donanımsal önlem. Bugün üretim sunucusu için en mantıklı ikinci el nesil.

Sipariş öncesi iki nokta: Birincisi, çift soketli yapılandırmada her iki soket de aynı modeli taşımalıdır — iki farklı model ile sunucu açılmaz. İkincisi, 135 W üzerindeki her işlemci yüksek performanslı soğutucu ve fan kiti ister; standart soğutucuyla sunucu kendini kısar ve ödediğiniz performansı alamazsınız.

Her işlemci sevkiyattan önce aynı sunucu neslinde başlatılıp test edilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'خانوادهٔ پردازنده',
                            'en' => 'Processor family',
                            'tr' => 'İşlemci ailesi',
                        ],
                        'value' => [
                            'fa' => 'Cascade Lake-SP',
                            'en' => 'Cascade Lake-SP',
                            'tr' => 'Cascade Lake-SP',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سوکت',
                            'en' => 'Socket',
                            'tr' => 'Soket',
                        ],
                        'value' => [
                            'fa' => 'LGA3647',
                            'en' => 'LGA3647',
                            'tr' => 'LGA3647',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'هسته / رشته',
                            'en' => 'Cores / threads',
                            'tr' => 'Çekirdek / iş parçacığı',
                        ],
                        'value' => [
                            'fa' => '24 / 48',
                            'en' => '24 / 48',
                            'tr' => '24 / 48',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس پایه',
                            'en' => 'Base clock',
                            'tr' => 'Temel hız',
                        ],
                        'value' => [
                            'fa' => '2.4 GHz',
                            'en' => '2.4 GHz',
                            'tr' => '2.4 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس توربو',
                            'en' => 'Max turbo',
                            'tr' => 'Maks. turbo',
                        ],
                        'value' => [
                            'fa' => '4.0 GHz',
                            'en' => '4.0 GHz',
                            'tr' => '4.0 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کش L3',
                            'en' => 'L3 cache',
                            'tr' => 'L3 önbellek',
                        ],
                        'value' => [
                            'fa' => '35.75 MB',
                            'en' => '35.75 MB',
                            'tr' => '35.75 MB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'توان طراحی حرارتی',
                            'en' => 'TDP',
                            'tr' => 'TDP',
                        ],
                        'value' => [
                            'fa' => '165 W',
                            'en' => '165 W',
                            'tr' => '165 W',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen10',
                            'en' => 'Gen10',
                            'tr' => 'Gen10',
                        ],
                    ],
                ],
                'attrs' => [
                    'cores' => 24,
                    'threads' => 48,
                    'ghz' => 2.4,
                    'ghz_turbo' => 4.0,
                    'cache_mb' => 35.75,
                    'tdp' => 165,
                ],
            ],
            [
                'slug' => 'xeon-gold-6248r',
                'category' => 'cpu',
                'brand' => 'Intel',
                'compat_gens' => [
                    'gen10',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 54621,
                'in_stock' => true,
                'popular' => false,
                'sort' => 240,
                'name' => [
                    'fa' => 'Intel Xeon Gold 6248R',
                    'en' => 'Intel Xeon Gold 6248R',
                    'tr' => 'Intel Xeon Gold 6248R',
                ],
                'tagline' => [
                    'fa' => '24 هسته، 48 رشته، تا 4.0 گیگاهرتز',
                    'en' => '24 cores, 48 threads, up to 4.0 GHz',
                    'tr' => '24 çekirdek, 48 iş parçacığı, 4.0 GHz’e kadar',
                ],
                'summary' => [
                    'fa' => 'پردازندهٔ Cascade Lake-SP روی سوکت LGA3647 برای سرورهای Gen10؛ 24 هستهٔ فیزیکی، 35.75 مگابایت کش L3 و توان طراحی حرارتی 205 وات.',
                    'en' => 'Cascade Lake-SP processor on socket LGA3647 for Gen10 servers: 24 physical cores, 35.75 MB of L3 cache and a 205 W thermal design power.',
                    'tr' => 'Gen10 sunucular için LGA3647 soketinde Cascade Lake-SP işlemci: 24 fiziksel çekirdek, 35.75 MB L3 önbellek ve 205 W termal tasarım gücü.',
                ],
                'body' => [
                    'fa' => 'Intel Xeon Gold 6248R پردازنده‌ای از خانوادهٔ Cascade Lake-SP است که روی سوکت LGA3647 می‌نشیند و در سرورهای HPE ProLiant Gen10 استفاده می‌شود. 24 هستهٔ فیزیکی و 48 رشتهٔ پردازشی دارد، از فرکانس پایهٔ 3.0 گیگاهرتز شروع می‌کند و در بار سبک تا 4.0 گیگاهرتز بالا می‌رود. 35.75 مگابایت کش L3 و توان طراحی حرارتی 205 وات.

همان معماری Skylake با فرکانس بالاتر، پشتیبانی از DDR4-2933 و اصلاح سخت‌افزاری چند آسیب‌پذیری اجرای گمانه‌زنانه. برای سرور تولیدی امروز، منطقی‌ترین نسل دست دوم.

پیش از سفارش دو نکته را چک کنید: اول اینکه در پیکربندی دو پردازنده‌ای، هر دو سوکت باید مدل یکسان داشته باشند — سرور با دو مدل متفاوت بالا نمی‌آید. دوم اینکه برای توان بالای ۱۳۵ وات، هیت‌سینک پرکارایی و کیت فن لازم است؛ با هیت‌سینک استاندارد سرور خودش را throttle می‌کند و کارایی‌ای که خریده‌اید را نمی‌گیرید.

هر پردازنده پیش از ارسال روی همان نسل سرور بوت و تست می‌شود.',
                    'en' => 'The Intel Xeon Gold 6248R is a Cascade Lake-SP processor for socket LGA3647, used in HPE ProLiant Gen10 servers. It has 24 physical cores and 48 threads, starts at a 3.0 GHz base clock and boosts to 4.0 GHz under light load. It carries 35.75 MB of L3 cache and a 205 W thermal design power.

The Skylake architecture at higher clocks, with DDR4-2933 support and hardware mitigation for several speculative-execution vulnerabilities. The most sensible second-hand generation for a production server today.

Two things to check before ordering. First, in a dual-socket configuration both sockets must hold the identical model — a server with two different SKUs will not post. Second, anything above 135 W needs the high-performance heatsink and fan kit; with the standard heatsink the server throttles itself and you never get the performance you paid for.

Every processor is booted and tested on the same server generation before it ships.',
                    'tr' => 'Intel Xeon Gold 6248R, LGA3647 soketi için bir Cascade Lake-SP işlemcisidir ve HPE ProLiant Gen10 sunucularında kullanılır. 24 fiziksel çekirdek ve 48 iş parçacığına sahiptir, 3.0 GHz temel hızda başlar ve hafif yükte 4.0 GHz’e çıkar. 35.75 MB L3 önbellek ve 205 W termal tasarım gücü taşır.

Daha yüksek hızlarda Skylake mimarisi, DDR4-2933 desteği ve birkaç spekülatif yürütme açığı için donanımsal önlem. Bugün üretim sunucusu için en mantıklı ikinci el nesil.

Sipariş öncesi iki nokta: Birincisi, çift soketli yapılandırmada her iki soket de aynı modeli taşımalıdır — iki farklı model ile sunucu açılmaz. İkincisi, 135 W üzerindeki her işlemci yüksek performanslı soğutucu ve fan kiti ister; standart soğutucuyla sunucu kendini kısar ve ödediğiniz performansı alamazsınız.

Her işlemci sevkiyattan önce aynı sunucu neslinde başlatılıp test edilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'خانوادهٔ پردازنده',
                            'en' => 'Processor family',
                            'tr' => 'İşlemci ailesi',
                        ],
                        'value' => [
                            'fa' => 'Cascade Lake-SP',
                            'en' => 'Cascade Lake-SP',
                            'tr' => 'Cascade Lake-SP',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سوکت',
                            'en' => 'Socket',
                            'tr' => 'Soket',
                        ],
                        'value' => [
                            'fa' => 'LGA3647',
                            'en' => 'LGA3647',
                            'tr' => 'LGA3647',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'هسته / رشته',
                            'en' => 'Cores / threads',
                            'tr' => 'Çekirdek / iş parçacığı',
                        ],
                        'value' => [
                            'fa' => '24 / 48',
                            'en' => '24 / 48',
                            'tr' => '24 / 48',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس پایه',
                            'en' => 'Base clock',
                            'tr' => 'Temel hız',
                        ],
                        'value' => [
                            'fa' => '3.0 GHz',
                            'en' => '3.0 GHz',
                            'tr' => '3.0 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس توربو',
                            'en' => 'Max turbo',
                            'tr' => 'Maks. turbo',
                        ],
                        'value' => [
                            'fa' => '4.0 GHz',
                            'en' => '4.0 GHz',
                            'tr' => '4.0 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کش L3',
                            'en' => 'L3 cache',
                            'tr' => 'L3 önbellek',
                        ],
                        'value' => [
                            'fa' => '35.75 MB',
                            'en' => '35.75 MB',
                            'tr' => '35.75 MB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'توان طراحی حرارتی',
                            'en' => 'TDP',
                            'tr' => 'TDP',
                        ],
                        'value' => [
                            'fa' => '205 W',
                            'en' => '205 W',
                            'tr' => '205 W',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen10',
                            'en' => 'Gen10',
                            'tr' => 'Gen10',
                        ],
                    ],
                ],
                'attrs' => [
                    'cores' => 24,
                    'threads' => 48,
                    'ghz' => 3.0,
                    'ghz_turbo' => 4.0,
                    'cache_mb' => 35.75,
                    'tdp' => 205,
                ],
            ],
            [
                'slug' => 'xeon-gold-6254',
                'category' => 'cpu',
                'brand' => 'Intel',
                'compat_gens' => [
                    'gen10',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 25209,
                'in_stock' => true,
                'popular' => false,
                'sort' => 250,
                'name' => [
                    'fa' => 'Intel Xeon Gold 6254',
                    'en' => 'Intel Xeon Gold 6254',
                    'tr' => 'Intel Xeon Gold 6254',
                ],
                'tagline' => [
                    'fa' => '18 هسته، 36 رشته، تا 4.0 گیگاهرتز',
                    'en' => '18 cores, 36 threads, up to 4.0 GHz',
                    'tr' => '18 çekirdek, 36 iş parçacığı, 4.0 GHz’e kadar',
                ],
                'summary' => [
                    'fa' => 'پردازندهٔ Cascade Lake-SP روی سوکت LGA3647 برای سرورهای Gen10؛ 18 هستهٔ فیزیکی، 24.75 مگابایت کش L3 و توان طراحی حرارتی 200 وات.',
                    'en' => 'Cascade Lake-SP processor on socket LGA3647 for Gen10 servers: 18 physical cores, 24.75 MB of L3 cache and a 200 W thermal design power.',
                    'tr' => 'Gen10 sunucular için LGA3647 soketinde Cascade Lake-SP işlemci: 18 fiziksel çekirdek, 24.75 MB L3 önbellek ve 200 W termal tasarım gücü.',
                ],
                'body' => [
                    'fa' => 'Intel Xeon Gold 6254 پردازنده‌ای از خانوادهٔ Cascade Lake-SP است که روی سوکت LGA3647 می‌نشیند و در سرورهای HPE ProLiant Gen10 استفاده می‌شود. 18 هستهٔ فیزیکی و 36 رشتهٔ پردازشی دارد، از فرکانس پایهٔ 3.1 گیگاهرتز شروع می‌کند و در بار سبک تا 4.0 گیگاهرتز بالا می‌رود. 24.75 مگابایت کش L3 و توان طراحی حرارتی 200 وات.

همان معماری Skylake با فرکانس بالاتر، پشتیبانی از DDR4-2933 و اصلاح سخت‌افزاری چند آسیب‌پذیری اجرای گمانه‌زنانه. برای سرور تولیدی امروز، منطقی‌ترین نسل دست دوم.

پیش از سفارش دو نکته را چک کنید: اول اینکه در پیکربندی دو پردازنده‌ای، هر دو سوکت باید مدل یکسان داشته باشند — سرور با دو مدل متفاوت بالا نمی‌آید. دوم اینکه برای توان بالای ۱۳۵ وات، هیت‌سینک پرکارایی و کیت فن لازم است؛ با هیت‌سینک استاندارد سرور خودش را throttle می‌کند و کارایی‌ای که خریده‌اید را نمی‌گیرید.

هر پردازنده پیش از ارسال روی همان نسل سرور بوت و تست می‌شود.',
                    'en' => 'The Intel Xeon Gold 6254 is a Cascade Lake-SP processor for socket LGA3647, used in HPE ProLiant Gen10 servers. It has 18 physical cores and 36 threads, starts at a 3.1 GHz base clock and boosts to 4.0 GHz under light load. It carries 24.75 MB of L3 cache and a 200 W thermal design power.

The Skylake architecture at higher clocks, with DDR4-2933 support and hardware mitigation for several speculative-execution vulnerabilities. The most sensible second-hand generation for a production server today.

Two things to check before ordering. First, in a dual-socket configuration both sockets must hold the identical model — a server with two different SKUs will not post. Second, anything above 135 W needs the high-performance heatsink and fan kit; with the standard heatsink the server throttles itself and you never get the performance you paid for.

Every processor is booted and tested on the same server generation before it ships.',
                    'tr' => 'Intel Xeon Gold 6254, LGA3647 soketi için bir Cascade Lake-SP işlemcisidir ve HPE ProLiant Gen10 sunucularında kullanılır. 18 fiziksel çekirdek ve 36 iş parçacığına sahiptir, 3.1 GHz temel hızda başlar ve hafif yükte 4.0 GHz’e çıkar. 24.75 MB L3 önbellek ve 200 W termal tasarım gücü taşır.

Daha yüksek hızlarda Skylake mimarisi, DDR4-2933 desteği ve birkaç spekülatif yürütme açığı için donanımsal önlem. Bugün üretim sunucusu için en mantıklı ikinci el nesil.

Sipariş öncesi iki nokta: Birincisi, çift soketli yapılandırmada her iki soket de aynı modeli taşımalıdır — iki farklı model ile sunucu açılmaz. İkincisi, 135 W üzerindeki her işlemci yüksek performanslı soğutucu ve fan kiti ister; standart soğutucuyla sunucu kendini kısar ve ödediğiniz performansı alamazsınız.

Her işlemci sevkiyattan önce aynı sunucu neslinde başlatılıp test edilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'خانوادهٔ پردازنده',
                            'en' => 'Processor family',
                            'tr' => 'İşlemci ailesi',
                        ],
                        'value' => [
                            'fa' => 'Cascade Lake-SP',
                            'en' => 'Cascade Lake-SP',
                            'tr' => 'Cascade Lake-SP',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سوکت',
                            'en' => 'Socket',
                            'tr' => 'Soket',
                        ],
                        'value' => [
                            'fa' => 'LGA3647',
                            'en' => 'LGA3647',
                            'tr' => 'LGA3647',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'هسته / رشته',
                            'en' => 'Cores / threads',
                            'tr' => 'Çekirdek / iş parçacığı',
                        ],
                        'value' => [
                            'fa' => '18 / 36',
                            'en' => '18 / 36',
                            'tr' => '18 / 36',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس پایه',
                            'en' => 'Base clock',
                            'tr' => 'Temel hız',
                        ],
                        'value' => [
                            'fa' => '3.1 GHz',
                            'en' => '3.1 GHz',
                            'tr' => '3.1 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس توربو',
                            'en' => 'Max turbo',
                            'tr' => 'Maks. turbo',
                        ],
                        'value' => [
                            'fa' => '4.0 GHz',
                            'en' => '4.0 GHz',
                            'tr' => '4.0 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کش L3',
                            'en' => 'L3 cache',
                            'tr' => 'L3 önbellek',
                        ],
                        'value' => [
                            'fa' => '24.75 MB',
                            'en' => '24.75 MB',
                            'tr' => '24.75 MB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'توان طراحی حرارتی',
                            'en' => 'TDP',
                            'tr' => 'TDP',
                        ],
                        'value' => [
                            'fa' => '200 W',
                            'en' => '200 W',
                            'tr' => '200 W',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen10',
                            'en' => 'Gen10',
                            'tr' => 'Gen10',
                        ],
                    ],
                ],
                'attrs' => [
                    'cores' => 18,
                    'threads' => 36,
                    'ghz' => 3.1,
                    'ghz_turbo' => 4.0,
                    'cache_mb' => 24.75,
                    'tdp' => 200,
                ],
            ],
            [
                'slug' => 'xeon-platinum-8168',
                'category' => 'cpu',
                'brand' => 'Intel',
                'compat_gens' => [
                    'gen10',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 8403,
                'in_stock' => true,
                'popular' => false,
                'sort' => 260,
                'name' => [
                    'fa' => 'Intel Xeon Platinum 8168',
                    'en' => 'Intel Xeon Platinum 8168',
                    'tr' => 'Intel Xeon Platinum 8168',
                ],
                'tagline' => [
                    'fa' => '24 هسته، 48 رشته، تا 3.7 گیگاهرتز',
                    'en' => '24 cores, 48 threads, up to 3.7 GHz',
                    'tr' => '24 çekirdek, 48 iş parçacığı, 3.7 GHz’e kadar',
                ],
                'summary' => [
                    'fa' => 'پردازندهٔ Skylake-SP روی سوکت LGA3647 برای سرورهای Gen10؛ 24 هستهٔ فیزیکی، 33 مگابایت کش L3 و توان طراحی حرارتی 205 وات.',
                    'en' => 'Skylake-SP processor on socket LGA3647 for Gen10 servers: 24 physical cores, 33 MB of L3 cache and a 205 W thermal design power.',
                    'tr' => 'Gen10 sunucular için LGA3647 soketinde Skylake-SP işlemci: 24 fiziksel çekirdek, 33 MB L3 önbellek ve 205 W termal tasarım gücü.',
                ],
                'body' => [
                    'fa' => 'Intel Xeon Platinum 8168 پردازنده‌ای از خانوادهٔ Skylake-SP است که روی سوکت LGA3647 می‌نشیند و در سرورهای HPE ProLiant Gen10 استفاده می‌شود. 24 هستهٔ فیزیکی و 48 رشتهٔ پردازشی دارد، از فرکانس پایهٔ 2.7 گیگاهرتز شروع می‌کند و در بار سبک تا 3.7 گیگاهرتز بالا می‌رود. 33 مگابایت کش L3 و توان طراحی حرارتی 205 وات.

شش کانال حافظه و AVX-512 را می‌آورد. برای بارهای محاسباتی و پایگاه دادهٔ درون‌حافظه‌ای تفاوت محسوسی با نسل قبل دارد.

پیش از سفارش دو نکته را چک کنید: اول اینکه در پیکربندی دو پردازنده‌ای، هر دو سوکت باید مدل یکسان داشته باشند — سرور با دو مدل متفاوت بالا نمی‌آید. دوم اینکه برای توان بالای ۱۳۵ وات، هیت‌سینک پرکارایی و کیت فن لازم است؛ با هیت‌سینک استاندارد سرور خودش را throttle می‌کند و کارایی‌ای که خریده‌اید را نمی‌گیرید.

هر پردازنده پیش از ارسال روی همان نسل سرور بوت و تست می‌شود.',
                    'en' => 'The Intel Xeon Platinum 8168 is a Skylake-SP processor for socket LGA3647, used in HPE ProLiant Gen10 servers. It has 24 physical cores and 48 threads, starts at a 2.7 GHz base clock and boosts to 3.7 GHz under light load. It carries 33 MB of L3 cache and a 205 W thermal design power.

Brings six memory channels and AVX-512. A noticeable step up from the previous generation for compute-heavy work and in-memory databases.

Two things to check before ordering. First, in a dual-socket configuration both sockets must hold the identical model — a server with two different SKUs will not post. Second, anything above 135 W needs the high-performance heatsink and fan kit; with the standard heatsink the server throttles itself and you never get the performance you paid for.

Every processor is booted and tested on the same server generation before it ships.',
                    'tr' => 'Intel Xeon Platinum 8168, LGA3647 soketi için bir Skylake-SP işlemcisidir ve HPE ProLiant Gen10 sunucularında kullanılır. 24 fiziksel çekirdek ve 48 iş parçacığına sahiptir, 2.7 GHz temel hızda başlar ve hafif yükte 3.7 GHz’e çıkar. 33 MB L3 önbellek ve 205 W termal tasarım gücü taşır.

Altı bellek kanalı ve AVX-512 getirir. Hesaplama yoğun işler ve bellek içi veritabanları için önceki nesle göre belirgin bir sıçrama.

Sipariş öncesi iki nokta: Birincisi, çift soketli yapılandırmada her iki soket de aynı modeli taşımalıdır — iki farklı model ile sunucu açılmaz. İkincisi, 135 W üzerindeki her işlemci yüksek performanslı soğutucu ve fan kiti ister; standart soğutucuyla sunucu kendini kısar ve ödediğiniz performansı alamazsınız.

Her işlemci sevkiyattan önce aynı sunucu neslinde başlatılıp test edilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'خانوادهٔ پردازنده',
                            'en' => 'Processor family',
                            'tr' => 'İşlemci ailesi',
                        ],
                        'value' => [
                            'fa' => 'Skylake-SP',
                            'en' => 'Skylake-SP',
                            'tr' => 'Skylake-SP',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سوکت',
                            'en' => 'Socket',
                            'tr' => 'Soket',
                        ],
                        'value' => [
                            'fa' => 'LGA3647',
                            'en' => 'LGA3647',
                            'tr' => 'LGA3647',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'هسته / رشته',
                            'en' => 'Cores / threads',
                            'tr' => 'Çekirdek / iş parçacığı',
                        ],
                        'value' => [
                            'fa' => '24 / 48',
                            'en' => '24 / 48',
                            'tr' => '24 / 48',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس پایه',
                            'en' => 'Base clock',
                            'tr' => 'Temel hız',
                        ],
                        'value' => [
                            'fa' => '2.7 GHz',
                            'en' => '2.7 GHz',
                            'tr' => '2.7 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس توربو',
                            'en' => 'Max turbo',
                            'tr' => 'Maks. turbo',
                        ],
                        'value' => [
                            'fa' => '3.7 GHz',
                            'en' => '3.7 GHz',
                            'tr' => '3.7 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کش L3',
                            'en' => 'L3 cache',
                            'tr' => 'L3 önbellek',
                        ],
                        'value' => [
                            'fa' => '33 MB',
                            'en' => '33 MB',
                            'tr' => '33 MB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'توان طراحی حرارتی',
                            'en' => 'TDP',
                            'tr' => 'TDP',
                        ],
                        'value' => [
                            'fa' => '205 W',
                            'en' => '205 W',
                            'tr' => '205 W',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen10',
                            'en' => 'Gen10',
                            'tr' => 'Gen10',
                        ],
                    ],
                ],
                'attrs' => [
                    'cores' => 24,
                    'threads' => 48,
                    'ghz' => 2.7,
                    'ghz_turbo' => 3.7,
                    'cache_mb' => 33,
                    'tdp' => 205,
                ],
            ],
            [
                'slug' => 'xeon-platinum-8176',
                'category' => 'cpu',
                'brand' => 'Intel',
                'compat_gens' => [
                    'gen10',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 12604,
                'in_stock' => true,
                'popular' => false,
                'sort' => 270,
                'name' => [
                    'fa' => 'Intel Xeon Platinum 8176',
                    'en' => 'Intel Xeon Platinum 8176',
                    'tr' => 'Intel Xeon Platinum 8176',
                ],
                'tagline' => [
                    'fa' => '28 هسته، 56 رشته، تا 3.8 گیگاهرتز',
                    'en' => '28 cores, 56 threads, up to 3.8 GHz',
                    'tr' => '28 çekirdek, 56 iş parçacığı, 3.8 GHz’e kadar',
                ],
                'summary' => [
                    'fa' => 'پردازندهٔ Skylake-SP روی سوکت LGA3647 برای سرورهای Gen10؛ 28 هستهٔ فیزیکی، 38.5 مگابایت کش L3 و توان طراحی حرارتی 165 وات.',
                    'en' => 'Skylake-SP processor on socket LGA3647 for Gen10 servers: 28 physical cores, 38.5 MB of L3 cache and a 165 W thermal design power.',
                    'tr' => 'Gen10 sunucular için LGA3647 soketinde Skylake-SP işlemci: 28 fiziksel çekirdek, 38.5 MB L3 önbellek ve 165 W termal tasarım gücü.',
                ],
                'body' => [
                    'fa' => 'Intel Xeon Platinum 8176 پردازنده‌ای از خانوادهٔ Skylake-SP است که روی سوکت LGA3647 می‌نشیند و در سرورهای HPE ProLiant Gen10 استفاده می‌شود. 28 هستهٔ فیزیکی و 56 رشتهٔ پردازشی دارد، از فرکانس پایهٔ 2.1 گیگاهرتز شروع می‌کند و در بار سبک تا 3.8 گیگاهرتز بالا می‌رود. 38.5 مگابایت کش L3 و توان طراحی حرارتی 165 وات.

شش کانال حافظه و AVX-512 را می‌آورد. برای بارهای محاسباتی و پایگاه دادهٔ درون‌حافظه‌ای تفاوت محسوسی با نسل قبل دارد.

پیش از سفارش دو نکته را چک کنید: اول اینکه در پیکربندی دو پردازنده‌ای، هر دو سوکت باید مدل یکسان داشته باشند — سرور با دو مدل متفاوت بالا نمی‌آید. دوم اینکه برای توان بالای ۱۳۵ وات، هیت‌سینک پرکارایی و کیت فن لازم است؛ با هیت‌سینک استاندارد سرور خودش را throttle می‌کند و کارایی‌ای که خریده‌اید را نمی‌گیرید.

هر پردازنده پیش از ارسال روی همان نسل سرور بوت و تست می‌شود.',
                    'en' => 'The Intel Xeon Platinum 8176 is a Skylake-SP processor for socket LGA3647, used in HPE ProLiant Gen10 servers. It has 28 physical cores and 56 threads, starts at a 2.1 GHz base clock and boosts to 3.8 GHz under light load. It carries 38.5 MB of L3 cache and a 165 W thermal design power.

Brings six memory channels and AVX-512. A noticeable step up from the previous generation for compute-heavy work and in-memory databases.

Two things to check before ordering. First, in a dual-socket configuration both sockets must hold the identical model — a server with two different SKUs will not post. Second, anything above 135 W needs the high-performance heatsink and fan kit; with the standard heatsink the server throttles itself and you never get the performance you paid for.

Every processor is booted and tested on the same server generation before it ships.',
                    'tr' => 'Intel Xeon Platinum 8176, LGA3647 soketi için bir Skylake-SP işlemcisidir ve HPE ProLiant Gen10 sunucularında kullanılır. 28 fiziksel çekirdek ve 56 iş parçacığına sahiptir, 2.1 GHz temel hızda başlar ve hafif yükte 3.8 GHz’e çıkar. 38.5 MB L3 önbellek ve 165 W termal tasarım gücü taşır.

Altı bellek kanalı ve AVX-512 getirir. Hesaplama yoğun işler ve bellek içi veritabanları için önceki nesle göre belirgin bir sıçrama.

Sipariş öncesi iki nokta: Birincisi, çift soketli yapılandırmada her iki soket de aynı modeli taşımalıdır — iki farklı model ile sunucu açılmaz. İkincisi, 135 W üzerindeki her işlemci yüksek performanslı soğutucu ve fan kiti ister; standart soğutucuyla sunucu kendini kısar ve ödediğiniz performansı alamazsınız.

Her işlemci sevkiyattan önce aynı sunucu neslinde başlatılıp test edilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'خانوادهٔ پردازنده',
                            'en' => 'Processor family',
                            'tr' => 'İşlemci ailesi',
                        ],
                        'value' => [
                            'fa' => 'Skylake-SP',
                            'en' => 'Skylake-SP',
                            'tr' => 'Skylake-SP',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سوکت',
                            'en' => 'Socket',
                            'tr' => 'Soket',
                        ],
                        'value' => [
                            'fa' => 'LGA3647',
                            'en' => 'LGA3647',
                            'tr' => 'LGA3647',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'هسته / رشته',
                            'en' => 'Cores / threads',
                            'tr' => 'Çekirdek / iş parçacığı',
                        ],
                        'value' => [
                            'fa' => '28 / 56',
                            'en' => '28 / 56',
                            'tr' => '28 / 56',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس پایه',
                            'en' => 'Base clock',
                            'tr' => 'Temel hız',
                        ],
                        'value' => [
                            'fa' => '2.1 GHz',
                            'en' => '2.1 GHz',
                            'tr' => '2.1 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس توربو',
                            'en' => 'Max turbo',
                            'tr' => 'Maks. turbo',
                        ],
                        'value' => [
                            'fa' => '3.8 GHz',
                            'en' => '3.8 GHz',
                            'tr' => '3.8 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کش L3',
                            'en' => 'L3 cache',
                            'tr' => 'L3 önbellek',
                        ],
                        'value' => [
                            'fa' => '38.5 MB',
                            'en' => '38.5 MB',
                            'tr' => '38.5 MB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'توان طراحی حرارتی',
                            'en' => 'TDP',
                            'tr' => 'TDP',
                        ],
                        'value' => [
                            'fa' => '165 W',
                            'en' => '165 W',
                            'tr' => '165 W',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen10',
                            'en' => 'Gen10',
                            'tr' => 'Gen10',
                        ],
                    ],
                ],
                'attrs' => [
                    'cores' => 28,
                    'threads' => 56,
                    'ghz' => 2.1,
                    'ghz_turbo' => 3.8,
                    'cache_mb' => 38.5,
                    'tdp' => 165,
                ],
            ],
            [
                'slug' => 'xeon-platinum-8268',
                'category' => 'cpu',
                'brand' => 'Intel',
                'compat_gens' => [
                    'gen10',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 29411,
                'in_stock' => true,
                'popular' => false,
                'sort' => 280,
                'name' => [
                    'fa' => 'Intel Xeon Platinum 8268',
                    'en' => 'Intel Xeon Platinum 8268',
                    'tr' => 'Intel Xeon Platinum 8268',
                ],
                'tagline' => [
                    'fa' => '24 هسته، 48 رشته، تا 3.9 گیگاهرتز',
                    'en' => '24 cores, 48 threads, up to 3.9 GHz',
                    'tr' => '24 çekirdek, 48 iş parçacığı, 3.9 GHz’e kadar',
                ],
                'summary' => [
                    'fa' => 'پردازندهٔ Cascade Lake-SP روی سوکت LGA3647 برای سرورهای Gen10؛ 24 هستهٔ فیزیکی، 35.75 مگابایت کش L3 و توان طراحی حرارتی 205 وات.',
                    'en' => 'Cascade Lake-SP processor on socket LGA3647 for Gen10 servers: 24 physical cores, 35.75 MB of L3 cache and a 205 W thermal design power.',
                    'tr' => 'Gen10 sunucular için LGA3647 soketinde Cascade Lake-SP işlemci: 24 fiziksel çekirdek, 35.75 MB L3 önbellek ve 205 W termal tasarım gücü.',
                ],
                'body' => [
                    'fa' => 'Intel Xeon Platinum 8268 پردازنده‌ای از خانوادهٔ Cascade Lake-SP است که روی سوکت LGA3647 می‌نشیند و در سرورهای HPE ProLiant Gen10 استفاده می‌شود. 24 هستهٔ فیزیکی و 48 رشتهٔ پردازشی دارد، از فرکانس پایهٔ 2.9 گیگاهرتز شروع می‌کند و در بار سبک تا 3.9 گیگاهرتز بالا می‌رود. 35.75 مگابایت کش L3 و توان طراحی حرارتی 205 وات.

همان معماری Skylake با فرکانس بالاتر، پشتیبانی از DDR4-2933 و اصلاح سخت‌افزاری چند آسیب‌پذیری اجرای گمانه‌زنانه. برای سرور تولیدی امروز، منطقی‌ترین نسل دست دوم.

پیش از سفارش دو نکته را چک کنید: اول اینکه در پیکربندی دو پردازنده‌ای، هر دو سوکت باید مدل یکسان داشته باشند — سرور با دو مدل متفاوت بالا نمی‌آید. دوم اینکه برای توان بالای ۱۳۵ وات، هیت‌سینک پرکارایی و کیت فن لازم است؛ با هیت‌سینک استاندارد سرور خودش را throttle می‌کند و کارایی‌ای که خریده‌اید را نمی‌گیرید.

هر پردازنده پیش از ارسال روی همان نسل سرور بوت و تست می‌شود.',
                    'en' => 'The Intel Xeon Platinum 8268 is a Cascade Lake-SP processor for socket LGA3647, used in HPE ProLiant Gen10 servers. It has 24 physical cores and 48 threads, starts at a 2.9 GHz base clock and boosts to 3.9 GHz under light load. It carries 35.75 MB of L3 cache and a 205 W thermal design power.

The Skylake architecture at higher clocks, with DDR4-2933 support and hardware mitigation for several speculative-execution vulnerabilities. The most sensible second-hand generation for a production server today.

Two things to check before ordering. First, in a dual-socket configuration both sockets must hold the identical model — a server with two different SKUs will not post. Second, anything above 135 W needs the high-performance heatsink and fan kit; with the standard heatsink the server throttles itself and you never get the performance you paid for.

Every processor is booted and tested on the same server generation before it ships.',
                    'tr' => 'Intel Xeon Platinum 8268, LGA3647 soketi için bir Cascade Lake-SP işlemcisidir ve HPE ProLiant Gen10 sunucularında kullanılır. 24 fiziksel çekirdek ve 48 iş parçacığına sahiptir, 2.9 GHz temel hızda başlar ve hafif yükte 3.9 GHz’e çıkar. 35.75 MB L3 önbellek ve 205 W termal tasarım gücü taşır.

Daha yüksek hızlarda Skylake mimarisi, DDR4-2933 desteği ve birkaç spekülatif yürütme açığı için donanımsal önlem. Bugün üretim sunucusu için en mantıklı ikinci el nesil.

Sipariş öncesi iki nokta: Birincisi, çift soketli yapılandırmada her iki soket de aynı modeli taşımalıdır — iki farklı model ile sunucu açılmaz. İkincisi, 135 W üzerindeki her işlemci yüksek performanslı soğutucu ve fan kiti ister; standart soğutucuyla sunucu kendini kısar ve ödediğiniz performansı alamazsınız.

Her işlemci sevkiyattan önce aynı sunucu neslinde başlatılıp test edilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'خانوادهٔ پردازنده',
                            'en' => 'Processor family',
                            'tr' => 'İşlemci ailesi',
                        ],
                        'value' => [
                            'fa' => 'Cascade Lake-SP',
                            'en' => 'Cascade Lake-SP',
                            'tr' => 'Cascade Lake-SP',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سوکت',
                            'en' => 'Socket',
                            'tr' => 'Soket',
                        ],
                        'value' => [
                            'fa' => 'LGA3647',
                            'en' => 'LGA3647',
                            'tr' => 'LGA3647',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'هسته / رشته',
                            'en' => 'Cores / threads',
                            'tr' => 'Çekirdek / iş parçacığı',
                        ],
                        'value' => [
                            'fa' => '24 / 48',
                            'en' => '24 / 48',
                            'tr' => '24 / 48',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس پایه',
                            'en' => 'Base clock',
                            'tr' => 'Temel hız',
                        ],
                        'value' => [
                            'fa' => '2.9 GHz',
                            'en' => '2.9 GHz',
                            'tr' => '2.9 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس توربو',
                            'en' => 'Max turbo',
                            'tr' => 'Maks. turbo',
                        ],
                        'value' => [
                            'fa' => '3.9 GHz',
                            'en' => '3.9 GHz',
                            'tr' => '3.9 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کش L3',
                            'en' => 'L3 cache',
                            'tr' => 'L3 önbellek',
                        ],
                        'value' => [
                            'fa' => '35.75 MB',
                            'en' => '35.75 MB',
                            'tr' => '35.75 MB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'توان طراحی حرارتی',
                            'en' => 'TDP',
                            'tr' => 'TDP',
                        ],
                        'value' => [
                            'fa' => '205 W',
                            'en' => '205 W',
                            'tr' => '205 W',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen10',
                            'en' => 'Gen10',
                            'tr' => 'Gen10',
                        ],
                    ],
                ],
                'attrs' => [
                    'cores' => 24,
                    'threads' => 48,
                    'ghz' => 2.9,
                    'ghz_turbo' => 3.9,
                    'cache_mb' => 35.75,
                    'tdp' => 205,
                ],
            ],
            [
                'slug' => 'xeon-silver-4410y',
                'category' => 'cpu',
                'brand' => 'Intel',
                'compat_gens' => [
                    'gen11',
                ],
                'condition' => 'refurb',
                'price_contact' => true,
                'price_eur' => null,
                'in_stock' => true,
                'popular' => false,
                'sort' => 290,
                'name' => [
                    'fa' => 'Intel Xeon Silver 4410Y',
                    'en' => 'Intel Xeon Silver 4410Y',
                    'tr' => 'Intel Xeon Silver 4410Y',
                ],
                'tagline' => [
                    'fa' => '12 هسته، 24 رشته، تا 3.9 گیگاهرتز',
                    'en' => '12 cores, 24 threads, up to 3.9 GHz',
                    'tr' => '12 çekirdek, 24 iş parçacığı, 3.9 GHz’e kadar',
                ],
                'summary' => [
                    'fa' => 'پردازندهٔ Sapphire Rapids روی سوکت LGA4677 برای سرورهای Gen11؛ 12 هستهٔ فیزیکی، 30 مگابایت کش L3 و توان طراحی حرارتی 150 وات.',
                    'en' => 'Sapphire Rapids processor on socket LGA4677 for Gen11 servers: 12 physical cores, 30 MB of L3 cache and a 150 W thermal design power.',
                    'tr' => 'Gen11 sunucular için LGA4677 soketinde Sapphire Rapids işlemci: 12 fiziksel çekirdek, 30 MB L3 önbellek ve 150 W termal tasarım gücü.',
                ],
                'body' => [
                    'fa' => 'Intel Xeon Silver 4410Y پردازنده‌ای از خانوادهٔ Sapphire Rapids است که روی سوکت LGA4677 می‌نشیند و در سرورهای HPE ProLiant Gen11 استفاده می‌شود. 12 هستهٔ فیزیکی و 24 رشتهٔ پردازشی دارد، از فرکانس پایهٔ 2.0 گیگاهرتز شروع می‌کند و در بار سبک تا 3.9 گیگاهرتز بالا می‌رود. 30 مگابایت کش L3 و توان طراحی حرارتی 150 وات.

DDR5، PCIe 5.0 و شتاب‌دهنده‌های درون‌تراشه‌ای (AMX برای هوش مصنوعی، QAT برای رمزنگاری و فشرده‌سازی). برای بار جدید و سرور نو.

پیش از سفارش دو نکته را چک کنید: اول اینکه در پیکربندی دو پردازنده‌ای، هر دو سوکت باید مدل یکسان داشته باشند — سرور با دو مدل متفاوت بالا نمی‌آید. دوم اینکه برای توان بالای ۱۳۵ وات، هیت‌سینک پرکارایی و کیت فن لازم است؛ با هیت‌سینک استاندارد سرور خودش را throttle می‌کند و کارایی‌ای که خریده‌اید را نمی‌گیرید.

هر پردازنده پیش از ارسال روی همان نسل سرور بوت و تست می‌شود.',
                    'en' => 'The Intel Xeon Silver 4410Y is a Sapphire Rapids processor for socket LGA4677, used in HPE ProLiant Gen11 servers. It has 12 physical cores and 24 threads, starts at a 2.0 GHz base clock and boosts to 3.9 GHz under light load. It carries 30 MB of L3 cache and a 150 W thermal design power.

DDR5, PCIe 5.0 and on-die accelerators (AMX for AI, QAT for crypto and compression). For new workloads on new hardware.

Two things to check before ordering. First, in a dual-socket configuration both sockets must hold the identical model — a server with two different SKUs will not post. Second, anything above 135 W needs the high-performance heatsink and fan kit; with the standard heatsink the server throttles itself and you never get the performance you paid for.

Every processor is booted and tested on the same server generation before it ships.',
                    'tr' => 'Intel Xeon Silver 4410Y, LGA4677 soketi için bir Sapphire Rapids işlemcisidir ve HPE ProLiant Gen11 sunucularında kullanılır. 12 fiziksel çekirdek ve 24 iş parçacığına sahiptir, 2.0 GHz temel hızda başlar ve hafif yükte 3.9 GHz’e çıkar. 30 MB L3 önbellek ve 150 W termal tasarım gücü taşır.

DDR5, PCIe 5.0 ve yonga üstü hızlandırıcılar (yapay zekâ için AMX, şifreleme ve sıkıştırma için QAT). Yeni donanımda yeni iş yükleri için.

Sipariş öncesi iki nokta: Birincisi, çift soketli yapılandırmada her iki soket de aynı modeli taşımalıdır — iki farklı model ile sunucu açılmaz. İkincisi, 135 W üzerindeki her işlemci yüksek performanslı soğutucu ve fan kiti ister; standart soğutucuyla sunucu kendini kısar ve ödediğiniz performansı alamazsınız.

Her işlemci sevkiyattan önce aynı sunucu neslinde başlatılıp test edilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'خانوادهٔ پردازنده',
                            'en' => 'Processor family',
                            'tr' => 'İşlemci ailesi',
                        ],
                        'value' => [
                            'fa' => 'Sapphire Rapids',
                            'en' => 'Sapphire Rapids',
                            'tr' => 'Sapphire Rapids',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سوکت',
                            'en' => 'Socket',
                            'tr' => 'Soket',
                        ],
                        'value' => [
                            'fa' => 'LGA4677',
                            'en' => 'LGA4677',
                            'tr' => 'LGA4677',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'هسته / رشته',
                            'en' => 'Cores / threads',
                            'tr' => 'Çekirdek / iş parçacığı',
                        ],
                        'value' => [
                            'fa' => '12 / 24',
                            'en' => '12 / 24',
                            'tr' => '12 / 24',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس پایه',
                            'en' => 'Base clock',
                            'tr' => 'Temel hız',
                        ],
                        'value' => [
                            'fa' => '2.0 GHz',
                            'en' => '2.0 GHz',
                            'tr' => '2.0 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس توربو',
                            'en' => 'Max turbo',
                            'tr' => 'Maks. turbo',
                        ],
                        'value' => [
                            'fa' => '3.9 GHz',
                            'en' => '3.9 GHz',
                            'tr' => '3.9 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کش L3',
                            'en' => 'L3 cache',
                            'tr' => 'L3 önbellek',
                        ],
                        'value' => [
                            'fa' => '30 MB',
                            'en' => '30 MB',
                            'tr' => '30 MB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'توان طراحی حرارتی',
                            'en' => 'TDP',
                            'tr' => 'TDP',
                        ],
                        'value' => [
                            'fa' => '150 W',
                            'en' => '150 W',
                            'tr' => '150 W',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen11',
                            'en' => 'Gen11',
                            'tr' => 'Gen11',
                        ],
                    ],
                ],
                'attrs' => [
                    'cores' => 12,
                    'threads' => 24,
                    'ghz' => 2.0,
                    'ghz_turbo' => 3.9,
                    'cache_mb' => 30,
                    'tdp' => 150,
                ],
            ],
            [
                'slug' => 'xeon-gold-5416s',
                'category' => 'cpu',
                'brand' => 'Intel',
                'compat_gens' => [
                    'gen11',
                ],
                'condition' => 'refurb',
                'price_contact' => true,
                'price_eur' => null,
                'in_stock' => true,
                'popular' => false,
                'sort' => 300,
                'name' => [
                    'fa' => 'Intel Xeon Gold 5416S',
                    'en' => 'Intel Xeon Gold 5416S',
                    'tr' => 'Intel Xeon Gold 5416S',
                ],
                'tagline' => [
                    'fa' => '16 هسته، 32 رشته، تا 4.0 گیگاهرتز',
                    'en' => '16 cores, 32 threads, up to 4.0 GHz',
                    'tr' => '16 çekirdek, 32 iş parçacığı, 4.0 GHz’e kadar',
                ],
                'summary' => [
                    'fa' => 'پردازندهٔ Sapphire Rapids روی سوکت LGA4677 برای سرورهای Gen11؛ 16 هستهٔ فیزیکی، 30 مگابایت کش L3 و توان طراحی حرارتی 150 وات.',
                    'en' => 'Sapphire Rapids processor on socket LGA4677 for Gen11 servers: 16 physical cores, 30 MB of L3 cache and a 150 W thermal design power.',
                    'tr' => 'Gen11 sunucular için LGA4677 soketinde Sapphire Rapids işlemci: 16 fiziksel çekirdek, 30 MB L3 önbellek ve 150 W termal tasarım gücü.',
                ],
                'body' => [
                    'fa' => 'Intel Xeon Gold 5416S پردازنده‌ای از خانوادهٔ Sapphire Rapids است که روی سوکت LGA4677 می‌نشیند و در سرورهای HPE ProLiant Gen11 استفاده می‌شود. 16 هستهٔ فیزیکی و 32 رشتهٔ پردازشی دارد، از فرکانس پایهٔ 2.0 گیگاهرتز شروع می‌کند و در بار سبک تا 4.0 گیگاهرتز بالا می‌رود. 30 مگابایت کش L3 و توان طراحی حرارتی 150 وات.

DDR5، PCIe 5.0 و شتاب‌دهنده‌های درون‌تراشه‌ای (AMX برای هوش مصنوعی، QAT برای رمزنگاری و فشرده‌سازی). برای بار جدید و سرور نو.

پیش از سفارش دو نکته را چک کنید: اول اینکه در پیکربندی دو پردازنده‌ای، هر دو سوکت باید مدل یکسان داشته باشند — سرور با دو مدل متفاوت بالا نمی‌آید. دوم اینکه برای توان بالای ۱۳۵ وات، هیت‌سینک پرکارایی و کیت فن لازم است؛ با هیت‌سینک استاندارد سرور خودش را throttle می‌کند و کارایی‌ای که خریده‌اید را نمی‌گیرید.

هر پردازنده پیش از ارسال روی همان نسل سرور بوت و تست می‌شود.',
                    'en' => 'The Intel Xeon Gold 5416S is a Sapphire Rapids processor for socket LGA4677, used in HPE ProLiant Gen11 servers. It has 16 physical cores and 32 threads, starts at a 2.0 GHz base clock and boosts to 4.0 GHz under light load. It carries 30 MB of L3 cache and a 150 W thermal design power.

DDR5, PCIe 5.0 and on-die accelerators (AMX for AI, QAT for crypto and compression). For new workloads on new hardware.

Two things to check before ordering. First, in a dual-socket configuration both sockets must hold the identical model — a server with two different SKUs will not post. Second, anything above 135 W needs the high-performance heatsink and fan kit; with the standard heatsink the server throttles itself and you never get the performance you paid for.

Every processor is booted and tested on the same server generation before it ships.',
                    'tr' => 'Intel Xeon Gold 5416S, LGA4677 soketi için bir Sapphire Rapids işlemcisidir ve HPE ProLiant Gen11 sunucularında kullanılır. 16 fiziksel çekirdek ve 32 iş parçacığına sahiptir, 2.0 GHz temel hızda başlar ve hafif yükte 4.0 GHz’e çıkar. 30 MB L3 önbellek ve 150 W termal tasarım gücü taşır.

DDR5, PCIe 5.0 ve yonga üstü hızlandırıcılar (yapay zekâ için AMX, şifreleme ve sıkıştırma için QAT). Yeni donanımda yeni iş yükleri için.

Sipariş öncesi iki nokta: Birincisi, çift soketli yapılandırmada her iki soket de aynı modeli taşımalıdır — iki farklı model ile sunucu açılmaz. İkincisi, 135 W üzerindeki her işlemci yüksek performanslı soğutucu ve fan kiti ister; standart soğutucuyla sunucu kendini kısar ve ödediğiniz performansı alamazsınız.

Her işlemci sevkiyattan önce aynı sunucu neslinde başlatılıp test edilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'خانوادهٔ پردازنده',
                            'en' => 'Processor family',
                            'tr' => 'İşlemci ailesi',
                        ],
                        'value' => [
                            'fa' => 'Sapphire Rapids',
                            'en' => 'Sapphire Rapids',
                            'tr' => 'Sapphire Rapids',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سوکت',
                            'en' => 'Socket',
                            'tr' => 'Soket',
                        ],
                        'value' => [
                            'fa' => 'LGA4677',
                            'en' => 'LGA4677',
                            'tr' => 'LGA4677',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'هسته / رشته',
                            'en' => 'Cores / threads',
                            'tr' => 'Çekirdek / iş parçacığı',
                        ],
                        'value' => [
                            'fa' => '16 / 32',
                            'en' => '16 / 32',
                            'tr' => '16 / 32',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس پایه',
                            'en' => 'Base clock',
                            'tr' => 'Temel hız',
                        ],
                        'value' => [
                            'fa' => '2.0 GHz',
                            'en' => '2.0 GHz',
                            'tr' => '2.0 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس توربو',
                            'en' => 'Max turbo',
                            'tr' => 'Maks. turbo',
                        ],
                        'value' => [
                            'fa' => '4.0 GHz',
                            'en' => '4.0 GHz',
                            'tr' => '4.0 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کش L3',
                            'en' => 'L3 cache',
                            'tr' => 'L3 önbellek',
                        ],
                        'value' => [
                            'fa' => '30 MB',
                            'en' => '30 MB',
                            'tr' => '30 MB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'توان طراحی حرارتی',
                            'en' => 'TDP',
                            'tr' => 'TDP',
                        ],
                        'value' => [
                            'fa' => '150 W',
                            'en' => '150 W',
                            'tr' => '150 W',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen11',
                            'en' => 'Gen11',
                            'tr' => 'Gen11',
                        ],
                    ],
                ],
                'attrs' => [
                    'cores' => 16,
                    'threads' => 32,
                    'ghz' => 2.0,
                    'ghz_turbo' => 4.0,
                    'cache_mb' => 30,
                    'tdp' => 150,
                ],
            ],
            [
                'slug' => 'xeon-gold-6430',
                'category' => 'cpu',
                'brand' => 'Intel',
                'compat_gens' => [
                    'gen11',
                ],
                'condition' => 'refurb',
                'price_contact' => true,
                'price_eur' => null,
                'in_stock' => true,
                'popular' => false,
                'sort' => 310,
                'name' => [
                    'fa' => 'Intel Xeon Gold 6430',
                    'en' => 'Intel Xeon Gold 6430',
                    'tr' => 'Intel Xeon Gold 6430',
                ],
                'tagline' => [
                    'fa' => '32 هسته، 64 رشته، تا 3.4 گیگاهرتز',
                    'en' => '32 cores, 64 threads, up to 3.4 GHz',
                    'tr' => '32 çekirdek, 64 iş parçacığı, 3.4 GHz’e kadar',
                ],
                'summary' => [
                    'fa' => 'پردازندهٔ Sapphire Rapids روی سوکت LGA4677 برای سرورهای Gen11؛ 32 هستهٔ فیزیکی، 60 مگابایت کش L3 و توان طراحی حرارتی 270 وات.',
                    'en' => 'Sapphire Rapids processor on socket LGA4677 for Gen11 servers: 32 physical cores, 60 MB of L3 cache and a 270 W thermal design power.',
                    'tr' => 'Gen11 sunucular için LGA4677 soketinde Sapphire Rapids işlemci: 32 fiziksel çekirdek, 60 MB L3 önbellek ve 270 W termal tasarım gücü.',
                ],
                'body' => [
                    'fa' => 'Intel Xeon Gold 6430 پردازنده‌ای از خانوادهٔ Sapphire Rapids است که روی سوکت LGA4677 می‌نشیند و در سرورهای HPE ProLiant Gen11 استفاده می‌شود. 32 هستهٔ فیزیکی و 64 رشتهٔ پردازشی دارد، از فرکانس پایهٔ 2.1 گیگاهرتز شروع می‌کند و در بار سبک تا 3.4 گیگاهرتز بالا می‌رود. 60 مگابایت کش L3 و توان طراحی حرارتی 270 وات.

DDR5، PCIe 5.0 و شتاب‌دهنده‌های درون‌تراشه‌ای (AMX برای هوش مصنوعی، QAT برای رمزنگاری و فشرده‌سازی). برای بار جدید و سرور نو.

پیش از سفارش دو نکته را چک کنید: اول اینکه در پیکربندی دو پردازنده‌ای، هر دو سوکت باید مدل یکسان داشته باشند — سرور با دو مدل متفاوت بالا نمی‌آید. دوم اینکه برای توان بالای ۱۳۵ وات، هیت‌سینک پرکارایی و کیت فن لازم است؛ با هیت‌سینک استاندارد سرور خودش را throttle می‌کند و کارایی‌ای که خریده‌اید را نمی‌گیرید.

هر پردازنده پیش از ارسال روی همان نسل سرور بوت و تست می‌شود.',
                    'en' => 'The Intel Xeon Gold 6430 is a Sapphire Rapids processor for socket LGA4677, used in HPE ProLiant Gen11 servers. It has 32 physical cores and 64 threads, starts at a 2.1 GHz base clock and boosts to 3.4 GHz under light load. It carries 60 MB of L3 cache and a 270 W thermal design power.

DDR5, PCIe 5.0 and on-die accelerators (AMX for AI, QAT for crypto and compression). For new workloads on new hardware.

Two things to check before ordering. First, in a dual-socket configuration both sockets must hold the identical model — a server with two different SKUs will not post. Second, anything above 135 W needs the high-performance heatsink and fan kit; with the standard heatsink the server throttles itself and you never get the performance you paid for.

Every processor is booted and tested on the same server generation before it ships.',
                    'tr' => 'Intel Xeon Gold 6430, LGA4677 soketi için bir Sapphire Rapids işlemcisidir ve HPE ProLiant Gen11 sunucularında kullanılır. 32 fiziksel çekirdek ve 64 iş parçacığına sahiptir, 2.1 GHz temel hızda başlar ve hafif yükte 3.4 GHz’e çıkar. 60 MB L3 önbellek ve 270 W termal tasarım gücü taşır.

DDR5, PCIe 5.0 ve yonga üstü hızlandırıcılar (yapay zekâ için AMX, şifreleme ve sıkıştırma için QAT). Yeni donanımda yeni iş yükleri için.

Sipariş öncesi iki nokta: Birincisi, çift soketli yapılandırmada her iki soket de aynı modeli taşımalıdır — iki farklı model ile sunucu açılmaz. İkincisi, 135 W üzerindeki her işlemci yüksek performanslı soğutucu ve fan kiti ister; standart soğutucuyla sunucu kendini kısar ve ödediğiniz performansı alamazsınız.

Her işlemci sevkiyattan önce aynı sunucu neslinde başlatılıp test edilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'خانوادهٔ پردازنده',
                            'en' => 'Processor family',
                            'tr' => 'İşlemci ailesi',
                        ],
                        'value' => [
                            'fa' => 'Sapphire Rapids',
                            'en' => 'Sapphire Rapids',
                            'tr' => 'Sapphire Rapids',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سوکت',
                            'en' => 'Socket',
                            'tr' => 'Soket',
                        ],
                        'value' => [
                            'fa' => 'LGA4677',
                            'en' => 'LGA4677',
                            'tr' => 'LGA4677',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'هسته / رشته',
                            'en' => 'Cores / threads',
                            'tr' => 'Çekirdek / iş parçacığı',
                        ],
                        'value' => [
                            'fa' => '32 / 64',
                            'en' => '32 / 64',
                            'tr' => '32 / 64',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس پایه',
                            'en' => 'Base clock',
                            'tr' => 'Temel hız',
                        ],
                        'value' => [
                            'fa' => '2.1 GHz',
                            'en' => '2.1 GHz',
                            'tr' => '2.1 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس توربو',
                            'en' => 'Max turbo',
                            'tr' => 'Maks. turbo',
                        ],
                        'value' => [
                            'fa' => '3.4 GHz',
                            'en' => '3.4 GHz',
                            'tr' => '3.4 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کش L3',
                            'en' => 'L3 cache',
                            'tr' => 'L3 önbellek',
                        ],
                        'value' => [
                            'fa' => '60 MB',
                            'en' => '60 MB',
                            'tr' => '60 MB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'توان طراحی حرارتی',
                            'en' => 'TDP',
                            'tr' => 'TDP',
                        ],
                        'value' => [
                            'fa' => '270 W',
                            'en' => '270 W',
                            'tr' => '270 W',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen11',
                            'en' => 'Gen11',
                            'tr' => 'Gen11',
                        ],
                    ],
                ],
                'attrs' => [
                    'cores' => 32,
                    'threads' => 64,
                    'ghz' => 2.1,
                    'ghz_turbo' => 3.4,
                    'cache_mb' => 60,
                    'tdp' => 270,
                ],
            ],
            [
                'slug' => 'xeon-6515p',
                'category' => 'cpu',
                'brand' => 'Intel',
                'compat_gens' => [
                    'gen12',
                ],
                'condition' => 'new',
                'price_contact' => true,
                'price_eur' => null,
                'in_stock' => true,
                'popular' => false,
                'sort' => 320,
                'name' => [
                    'fa' => 'Intel Xeon 6515P',
                    'en' => 'Intel Xeon 6515P',
                    'tr' => 'Intel Xeon 6515P',
                ],
                'tagline' => [
                    'fa' => '16 هسته، 32 رشته، تا 3.8 گیگاهرتز',
                    'en' => '16 cores, 32 threads, up to 3.8 GHz',
                    'tr' => '16 çekirdek, 32 iş parçacığı, 3.8 GHz’e kadar',
                ],
                'summary' => [
                    'fa' => 'پردازندهٔ Granite Rapids روی سوکت LGA4710 برای سرورهای Gen12؛ 16 هستهٔ فیزیکی، 72 مگابایت کش L3 و توان طراحی حرارتی 150 وات.',
                    'en' => 'Granite Rapids processor on socket LGA4710 for Gen12 servers: 16 physical cores, 72 MB of L3 cache and a 150 W thermal design power.',
                    'tr' => 'Gen12 sunucular için LGA4710 soketinde Granite Rapids işlemci: 16 fiziksel çekirdek, 72 MB L3 önbellek ve 150 W termal tasarım gücü.',
                ],
                'body' => [
                    'fa' => 'Intel Xeon 6515P پردازنده‌ای از خانوادهٔ Granite Rapids است که روی سوکت LGA4710 می‌نشیند و در سرورهای HPE ProLiant Gen12 استفاده می‌شود. 16 هستهٔ فیزیکی و 32 رشتهٔ پردازشی دارد، از فرکانس پایهٔ 2.3 گیگاهرتز شروع می‌کند و در بار سبک تا 3.8 گیگاهرتز بالا می‌رود. 72 مگابایت کش L3 و توان طراحی حرارتی 150 وات.

تازه‌ترین نسل Xeon با هستهٔ P و کش بزرگ. برای مراکز داده‌ای که همین امروز روی نسل بعد سرمایه‌گذاری می‌کنند.

پیش از سفارش دو نکته را چک کنید: اول اینکه در پیکربندی دو پردازنده‌ای، هر دو سوکت باید مدل یکسان داشته باشند — سرور با دو مدل متفاوت بالا نمی‌آید. دوم اینکه برای توان بالای ۱۳۵ وات، هیت‌سینک پرکارایی و کیت فن لازم است؛ با هیت‌سینک استاندارد سرور خودش را throttle می‌کند و کارایی‌ای که خریده‌اید را نمی‌گیرید.

هر پردازنده پیش از ارسال روی همان نسل سرور بوت و تست می‌شود.',
                    'en' => 'The Intel Xeon 6515P is a Granite Rapids processor for socket LGA4710, used in HPE ProLiant Gen12 servers. It has 16 physical cores and 32 threads, starts at a 2.3 GHz base clock and boosts to 3.8 GHz under light load. It carries 72 MB of L3 cache and a 150 W thermal design power.

The newest Xeon generation with P-cores and a large cache. For data centres investing in the next platform today.

Two things to check before ordering. First, in a dual-socket configuration both sockets must hold the identical model — a server with two different SKUs will not post. Second, anything above 135 W needs the high-performance heatsink and fan kit; with the standard heatsink the server throttles itself and you never get the performance you paid for.

Every processor is booted and tested on the same server generation before it ships.',
                    'tr' => 'Intel Xeon 6515P, LGA4710 soketi için bir Granite Rapids işlemcisidir ve HPE ProLiant Gen12 sunucularında kullanılır. 16 fiziksel çekirdek ve 32 iş parçacığına sahiptir, 2.3 GHz temel hızda başlar ve hafif yükte 3.8 GHz’e çıkar. 72 MB L3 önbellek ve 150 W termal tasarım gücü taşır.

P çekirdekli ve büyük önbellekli en yeni Xeon nesli. Bugünden bir sonraki platforma yatırım yapan veri merkezleri için.

Sipariş öncesi iki nokta: Birincisi, çift soketli yapılandırmada her iki soket de aynı modeli taşımalıdır — iki farklı model ile sunucu açılmaz. İkincisi, 135 W üzerindeki her işlemci yüksek performanslı soğutucu ve fan kiti ister; standart soğutucuyla sunucu kendini kısar ve ödediğiniz performansı alamazsınız.

Her işlemci sevkiyattan önce aynı sunucu neslinde başlatılıp test edilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'خانوادهٔ پردازنده',
                            'en' => 'Processor family',
                            'tr' => 'İşlemci ailesi',
                        ],
                        'value' => [
                            'fa' => 'Granite Rapids',
                            'en' => 'Granite Rapids',
                            'tr' => 'Granite Rapids',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سوکت',
                            'en' => 'Socket',
                            'tr' => 'Soket',
                        ],
                        'value' => [
                            'fa' => 'LGA4710',
                            'en' => 'LGA4710',
                            'tr' => 'LGA4710',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'هسته / رشته',
                            'en' => 'Cores / threads',
                            'tr' => 'Çekirdek / iş parçacığı',
                        ],
                        'value' => [
                            'fa' => '16 / 32',
                            'en' => '16 / 32',
                            'tr' => '16 / 32',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس پایه',
                            'en' => 'Base clock',
                            'tr' => 'Temel hız',
                        ],
                        'value' => [
                            'fa' => '2.3 GHz',
                            'en' => '2.3 GHz',
                            'tr' => '2.3 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس توربو',
                            'en' => 'Max turbo',
                            'tr' => 'Maks. turbo',
                        ],
                        'value' => [
                            'fa' => '3.8 GHz',
                            'en' => '3.8 GHz',
                            'tr' => '3.8 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کش L3',
                            'en' => 'L3 cache',
                            'tr' => 'L3 önbellek',
                        ],
                        'value' => [
                            'fa' => '72 MB',
                            'en' => '72 MB',
                            'tr' => '72 MB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'توان طراحی حرارتی',
                            'en' => 'TDP',
                            'tr' => 'TDP',
                        ],
                        'value' => [
                            'fa' => '150 W',
                            'en' => '150 W',
                            'tr' => '150 W',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen12',
                            'en' => 'Gen12',
                            'tr' => 'Gen12',
                        ],
                    ],
                ],
                'attrs' => [
                    'cores' => 16,
                    'threads' => 32,
                    'ghz' => 2.3,
                    'ghz_turbo' => 3.8,
                    'cache_mb' => 72,
                    'tdp' => 150,
                ],
            ],
            [
                'slug' => 'xeon-6517p',
                'category' => 'cpu',
                'brand' => 'Intel',
                'compat_gens' => [
                    'gen12',
                ],
                'condition' => 'new',
                'price_contact' => true,
                'price_eur' => null,
                'in_stock' => true,
                'popular' => false,
                'sort' => 330,
                'name' => [
                    'fa' => 'Intel Xeon 6517P',
                    'en' => 'Intel Xeon 6517P',
                    'tr' => 'Intel Xeon 6517P',
                ],
                'tagline' => [
                    'fa' => '16 هسته، 32 رشته، تا 4.2 گیگاهرتز',
                    'en' => '16 cores, 32 threads, up to 4.2 GHz',
                    'tr' => '16 çekirdek, 32 iş parçacığı, 4.2 GHz’e kadar',
                ],
                'summary' => [
                    'fa' => 'پردازندهٔ Granite Rapids روی سوکت LGA4710 برای سرورهای Gen12؛ 16 هستهٔ فیزیکی، 72 مگابایت کش L3 و توان طراحی حرارتی 190 وات.',
                    'en' => 'Granite Rapids processor on socket LGA4710 for Gen12 servers: 16 physical cores, 72 MB of L3 cache and a 190 W thermal design power.',
                    'tr' => 'Gen12 sunucular için LGA4710 soketinde Granite Rapids işlemci: 16 fiziksel çekirdek, 72 MB L3 önbellek ve 190 W termal tasarım gücü.',
                ],
                'body' => [
                    'fa' => 'Intel Xeon 6517P پردازنده‌ای از خانوادهٔ Granite Rapids است که روی سوکت LGA4710 می‌نشیند و در سرورهای HPE ProLiant Gen12 استفاده می‌شود. 16 هستهٔ فیزیکی و 32 رشتهٔ پردازشی دارد، از فرکانس پایهٔ 3.2 گیگاهرتز شروع می‌کند و در بار سبک تا 4.2 گیگاهرتز بالا می‌رود. 72 مگابایت کش L3 و توان طراحی حرارتی 190 وات.

تازه‌ترین نسل Xeon با هستهٔ P و کش بزرگ. برای مراکز داده‌ای که همین امروز روی نسل بعد سرمایه‌گذاری می‌کنند.

پیش از سفارش دو نکته را چک کنید: اول اینکه در پیکربندی دو پردازنده‌ای، هر دو سوکت باید مدل یکسان داشته باشند — سرور با دو مدل متفاوت بالا نمی‌آید. دوم اینکه برای توان بالای ۱۳۵ وات، هیت‌سینک پرکارایی و کیت فن لازم است؛ با هیت‌سینک استاندارد سرور خودش را throttle می‌کند و کارایی‌ای که خریده‌اید را نمی‌گیرید.

هر پردازنده پیش از ارسال روی همان نسل سرور بوت و تست می‌شود.',
                    'en' => 'The Intel Xeon 6517P is a Granite Rapids processor for socket LGA4710, used in HPE ProLiant Gen12 servers. It has 16 physical cores and 32 threads, starts at a 3.2 GHz base clock and boosts to 4.2 GHz under light load. It carries 72 MB of L3 cache and a 190 W thermal design power.

The newest Xeon generation with P-cores and a large cache. For data centres investing in the next platform today.

Two things to check before ordering. First, in a dual-socket configuration both sockets must hold the identical model — a server with two different SKUs will not post. Second, anything above 135 W needs the high-performance heatsink and fan kit; with the standard heatsink the server throttles itself and you never get the performance you paid for.

Every processor is booted and tested on the same server generation before it ships.',
                    'tr' => 'Intel Xeon 6517P, LGA4710 soketi için bir Granite Rapids işlemcisidir ve HPE ProLiant Gen12 sunucularında kullanılır. 16 fiziksel çekirdek ve 32 iş parçacığına sahiptir, 3.2 GHz temel hızda başlar ve hafif yükte 4.2 GHz’e çıkar. 72 MB L3 önbellek ve 190 W termal tasarım gücü taşır.

P çekirdekli ve büyük önbellekli en yeni Xeon nesli. Bugünden bir sonraki platforma yatırım yapan veri merkezleri için.

Sipariş öncesi iki nokta: Birincisi, çift soketli yapılandırmada her iki soket de aynı modeli taşımalıdır — iki farklı model ile sunucu açılmaz. İkincisi, 135 W üzerindeki her işlemci yüksek performanslı soğutucu ve fan kiti ister; standart soğutucuyla sunucu kendini kısar ve ödediğiniz performansı alamazsınız.

Her işlemci sevkiyattan önce aynı sunucu neslinde başlatılıp test edilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'خانوادهٔ پردازنده',
                            'en' => 'Processor family',
                            'tr' => 'İşlemci ailesi',
                        ],
                        'value' => [
                            'fa' => 'Granite Rapids',
                            'en' => 'Granite Rapids',
                            'tr' => 'Granite Rapids',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سوکت',
                            'en' => 'Socket',
                            'tr' => 'Soket',
                        ],
                        'value' => [
                            'fa' => 'LGA4710',
                            'en' => 'LGA4710',
                            'tr' => 'LGA4710',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'هسته / رشته',
                            'en' => 'Cores / threads',
                            'tr' => 'Çekirdek / iş parçacığı',
                        ],
                        'value' => [
                            'fa' => '16 / 32',
                            'en' => '16 / 32',
                            'tr' => '16 / 32',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس پایه',
                            'en' => 'Base clock',
                            'tr' => 'Temel hız',
                        ],
                        'value' => [
                            'fa' => '3.2 GHz',
                            'en' => '3.2 GHz',
                            'tr' => '3.2 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'فرکانس توربو',
                            'en' => 'Max turbo',
                            'tr' => 'Maks. turbo',
                        ],
                        'value' => [
                            'fa' => '4.2 GHz',
                            'en' => '4.2 GHz',
                            'tr' => '4.2 GHz',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کش L3',
                            'en' => 'L3 cache',
                            'tr' => 'L3 önbellek',
                        ],
                        'value' => [
                            'fa' => '72 MB',
                            'en' => '72 MB',
                            'tr' => '72 MB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'توان طراحی حرارتی',
                            'en' => 'TDP',
                            'tr' => 'TDP',
                        ],
                        'value' => [
                            'fa' => '190 W',
                            'en' => '190 W',
                            'tr' => '190 W',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen12',
                            'en' => 'Gen12',
                            'tr' => 'Gen12',
                        ],
                    ],
                ],
                'attrs' => [
                    'cores' => 16,
                    'threads' => 32,
                    'ghz' => 3.2,
                    'ghz_turbo' => 4.2,
                    'cache_mb' => 72,
                    'tdp' => 190,
                ],
            ],
            [
                'slug' => 'ram-ddr3-8gb-1600r',
                'category' => 'ram',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen8',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 900,
                'in_stock' => true,
                'popular' => false,
                'sort' => 340,
                'name' => [
                    'fa' => '8GB DDR3 PC3-12800R ECC RDIMM',
                    'en' => '8GB DDR3 PC3-12800R ECC RDIMM',
                    'tr' => '8GB DDR3 PC3-12800R ECC RDIMM',
                ],
                'tagline' => [
                    'fa' => '8 گیگابایت DDR3 ECC — 1600 MT/s',
                    'en' => '8 GB DDR3 ECC — 1600 MT/s',
                    'tr' => '8 GB DDR3 ECC — 1600 MT/s',
                ],
                'summary' => [
                    'fa' => 'ماژول 8 گیگابایتی DDR3 ECC RDIMM با کد PC3-12800R برای سرورهای Gen8.',
                    'en' => 'A 8 GB DDR3 ECC RDIMM module (PC3-12800R) for Gen8 servers.',
                    'tr' => 'Gen8 sunucular için 8 GB DDR3 ECC RDIMM modül (PC3-12800R).',
                ],
                'body' => [
                    'fa' => '8GB DDR3 PC3-12800R ECC RDIMM یک ماژول حافظهٔ سرور با ظرفیت 8 گیگابایت، فناوری DDR3 و پشتیبانی ECC است. سرعت آن 1600 مگاترانسفر بر ثانیه و کد رسمی‌اش PC3-12800R است. روی سرورهای HPE ProLiant Gen8 کار می‌کند.

RDIMM انتخاب استاندارد سرور است: بافر ثبات، تأخیر پایین‌تر از LRDIMM و پشتیبانی کامل ECC.

ECC یعنی خطای تک‌بیتی حافظه پیش از رسیدن به برنامه اصلاح می‌شود. روی یک ایستگاه کاری این یک ویژگی اضافه است؛ روی سروری که ماه‌ها بی‌ری‌استارت کار می‌کند، تفاوت بین «یک بیت پرید» و «پایگاه داده خراب شد» است.

یک نکتهٔ پیکربندی که زیاد از قلم می‌افتد: ماژول‌ها را کانال‌به‌کانال پر کنید، نه پشت سر هم. شش ماژول روی سه کانال، از شش ماژول روی یک کانال به‌مراتب سریع‌تر است — و ترتیب درست در راهنمای همان مدل سرور آمده. همچنین اگر ماژول‌های با سرعت متفاوت را قاطی کنید، همه با کندترین ماژول کار می‌کنند.',
                    'en' => 'The 8GB DDR3 PC3-12800R ECC RDIMM is a 8 GB DDR3 ECC server memory module rated at 1600 MT/s, part code PC3-12800R. It works in HPE ProLiant Gen8 servers.

RDIMM is the standard server choice: a registered buffer, lower latency than LRDIMM and full ECC support.

ECC means a single-bit memory error is corrected before it ever reaches your application. On a workstation that is a nice extra; on a server that runs for months without a reboot it is the difference between “a bit flipped” and “the database is corrupt”.

One configuration detail that is often missed: populate channel by channel, not slot by slot. Six modules across three channels are considerably faster than six modules on one channel — the correct order is in the server model’s own memory-population guide. Also, if you mix modules of different speeds they all run at the speed of the slowest one.',
                    'tr' => '8GB DDR3 PC3-12800R ECC RDIMM, 1600 MT/s hızında 8 GB DDR3 ECC sunucu bellek modülüdür; parça kodu PC3-12800R. HPE ProLiant Gen8 sunucularında çalışır.

RDIMM standart sunucu tercihidir: kayıtlı tampon, LRDIMM’den düşük gecikme ve tam ECC desteği.

ECC, tek bitlik bir bellek hatasının uygulamanıza ulaşmadan düzeltilmesi demektir. Bir iş istasyonunda bu hoş bir ekstradır; aylarca yeniden başlatılmadan çalışan bir sunucuda ise “bir bit değişti” ile “veritabanı bozuldu” arasındaki farktır.

Sık atlanan bir yapılandırma ayrıntısı: yuva yuva değil, kanal kanal doldurun. Üç kanala dağılmış altı modül, tek kanaldaki altı modülden belirgin şekilde hızlıdır — doğru sıra, sunucu modelinin bellek doldurma kılavuzundadır. Ayrıca farklı hızlarda modülleri karıştırırsanız hepsi en yavaş olanın hızında çalışır.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'ظرفیت',
                            'en' => 'Capacity',
                            'tr' => 'Kapasite',
                        ],
                        'value' => [
                            'fa' => '8 GB',
                            'en' => '8 GB',
                            'tr' => '8 GB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نوع',
                            'en' => 'Type',
                            'tr' => 'Tip',
                        ],
                        'value' => [
                            'fa' => 'DDR3 ECC RDIMM',
                            'en' => 'DDR3 ECC RDIMM',
                            'tr' => 'DDR3 ECC RDIMM',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سرعت',
                            'en' => 'Speed',
                            'tr' => 'Hız',
                        ],
                        'value' => [
                            'fa' => '1600 MT/s',
                            'en' => '1600 MT/s',
                            'tr' => '1600 MT/s',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کد قطعه',
                            'en' => 'Part code',
                            'tr' => 'Parça kodu',
                        ],
                        'value' => [
                            'fa' => 'PC3-12800R',
                            'en' => 'PC3-12800R',
                            'tr' => 'PC3-12800R',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen8',
                            'en' => 'Gen8',
                            'tr' => 'Gen8',
                        ],
                    ],
                ],
                'attrs' => [
                    'gb' => 8,
                    'speed_mts' => 1600,
                ],
            ],
            [
                'slug' => 'ram-ddr3-16gb-1600r',
                'category' => 'ram',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen8',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 1400,
                'in_stock' => true,
                'popular' => true,
                'sort' => 350,
                'name' => [
                    'fa' => '16GB DDR3 PC3-12800R ECC RDIMM',
                    'en' => '16GB DDR3 PC3-12800R ECC RDIMM',
                    'tr' => '16GB DDR3 PC3-12800R ECC RDIMM',
                ],
                'tagline' => [
                    'fa' => '16 گیگابایت DDR3 ECC — 1600 MT/s',
                    'en' => '16 GB DDR3 ECC — 1600 MT/s',
                    'tr' => '16 GB DDR3 ECC — 1600 MT/s',
                ],
                'summary' => [
                    'fa' => 'ماژول 16 گیگابایتی DDR3 ECC RDIMM با کد PC3-12800R برای سرورهای Gen8.',
                    'en' => 'A 16 GB DDR3 ECC RDIMM module (PC3-12800R) for Gen8 servers.',
                    'tr' => 'Gen8 sunucular için 16 GB DDR3 ECC RDIMM modül (PC3-12800R).',
                ],
                'body' => [
                    'fa' => '16GB DDR3 PC3-12800R ECC RDIMM یک ماژول حافظهٔ سرور با ظرفیت 16 گیگابایت، فناوری DDR3 و پشتیبانی ECC است. سرعت آن 1600 مگاترانسفر بر ثانیه و کد رسمی‌اش PC3-12800R است. روی سرورهای HPE ProLiant Gen8 کار می‌کند.

RDIMM انتخاب استاندارد سرور است: بافر ثبات، تأخیر پایین‌تر از LRDIMM و پشتیبانی کامل ECC.

ECC یعنی خطای تک‌بیتی حافظه پیش از رسیدن به برنامه اصلاح می‌شود. روی یک ایستگاه کاری این یک ویژگی اضافه است؛ روی سروری که ماه‌ها بی‌ری‌استارت کار می‌کند، تفاوت بین «یک بیت پرید» و «پایگاه داده خراب شد» است.

یک نکتهٔ پیکربندی که زیاد از قلم می‌افتد: ماژول‌ها را کانال‌به‌کانال پر کنید، نه پشت سر هم. شش ماژول روی سه کانال، از شش ماژول روی یک کانال به‌مراتب سریع‌تر است — و ترتیب درست در راهنمای همان مدل سرور آمده. همچنین اگر ماژول‌های با سرعت متفاوت را قاطی کنید، همه با کندترین ماژول کار می‌کنند.',
                    'en' => 'The 16GB DDR3 PC3-12800R ECC RDIMM is a 16 GB DDR3 ECC server memory module rated at 1600 MT/s, part code PC3-12800R. It works in HPE ProLiant Gen8 servers.

RDIMM is the standard server choice: a registered buffer, lower latency than LRDIMM and full ECC support.

ECC means a single-bit memory error is corrected before it ever reaches your application. On a workstation that is a nice extra; on a server that runs for months without a reboot it is the difference between “a bit flipped” and “the database is corrupt”.

One configuration detail that is often missed: populate channel by channel, not slot by slot. Six modules across three channels are considerably faster than six modules on one channel — the correct order is in the server model’s own memory-population guide. Also, if you mix modules of different speeds they all run at the speed of the slowest one.',
                    'tr' => '16GB DDR3 PC3-12800R ECC RDIMM, 1600 MT/s hızında 16 GB DDR3 ECC sunucu bellek modülüdür; parça kodu PC3-12800R. HPE ProLiant Gen8 sunucularında çalışır.

RDIMM standart sunucu tercihidir: kayıtlı tampon, LRDIMM’den düşük gecikme ve tam ECC desteği.

ECC, tek bitlik bir bellek hatasının uygulamanıza ulaşmadan düzeltilmesi demektir. Bir iş istasyonunda bu hoş bir ekstradır; aylarca yeniden başlatılmadan çalışan bir sunucuda ise “bir bit değişti” ile “veritabanı bozuldu” arasındaki farktır.

Sık atlanan bir yapılandırma ayrıntısı: yuva yuva değil, kanal kanal doldurun. Üç kanala dağılmış altı modül, tek kanaldaki altı modülden belirgin şekilde hızlıdır — doğru sıra, sunucu modelinin bellek doldurma kılavuzundadır. Ayrıca farklı hızlarda modülleri karıştırırsanız hepsi en yavaş olanın hızında çalışır.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'ظرفیت',
                            'en' => 'Capacity',
                            'tr' => 'Kapasite',
                        ],
                        'value' => [
                            'fa' => '16 GB',
                            'en' => '16 GB',
                            'tr' => '16 GB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نوع',
                            'en' => 'Type',
                            'tr' => 'Tip',
                        ],
                        'value' => [
                            'fa' => 'DDR3 ECC RDIMM',
                            'en' => 'DDR3 ECC RDIMM',
                            'tr' => 'DDR3 ECC RDIMM',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سرعت',
                            'en' => 'Speed',
                            'tr' => 'Hız',
                        ],
                        'value' => [
                            'fa' => '1600 MT/s',
                            'en' => '1600 MT/s',
                            'tr' => '1600 MT/s',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کد قطعه',
                            'en' => 'Part code',
                            'tr' => 'Parça kodu',
                        ],
                        'value' => [
                            'fa' => 'PC3-12800R',
                            'en' => 'PC3-12800R',
                            'tr' => 'PC3-12800R',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen8',
                            'en' => 'Gen8',
                            'tr' => 'Gen8',
                        ],
                    ],
                ],
                'attrs' => [
                    'gb' => 16,
                    'speed_mts' => 1600,
                ],
            ],
            [
                'slug' => 'ram-ddr3-32gb-1866l',
                'category' => 'ram',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen8',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 2600,
                'in_stock' => true,
                'popular' => false,
                'sort' => 360,
                'name' => [
                    'fa' => '32GB DDR3 PC3-14900L ECC LRDIMM',
                    'en' => '32GB DDR3 PC3-14900L ECC LRDIMM',
                    'tr' => '32GB DDR3 PC3-14900L ECC LRDIMM',
                ],
                'tagline' => [
                    'fa' => '32 گیگابایت DDR3 ECC — 1866 MT/s',
                    'en' => '32 GB DDR3 ECC — 1866 MT/s',
                    'tr' => '32 GB DDR3 ECC — 1866 MT/s',
                ],
                'summary' => [
                    'fa' => 'ماژول 32 گیگابایتی DDR3 ECC LRDIMM با کد PC3-14900L برای سرورهای Gen8.',
                    'en' => 'A 32 GB DDR3 ECC LRDIMM module (PC3-14900L) for Gen8 servers.',
                    'tr' => 'Gen8 sunucular için 32 GB DDR3 ECC LRDIMM modül (PC3-14900L).',
                ],
                'body' => [
                    'fa' => '32GB DDR3 PC3-14900L ECC LRDIMM یک ماژول حافظهٔ سرور با ظرفیت 32 گیگابایت، فناوری DDR3 و پشتیبانی ECC است. سرعت آن 1866 مگاترانسفر بر ثانیه و کد رسمی‌اش PC3-14900L است. روی سرورهای HPE ProLiant Gen8 کار می‌کند.

LRDIMM ظرفیت بیشتری در هر کانال می‌دهد ولی تأخیرش کمی بالاتر است؛ برای پرکردن کامل ۲۴ اسلات ساخته شده.

ECC یعنی خطای تک‌بیتی حافظه پیش از رسیدن به برنامه اصلاح می‌شود. روی یک ایستگاه کاری این یک ویژگی اضافه است؛ روی سروری که ماه‌ها بی‌ری‌استارت کار می‌کند، تفاوت بین «یک بیت پرید» و «پایگاه داده خراب شد» است.

یک نکتهٔ پیکربندی که زیاد از قلم می‌افتد: ماژول‌ها را کانال‌به‌کانال پر کنید، نه پشت سر هم. شش ماژول روی سه کانال، از شش ماژول روی یک کانال به‌مراتب سریع‌تر است — و ترتیب درست در راهنمای همان مدل سرور آمده. همچنین اگر ماژول‌های با سرعت متفاوت را قاطی کنید، همه با کندترین ماژول کار می‌کنند.',
                    'en' => 'The 32GB DDR3 PC3-14900L ECC LRDIMM is a 32 GB DDR3 ECC server memory module rated at 1866 MT/s, part code PC3-14900L. It works in HPE ProLiant Gen8 servers.

LRDIMMs give more capacity per channel at slightly higher latency; they exist to fill all 24 slots.

ECC means a single-bit memory error is corrected before it ever reaches your application. On a workstation that is a nice extra; on a server that runs for months without a reboot it is the difference between “a bit flipped” and “the database is corrupt”.

One configuration detail that is often missed: populate channel by channel, not slot by slot. Six modules across three channels are considerably faster than six modules on one channel — the correct order is in the server model’s own memory-population guide. Also, if you mix modules of different speeds they all run at the speed of the slowest one.',
                    'tr' => '32GB DDR3 PC3-14900L ECC LRDIMM, 1866 MT/s hızında 32 GB DDR3 ECC sunucu bellek modülüdür; parça kodu PC3-14900L. HPE ProLiant Gen8 sunucularında çalışır.

LRDIMM kanal başına daha fazla kapasite verir, gecikmesi biraz daha yüksektir; 24 yuvanın tamamını doldurmak için vardır.

ECC, tek bitlik bir bellek hatasının uygulamanıza ulaşmadan düzeltilmesi demektir. Bir iş istasyonunda bu hoş bir ekstradır; aylarca yeniden başlatılmadan çalışan bir sunucuda ise “bir bit değişti” ile “veritabanı bozuldu” arasındaki farktır.

Sık atlanan bir yapılandırma ayrıntısı: yuva yuva değil, kanal kanal doldurun. Üç kanala dağılmış altı modül, tek kanaldaki altı modülden belirgin şekilde hızlıdır — doğru sıra, sunucu modelinin bellek doldurma kılavuzundadır. Ayrıca farklı hızlarda modülleri karıştırırsanız hepsi en yavaş olanın hızında çalışır.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'ظرفیت',
                            'en' => 'Capacity',
                            'tr' => 'Kapasite',
                        ],
                        'value' => [
                            'fa' => '32 GB',
                            'en' => '32 GB',
                            'tr' => '32 GB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نوع',
                            'en' => 'Type',
                            'tr' => 'Tip',
                        ],
                        'value' => [
                            'fa' => 'DDR3 ECC LRDIMM',
                            'en' => 'DDR3 ECC LRDIMM',
                            'tr' => 'DDR3 ECC LRDIMM',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سرعت',
                            'en' => 'Speed',
                            'tr' => 'Hız',
                        ],
                        'value' => [
                            'fa' => '1866 MT/s',
                            'en' => '1866 MT/s',
                            'tr' => '1866 MT/s',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کد قطعه',
                            'en' => 'Part code',
                            'tr' => 'Parça kodu',
                        ],
                        'value' => [
                            'fa' => 'PC3-14900L',
                            'en' => 'PC3-14900L',
                            'tr' => 'PC3-14900L',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen8',
                            'en' => 'Gen8',
                            'tr' => 'Gen8',
                        ],
                    ],
                ],
                'attrs' => [
                    'gb' => 32,
                    'speed_mts' => 1866,
                ],
            ],
            [
                'slug' => 'ram-ddr4-16gb-2400r',
                'category' => 'ram',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen9',
                    'gen10',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 8403,
                'in_stock' => true,
                'popular' => true,
                'sort' => 370,
                'name' => [
                    'fa' => '16GB DDR4 PC4-2400T ECC RDIMM',
                    'en' => '16GB DDR4 PC4-2400T ECC RDIMM',
                    'tr' => '16GB DDR4 PC4-2400T ECC RDIMM',
                ],
                'tagline' => [
                    'fa' => '16 گیگابایت DDR4 ECC — 2400 MT/s',
                    'en' => '16 GB DDR4 ECC — 2400 MT/s',
                    'tr' => '16 GB DDR4 ECC — 2400 MT/s',
                ],
                'summary' => [
                    'fa' => 'ماژول 16 گیگابایتی DDR4 ECC RDIMM با کد PC4-2400T برای سرورهای Gen9 / Gen10.',
                    'en' => 'A 16 GB DDR4 ECC RDIMM module (PC4-2400T) for Gen9 / Gen10 servers.',
                    'tr' => 'Gen9 / Gen10 sunucular için 16 GB DDR4 ECC RDIMM modül (PC4-2400T).',
                ],
                'body' => [
                    'fa' => '16GB DDR4 PC4-2400T ECC RDIMM یک ماژول حافظهٔ سرور با ظرفیت 16 گیگابایت، فناوری DDR4 و پشتیبانی ECC است. سرعت آن 2400 مگاترانسفر بر ثانیه و کد رسمی‌اش PC4-2400T است. روی سرورهای HPE ProLiant Gen9 / Gen10 کار می‌کند.

RDIMM انتخاب استاندارد سرور است: بافر ثبات، تأخیر پایین‌تر از LRDIMM و پشتیبانی کامل ECC.

ECC یعنی خطای تک‌بیتی حافظه پیش از رسیدن به برنامه اصلاح می‌شود. روی یک ایستگاه کاری این یک ویژگی اضافه است؛ روی سروری که ماه‌ها بی‌ری‌استارت کار می‌کند، تفاوت بین «یک بیت پرید» و «پایگاه داده خراب شد» است.

یک نکتهٔ پیکربندی که زیاد از قلم می‌افتد: ماژول‌ها را کانال‌به‌کانال پر کنید، نه پشت سر هم. شش ماژول روی سه کانال، از شش ماژول روی یک کانال به‌مراتب سریع‌تر است — و ترتیب درست در راهنمای همان مدل سرور آمده. همچنین اگر ماژول‌های با سرعت متفاوت را قاطی کنید، همه با کندترین ماژول کار می‌کنند.',
                    'en' => 'The 16GB DDR4 PC4-2400T ECC RDIMM is a 16 GB DDR4 ECC server memory module rated at 2400 MT/s, part code PC4-2400T. It works in HPE ProLiant Gen9 / Gen10 servers.

RDIMM is the standard server choice: a registered buffer, lower latency than LRDIMM and full ECC support.

ECC means a single-bit memory error is corrected before it ever reaches your application. On a workstation that is a nice extra; on a server that runs for months without a reboot it is the difference between “a bit flipped” and “the database is corrupt”.

One configuration detail that is often missed: populate channel by channel, not slot by slot. Six modules across three channels are considerably faster than six modules on one channel — the correct order is in the server model’s own memory-population guide. Also, if you mix modules of different speeds they all run at the speed of the slowest one.',
                    'tr' => '16GB DDR4 PC4-2400T ECC RDIMM, 2400 MT/s hızında 16 GB DDR4 ECC sunucu bellek modülüdür; parça kodu PC4-2400T. HPE ProLiant Gen9 / Gen10 sunucularında çalışır.

RDIMM standart sunucu tercihidir: kayıtlı tampon, LRDIMM’den düşük gecikme ve tam ECC desteği.

ECC, tek bitlik bir bellek hatasının uygulamanıza ulaşmadan düzeltilmesi demektir. Bir iş istasyonunda bu hoş bir ekstradır; aylarca yeniden başlatılmadan çalışan bir sunucuda ise “bir bit değişti” ile “veritabanı bozuldu” arasındaki farktır.

Sık atlanan bir yapılandırma ayrıntısı: yuva yuva değil, kanal kanal doldurun. Üç kanala dağılmış altı modül, tek kanaldaki altı modülden belirgin şekilde hızlıdır — doğru sıra, sunucu modelinin bellek doldurma kılavuzundadır. Ayrıca farklı hızlarda modülleri karıştırırsanız hepsi en yavaş olanın hızında çalışır.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'ظرفیت',
                            'en' => 'Capacity',
                            'tr' => 'Kapasite',
                        ],
                        'value' => [
                            'fa' => '16 GB',
                            'en' => '16 GB',
                            'tr' => '16 GB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نوع',
                            'en' => 'Type',
                            'tr' => 'Tip',
                        ],
                        'value' => [
                            'fa' => 'DDR4 ECC RDIMM',
                            'en' => 'DDR4 ECC RDIMM',
                            'tr' => 'DDR4 ECC RDIMM',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سرعت',
                            'en' => 'Speed',
                            'tr' => 'Hız',
                        ],
                        'value' => [
                            'fa' => '2400 MT/s',
                            'en' => '2400 MT/s',
                            'tr' => '2400 MT/s',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کد قطعه',
                            'en' => 'Part code',
                            'tr' => 'Parça kodu',
                        ],
                        'value' => [
                            'fa' => 'PC4-2400T',
                            'en' => 'PC4-2400T',
                            'tr' => 'PC4-2400T',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen9 / Gen10',
                            'en' => 'Gen9 / Gen10',
                            'tr' => 'Gen9 / Gen10',
                        ],
                    ],
                ],
                'attrs' => [
                    'gb' => 16,
                    'speed_mts' => 2400,
                ],
            ],
            [
                'slug' => 'ram-ddr4-32gb-2400r',
                'category' => 'ram',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen9',
                    'gen10',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 16806,
                'in_stock' => true,
                'popular' => true,
                'sort' => 380,
                'name' => [
                    'fa' => '32GB DDR4 PC4-2400T ECC RDIMM',
                    'en' => '32GB DDR4 PC4-2400T ECC RDIMM',
                    'tr' => '32GB DDR4 PC4-2400T ECC RDIMM',
                ],
                'tagline' => [
                    'fa' => '32 گیگابایت DDR4 ECC — 2400 MT/s',
                    'en' => '32 GB DDR4 ECC — 2400 MT/s',
                    'tr' => '32 GB DDR4 ECC — 2400 MT/s',
                ],
                'summary' => [
                    'fa' => 'ماژول 32 گیگابایتی DDR4 ECC RDIMM با کد PC4-2400T برای سرورهای Gen9 / Gen10.',
                    'en' => 'A 32 GB DDR4 ECC RDIMM module (PC4-2400T) for Gen9 / Gen10 servers.',
                    'tr' => 'Gen9 / Gen10 sunucular için 32 GB DDR4 ECC RDIMM modül (PC4-2400T).',
                ],
                'body' => [
                    'fa' => '32GB DDR4 PC4-2400T ECC RDIMM یک ماژول حافظهٔ سرور با ظرفیت 32 گیگابایت، فناوری DDR4 و پشتیبانی ECC است. سرعت آن 2400 مگاترانسفر بر ثانیه و کد رسمی‌اش PC4-2400T است. روی سرورهای HPE ProLiant Gen9 / Gen10 کار می‌کند.

RDIMM انتخاب استاندارد سرور است: بافر ثبات، تأخیر پایین‌تر از LRDIMM و پشتیبانی کامل ECC.

ECC یعنی خطای تک‌بیتی حافظه پیش از رسیدن به برنامه اصلاح می‌شود. روی یک ایستگاه کاری این یک ویژگی اضافه است؛ روی سروری که ماه‌ها بی‌ری‌استارت کار می‌کند، تفاوت بین «یک بیت پرید» و «پایگاه داده خراب شد» است.

یک نکتهٔ پیکربندی که زیاد از قلم می‌افتد: ماژول‌ها را کانال‌به‌کانال پر کنید، نه پشت سر هم. شش ماژول روی سه کانال، از شش ماژول روی یک کانال به‌مراتب سریع‌تر است — و ترتیب درست در راهنمای همان مدل سرور آمده. همچنین اگر ماژول‌های با سرعت متفاوت را قاطی کنید، همه با کندترین ماژول کار می‌کنند.',
                    'en' => 'The 32GB DDR4 PC4-2400T ECC RDIMM is a 32 GB DDR4 ECC server memory module rated at 2400 MT/s, part code PC4-2400T. It works in HPE ProLiant Gen9 / Gen10 servers.

RDIMM is the standard server choice: a registered buffer, lower latency than LRDIMM and full ECC support.

ECC means a single-bit memory error is corrected before it ever reaches your application. On a workstation that is a nice extra; on a server that runs for months without a reboot it is the difference between “a bit flipped” and “the database is corrupt”.

One configuration detail that is often missed: populate channel by channel, not slot by slot. Six modules across three channels are considerably faster than six modules on one channel — the correct order is in the server model’s own memory-population guide. Also, if you mix modules of different speeds they all run at the speed of the slowest one.',
                    'tr' => '32GB DDR4 PC4-2400T ECC RDIMM, 2400 MT/s hızında 32 GB DDR4 ECC sunucu bellek modülüdür; parça kodu PC4-2400T. HPE ProLiant Gen9 / Gen10 sunucularında çalışır.

RDIMM standart sunucu tercihidir: kayıtlı tampon, LRDIMM’den düşük gecikme ve tam ECC desteği.

ECC, tek bitlik bir bellek hatasının uygulamanıza ulaşmadan düzeltilmesi demektir. Bir iş istasyonunda bu hoş bir ekstradır; aylarca yeniden başlatılmadan çalışan bir sunucuda ise “bir bit değişti” ile “veritabanı bozuldu” arasındaki farktır.

Sık atlanan bir yapılandırma ayrıntısı: yuva yuva değil, kanal kanal doldurun. Üç kanala dağılmış altı modül, tek kanaldaki altı modülden belirgin şekilde hızlıdır — doğru sıra, sunucu modelinin bellek doldurma kılavuzundadır. Ayrıca farklı hızlarda modülleri karıştırırsanız hepsi en yavaş olanın hızında çalışır.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'ظرفیت',
                            'en' => 'Capacity',
                            'tr' => 'Kapasite',
                        ],
                        'value' => [
                            'fa' => '32 GB',
                            'en' => '32 GB',
                            'tr' => '32 GB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نوع',
                            'en' => 'Type',
                            'tr' => 'Tip',
                        ],
                        'value' => [
                            'fa' => 'DDR4 ECC RDIMM',
                            'en' => 'DDR4 ECC RDIMM',
                            'tr' => 'DDR4 ECC RDIMM',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سرعت',
                            'en' => 'Speed',
                            'tr' => 'Hız',
                        ],
                        'value' => [
                            'fa' => '2400 MT/s',
                            'en' => '2400 MT/s',
                            'tr' => '2400 MT/s',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کد قطعه',
                            'en' => 'Part code',
                            'tr' => 'Parça kodu',
                        ],
                        'value' => [
                            'fa' => 'PC4-2400T',
                            'en' => 'PC4-2400T',
                            'tr' => 'PC4-2400T',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen9 / Gen10',
                            'en' => 'Gen9 / Gen10',
                            'tr' => 'Gen9 / Gen10',
                        ],
                    ],
                ],
                'attrs' => [
                    'gb' => 32,
                    'speed_mts' => 2400,
                ],
            ],
            [
                'slug' => 'ram-ddr4-16gb-2666r',
                'category' => 'ram',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen10',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 10083,
                'in_stock' => true,
                'popular' => false,
                'sort' => 390,
                'name' => [
                    'fa' => '16GB DDR4 PC4-2666V ECC RDIMM',
                    'en' => '16GB DDR4 PC4-2666V ECC RDIMM',
                    'tr' => '16GB DDR4 PC4-2666V ECC RDIMM',
                ],
                'tagline' => [
                    'fa' => '16 گیگابایت DDR4 ECC — 2666 MT/s',
                    'en' => '16 GB DDR4 ECC — 2666 MT/s',
                    'tr' => '16 GB DDR4 ECC — 2666 MT/s',
                ],
                'summary' => [
                    'fa' => 'ماژول 16 گیگابایتی DDR4 ECC RDIMM با کد PC4-2666V برای سرورهای Gen10.',
                    'en' => 'A 16 GB DDR4 ECC RDIMM module (PC4-2666V) for Gen10 servers.',
                    'tr' => 'Gen10 sunucular için 16 GB DDR4 ECC RDIMM modül (PC4-2666V).',
                ],
                'body' => [
                    'fa' => '16GB DDR4 PC4-2666V ECC RDIMM یک ماژول حافظهٔ سرور با ظرفیت 16 گیگابایت، فناوری DDR4 و پشتیبانی ECC است. سرعت آن 2666 مگاترانسفر بر ثانیه و کد رسمی‌اش PC4-2666V است. روی سرورهای HPE ProLiant Gen10 کار می‌کند.

RDIMM انتخاب استاندارد سرور است: بافر ثبات، تأخیر پایین‌تر از LRDIMM و پشتیبانی کامل ECC.

ECC یعنی خطای تک‌بیتی حافظه پیش از رسیدن به برنامه اصلاح می‌شود. روی یک ایستگاه کاری این یک ویژگی اضافه است؛ روی سروری که ماه‌ها بی‌ری‌استارت کار می‌کند، تفاوت بین «یک بیت پرید» و «پایگاه داده خراب شد» است.

یک نکتهٔ پیکربندی که زیاد از قلم می‌افتد: ماژول‌ها را کانال‌به‌کانال پر کنید، نه پشت سر هم. شش ماژول روی سه کانال، از شش ماژول روی یک کانال به‌مراتب سریع‌تر است — و ترتیب درست در راهنمای همان مدل سرور آمده. همچنین اگر ماژول‌های با سرعت متفاوت را قاطی کنید، همه با کندترین ماژول کار می‌کنند.',
                    'en' => 'The 16GB DDR4 PC4-2666V ECC RDIMM is a 16 GB DDR4 ECC server memory module rated at 2666 MT/s, part code PC4-2666V. It works in HPE ProLiant Gen10 servers.

RDIMM is the standard server choice: a registered buffer, lower latency than LRDIMM and full ECC support.

ECC means a single-bit memory error is corrected before it ever reaches your application. On a workstation that is a nice extra; on a server that runs for months without a reboot it is the difference between “a bit flipped” and “the database is corrupt”.

One configuration detail that is often missed: populate channel by channel, not slot by slot. Six modules across three channels are considerably faster than six modules on one channel — the correct order is in the server model’s own memory-population guide. Also, if you mix modules of different speeds they all run at the speed of the slowest one.',
                    'tr' => '16GB DDR4 PC4-2666V ECC RDIMM, 2666 MT/s hızında 16 GB DDR4 ECC sunucu bellek modülüdür; parça kodu PC4-2666V. HPE ProLiant Gen10 sunucularında çalışır.

RDIMM standart sunucu tercihidir: kayıtlı tampon, LRDIMM’den düşük gecikme ve tam ECC desteği.

ECC, tek bitlik bir bellek hatasının uygulamanıza ulaşmadan düzeltilmesi demektir. Bir iş istasyonunda bu hoş bir ekstradır; aylarca yeniden başlatılmadan çalışan bir sunucuda ise “bir bit değişti” ile “veritabanı bozuldu” arasındaki farktır.

Sık atlanan bir yapılandırma ayrıntısı: yuva yuva değil, kanal kanal doldurun. Üç kanala dağılmış altı modül, tek kanaldaki altı modülden belirgin şekilde hızlıdır — doğru sıra, sunucu modelinin bellek doldurma kılavuzundadır. Ayrıca farklı hızlarda modülleri karıştırırsanız hepsi en yavaş olanın hızında çalışır.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'ظرفیت',
                            'en' => 'Capacity',
                            'tr' => 'Kapasite',
                        ],
                        'value' => [
                            'fa' => '16 GB',
                            'en' => '16 GB',
                            'tr' => '16 GB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نوع',
                            'en' => 'Type',
                            'tr' => 'Tip',
                        ],
                        'value' => [
                            'fa' => 'DDR4 ECC RDIMM',
                            'en' => 'DDR4 ECC RDIMM',
                            'tr' => 'DDR4 ECC RDIMM',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سرعت',
                            'en' => 'Speed',
                            'tr' => 'Hız',
                        ],
                        'value' => [
                            'fa' => '2666 MT/s',
                            'en' => '2666 MT/s',
                            'tr' => '2666 MT/s',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کد قطعه',
                            'en' => 'Part code',
                            'tr' => 'Parça kodu',
                        ],
                        'value' => [
                            'fa' => 'PC4-2666V',
                            'en' => 'PC4-2666V',
                            'tr' => 'PC4-2666V',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen10',
                            'en' => 'Gen10',
                            'tr' => 'Gen10',
                        ],
                    ],
                ],
                'attrs' => [
                    'gb' => 16,
                    'speed_mts' => 2666,
                ],
            ],
            [
                'slug' => 'ram-ddr4-64gb-2666l',
                'category' => 'ram',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen10',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 37814,
                'in_stock' => true,
                'popular' => false,
                'sort' => 400,
                'name' => [
                    'fa' => '64GB DDR4 PC4-2666V ECC LRDIMM',
                    'en' => '64GB DDR4 PC4-2666V ECC LRDIMM',
                    'tr' => '64GB DDR4 PC4-2666V ECC LRDIMM',
                ],
                'tagline' => [
                    'fa' => '64 گیگابایت DDR4 ECC — 2666 MT/s',
                    'en' => '64 GB DDR4 ECC — 2666 MT/s',
                    'tr' => '64 GB DDR4 ECC — 2666 MT/s',
                ],
                'summary' => [
                    'fa' => 'ماژول 64 گیگابایتی DDR4 ECC LRDIMM با کد PC4-2666V برای سرورهای Gen10.',
                    'en' => 'A 64 GB DDR4 ECC LRDIMM module (PC4-2666V) for Gen10 servers.',
                    'tr' => 'Gen10 sunucular için 64 GB DDR4 ECC LRDIMM modül (PC4-2666V).',
                ],
                'body' => [
                    'fa' => '64GB DDR4 PC4-2666V ECC LRDIMM یک ماژول حافظهٔ سرور با ظرفیت 64 گیگابایت، فناوری DDR4 و پشتیبانی ECC است. سرعت آن 2666 مگاترانسفر بر ثانیه و کد رسمی‌اش PC4-2666V است. روی سرورهای HPE ProLiant Gen10 کار می‌کند.

LRDIMM ظرفیت بیشتری در هر کانال می‌دهد ولی تأخیرش کمی بالاتر است؛ برای پرکردن کامل ۲۴ اسلات ساخته شده.

ECC یعنی خطای تک‌بیتی حافظه پیش از رسیدن به برنامه اصلاح می‌شود. روی یک ایستگاه کاری این یک ویژگی اضافه است؛ روی سروری که ماه‌ها بی‌ری‌استارت کار می‌کند، تفاوت بین «یک بیت پرید» و «پایگاه داده خراب شد» است.

یک نکتهٔ پیکربندی که زیاد از قلم می‌افتد: ماژول‌ها را کانال‌به‌کانال پر کنید، نه پشت سر هم. شش ماژول روی سه کانال، از شش ماژول روی یک کانال به‌مراتب سریع‌تر است — و ترتیب درست در راهنمای همان مدل سرور آمده. همچنین اگر ماژول‌های با سرعت متفاوت را قاطی کنید، همه با کندترین ماژول کار می‌کنند.',
                    'en' => 'The 64GB DDR4 PC4-2666V ECC LRDIMM is a 64 GB DDR4 ECC server memory module rated at 2666 MT/s, part code PC4-2666V. It works in HPE ProLiant Gen10 servers.

LRDIMMs give more capacity per channel at slightly higher latency; they exist to fill all 24 slots.

ECC means a single-bit memory error is corrected before it ever reaches your application. On a workstation that is a nice extra; on a server that runs for months without a reboot it is the difference between “a bit flipped” and “the database is corrupt”.

One configuration detail that is often missed: populate channel by channel, not slot by slot. Six modules across three channels are considerably faster than six modules on one channel — the correct order is in the server model’s own memory-population guide. Also, if you mix modules of different speeds they all run at the speed of the slowest one.',
                    'tr' => '64GB DDR4 PC4-2666V ECC LRDIMM, 2666 MT/s hızında 64 GB DDR4 ECC sunucu bellek modülüdür; parça kodu PC4-2666V. HPE ProLiant Gen10 sunucularında çalışır.

LRDIMM kanal başına daha fazla kapasite verir, gecikmesi biraz daha yüksektir; 24 yuvanın tamamını doldurmak için vardır.

ECC, tek bitlik bir bellek hatasının uygulamanıza ulaşmadan düzeltilmesi demektir. Bir iş istasyonunda bu hoş bir ekstradır; aylarca yeniden başlatılmadan çalışan bir sunucuda ise “bir bit değişti” ile “veritabanı bozuldu” arasındaki farktır.

Sık atlanan bir yapılandırma ayrıntısı: yuva yuva değil, kanal kanal doldurun. Üç kanala dağılmış altı modül, tek kanaldaki altı modülden belirgin şekilde hızlıdır — doğru sıra, sunucu modelinin bellek doldurma kılavuzundadır. Ayrıca farklı hızlarda modülleri karıştırırsanız hepsi en yavaş olanın hızında çalışır.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'ظرفیت',
                            'en' => 'Capacity',
                            'tr' => 'Kapasite',
                        ],
                        'value' => [
                            'fa' => '64 GB',
                            'en' => '64 GB',
                            'tr' => '64 GB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نوع',
                            'en' => 'Type',
                            'tr' => 'Tip',
                        ],
                        'value' => [
                            'fa' => 'DDR4 ECC LRDIMM',
                            'en' => 'DDR4 ECC LRDIMM',
                            'tr' => 'DDR4 ECC LRDIMM',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سرعت',
                            'en' => 'Speed',
                            'tr' => 'Hız',
                        ],
                        'value' => [
                            'fa' => '2666 MT/s',
                            'en' => '2666 MT/s',
                            'tr' => '2666 MT/s',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کد قطعه',
                            'en' => 'Part code',
                            'tr' => 'Parça kodu',
                        ],
                        'value' => [
                            'fa' => 'PC4-2666V',
                            'en' => 'PC4-2666V',
                            'tr' => 'PC4-2666V',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen10',
                            'en' => 'Gen10',
                            'tr' => 'Gen10',
                        ],
                    ],
                ],
                'attrs' => [
                    'gb' => 64,
                    'speed_mts' => 2666,
                ],
            ],
            [
                'slug' => 'ram-ddr4-32gb-2933r',
                'category' => 'ram',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen10',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 25209,
                'in_stock' => true,
                'popular' => false,
                'sort' => 410,
                'name' => [
                    'fa' => '32GB DDR4 PC4-2933Y ECC RDIMM',
                    'en' => '32GB DDR4 PC4-2933Y ECC RDIMM',
                    'tr' => '32GB DDR4 PC4-2933Y ECC RDIMM',
                ],
                'tagline' => [
                    'fa' => '32 گیگابایت DDR4 ECC — 2933 MT/s',
                    'en' => '32 GB DDR4 ECC — 2933 MT/s',
                    'tr' => '32 GB DDR4 ECC — 2933 MT/s',
                ],
                'summary' => [
                    'fa' => 'ماژول 32 گیگابایتی DDR4 ECC RDIMM با کد PC4-2933Y برای سرورهای Gen10.',
                    'en' => 'A 32 GB DDR4 ECC RDIMM module (PC4-2933Y) for Gen10 servers.',
                    'tr' => 'Gen10 sunucular için 32 GB DDR4 ECC RDIMM modül (PC4-2933Y).',
                ],
                'body' => [
                    'fa' => '32GB DDR4 PC4-2933Y ECC RDIMM یک ماژول حافظهٔ سرور با ظرفیت 32 گیگابایت، فناوری DDR4 و پشتیبانی ECC است. سرعت آن 2933 مگاترانسفر بر ثانیه و کد رسمی‌اش PC4-2933Y است. روی سرورهای HPE ProLiant Gen10 کار می‌کند.

RDIMM انتخاب استاندارد سرور است: بافر ثبات، تأخیر پایین‌تر از LRDIMM و پشتیبانی کامل ECC.

ECC یعنی خطای تک‌بیتی حافظه پیش از رسیدن به برنامه اصلاح می‌شود. روی یک ایستگاه کاری این یک ویژگی اضافه است؛ روی سروری که ماه‌ها بی‌ری‌استارت کار می‌کند، تفاوت بین «یک بیت پرید» و «پایگاه داده خراب شد» است.

یک نکتهٔ پیکربندی که زیاد از قلم می‌افتد: ماژول‌ها را کانال‌به‌کانال پر کنید، نه پشت سر هم. شش ماژول روی سه کانال، از شش ماژول روی یک کانال به‌مراتب سریع‌تر است — و ترتیب درست در راهنمای همان مدل سرور آمده. همچنین اگر ماژول‌های با سرعت متفاوت را قاطی کنید، همه با کندترین ماژول کار می‌کنند.',
                    'en' => 'The 32GB DDR4 PC4-2933Y ECC RDIMM is a 32 GB DDR4 ECC server memory module rated at 2933 MT/s, part code PC4-2933Y. It works in HPE ProLiant Gen10 servers.

RDIMM is the standard server choice: a registered buffer, lower latency than LRDIMM and full ECC support.

ECC means a single-bit memory error is corrected before it ever reaches your application. On a workstation that is a nice extra; on a server that runs for months without a reboot it is the difference between “a bit flipped” and “the database is corrupt”.

One configuration detail that is often missed: populate channel by channel, not slot by slot. Six modules across three channels are considerably faster than six modules on one channel — the correct order is in the server model’s own memory-population guide. Also, if you mix modules of different speeds they all run at the speed of the slowest one.',
                    'tr' => '32GB DDR4 PC4-2933Y ECC RDIMM, 2933 MT/s hızında 32 GB DDR4 ECC sunucu bellek modülüdür; parça kodu PC4-2933Y. HPE ProLiant Gen10 sunucularında çalışır.

RDIMM standart sunucu tercihidir: kayıtlı tampon, LRDIMM’den düşük gecikme ve tam ECC desteği.

ECC, tek bitlik bir bellek hatasının uygulamanıza ulaşmadan düzeltilmesi demektir. Bir iş istasyonunda bu hoş bir ekstradır; aylarca yeniden başlatılmadan çalışan bir sunucuda ise “bir bit değişti” ile “veritabanı bozuldu” arasındaki farktır.

Sık atlanan bir yapılandırma ayrıntısı: yuva yuva değil, kanal kanal doldurun. Üç kanala dağılmış altı modül, tek kanaldaki altı modülden belirgin şekilde hızlıdır — doğru sıra, sunucu modelinin bellek doldurma kılavuzundadır. Ayrıca farklı hızlarda modülleri karıştırırsanız hepsi en yavaş olanın hızında çalışır.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'ظرفیت',
                            'en' => 'Capacity',
                            'tr' => 'Kapasite',
                        ],
                        'value' => [
                            'fa' => '32 GB',
                            'en' => '32 GB',
                            'tr' => '32 GB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نوع',
                            'en' => 'Type',
                            'tr' => 'Tip',
                        ],
                        'value' => [
                            'fa' => 'DDR4 ECC RDIMM',
                            'en' => 'DDR4 ECC RDIMM',
                            'tr' => 'DDR4 ECC RDIMM',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سرعت',
                            'en' => 'Speed',
                            'tr' => 'Hız',
                        ],
                        'value' => [
                            'fa' => '2933 MT/s',
                            'en' => '2933 MT/s',
                            'tr' => '2933 MT/s',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کد قطعه',
                            'en' => 'Part code',
                            'tr' => 'Parça kodu',
                        ],
                        'value' => [
                            'fa' => 'PC4-2933Y',
                            'en' => 'PC4-2933Y',
                            'tr' => 'PC4-2933Y',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen10',
                            'en' => 'Gen10',
                            'tr' => 'Gen10',
                        ],
                    ],
                ],
                'attrs' => [
                    'gb' => 32,
                    'speed_mts' => 2933,
                ],
            ],
            [
                'slug' => 'ram-ddr4-64gb-2933r',
                'category' => 'ram',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen10',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 46218,
                'in_stock' => true,
                'popular' => false,
                'sort' => 420,
                'name' => [
                    'fa' => '64GB DDR4 PC4-2933Y ECC RDIMM',
                    'en' => '64GB DDR4 PC4-2933Y ECC RDIMM',
                    'tr' => '64GB DDR4 PC4-2933Y ECC RDIMM',
                ],
                'tagline' => [
                    'fa' => '64 گیگابایت DDR4 ECC — 2933 MT/s',
                    'en' => '64 GB DDR4 ECC — 2933 MT/s',
                    'tr' => '64 GB DDR4 ECC — 2933 MT/s',
                ],
                'summary' => [
                    'fa' => 'ماژول 64 گیگابایتی DDR4 ECC RDIMM با کد PC4-2933Y برای سرورهای Gen10.',
                    'en' => 'A 64 GB DDR4 ECC RDIMM module (PC4-2933Y) for Gen10 servers.',
                    'tr' => 'Gen10 sunucular için 64 GB DDR4 ECC RDIMM modül (PC4-2933Y).',
                ],
                'body' => [
                    'fa' => '64GB DDR4 PC4-2933Y ECC RDIMM یک ماژول حافظهٔ سرور با ظرفیت 64 گیگابایت، فناوری DDR4 و پشتیبانی ECC است. سرعت آن 2933 مگاترانسفر بر ثانیه و کد رسمی‌اش PC4-2933Y است. روی سرورهای HPE ProLiant Gen10 کار می‌کند.

RDIMM انتخاب استاندارد سرور است: بافر ثبات، تأخیر پایین‌تر از LRDIMM و پشتیبانی کامل ECC.

ECC یعنی خطای تک‌بیتی حافظه پیش از رسیدن به برنامه اصلاح می‌شود. روی یک ایستگاه کاری این یک ویژگی اضافه است؛ روی سروری که ماه‌ها بی‌ری‌استارت کار می‌کند، تفاوت بین «یک بیت پرید» و «پایگاه داده خراب شد» است.

یک نکتهٔ پیکربندی که زیاد از قلم می‌افتد: ماژول‌ها را کانال‌به‌کانال پر کنید، نه پشت سر هم. شش ماژول روی سه کانال، از شش ماژول روی یک کانال به‌مراتب سریع‌تر است — و ترتیب درست در راهنمای همان مدل سرور آمده. همچنین اگر ماژول‌های با سرعت متفاوت را قاطی کنید، همه با کندترین ماژول کار می‌کنند.',
                    'en' => 'The 64GB DDR4 PC4-2933Y ECC RDIMM is a 64 GB DDR4 ECC server memory module rated at 2933 MT/s, part code PC4-2933Y. It works in HPE ProLiant Gen10 servers.

RDIMM is the standard server choice: a registered buffer, lower latency than LRDIMM and full ECC support.

ECC means a single-bit memory error is corrected before it ever reaches your application. On a workstation that is a nice extra; on a server that runs for months without a reboot it is the difference between “a bit flipped” and “the database is corrupt”.

One configuration detail that is often missed: populate channel by channel, not slot by slot. Six modules across three channels are considerably faster than six modules on one channel — the correct order is in the server model’s own memory-population guide. Also, if you mix modules of different speeds they all run at the speed of the slowest one.',
                    'tr' => '64GB DDR4 PC4-2933Y ECC RDIMM, 2933 MT/s hızında 64 GB DDR4 ECC sunucu bellek modülüdür; parça kodu PC4-2933Y. HPE ProLiant Gen10 sunucularında çalışır.

RDIMM standart sunucu tercihidir: kayıtlı tampon, LRDIMM’den düşük gecikme ve tam ECC desteği.

ECC, tek bitlik bir bellek hatasının uygulamanıza ulaşmadan düzeltilmesi demektir. Bir iş istasyonunda bu hoş bir ekstradır; aylarca yeniden başlatılmadan çalışan bir sunucuda ise “bir bit değişti” ile “veritabanı bozuldu” arasındaki farktır.

Sık atlanan bir yapılandırma ayrıntısı: yuva yuva değil, kanal kanal doldurun. Üç kanala dağılmış altı modül, tek kanaldaki altı modülden belirgin şekilde hızlıdır — doğru sıra, sunucu modelinin bellek doldurma kılavuzundadır. Ayrıca farklı hızlarda modülleri karıştırırsanız hepsi en yavaş olanın hızında çalışır.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'ظرفیت',
                            'en' => 'Capacity',
                            'tr' => 'Kapasite',
                        ],
                        'value' => [
                            'fa' => '64 GB',
                            'en' => '64 GB',
                            'tr' => '64 GB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نوع',
                            'en' => 'Type',
                            'tr' => 'Tip',
                        ],
                        'value' => [
                            'fa' => 'DDR4 ECC RDIMM',
                            'en' => 'DDR4 ECC RDIMM',
                            'tr' => 'DDR4 ECC RDIMM',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سرعت',
                            'en' => 'Speed',
                            'tr' => 'Hız',
                        ],
                        'value' => [
                            'fa' => '2933 MT/s',
                            'en' => '2933 MT/s',
                            'tr' => '2933 MT/s',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کد قطعه',
                            'en' => 'Part code',
                            'tr' => 'Parça kodu',
                        ],
                        'value' => [
                            'fa' => 'PC4-2933Y',
                            'en' => 'PC4-2933Y',
                            'tr' => 'PC4-2933Y',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen10',
                            'en' => 'Gen10',
                            'tr' => 'Gen10',
                        ],
                    ],
                ],
                'attrs' => [
                    'gb' => 64,
                    'speed_mts' => 2933,
                ],
            ],
            [
                'slug' => 'ram-ddr4-32gb-3200r',
                'category' => 'ram',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen10',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 29411,
                'in_stock' => true,
                'popular' => false,
                'sort' => 430,
                'name' => [
                    'fa' => '32GB DDR4 PC4-3200AA ECC RDIMM',
                    'en' => '32GB DDR4 PC4-3200AA ECC RDIMM',
                    'tr' => '32GB DDR4 PC4-3200AA ECC RDIMM',
                ],
                'tagline' => [
                    'fa' => '32 گیگابایت DDR4 ECC — 3200 MT/s',
                    'en' => '32 GB DDR4 ECC — 3200 MT/s',
                    'tr' => '32 GB DDR4 ECC — 3200 MT/s',
                ],
                'summary' => [
                    'fa' => 'ماژول 32 گیگابایتی DDR4 ECC RDIMM با کد PC4-3200AA برای سرورهای Gen10.',
                    'en' => 'A 32 GB DDR4 ECC RDIMM module (PC4-3200AA) for Gen10 servers.',
                    'tr' => 'Gen10 sunucular için 32 GB DDR4 ECC RDIMM modül (PC4-3200AA).',
                ],
                'body' => [
                    'fa' => '32GB DDR4 PC4-3200AA ECC RDIMM یک ماژول حافظهٔ سرور با ظرفیت 32 گیگابایت، فناوری DDR4 و پشتیبانی ECC است. سرعت آن 3200 مگاترانسفر بر ثانیه و کد رسمی‌اش PC4-3200AA است. روی سرورهای HPE ProLiant Gen10 کار می‌کند.

RDIMM انتخاب استاندارد سرور است: بافر ثبات، تأخیر پایین‌تر از LRDIMM و پشتیبانی کامل ECC.

ECC یعنی خطای تک‌بیتی حافظه پیش از رسیدن به برنامه اصلاح می‌شود. روی یک ایستگاه کاری این یک ویژگی اضافه است؛ روی سروری که ماه‌ها بی‌ری‌استارت کار می‌کند، تفاوت بین «یک بیت پرید» و «پایگاه داده خراب شد» است.

یک نکتهٔ پیکربندی که زیاد از قلم می‌افتد: ماژول‌ها را کانال‌به‌کانال پر کنید، نه پشت سر هم. شش ماژول روی سه کانال، از شش ماژول روی یک کانال به‌مراتب سریع‌تر است — و ترتیب درست در راهنمای همان مدل سرور آمده. همچنین اگر ماژول‌های با سرعت متفاوت را قاطی کنید، همه با کندترین ماژول کار می‌کنند.',
                    'en' => 'The 32GB DDR4 PC4-3200AA ECC RDIMM is a 32 GB DDR4 ECC server memory module rated at 3200 MT/s, part code PC4-3200AA. It works in HPE ProLiant Gen10 servers.

RDIMM is the standard server choice: a registered buffer, lower latency than LRDIMM and full ECC support.

ECC means a single-bit memory error is corrected before it ever reaches your application. On a workstation that is a nice extra; on a server that runs for months without a reboot it is the difference between “a bit flipped” and “the database is corrupt”.

One configuration detail that is often missed: populate channel by channel, not slot by slot. Six modules across three channels are considerably faster than six modules on one channel — the correct order is in the server model’s own memory-population guide. Also, if you mix modules of different speeds they all run at the speed of the slowest one.',
                    'tr' => '32GB DDR4 PC4-3200AA ECC RDIMM, 3200 MT/s hızında 32 GB DDR4 ECC sunucu bellek modülüdür; parça kodu PC4-3200AA. HPE ProLiant Gen10 sunucularında çalışır.

RDIMM standart sunucu tercihidir: kayıtlı tampon, LRDIMM’den düşük gecikme ve tam ECC desteği.

ECC, tek bitlik bir bellek hatasının uygulamanıza ulaşmadan düzeltilmesi demektir. Bir iş istasyonunda bu hoş bir ekstradır; aylarca yeniden başlatılmadan çalışan bir sunucuda ise “bir bit değişti” ile “veritabanı bozuldu” arasındaki farktır.

Sık atlanan bir yapılandırma ayrıntısı: yuva yuva değil, kanal kanal doldurun. Üç kanala dağılmış altı modül, tek kanaldaki altı modülden belirgin şekilde hızlıdır — doğru sıra, sunucu modelinin bellek doldurma kılavuzundadır. Ayrıca farklı hızlarda modülleri karıştırırsanız hepsi en yavaş olanın hızında çalışır.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'ظرفیت',
                            'en' => 'Capacity',
                            'tr' => 'Kapasite',
                        ],
                        'value' => [
                            'fa' => '32 GB',
                            'en' => '32 GB',
                            'tr' => '32 GB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نوع',
                            'en' => 'Type',
                            'tr' => 'Tip',
                        ],
                        'value' => [
                            'fa' => 'DDR4 ECC RDIMM',
                            'en' => 'DDR4 ECC RDIMM',
                            'tr' => 'DDR4 ECC RDIMM',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سرعت',
                            'en' => 'Speed',
                            'tr' => 'Hız',
                        ],
                        'value' => [
                            'fa' => '3200 MT/s',
                            'en' => '3200 MT/s',
                            'tr' => '3200 MT/s',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کد قطعه',
                            'en' => 'Part code',
                            'tr' => 'Parça kodu',
                        ],
                        'value' => [
                            'fa' => 'PC4-3200AA',
                            'en' => 'PC4-3200AA',
                            'tr' => 'PC4-3200AA',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen10',
                            'en' => 'Gen10',
                            'tr' => 'Gen10',
                        ],
                    ],
                ],
                'attrs' => [
                    'gb' => 32,
                    'speed_mts' => 3200,
                ],
            ],
            [
                'slug' => 'ram-ddr5-32gb-4800r',
                'category' => 'ram',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen11',
                ],
                'condition' => 'new',
                'price_contact' => true,
                'price_eur' => null,
                'in_stock' => true,
                'popular' => false,
                'sort' => 440,
                'name' => [
                    'fa' => '32GB DDR5 PC5-4800B ECC RDIMM',
                    'en' => '32GB DDR5 PC5-4800B ECC RDIMM',
                    'tr' => '32GB DDR5 PC5-4800B ECC RDIMM',
                ],
                'tagline' => [
                    'fa' => '32 گیگابایت DDR5 ECC — 4800 MT/s',
                    'en' => '32 GB DDR5 ECC — 4800 MT/s',
                    'tr' => '32 GB DDR5 ECC — 4800 MT/s',
                ],
                'summary' => [
                    'fa' => 'ماژول 32 گیگابایتی DDR5 ECC RDIMM با کد PC5-4800B برای سرورهای Gen11.',
                    'en' => 'A 32 GB DDR5 ECC RDIMM module (PC5-4800B) for Gen11 servers.',
                    'tr' => 'Gen11 sunucular için 32 GB DDR5 ECC RDIMM modül (PC5-4800B).',
                ],
                'body' => [
                    'fa' => '32GB DDR5 PC5-4800B ECC RDIMM یک ماژول حافظهٔ سرور با ظرفیت 32 گیگابایت، فناوری DDR5 و پشتیبانی ECC است. سرعت آن 4800 مگاترانسفر بر ثانیه و کد رسمی‌اش PC5-4800B است. روی سرورهای HPE ProLiant Gen11 کار می‌کند.

RDIMM انتخاب استاندارد سرور است: بافر ثبات، تأخیر پایین‌تر از LRDIMM و پشتیبانی کامل ECC.

ECC یعنی خطای تک‌بیتی حافظه پیش از رسیدن به برنامه اصلاح می‌شود. روی یک ایستگاه کاری این یک ویژگی اضافه است؛ روی سروری که ماه‌ها بی‌ری‌استارت کار می‌کند، تفاوت بین «یک بیت پرید» و «پایگاه داده خراب شد» است.

یک نکتهٔ پیکربندی که زیاد از قلم می‌افتد: ماژول‌ها را کانال‌به‌کانال پر کنید، نه پشت سر هم. شش ماژول روی سه کانال، از شش ماژول روی یک کانال به‌مراتب سریع‌تر است — و ترتیب درست در راهنمای همان مدل سرور آمده. همچنین اگر ماژول‌های با سرعت متفاوت را قاطی کنید، همه با کندترین ماژول کار می‌کنند.',
                    'en' => 'The 32GB DDR5 PC5-4800B ECC RDIMM is a 32 GB DDR5 ECC server memory module rated at 4800 MT/s, part code PC5-4800B. It works in HPE ProLiant Gen11 servers.

RDIMM is the standard server choice: a registered buffer, lower latency than LRDIMM and full ECC support.

ECC means a single-bit memory error is corrected before it ever reaches your application. On a workstation that is a nice extra; on a server that runs for months without a reboot it is the difference between “a bit flipped” and “the database is corrupt”.

One configuration detail that is often missed: populate channel by channel, not slot by slot. Six modules across three channels are considerably faster than six modules on one channel — the correct order is in the server model’s own memory-population guide. Also, if you mix modules of different speeds they all run at the speed of the slowest one.',
                    'tr' => '32GB DDR5 PC5-4800B ECC RDIMM, 4800 MT/s hızında 32 GB DDR5 ECC sunucu bellek modülüdür; parça kodu PC5-4800B. HPE ProLiant Gen11 sunucularında çalışır.

RDIMM standart sunucu tercihidir: kayıtlı tampon, LRDIMM’den düşük gecikme ve tam ECC desteği.

ECC, tek bitlik bir bellek hatasının uygulamanıza ulaşmadan düzeltilmesi demektir. Bir iş istasyonunda bu hoş bir ekstradır; aylarca yeniden başlatılmadan çalışan bir sunucuda ise “bir bit değişti” ile “veritabanı bozuldu” arasındaki farktır.

Sık atlanan bir yapılandırma ayrıntısı: yuva yuva değil, kanal kanal doldurun. Üç kanala dağılmış altı modül, tek kanaldaki altı modülden belirgin şekilde hızlıdır — doğru sıra, sunucu modelinin bellek doldurma kılavuzundadır. Ayrıca farklı hızlarda modülleri karıştırırsanız hepsi en yavaş olanın hızında çalışır.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'ظرفیت',
                            'en' => 'Capacity',
                            'tr' => 'Kapasite',
                        ],
                        'value' => [
                            'fa' => '32 GB',
                            'en' => '32 GB',
                            'tr' => '32 GB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نوع',
                            'en' => 'Type',
                            'tr' => 'Tip',
                        ],
                        'value' => [
                            'fa' => 'DDR5 ECC RDIMM',
                            'en' => 'DDR5 ECC RDIMM',
                            'tr' => 'DDR5 ECC RDIMM',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سرعت',
                            'en' => 'Speed',
                            'tr' => 'Hız',
                        ],
                        'value' => [
                            'fa' => '4800 MT/s',
                            'en' => '4800 MT/s',
                            'tr' => '4800 MT/s',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کد قطعه',
                            'en' => 'Part code',
                            'tr' => 'Parça kodu',
                        ],
                        'value' => [
                            'fa' => 'PC5-4800B',
                            'en' => 'PC5-4800B',
                            'tr' => 'PC5-4800B',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen11',
                            'en' => 'Gen11',
                            'tr' => 'Gen11',
                        ],
                    ],
                ],
                'attrs' => [
                    'gb' => 32,
                    'speed_mts' => 4800,
                ],
            ],
            [
                'slug' => 'ram-ddr5-64gb-5600r',
                'category' => 'ram',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen11',
                    'gen12',
                ],
                'condition' => 'new',
                'price_contact' => true,
                'price_eur' => null,
                'in_stock' => true,
                'popular' => false,
                'sort' => 450,
                'name' => [
                    'fa' => '64GB DDR5 PC5-5600B ECC RDIMM',
                    'en' => '64GB DDR5 PC5-5600B ECC RDIMM',
                    'tr' => '64GB DDR5 PC5-5600B ECC RDIMM',
                ],
                'tagline' => [
                    'fa' => '64 گیگابایت DDR5 ECC — 5600 MT/s',
                    'en' => '64 GB DDR5 ECC — 5600 MT/s',
                    'tr' => '64 GB DDR5 ECC — 5600 MT/s',
                ],
                'summary' => [
                    'fa' => 'ماژول 64 گیگابایتی DDR5 ECC RDIMM با کد PC5-5600B برای سرورهای Gen11 / Gen12.',
                    'en' => 'A 64 GB DDR5 ECC RDIMM module (PC5-5600B) for Gen11 / Gen12 servers.',
                    'tr' => 'Gen11 / Gen12 sunucular için 64 GB DDR5 ECC RDIMM modül (PC5-5600B).',
                ],
                'body' => [
                    'fa' => '64GB DDR5 PC5-5600B ECC RDIMM یک ماژول حافظهٔ سرور با ظرفیت 64 گیگابایت، فناوری DDR5 و پشتیبانی ECC است. سرعت آن 5600 مگاترانسفر بر ثانیه و کد رسمی‌اش PC5-5600B است. روی سرورهای HPE ProLiant Gen11 / Gen12 کار می‌کند.

RDIMM انتخاب استاندارد سرور است: بافر ثبات، تأخیر پایین‌تر از LRDIMM و پشتیبانی کامل ECC.

ECC یعنی خطای تک‌بیتی حافظه پیش از رسیدن به برنامه اصلاح می‌شود. روی یک ایستگاه کاری این یک ویژگی اضافه است؛ روی سروری که ماه‌ها بی‌ری‌استارت کار می‌کند، تفاوت بین «یک بیت پرید» و «پایگاه داده خراب شد» است.

یک نکتهٔ پیکربندی که زیاد از قلم می‌افتد: ماژول‌ها را کانال‌به‌کانال پر کنید، نه پشت سر هم. شش ماژول روی سه کانال، از شش ماژول روی یک کانال به‌مراتب سریع‌تر است — و ترتیب درست در راهنمای همان مدل سرور آمده. همچنین اگر ماژول‌های با سرعت متفاوت را قاطی کنید، همه با کندترین ماژول کار می‌کنند.',
                    'en' => 'The 64GB DDR5 PC5-5600B ECC RDIMM is a 64 GB DDR5 ECC server memory module rated at 5600 MT/s, part code PC5-5600B. It works in HPE ProLiant Gen11 / Gen12 servers.

RDIMM is the standard server choice: a registered buffer, lower latency than LRDIMM and full ECC support.

ECC means a single-bit memory error is corrected before it ever reaches your application. On a workstation that is a nice extra; on a server that runs for months without a reboot it is the difference between “a bit flipped” and “the database is corrupt”.

One configuration detail that is often missed: populate channel by channel, not slot by slot. Six modules across three channels are considerably faster than six modules on one channel — the correct order is in the server model’s own memory-population guide. Also, if you mix modules of different speeds they all run at the speed of the slowest one.',
                    'tr' => '64GB DDR5 PC5-5600B ECC RDIMM, 5600 MT/s hızında 64 GB DDR5 ECC sunucu bellek modülüdür; parça kodu PC5-5600B. HPE ProLiant Gen11 / Gen12 sunucularında çalışır.

RDIMM standart sunucu tercihidir: kayıtlı tampon, LRDIMM’den düşük gecikme ve tam ECC desteği.

ECC, tek bitlik bir bellek hatasının uygulamanıza ulaşmadan düzeltilmesi demektir. Bir iş istasyonunda bu hoş bir ekstradır; aylarca yeniden başlatılmadan çalışan bir sunucuda ise “bir bit değişti” ile “veritabanı bozuldu” arasındaki farktır.

Sık atlanan bir yapılandırma ayrıntısı: yuva yuva değil, kanal kanal doldurun. Üç kanala dağılmış altı modül, tek kanaldaki altı modülden belirgin şekilde hızlıdır — doğru sıra, sunucu modelinin bellek doldurma kılavuzundadır. Ayrıca farklı hızlarda modülleri karıştırırsanız hepsi en yavaş olanın hızında çalışır.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'ظرفیت',
                            'en' => 'Capacity',
                            'tr' => 'Kapasite',
                        ],
                        'value' => [
                            'fa' => '64 GB',
                            'en' => '64 GB',
                            'tr' => '64 GB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نوع',
                            'en' => 'Type',
                            'tr' => 'Tip',
                        ],
                        'value' => [
                            'fa' => 'DDR5 ECC RDIMM',
                            'en' => 'DDR5 ECC RDIMM',
                            'tr' => 'DDR5 ECC RDIMM',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سرعت',
                            'en' => 'Speed',
                            'tr' => 'Hız',
                        ],
                        'value' => [
                            'fa' => '5600 MT/s',
                            'en' => '5600 MT/s',
                            'tr' => '5600 MT/s',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'کد قطعه',
                            'en' => 'Part code',
                            'tr' => 'Parça kodu',
                        ],
                        'value' => [
                            'fa' => 'PC5-5600B',
                            'en' => 'PC5-5600B',
                            'tr' => 'PC5-5600B',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen11 / Gen12',
                            'en' => 'Gen11 / Gen12',
                            'tr' => 'Gen11 / Gen12',
                        ],
                    ],
                ],
                'attrs' => [
                    'gb' => 64,
                    'speed_mts' => 5600,
                ],
            ],
            [
                'slug' => 'hdd-sas-300gb-10k',
                'category' => 'disk',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen8',
                    'gen9',
                    'gen10',
                    'gen11',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 3361,
                'in_stock' => true,
                'popular' => false,
                'sort' => 460,
                'name' => [
                    'fa' => 'HPE 300GB 12G 10K SAS 2.5" SFF',
                    'en' => 'HPE 300GB 12G 10K SAS 2.5" SFF',
                    'tr' => 'HPE 300GB 12G 10K SAS 2.5" SFF',
                ],
                'tagline' => [
                    'fa' => '300 GB · SAS 12G · 10K',
                    'en' => '300 GB · SAS 12G · 10K',
                    'tr' => '300 GB · SAS 12G · 10K',
                ],
                'summary' => [
                    'fa' => 'دیسک مکانیکی 10K دور با ظرفیت 300 GB و رابط SAS 12G برای سرورهای Gen8 / Gen9 / Gen10 / Gen11.',
                    'en' => 'A 300 GB 10K rpm mechanical drive on a SAS 12G interface for Gen8 / Gen9 / Gen10 / Gen11 servers.',
                    'tr' => 'Gen8 / Gen9 / Gen10 / Gen11 sunucular için SAS 12G arayüzünde 300 GB 10K devir mekanik disk.',
                ],
                'body' => [
                    'fa' => 'HPE 300GB 12G 10K SAS 2.5" SFF یک دیسک مکانیکی 10K دور با ظرفیت 300 GB و رابط SAS 12G است که روی سرورهای HPE ProLiant Gen8 / Gen9 / Gen10 / Gen11 نصب می‌شود.

برای بایگانی، پشتیبان‌گیری و فایل‌های بزرگ، دیسک مکانیکی هنوز ارزان‌ترین گیگابایت را می‌دهد. برای دیسک سیستم‌عامل یا پایگاه داده نه — آن‌جا تأخیر گلوگاه می‌شود.

دو نکتهٔ عملی: اول اینکه دیسک باید با کدی (caddy) همان نسل بیاید — کدی نسل ۸ تا ۱۰ با کدی Basic Carrier نسل ۱۰ به بعد جابه‌جا نمی‌شود و دیسک بی‌کدی در جایگاه هات‌پلاگ نمی‌نشیند. دوم اینکه در آرایهٔ RAID، همهٔ اعضا باید ظرفیت و سرعت یکسان داشته باشند؛ اگر یکی کوچک‌تر باشد، کنترلر همهٔ دیسک‌ها را به اندازهٔ کوچک‌ترین می‌بیند.

هر دیسک پیش از ارسال SMART و تست سطح می‌شود و ساعت کارکردش اعلام می‌گردد.',
                    'en' => 'The HPE 300GB 12G 10K SAS 2.5" SFF is a 300 GB 10K rpm mechanical drive on a SAS 12G interface, for HPE ProLiant Gen8 / Gen9 / Gen10 / Gen11 servers.

For archives, backups and large files a mechanical drive still delivers the cheapest gigabyte. Not for an OS volume or a database, where latency becomes the bottleneck.

Two practical notes. First, the drive has to come in the caddy for its generation — the Gen8–Gen10 Smart Carrier and the Gen10-and-later Basic Carrier are not interchangeable, and a bare drive will not seat in a hot-plug bay. Second, in a RAID array every member should have the same capacity and speed; if one is smaller, the controller treats every disk as if it were that size.

Every drive is SMART-checked and surface-tested before it ships, and its power-on hours are disclosed.',
                    'tr' => 'HPE 300GB 12G 10K SAS 2.5" SFF, HPE ProLiant Gen8 / Gen9 / Gen10 / Gen11 sunucuları için SAS 12G arayüzünde 300 GB kapasiteli bir 10K devir mekanik diskdir.

Arşiv, yedekleme ve büyük dosyalar için mekanik disk hâlâ en ucuz gigabaytı verir. İşletim sistemi birimi veya veritabanı için değil — orada gecikme darboğaz olur.

İki pratik not: Birincisi, disk kendi nesline ait kızakla gelmelidir — Gen8–Gen10 Smart Carrier ile Gen10 ve sonrası Basic Carrier birbirinin yerine geçmez ve kızaksız disk hot-plug yuvasına oturmaz. İkincisi, RAID dizisinde her üye aynı kapasite ve hızda olmalıdır; biri küçükse denetleyici tüm diskleri o boyutta görür.

Her disk sevkiyattan önce SMART ve yüzey testinden geçer, çalışma saati bildirilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'ظرفیت',
                            'en' => 'Capacity',
                            'tr' => 'Kapasite',
                        ],
                        'value' => [
                            'fa' => '300 GB',
                            'en' => '300 GB',
                            'tr' => '300 GB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'رابط',
                            'en' => 'Interface',
                            'tr' => 'Arayüz',
                        ],
                        'value' => [
                            'fa' => 'SAS 12G',
                            'en' => 'SAS 12G',
                            'tr' => 'SAS 12G',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نوع',
                            'en' => 'Type',
                            'tr' => 'Tip',
                        ],
                        'value' => [
                            'fa' => '10K rpm',
                            'en' => '10K rpm',
                            'tr' => '10K rpm',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'IOPS تخمینی',
                            'en' => 'Estimated IOPS',
                            'tr' => 'Tahmini IOPS',
                        ],
                        'value' => [
                            'fa' => '180',
                            'en' => '180',
                            'tr' => '180',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen8 / Gen9 / Gen10 / Gen11',
                            'en' => 'Gen8 / Gen9 / Gen10 / Gen11',
                            'tr' => 'Gen8 / Gen9 / Gen10 / Gen11',
                        ],
                    ],
                ],
                'attrs' => [
                    'gb' => 300,
                    'iops' => 180,
                ],
            ],
            [
                'slug' => 'hdd-sas-600gb-10k',
                'category' => 'disk',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen8',
                    'gen9',
                    'gen10',
                    'gen11',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 3781,
                'in_stock' => true,
                'popular' => true,
                'sort' => 470,
                'name' => [
                    'fa' => 'HPE 600GB 12G 10K SAS 2.5" SFF',
                    'en' => 'HPE 600GB 12G 10K SAS 2.5" SFF',
                    'tr' => 'HPE 600GB 12G 10K SAS 2.5" SFF',
                ],
                'tagline' => [
                    'fa' => '600 GB · SAS 12G · 10K',
                    'en' => '600 GB · SAS 12G · 10K',
                    'tr' => '600 GB · SAS 12G · 10K',
                ],
                'summary' => [
                    'fa' => 'دیسک مکانیکی 10K دور با ظرفیت 600 GB و رابط SAS 12G برای سرورهای Gen8 / Gen9 / Gen10 / Gen11.',
                    'en' => 'A 600 GB 10K rpm mechanical drive on a SAS 12G interface for Gen8 / Gen9 / Gen10 / Gen11 servers.',
                    'tr' => 'Gen8 / Gen9 / Gen10 / Gen11 sunucular için SAS 12G arayüzünde 600 GB 10K devir mekanik disk.',
                ],
                'body' => [
                    'fa' => 'HPE 600GB 12G 10K SAS 2.5" SFF یک دیسک مکانیکی 10K دور با ظرفیت 600 GB و رابط SAS 12G است که روی سرورهای HPE ProLiant Gen8 / Gen9 / Gen10 / Gen11 نصب می‌شود.

برای بایگانی، پشتیبان‌گیری و فایل‌های بزرگ، دیسک مکانیکی هنوز ارزان‌ترین گیگابایت را می‌دهد. برای دیسک سیستم‌عامل یا پایگاه داده نه — آن‌جا تأخیر گلوگاه می‌شود.

دو نکتهٔ عملی: اول اینکه دیسک باید با کدی (caddy) همان نسل بیاید — کدی نسل ۸ تا ۱۰ با کدی Basic Carrier نسل ۱۰ به بعد جابه‌جا نمی‌شود و دیسک بی‌کدی در جایگاه هات‌پلاگ نمی‌نشیند. دوم اینکه در آرایهٔ RAID، همهٔ اعضا باید ظرفیت و سرعت یکسان داشته باشند؛ اگر یکی کوچک‌تر باشد، کنترلر همهٔ دیسک‌ها را به اندازهٔ کوچک‌ترین می‌بیند.

هر دیسک پیش از ارسال SMART و تست سطح می‌شود و ساعت کارکردش اعلام می‌گردد.',
                    'en' => 'The HPE 600GB 12G 10K SAS 2.5" SFF is a 600 GB 10K rpm mechanical drive on a SAS 12G interface, for HPE ProLiant Gen8 / Gen9 / Gen10 / Gen11 servers.

For archives, backups and large files a mechanical drive still delivers the cheapest gigabyte. Not for an OS volume or a database, where latency becomes the bottleneck.

Two practical notes. First, the drive has to come in the caddy for its generation — the Gen8–Gen10 Smart Carrier and the Gen10-and-later Basic Carrier are not interchangeable, and a bare drive will not seat in a hot-plug bay. Second, in a RAID array every member should have the same capacity and speed; if one is smaller, the controller treats every disk as if it were that size.

Every drive is SMART-checked and surface-tested before it ships, and its power-on hours are disclosed.',
                    'tr' => 'HPE 600GB 12G 10K SAS 2.5" SFF, HPE ProLiant Gen8 / Gen9 / Gen10 / Gen11 sunucuları için SAS 12G arayüzünde 600 GB kapasiteli bir 10K devir mekanik diskdir.

Arşiv, yedekleme ve büyük dosyalar için mekanik disk hâlâ en ucuz gigabaytı verir. İşletim sistemi birimi veya veritabanı için değil — orada gecikme darboğaz olur.

İki pratik not: Birincisi, disk kendi nesline ait kızakla gelmelidir — Gen8–Gen10 Smart Carrier ile Gen10 ve sonrası Basic Carrier birbirinin yerine geçmez ve kızaksız disk hot-plug yuvasına oturmaz. İkincisi, RAID dizisinde her üye aynı kapasite ve hızda olmalıdır; biri küçükse denetleyici tüm diskleri o boyutta görür.

Her disk sevkiyattan önce SMART ve yüzey testinden geçer, çalışma saati bildirilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'ظرفیت',
                            'en' => 'Capacity',
                            'tr' => 'Kapasite',
                        ],
                        'value' => [
                            'fa' => '600 GB',
                            'en' => '600 GB',
                            'tr' => '600 GB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'رابط',
                            'en' => 'Interface',
                            'tr' => 'Arayüz',
                        ],
                        'value' => [
                            'fa' => 'SAS 12G',
                            'en' => 'SAS 12G',
                            'tr' => 'SAS 12G',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نوع',
                            'en' => 'Type',
                            'tr' => 'Tip',
                        ],
                        'value' => [
                            'fa' => '10K rpm',
                            'en' => '10K rpm',
                            'tr' => '10K rpm',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'IOPS تخمینی',
                            'en' => 'Estimated IOPS',
                            'tr' => 'Tahmini IOPS',
                        ],
                        'value' => [
                            'fa' => '180',
                            'en' => '180',
                            'tr' => '180',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen8 / Gen9 / Gen10 / Gen11',
                            'en' => 'Gen8 / Gen9 / Gen10 / Gen11',
                            'tr' => 'Gen8 / Gen9 / Gen10 / Gen11',
                        ],
                    ],
                ],
                'attrs' => [
                    'gb' => 600,
                    'iops' => 180,
                ],
            ],
            [
                'slug' => 'hdd-sas-1200gb-10k',
                'category' => 'disk',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen8',
                    'gen9',
                    'gen10',
                    'gen11',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 5882,
                'in_stock' => true,
                'popular' => true,
                'sort' => 480,
                'name' => [
                    'fa' => 'HPE 1.2TB 12G 10K SAS 2.5" SFF',
                    'en' => 'HPE 1.2TB 12G 10K SAS 2.5" SFF',
                    'tr' => 'HPE 1.2TB 12G 10K SAS 2.5" SFF',
                ],
                'tagline' => [
                    'fa' => '1.2 TB · SAS 12G · 10K',
                    'en' => '1.2 TB · SAS 12G · 10K',
                    'tr' => '1.2 TB · SAS 12G · 10K',
                ],
                'summary' => [
                    'fa' => 'دیسک مکانیکی 10K دور با ظرفیت 1.2 TB و رابط SAS 12G برای سرورهای Gen8 / Gen9 / Gen10 / Gen11.',
                    'en' => 'A 1.2 TB 10K rpm mechanical drive on a SAS 12G interface for Gen8 / Gen9 / Gen10 / Gen11 servers.',
                    'tr' => 'Gen8 / Gen9 / Gen10 / Gen11 sunucular için SAS 12G arayüzünde 1.2 TB 10K devir mekanik disk.',
                ],
                'body' => [
                    'fa' => 'HPE 1.2TB 12G 10K SAS 2.5" SFF یک دیسک مکانیکی 10K دور با ظرفیت 1.2 TB و رابط SAS 12G است که روی سرورهای HPE ProLiant Gen8 / Gen9 / Gen10 / Gen11 نصب می‌شود.

برای بایگانی، پشتیبان‌گیری و فایل‌های بزرگ، دیسک مکانیکی هنوز ارزان‌ترین گیگابایت را می‌دهد. برای دیسک سیستم‌عامل یا پایگاه داده نه — آن‌جا تأخیر گلوگاه می‌شود.

دو نکتهٔ عملی: اول اینکه دیسک باید با کدی (caddy) همان نسل بیاید — کدی نسل ۸ تا ۱۰ با کدی Basic Carrier نسل ۱۰ به بعد جابه‌جا نمی‌شود و دیسک بی‌کدی در جایگاه هات‌پلاگ نمی‌نشیند. دوم اینکه در آرایهٔ RAID، همهٔ اعضا باید ظرفیت و سرعت یکسان داشته باشند؛ اگر یکی کوچک‌تر باشد، کنترلر همهٔ دیسک‌ها را به اندازهٔ کوچک‌ترین می‌بیند.

هر دیسک پیش از ارسال SMART و تست سطح می‌شود و ساعت کارکردش اعلام می‌گردد.',
                    'en' => 'The HPE 1.2TB 12G 10K SAS 2.5" SFF is a 1.2 TB 10K rpm mechanical drive on a SAS 12G interface, for HPE ProLiant Gen8 / Gen9 / Gen10 / Gen11 servers.

For archives, backups and large files a mechanical drive still delivers the cheapest gigabyte. Not for an OS volume or a database, where latency becomes the bottleneck.

Two practical notes. First, the drive has to come in the caddy for its generation — the Gen8–Gen10 Smart Carrier and the Gen10-and-later Basic Carrier are not interchangeable, and a bare drive will not seat in a hot-plug bay. Second, in a RAID array every member should have the same capacity and speed; if one is smaller, the controller treats every disk as if it were that size.

Every drive is SMART-checked and surface-tested before it ships, and its power-on hours are disclosed.',
                    'tr' => 'HPE 1.2TB 12G 10K SAS 2.5" SFF, HPE ProLiant Gen8 / Gen9 / Gen10 / Gen11 sunucuları için SAS 12G arayüzünde 1.2 TB kapasiteli bir 10K devir mekanik diskdir.

Arşiv, yedekleme ve büyük dosyalar için mekanik disk hâlâ en ucuz gigabaytı verir. İşletim sistemi birimi veya veritabanı için değil — orada gecikme darboğaz olur.

İki pratik not: Birincisi, disk kendi nesline ait kızakla gelmelidir — Gen8–Gen10 Smart Carrier ile Gen10 ve sonrası Basic Carrier birbirinin yerine geçmez ve kızaksız disk hot-plug yuvasına oturmaz. İkincisi, RAID dizisinde her üye aynı kapasite ve hızda olmalıdır; biri küçükse denetleyici tüm diskleri o boyutta görür.

Her disk sevkiyattan önce SMART ve yüzey testinden geçer, çalışma saati bildirilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'ظرفیت',
                            'en' => 'Capacity',
                            'tr' => 'Kapasite',
                        ],
                        'value' => [
                            'fa' => '1.2 TB',
                            'en' => '1.2 TB',
                            'tr' => '1.2 TB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'رابط',
                            'en' => 'Interface',
                            'tr' => 'Arayüz',
                        ],
                        'value' => [
                            'fa' => 'SAS 12G',
                            'en' => 'SAS 12G',
                            'tr' => 'SAS 12G',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نوع',
                            'en' => 'Type',
                            'tr' => 'Tip',
                        ],
                        'value' => [
                            'fa' => '10K rpm',
                            'en' => '10K rpm',
                            'tr' => '10K rpm',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'IOPS تخمینی',
                            'en' => 'Estimated IOPS',
                            'tr' => 'Tahmini IOPS',
                        ],
                        'value' => [
                            'fa' => '180',
                            'en' => '180',
                            'tr' => '180',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen8 / Gen9 / Gen10 / Gen11',
                            'en' => 'Gen8 / Gen9 / Gen10 / Gen11',
                            'tr' => 'Gen8 / Gen9 / Gen10 / Gen11',
                        ],
                    ],
                ],
                'attrs' => [
                    'gb' => 1200,
                    'iops' => 180,
                ],
            ],
            [
                'slug' => 'hdd-sas-1800gb-10k',
                'category' => 'disk',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen9',
                    'gen10',
                    'gen11',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 6722,
                'in_stock' => true,
                'popular' => false,
                'sort' => 490,
                'name' => [
                    'fa' => 'HPE 1.8TB 12G 10K SAS 2.5" SFF',
                    'en' => 'HPE 1.8TB 12G 10K SAS 2.5" SFF',
                    'tr' => 'HPE 1.8TB 12G 10K SAS 2.5" SFF',
                ],
                'tagline' => [
                    'fa' => '1.8 TB · SAS 12G · 10K',
                    'en' => '1.8 TB · SAS 12G · 10K',
                    'tr' => '1.8 TB · SAS 12G · 10K',
                ],
                'summary' => [
                    'fa' => 'دیسک مکانیکی 10K دور با ظرفیت 1.8 TB و رابط SAS 12G برای سرورهای Gen9 / Gen10 / Gen11.',
                    'en' => 'A 1.8 TB 10K rpm mechanical drive on a SAS 12G interface for Gen9 / Gen10 / Gen11 servers.',
                    'tr' => 'Gen9 / Gen10 / Gen11 sunucular için SAS 12G arayüzünde 1.8 TB 10K devir mekanik disk.',
                ],
                'body' => [
                    'fa' => 'HPE 1.8TB 12G 10K SAS 2.5" SFF یک دیسک مکانیکی 10K دور با ظرفیت 1.8 TB و رابط SAS 12G است که روی سرورهای HPE ProLiant Gen9 / Gen10 / Gen11 نصب می‌شود.

برای بایگانی، پشتیبان‌گیری و فایل‌های بزرگ، دیسک مکانیکی هنوز ارزان‌ترین گیگابایت را می‌دهد. برای دیسک سیستم‌عامل یا پایگاه داده نه — آن‌جا تأخیر گلوگاه می‌شود.

دو نکتهٔ عملی: اول اینکه دیسک باید با کدی (caddy) همان نسل بیاید — کدی نسل ۸ تا ۱۰ با کدی Basic Carrier نسل ۱۰ به بعد جابه‌جا نمی‌شود و دیسک بی‌کدی در جایگاه هات‌پلاگ نمی‌نشیند. دوم اینکه در آرایهٔ RAID، همهٔ اعضا باید ظرفیت و سرعت یکسان داشته باشند؛ اگر یکی کوچک‌تر باشد، کنترلر همهٔ دیسک‌ها را به اندازهٔ کوچک‌ترین می‌بیند.

هر دیسک پیش از ارسال SMART و تست سطح می‌شود و ساعت کارکردش اعلام می‌گردد.',
                    'en' => 'The HPE 1.8TB 12G 10K SAS 2.5" SFF is a 1.8 TB 10K rpm mechanical drive on a SAS 12G interface, for HPE ProLiant Gen9 / Gen10 / Gen11 servers.

For archives, backups and large files a mechanical drive still delivers the cheapest gigabyte. Not for an OS volume or a database, where latency becomes the bottleneck.

Two practical notes. First, the drive has to come in the caddy for its generation — the Gen8–Gen10 Smart Carrier and the Gen10-and-later Basic Carrier are not interchangeable, and a bare drive will not seat in a hot-plug bay. Second, in a RAID array every member should have the same capacity and speed; if one is smaller, the controller treats every disk as if it were that size.

Every drive is SMART-checked and surface-tested before it ships, and its power-on hours are disclosed.',
                    'tr' => 'HPE 1.8TB 12G 10K SAS 2.5" SFF, HPE ProLiant Gen9 / Gen10 / Gen11 sunucuları için SAS 12G arayüzünde 1.8 TB kapasiteli bir 10K devir mekanik diskdir.

Arşiv, yedekleme ve büyük dosyalar için mekanik disk hâlâ en ucuz gigabaytı verir. İşletim sistemi birimi veya veritabanı için değil — orada gecikme darboğaz olur.

İki pratik not: Birincisi, disk kendi nesline ait kızakla gelmelidir — Gen8–Gen10 Smart Carrier ile Gen10 ve sonrası Basic Carrier birbirinin yerine geçmez ve kızaksız disk hot-plug yuvasına oturmaz. İkincisi, RAID dizisinde her üye aynı kapasite ve hızda olmalıdır; biri küçükse denetleyici tüm diskleri o boyutta görür.

Her disk sevkiyattan önce SMART ve yüzey testinden geçer, çalışma saati bildirilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'ظرفیت',
                            'en' => 'Capacity',
                            'tr' => 'Kapasite',
                        ],
                        'value' => [
                            'fa' => '1.8 TB',
                            'en' => '1.8 TB',
                            'tr' => '1.8 TB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'رابط',
                            'en' => 'Interface',
                            'tr' => 'Arayüz',
                        ],
                        'value' => [
                            'fa' => 'SAS 12G',
                            'en' => 'SAS 12G',
                            'tr' => 'SAS 12G',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نوع',
                            'en' => 'Type',
                            'tr' => 'Tip',
                        ],
                        'value' => [
                            'fa' => '10K rpm',
                            'en' => '10K rpm',
                            'tr' => '10K rpm',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'IOPS تخمینی',
                            'en' => 'Estimated IOPS',
                            'tr' => 'Tahmini IOPS',
                        ],
                        'value' => [
                            'fa' => '180',
                            'en' => '180',
                            'tr' => '180',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen9 / Gen10 / Gen11',
                            'en' => 'Gen9 / Gen10 / Gen11',
                            'tr' => 'Gen9 / Gen10 / Gen11',
                        ],
                    ],
                ],
                'attrs' => [
                    'gb' => 1800,
                    'iops' => 180,
                ],
            ],
            [
                'slug' => 'hdd-sas-1tb-7k2',
                'category' => 'disk',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen8',
                    'gen9',
                    'gen10',
                    'gen11',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 4621,
                'in_stock' => true,
                'popular' => false,
                'sort' => 500,
                'name' => [
                    'fa' => 'HPE 1TB 12G 7.2K SAS 2.5" SFF',
                    'en' => 'HPE 1TB 12G 7.2K SAS 2.5" SFF',
                    'tr' => 'HPE 1TB 12G 7.2K SAS 2.5" SFF',
                ],
                'tagline' => [
                    'fa' => '1 TB · SAS 12G · 7.2K',
                    'en' => '1 TB · SAS 12G · 7.2K',
                    'tr' => '1 TB · SAS 12G · 7.2K',
                ],
                'summary' => [
                    'fa' => 'دیسک مکانیکی 7.2K دور با ظرفیت 1 TB و رابط SAS 12G برای سرورهای Gen8 / Gen9 / Gen10 / Gen11.',
                    'en' => 'A 1 TB 7.2K rpm mechanical drive on a SAS 12G interface for Gen8 / Gen9 / Gen10 / Gen11 servers.',
                    'tr' => 'Gen8 / Gen9 / Gen10 / Gen11 sunucular için SAS 12G arayüzünde 1 TB 7.2K devir mekanik disk.',
                ],
                'body' => [
                    'fa' => 'HPE 1TB 12G 7.2K SAS 2.5" SFF یک دیسک مکانیکی 7.2K دور با ظرفیت 1 TB و رابط SAS 12G است که روی سرورهای HPE ProLiant Gen8 / Gen9 / Gen10 / Gen11 نصب می‌شود.

برای بایگانی، پشتیبان‌گیری و فایل‌های بزرگ، دیسک مکانیکی هنوز ارزان‌ترین گیگابایت را می‌دهد. برای دیسک سیستم‌عامل یا پایگاه داده نه — آن‌جا تأخیر گلوگاه می‌شود.

دو نکتهٔ عملی: اول اینکه دیسک باید با کدی (caddy) همان نسل بیاید — کدی نسل ۸ تا ۱۰ با کدی Basic Carrier نسل ۱۰ به بعد جابه‌جا نمی‌شود و دیسک بی‌کدی در جایگاه هات‌پلاگ نمی‌نشیند. دوم اینکه در آرایهٔ RAID، همهٔ اعضا باید ظرفیت و سرعت یکسان داشته باشند؛ اگر یکی کوچک‌تر باشد، کنترلر همهٔ دیسک‌ها را به اندازهٔ کوچک‌ترین می‌بیند.

هر دیسک پیش از ارسال SMART و تست سطح می‌شود و ساعت کارکردش اعلام می‌گردد.',
                    'en' => 'The HPE 1TB 12G 7.2K SAS 2.5" SFF is a 1 TB 7.2K rpm mechanical drive on a SAS 12G interface, for HPE ProLiant Gen8 / Gen9 / Gen10 / Gen11 servers.

For archives, backups and large files a mechanical drive still delivers the cheapest gigabyte. Not for an OS volume or a database, where latency becomes the bottleneck.

Two practical notes. First, the drive has to come in the caddy for its generation — the Gen8–Gen10 Smart Carrier and the Gen10-and-later Basic Carrier are not interchangeable, and a bare drive will not seat in a hot-plug bay. Second, in a RAID array every member should have the same capacity and speed; if one is smaller, the controller treats every disk as if it were that size.

Every drive is SMART-checked and surface-tested before it ships, and its power-on hours are disclosed.',
                    'tr' => 'HPE 1TB 12G 7.2K SAS 2.5" SFF, HPE ProLiant Gen8 / Gen9 / Gen10 / Gen11 sunucuları için SAS 12G arayüzünde 1 TB kapasiteli bir 7.2K devir mekanik diskdir.

Arşiv, yedekleme ve büyük dosyalar için mekanik disk hâlâ en ucuz gigabaytı verir. İşletim sistemi birimi veya veritabanı için değil — orada gecikme darboğaz olur.

İki pratik not: Birincisi, disk kendi nesline ait kızakla gelmelidir — Gen8–Gen10 Smart Carrier ile Gen10 ve sonrası Basic Carrier birbirinin yerine geçmez ve kızaksız disk hot-plug yuvasına oturmaz. İkincisi, RAID dizisinde her üye aynı kapasite ve hızda olmalıdır; biri küçükse denetleyici tüm diskleri o boyutta görür.

Her disk sevkiyattan önce SMART ve yüzey testinden geçer, çalışma saati bildirilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'ظرفیت',
                            'en' => 'Capacity',
                            'tr' => 'Kapasite',
                        ],
                        'value' => [
                            'fa' => '1 TB',
                            'en' => '1 TB',
                            'tr' => '1 TB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'رابط',
                            'en' => 'Interface',
                            'tr' => 'Arayüz',
                        ],
                        'value' => [
                            'fa' => 'SAS 12G',
                            'en' => 'SAS 12G',
                            'tr' => 'SAS 12G',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نوع',
                            'en' => 'Type',
                            'tr' => 'Tip',
                        ],
                        'value' => [
                            'fa' => '7.2K rpm',
                            'en' => '7.2K rpm',
                            'tr' => '7.2K rpm',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'IOPS تخمینی',
                            'en' => 'Estimated IOPS',
                            'tr' => 'Tahmini IOPS',
                        ],
                        'value' => [
                            'fa' => '80',
                            'en' => '80',
                            'tr' => '80',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen8 / Gen9 / Gen10 / Gen11',
                            'en' => 'Gen8 / Gen9 / Gen10 / Gen11',
                            'tr' => 'Gen8 / Gen9 / Gen10 / Gen11',
                        ],
                    ],
                ],
                'attrs' => [
                    'gb' => 1000,
                    'iops' => 80,
                ],
            ],
            [
                'slug' => 'hdd-sata-4tb-7k2',
                'category' => 'disk',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen8',
                    'gen9',
                    'gen10',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 8403,
                'in_stock' => true,
                'popular' => false,
                'sort' => 510,
                'name' => [
                    'fa' => 'HPE 4TB 6G 7.2K SATA 3.5" LFF',
                    'en' => 'HPE 4TB 6G 7.2K SATA 3.5" LFF',
                    'tr' => 'HPE 4TB 6G 7.2K SATA 3.5" LFF',
                ],
                'tagline' => [
                    'fa' => '4 TB · SATA 6G · 7.2K',
                    'en' => '4 TB · SATA 6G · 7.2K',
                    'tr' => '4 TB · SATA 6G · 7.2K',
                ],
                'summary' => [
                    'fa' => 'دیسک مکانیکی 7.2K دور با ظرفیت 4 TB و رابط SATA 6G برای سرورهای Gen8 / Gen9 / Gen10.',
                    'en' => 'A 4 TB 7.2K rpm mechanical drive on a SATA 6G interface for Gen8 / Gen9 / Gen10 servers.',
                    'tr' => 'Gen8 / Gen9 / Gen10 sunucular için SATA 6G arayüzünde 4 TB 7.2K devir mekanik disk.',
                ],
                'body' => [
                    'fa' => 'HPE 4TB 6G 7.2K SATA 3.5" LFF یک دیسک مکانیکی 7.2K دور با ظرفیت 4 TB و رابط SATA 6G است که روی سرورهای HPE ProLiant Gen8 / Gen9 / Gen10 نصب می‌شود.

برای بایگانی، پشتیبان‌گیری و فایل‌های بزرگ، دیسک مکانیکی هنوز ارزان‌ترین گیگابایت را می‌دهد. برای دیسک سیستم‌عامل یا پایگاه داده نه — آن‌جا تأخیر گلوگاه می‌شود.

دو نکتهٔ عملی: اول اینکه دیسک باید با کدی (caddy) همان نسل بیاید — کدی نسل ۸ تا ۱۰ با کدی Basic Carrier نسل ۱۰ به بعد جابه‌جا نمی‌شود و دیسک بی‌کدی در جایگاه هات‌پلاگ نمی‌نشیند. دوم اینکه در آرایهٔ RAID، همهٔ اعضا باید ظرفیت و سرعت یکسان داشته باشند؛ اگر یکی کوچک‌تر باشد، کنترلر همهٔ دیسک‌ها را به اندازهٔ کوچک‌ترین می‌بیند.

هر دیسک پیش از ارسال SMART و تست سطح می‌شود و ساعت کارکردش اعلام می‌گردد.',
                    'en' => 'The HPE 4TB 6G 7.2K SATA 3.5" LFF is a 4 TB 7.2K rpm mechanical drive on a SATA 6G interface, for HPE ProLiant Gen8 / Gen9 / Gen10 servers.

For archives, backups and large files a mechanical drive still delivers the cheapest gigabyte. Not for an OS volume or a database, where latency becomes the bottleneck.

Two practical notes. First, the drive has to come in the caddy for its generation — the Gen8–Gen10 Smart Carrier and the Gen10-and-later Basic Carrier are not interchangeable, and a bare drive will not seat in a hot-plug bay. Second, in a RAID array every member should have the same capacity and speed; if one is smaller, the controller treats every disk as if it were that size.

Every drive is SMART-checked and surface-tested before it ships, and its power-on hours are disclosed.',
                    'tr' => 'HPE 4TB 6G 7.2K SATA 3.5" LFF, HPE ProLiant Gen8 / Gen9 / Gen10 sunucuları için SATA 6G arayüzünde 4 TB kapasiteli bir 7.2K devir mekanik diskdir.

Arşiv, yedekleme ve büyük dosyalar için mekanik disk hâlâ en ucuz gigabaytı verir. İşletim sistemi birimi veya veritabanı için değil — orada gecikme darboğaz olur.

İki pratik not: Birincisi, disk kendi nesline ait kızakla gelmelidir — Gen8–Gen10 Smart Carrier ile Gen10 ve sonrası Basic Carrier birbirinin yerine geçmez ve kızaksız disk hot-plug yuvasına oturmaz. İkincisi, RAID dizisinde her üye aynı kapasite ve hızda olmalıdır; biri küçükse denetleyici tüm diskleri o boyutta görür.

Her disk sevkiyattan önce SMART ve yüzey testinden geçer, çalışma saati bildirilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'ظرفیت',
                            'en' => 'Capacity',
                            'tr' => 'Kapasite',
                        ],
                        'value' => [
                            'fa' => '4 TB',
                            'en' => '4 TB',
                            'tr' => '4 TB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'رابط',
                            'en' => 'Interface',
                            'tr' => 'Arayüz',
                        ],
                        'value' => [
                            'fa' => 'SATA 6G',
                            'en' => 'SATA 6G',
                            'tr' => 'SATA 6G',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نوع',
                            'en' => 'Type',
                            'tr' => 'Tip',
                        ],
                        'value' => [
                            'fa' => '7.2K rpm',
                            'en' => '7.2K rpm',
                            'tr' => '7.2K rpm',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'IOPS تخمینی',
                            'en' => 'Estimated IOPS',
                            'tr' => 'Tahmini IOPS',
                        ],
                        'value' => [
                            'fa' => '80',
                            'en' => '80',
                            'tr' => '80',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen8 / Gen9 / Gen10',
                            'en' => 'Gen8 / Gen9 / Gen10',
                            'tr' => 'Gen8 / Gen9 / Gen10',
                        ],
                    ],
                ],
                'attrs' => [
                    'gb' => 4000,
                    'iops' => 80,
                ],
            ],
            [
                'slug' => 'hdd-sata-14tb-7k2',
                'category' => 'disk',
                'brand' => 'Toshiba',
                'compat_gens' => [
                    'gen9',
                    'gen10',
                    'gen11',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 25209,
                'in_stock' => true,
                'popular' => false,
                'sort' => 520,
                'name' => [
                    'fa' => 'Toshiba MG07ACA 14TB 6G 7.2K SATA 3.5" LFF',
                    'en' => 'Toshiba MG07ACA 14TB 6G 7.2K SATA 3.5" LFF',
                    'tr' => 'Toshiba MG07ACA 14TB 6G 7.2K SATA 3.5" LFF',
                ],
                'tagline' => [
                    'fa' => '14 TB · SATA 6G · 7.2K',
                    'en' => '14 TB · SATA 6G · 7.2K',
                    'tr' => '14 TB · SATA 6G · 7.2K',
                ],
                'summary' => [
                    'fa' => 'دیسک مکانیکی 7.2K دور با ظرفیت 14 TB و رابط SATA 6G برای سرورهای Gen9 / Gen10 / Gen11.',
                    'en' => 'A 14 TB 7.2K rpm mechanical drive on a SATA 6G interface for Gen9 / Gen10 / Gen11 servers.',
                    'tr' => 'Gen9 / Gen10 / Gen11 sunucular için SATA 6G arayüzünde 14 TB 7.2K devir mekanik disk.',
                ],
                'body' => [
                    'fa' => 'Toshiba MG07ACA 14TB 6G 7.2K SATA 3.5" LFF یک دیسک مکانیکی 7.2K دور با ظرفیت 14 TB و رابط SATA 6G است که روی سرورهای HPE ProLiant Gen9 / Gen10 / Gen11 نصب می‌شود.

برای بایگانی، پشتیبان‌گیری و فایل‌های بزرگ، دیسک مکانیکی هنوز ارزان‌ترین گیگابایت را می‌دهد. برای دیسک سیستم‌عامل یا پایگاه داده نه — آن‌جا تأخیر گلوگاه می‌شود.

دو نکتهٔ عملی: اول اینکه دیسک باید با کدی (caddy) همان نسل بیاید — کدی نسل ۸ تا ۱۰ با کدی Basic Carrier نسل ۱۰ به بعد جابه‌جا نمی‌شود و دیسک بی‌کدی در جایگاه هات‌پلاگ نمی‌نشیند. دوم اینکه در آرایهٔ RAID، همهٔ اعضا باید ظرفیت و سرعت یکسان داشته باشند؛ اگر یکی کوچک‌تر باشد، کنترلر همهٔ دیسک‌ها را به اندازهٔ کوچک‌ترین می‌بیند.

هر دیسک پیش از ارسال SMART و تست سطح می‌شود و ساعت کارکردش اعلام می‌گردد.',
                    'en' => 'The Toshiba MG07ACA 14TB 6G 7.2K SATA 3.5" LFF is a 14 TB 7.2K rpm mechanical drive on a SATA 6G interface, for HPE ProLiant Gen9 / Gen10 / Gen11 servers.

For archives, backups and large files a mechanical drive still delivers the cheapest gigabyte. Not for an OS volume or a database, where latency becomes the bottleneck.

Two practical notes. First, the drive has to come in the caddy for its generation — the Gen8–Gen10 Smart Carrier and the Gen10-and-later Basic Carrier are not interchangeable, and a bare drive will not seat in a hot-plug bay. Second, in a RAID array every member should have the same capacity and speed; if one is smaller, the controller treats every disk as if it were that size.

Every drive is SMART-checked and surface-tested before it ships, and its power-on hours are disclosed.',
                    'tr' => 'Toshiba MG07ACA 14TB 6G 7.2K SATA 3.5" LFF, HPE ProLiant Gen9 / Gen10 / Gen11 sunucuları için SATA 6G arayüzünde 14 TB kapasiteli bir 7.2K devir mekanik diskdir.

Arşiv, yedekleme ve büyük dosyalar için mekanik disk hâlâ en ucuz gigabaytı verir. İşletim sistemi birimi veya veritabanı için değil — orada gecikme darboğaz olur.

İki pratik not: Birincisi, disk kendi nesline ait kızakla gelmelidir — Gen8–Gen10 Smart Carrier ile Gen10 ve sonrası Basic Carrier birbirinin yerine geçmez ve kızaksız disk hot-plug yuvasına oturmaz. İkincisi, RAID dizisinde her üye aynı kapasite ve hızda olmalıdır; biri küçükse denetleyici tüm diskleri o boyutta görür.

Her disk sevkiyattan önce SMART ve yüzey testinden geçer, çalışma saati bildirilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'ظرفیت',
                            'en' => 'Capacity',
                            'tr' => 'Kapasite',
                        ],
                        'value' => [
                            'fa' => '14 TB',
                            'en' => '14 TB',
                            'tr' => '14 TB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'رابط',
                            'en' => 'Interface',
                            'tr' => 'Arayüz',
                        ],
                        'value' => [
                            'fa' => 'SATA 6G',
                            'en' => 'SATA 6G',
                            'tr' => 'SATA 6G',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نوع',
                            'en' => 'Type',
                            'tr' => 'Tip',
                        ],
                        'value' => [
                            'fa' => '7.2K rpm',
                            'en' => '7.2K rpm',
                            'tr' => '7.2K rpm',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'IOPS تخمینی',
                            'en' => 'Estimated IOPS',
                            'tr' => 'Tahmini IOPS',
                        ],
                        'value' => [
                            'fa' => '80',
                            'en' => '80',
                            'tr' => '80',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen9 / Gen10 / Gen11',
                            'en' => 'Gen9 / Gen10 / Gen11',
                            'tr' => 'Gen9 / Gen10 / Gen11',
                        ],
                    ],
                ],
                'attrs' => [
                    'gb' => 14000,
                    'iops' => 80,
                ],
            ],
            [
                'slug' => 'ssd-sata-480gb-ri',
                'category' => 'disk',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen8',
                    'gen9',
                    'gen10',
                    'gen11',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 8403,
                'in_stock' => true,
                'popular' => true,
                'sort' => 530,
                'name' => [
                    'fa' => 'HPE 480GB 6G SATA Read Intensive SSD 2.5" SFF',
                    'en' => 'HPE 480GB 6G SATA Read Intensive SSD 2.5" SFF',
                    'tr' => 'HPE 480GB 6G SATA Read Intensive SSD 2.5" SFF',
                ],
                'tagline' => [
                    'fa' => '480 GB · SATA 6G · SSD',
                    'en' => '480 GB · SATA 6G · SSD',
                    'tr' => '480 GB · SATA 6G · SSD',
                ],
                'summary' => [
                    'fa' => 'درایو حالت‌جامد (SSD) با ظرفیت 480 GB و رابط SATA 6G برای سرورهای Gen8 / Gen9 / Gen10 / Gen11.',
                    'en' => 'A 480 GB solid-state drive on a SATA 6G interface for Gen8 / Gen9 / Gen10 / Gen11 servers.',
                    'tr' => 'Gen8 / Gen9 / Gen10 / Gen11 sunucular için SATA 6G arayüzünde 480 GB katı hal sürücüsü.',
                ],
                'body' => [
                    'fa' => 'HPE 480GB 6G SATA Read Intensive SSD 2.5" SFF یک درایو حالت‌جامد (SSD) با ظرفیت 480 GB و رابط SATA 6G است که روی سرورهای HPE ProLiant Gen8 / Gen9 / Gen10 / Gen11 نصب می‌شود.

برای هر باری که تأخیر برایش مهم است — پایگاه داده، ماشین مجازی، دیسک سیستم‌عامل — SSD انتخاب درست است. اختلاف با دیسک مکانیکی در IOPS دو تا سه رقم است، نه چند درصد.

دو نکتهٔ عملی: اول اینکه دیسک باید با کدی (caddy) همان نسل بیاید — کدی نسل ۸ تا ۱۰ با کدی Basic Carrier نسل ۱۰ به بعد جابه‌جا نمی‌شود و دیسک بی‌کدی در جایگاه هات‌پلاگ نمی‌نشیند. دوم اینکه در آرایهٔ RAID، همهٔ اعضا باید ظرفیت و سرعت یکسان داشته باشند؛ اگر یکی کوچک‌تر باشد، کنترلر همهٔ دیسک‌ها را به اندازهٔ کوچک‌ترین می‌بیند.

هر دیسک پیش از ارسال SMART و تست سطح می‌شود و ساعت کارکردش اعلام می‌گردد.',
                    'en' => 'The HPE 480GB 6G SATA Read Intensive SSD 2.5" SFF is a 480 GB solid-state drive on a SATA 6G interface, for HPE ProLiant Gen8 / Gen9 / Gen10 / Gen11 servers.

For anything latency-sensitive — databases, virtual machines, the OS volume — an SSD is the right answer. The gap against a mechanical drive is two to three orders of magnitude in IOPS, not a few percent.

Two practical notes. First, the drive has to come in the caddy for its generation — the Gen8–Gen10 Smart Carrier and the Gen10-and-later Basic Carrier are not interchangeable, and a bare drive will not seat in a hot-plug bay. Second, in a RAID array every member should have the same capacity and speed; if one is smaller, the controller treats every disk as if it were that size.

Every drive is SMART-checked and surface-tested before it ships, and its power-on hours are disclosed.',
                    'tr' => 'HPE 480GB 6G SATA Read Intensive SSD 2.5" SFF, HPE ProLiant Gen8 / Gen9 / Gen10 / Gen11 sunucuları için SATA 6G arayüzünde 480 GB kapasiteli bir katı hal sürücüsüdir.

Gecikmeye duyarlı her iş için — veritabanı, sanal makine, işletim sistemi birimi — doğru cevap SSD’dir. Mekanik diskle arasındaki fark IOPS’ta yüzde birkaç değil, iki-üç kat büyüklük mertebesidir.

İki pratik not: Birincisi, disk kendi nesline ait kızakla gelmelidir — Gen8–Gen10 Smart Carrier ile Gen10 ve sonrası Basic Carrier birbirinin yerine geçmez ve kızaksız disk hot-plug yuvasına oturmaz. İkincisi, RAID dizisinde her üye aynı kapasite ve hızda olmalıdır; biri küçükse denetleyici tüm diskleri o boyutta görür.

Her disk sevkiyattan önce SMART ve yüzey testinden geçer, çalışma saati bildirilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'ظرفیت',
                            'en' => 'Capacity',
                            'tr' => 'Kapasite',
                        ],
                        'value' => [
                            'fa' => '480 GB',
                            'en' => '480 GB',
                            'tr' => '480 GB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'رابط',
                            'en' => 'Interface',
                            'tr' => 'Arayüz',
                        ],
                        'value' => [
                            'fa' => 'SATA 6G',
                            'en' => 'SATA 6G',
                            'tr' => 'SATA 6G',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نوع',
                            'en' => 'Type',
                            'tr' => 'Tip',
                        ],
                        'value' => [
                            'fa' => 'SSD',
                            'en' => 'SSD',
                            'tr' => 'SSD',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'IOPS تخمینی',
                            'en' => 'Estimated IOPS',
                            'tr' => 'Tahmini IOPS',
                        ],
                        'value' => [
                            'fa' => '75,000',
                            'en' => '75,000',
                            'tr' => '75,000',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen8 / Gen9 / Gen10 / Gen11',
                            'en' => 'Gen8 / Gen9 / Gen10 / Gen11',
                            'tr' => 'Gen8 / Gen9 / Gen10 / Gen11',
                        ],
                    ],
                ],
                'attrs' => [
                    'gb' => 480,
                    'iops' => 75000,
                ],
            ],
            [
                'slug' => 'ssd-sata-960gb-ri',
                'category' => 'disk',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen9',
                    'gen10',
                    'gen11',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 12604,
                'in_stock' => true,
                'popular' => true,
                'sort' => 540,
                'name' => [
                    'fa' => 'HPE 960GB 6G SATA Read Intensive SSD 2.5" SFF',
                    'en' => 'HPE 960GB 6G SATA Read Intensive SSD 2.5" SFF',
                    'tr' => 'HPE 960GB 6G SATA Read Intensive SSD 2.5" SFF',
                ],
                'tagline' => [
                    'fa' => '960 GB · SATA 6G · SSD',
                    'en' => '960 GB · SATA 6G · SSD',
                    'tr' => '960 GB · SATA 6G · SSD',
                ],
                'summary' => [
                    'fa' => 'درایو حالت‌جامد (SSD) با ظرفیت 960 GB و رابط SATA 6G برای سرورهای Gen9 / Gen10 / Gen11.',
                    'en' => 'A 960 GB solid-state drive on a SATA 6G interface for Gen9 / Gen10 / Gen11 servers.',
                    'tr' => 'Gen9 / Gen10 / Gen11 sunucular için SATA 6G arayüzünde 960 GB katı hal sürücüsü.',
                ],
                'body' => [
                    'fa' => 'HPE 960GB 6G SATA Read Intensive SSD 2.5" SFF یک درایو حالت‌جامد (SSD) با ظرفیت 960 GB و رابط SATA 6G است که روی سرورهای HPE ProLiant Gen9 / Gen10 / Gen11 نصب می‌شود.

برای هر باری که تأخیر برایش مهم است — پایگاه داده، ماشین مجازی، دیسک سیستم‌عامل — SSD انتخاب درست است. اختلاف با دیسک مکانیکی در IOPS دو تا سه رقم است، نه چند درصد.

دو نکتهٔ عملی: اول اینکه دیسک باید با کدی (caddy) همان نسل بیاید — کدی نسل ۸ تا ۱۰ با کدی Basic Carrier نسل ۱۰ به بعد جابه‌جا نمی‌شود و دیسک بی‌کدی در جایگاه هات‌پلاگ نمی‌نشیند. دوم اینکه در آرایهٔ RAID، همهٔ اعضا باید ظرفیت و سرعت یکسان داشته باشند؛ اگر یکی کوچک‌تر باشد، کنترلر همهٔ دیسک‌ها را به اندازهٔ کوچک‌ترین می‌بیند.

هر دیسک پیش از ارسال SMART و تست سطح می‌شود و ساعت کارکردش اعلام می‌گردد.',
                    'en' => 'The HPE 960GB 6G SATA Read Intensive SSD 2.5" SFF is a 960 GB solid-state drive on a SATA 6G interface, for HPE ProLiant Gen9 / Gen10 / Gen11 servers.

For anything latency-sensitive — databases, virtual machines, the OS volume — an SSD is the right answer. The gap against a mechanical drive is two to three orders of magnitude in IOPS, not a few percent.

Two practical notes. First, the drive has to come in the caddy for its generation — the Gen8–Gen10 Smart Carrier and the Gen10-and-later Basic Carrier are not interchangeable, and a bare drive will not seat in a hot-plug bay. Second, in a RAID array every member should have the same capacity and speed; if one is smaller, the controller treats every disk as if it were that size.

Every drive is SMART-checked and surface-tested before it ships, and its power-on hours are disclosed.',
                    'tr' => 'HPE 960GB 6G SATA Read Intensive SSD 2.5" SFF, HPE ProLiant Gen9 / Gen10 / Gen11 sunucuları için SATA 6G arayüzünde 960 GB kapasiteli bir katı hal sürücüsüdir.

Gecikmeye duyarlı her iş için — veritabanı, sanal makine, işletim sistemi birimi — doğru cevap SSD’dir. Mekanik diskle arasındaki fark IOPS’ta yüzde birkaç değil, iki-üç kat büyüklük mertebesidir.

İki pratik not: Birincisi, disk kendi nesline ait kızakla gelmelidir — Gen8–Gen10 Smart Carrier ile Gen10 ve sonrası Basic Carrier birbirinin yerine geçmez ve kızaksız disk hot-plug yuvasına oturmaz. İkincisi, RAID dizisinde her üye aynı kapasite ve hızda olmalıdır; biri küçükse denetleyici tüm diskleri o boyutta görür.

Her disk sevkiyattan önce SMART ve yüzey testinden geçer, çalışma saati bildirilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'ظرفیت',
                            'en' => 'Capacity',
                            'tr' => 'Kapasite',
                        ],
                        'value' => [
                            'fa' => '960 GB',
                            'en' => '960 GB',
                            'tr' => '960 GB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'رابط',
                            'en' => 'Interface',
                            'tr' => 'Arayüz',
                        ],
                        'value' => [
                            'fa' => 'SATA 6G',
                            'en' => 'SATA 6G',
                            'tr' => 'SATA 6G',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نوع',
                            'en' => 'Type',
                            'tr' => 'Tip',
                        ],
                        'value' => [
                            'fa' => 'SSD',
                            'en' => 'SSD',
                            'tr' => 'SSD',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'IOPS تخمینی',
                            'en' => 'Estimated IOPS',
                            'tr' => 'Tahmini IOPS',
                        ],
                        'value' => [
                            'fa' => '75,000',
                            'en' => '75,000',
                            'tr' => '75,000',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen9 / Gen10 / Gen11',
                            'en' => 'Gen9 / Gen10 / Gen11',
                            'tr' => 'Gen9 / Gen10 / Gen11',
                        ],
                    ],
                ],
                'attrs' => [
                    'gb' => 960,
                    'iops' => 75000,
                ],
            ],
            [
                'slug' => 'ssd-sas-800gb-wi',
                'category' => 'disk',
                'brand' => 'DELL',
                'compat_gens' => [
                    'gen9',
                    'gen10',
                    'gen11',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 16806,
                'in_stock' => true,
                'popular' => false,
                'sort' => 550,
                'name' => [
                    'fa' => 'DELL 800GB 12G SAS Write Intensive SSD 2.5" SFF',
                    'en' => 'DELL 800GB 12G SAS Write Intensive SSD 2.5" SFF',
                    'tr' => 'DELL 800GB 12G SAS Write Intensive SSD 2.5" SFF',
                ],
                'tagline' => [
                    'fa' => '800 GB · SAS 12G · SSD',
                    'en' => '800 GB · SAS 12G · SSD',
                    'tr' => '800 GB · SAS 12G · SSD',
                ],
                'summary' => [
                    'fa' => 'درایو حالت‌جامد (SSD) با ظرفیت 800 GB و رابط SAS 12G برای سرورهای Gen9 / Gen10 / Gen11.',
                    'en' => 'A 800 GB solid-state drive on a SAS 12G interface for Gen9 / Gen10 / Gen11 servers.',
                    'tr' => 'Gen9 / Gen10 / Gen11 sunucular için SAS 12G arayüzünde 800 GB katı hal sürücüsü.',
                ],
                'body' => [
                    'fa' => 'DELL 800GB 12G SAS Write Intensive SSD 2.5" SFF یک درایو حالت‌جامد (SSD) با ظرفیت 800 GB و رابط SAS 12G است که روی سرورهای HPE ProLiant Gen9 / Gen10 / Gen11 نصب می‌شود.

برای هر باری که تأخیر برایش مهم است — پایگاه داده، ماشین مجازی، دیسک سیستم‌عامل — SSD انتخاب درست است. اختلاف با دیسک مکانیکی در IOPS دو تا سه رقم است، نه چند درصد.

دو نکتهٔ عملی: اول اینکه دیسک باید با کدی (caddy) همان نسل بیاید — کدی نسل ۸ تا ۱۰ با کدی Basic Carrier نسل ۱۰ به بعد جابه‌جا نمی‌شود و دیسک بی‌کدی در جایگاه هات‌پلاگ نمی‌نشیند. دوم اینکه در آرایهٔ RAID، همهٔ اعضا باید ظرفیت و سرعت یکسان داشته باشند؛ اگر یکی کوچک‌تر باشد، کنترلر همهٔ دیسک‌ها را به اندازهٔ کوچک‌ترین می‌بیند.

هر دیسک پیش از ارسال SMART و تست سطح می‌شود و ساعت کارکردش اعلام می‌گردد.',
                    'en' => 'The DELL 800GB 12G SAS Write Intensive SSD 2.5" SFF is a 800 GB solid-state drive on a SAS 12G interface, for HPE ProLiant Gen9 / Gen10 / Gen11 servers.

For anything latency-sensitive — databases, virtual machines, the OS volume — an SSD is the right answer. The gap against a mechanical drive is two to three orders of magnitude in IOPS, not a few percent.

Two practical notes. First, the drive has to come in the caddy for its generation — the Gen8–Gen10 Smart Carrier and the Gen10-and-later Basic Carrier are not interchangeable, and a bare drive will not seat in a hot-plug bay. Second, in a RAID array every member should have the same capacity and speed; if one is smaller, the controller treats every disk as if it were that size.

Every drive is SMART-checked and surface-tested before it ships, and its power-on hours are disclosed.',
                    'tr' => 'DELL 800GB 12G SAS Write Intensive SSD 2.5" SFF, HPE ProLiant Gen9 / Gen10 / Gen11 sunucuları için SAS 12G arayüzünde 800 GB kapasiteli bir katı hal sürücüsüdir.

Gecikmeye duyarlı her iş için — veritabanı, sanal makine, işletim sistemi birimi — doğru cevap SSD’dir. Mekanik diskle arasındaki fark IOPS’ta yüzde birkaç değil, iki-üç kat büyüklük mertebesidir.

İki pratik not: Birincisi, disk kendi nesline ait kızakla gelmelidir — Gen8–Gen10 Smart Carrier ile Gen10 ve sonrası Basic Carrier birbirinin yerine geçmez ve kızaksız disk hot-plug yuvasına oturmaz. İkincisi, RAID dizisinde her üye aynı kapasite ve hızda olmalıdır; biri küçükse denetleyici tüm diskleri o boyutta görür.

Her disk sevkiyattan önce SMART ve yüzey testinden geçer, çalışma saati bildirilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'ظرفیت',
                            'en' => 'Capacity',
                            'tr' => 'Kapasite',
                        ],
                        'value' => [
                            'fa' => '800 GB',
                            'en' => '800 GB',
                            'tr' => '800 GB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'رابط',
                            'en' => 'Interface',
                            'tr' => 'Arayüz',
                        ],
                        'value' => [
                            'fa' => 'SAS 12G',
                            'en' => 'SAS 12G',
                            'tr' => 'SAS 12G',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نوع',
                            'en' => 'Type',
                            'tr' => 'Tip',
                        ],
                        'value' => [
                            'fa' => 'SSD',
                            'en' => 'SSD',
                            'tr' => 'SSD',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'IOPS تخمینی',
                            'en' => 'Estimated IOPS',
                            'tr' => 'Tahmini IOPS',
                        ],
                        'value' => [
                            'fa' => '110,000',
                            'en' => '110,000',
                            'tr' => '110,000',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen9 / Gen10 / Gen11',
                            'en' => 'Gen9 / Gen10 / Gen11',
                            'tr' => 'Gen9 / Gen10 / Gen11',
                        ],
                    ],
                ],
                'attrs' => [
                    'gb' => 800,
                    'iops' => 110000,
                ],
            ],
            [
                'slug' => 'ssd-sas-3840gb-ri',
                'category' => 'disk',
                'brand' => 'Samsung',
                'compat_gens' => [
                    'gen9',
                    'gen10',
                    'gen11',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 63024,
                'in_stock' => true,
                'popular' => false,
                'sort' => 560,
                'name' => [
                    'fa' => 'Samsung PM1643 3.84TB 12G SAS Read Intensive SSD 2.5"',
                    'en' => 'Samsung PM1643 3.84TB 12G SAS Read Intensive SSD 2.5"',
                    'tr' => 'Samsung PM1643 3.84TB 12G SAS Read Intensive SSD 2.5"',
                ],
                'tagline' => [
                    'fa' => '3.84 TB · SAS 12G · SSD',
                    'en' => '3.84 TB · SAS 12G · SSD',
                    'tr' => '3.84 TB · SAS 12G · SSD',
                ],
                'summary' => [
                    'fa' => 'درایو حالت‌جامد (SSD) با ظرفیت 3.84 TB و رابط SAS 12G برای سرورهای Gen9 / Gen10 / Gen11.',
                    'en' => 'A 3.84 TB solid-state drive on a SAS 12G interface for Gen9 / Gen10 / Gen11 servers.',
                    'tr' => 'Gen9 / Gen10 / Gen11 sunucular için SAS 12G arayüzünde 3.84 TB katı hal sürücüsü.',
                ],
                'body' => [
                    'fa' => 'Samsung PM1643 3.84TB 12G SAS Read Intensive SSD 2.5" یک درایو حالت‌جامد (SSD) با ظرفیت 3.84 TB و رابط SAS 12G است که روی سرورهای HPE ProLiant Gen9 / Gen10 / Gen11 نصب می‌شود.

برای هر باری که تأخیر برایش مهم است — پایگاه داده، ماشین مجازی، دیسک سیستم‌عامل — SSD انتخاب درست است. اختلاف با دیسک مکانیکی در IOPS دو تا سه رقم است، نه چند درصد.

دو نکتهٔ عملی: اول اینکه دیسک باید با کدی (caddy) همان نسل بیاید — کدی نسل ۸ تا ۱۰ با کدی Basic Carrier نسل ۱۰ به بعد جابه‌جا نمی‌شود و دیسک بی‌کدی در جایگاه هات‌پلاگ نمی‌نشیند. دوم اینکه در آرایهٔ RAID، همهٔ اعضا باید ظرفیت و سرعت یکسان داشته باشند؛ اگر یکی کوچک‌تر باشد، کنترلر همهٔ دیسک‌ها را به اندازهٔ کوچک‌ترین می‌بیند.

هر دیسک پیش از ارسال SMART و تست سطح می‌شود و ساعت کارکردش اعلام می‌گردد.',
                    'en' => 'The Samsung PM1643 3.84TB 12G SAS Read Intensive SSD 2.5" is a 3.84 TB solid-state drive on a SAS 12G interface, for HPE ProLiant Gen9 / Gen10 / Gen11 servers.

For anything latency-sensitive — databases, virtual machines, the OS volume — an SSD is the right answer. The gap against a mechanical drive is two to three orders of magnitude in IOPS, not a few percent.

Two practical notes. First, the drive has to come in the caddy for its generation — the Gen8–Gen10 Smart Carrier and the Gen10-and-later Basic Carrier are not interchangeable, and a bare drive will not seat in a hot-plug bay. Second, in a RAID array every member should have the same capacity and speed; if one is smaller, the controller treats every disk as if it were that size.

Every drive is SMART-checked and surface-tested before it ships, and its power-on hours are disclosed.',
                    'tr' => 'Samsung PM1643 3.84TB 12G SAS Read Intensive SSD 2.5", HPE ProLiant Gen9 / Gen10 / Gen11 sunucuları için SAS 12G arayüzünde 3.84 TB kapasiteli bir katı hal sürücüsüdir.

Gecikmeye duyarlı her iş için — veritabanı, sanal makine, işletim sistemi birimi — doğru cevap SSD’dir. Mekanik diskle arasındaki fark IOPS’ta yüzde birkaç değil, iki-üç kat büyüklük mertebesidir.

İki pratik not: Birincisi, disk kendi nesline ait kızakla gelmelidir — Gen8–Gen10 Smart Carrier ile Gen10 ve sonrası Basic Carrier birbirinin yerine geçmez ve kızaksız disk hot-plug yuvasına oturmaz. İkincisi, RAID dizisinde her üye aynı kapasite ve hızda olmalıdır; biri küçükse denetleyici tüm diskleri o boyutta görür.

Her disk sevkiyattan önce SMART ve yüzey testinden geçer, çalışma saati bildirilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'ظرفیت',
                            'en' => 'Capacity',
                            'tr' => 'Kapasite',
                        ],
                        'value' => [
                            'fa' => '3.84 TB',
                            'en' => '3.84 TB',
                            'tr' => '3.84 TB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'رابط',
                            'en' => 'Interface',
                            'tr' => 'Arayüz',
                        ],
                        'value' => [
                            'fa' => 'SAS 12G',
                            'en' => 'SAS 12G',
                            'tr' => 'SAS 12G',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نوع',
                            'en' => 'Type',
                            'tr' => 'Tip',
                        ],
                        'value' => [
                            'fa' => 'SSD',
                            'en' => 'SSD',
                            'tr' => 'SSD',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'IOPS تخمینی',
                            'en' => 'Estimated IOPS',
                            'tr' => 'Tahmini IOPS',
                        ],
                        'value' => [
                            'fa' => '130,000',
                            'en' => '130,000',
                            'tr' => '130,000',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen9 / Gen10 / Gen11',
                            'en' => 'Gen9 / Gen10 / Gen11',
                            'tr' => 'Gen9 / Gen10 / Gen11',
                        ],
                    ],
                ],
                'attrs' => [
                    'gb' => 3840,
                    'iops' => 130000,
                ],
            ],
            [
                'slug' => 'ssd-nvme-1920gb-u2',
                'category' => 'disk',
                'brand' => 'Samsung',
                'compat_gens' => [
                    'gen10',
                    'gen11',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 29411,
                'in_stock' => true,
                'popular' => false,
                'sort' => 570,
                'name' => [
                    'fa' => 'Samsung PM983 1.92TB U.2 PCIe Gen3 NVMe SSD 2.5"',
                    'en' => 'Samsung PM983 1.92TB U.2 PCIe Gen3 NVMe SSD 2.5"',
                    'tr' => 'Samsung PM983 1.92TB U.2 PCIe Gen3 NVMe SSD 2.5"',
                ],
                'tagline' => [
                    'fa' => '1.92 TB · NVMe PCIe 3.0 · SSD',
                    'en' => '1.92 TB · NVMe PCIe 3.0 · SSD',
                    'tr' => '1.92 TB · NVMe PCIe 3.0 · SSD',
                ],
                'summary' => [
                    'fa' => 'درایو حالت‌جامد (SSD) با ظرفیت 1.92 TB و رابط NVMe PCIe 3.0 برای سرورهای Gen10 / Gen11.',
                    'en' => 'A 1.92 TB solid-state drive on a NVMe PCIe 3.0 interface for Gen10 / Gen11 servers.',
                    'tr' => 'Gen10 / Gen11 sunucular için NVMe PCIe 3.0 arayüzünde 1.92 TB katı hal sürücüsü.',
                ],
                'body' => [
                    'fa' => 'Samsung PM983 1.92TB U.2 PCIe Gen3 NVMe SSD 2.5" یک درایو حالت‌جامد (SSD) با ظرفیت 1.92 TB و رابط NVMe PCIe 3.0 است که روی سرورهای HPE ProLiant Gen10 / Gen11 نصب می‌شود.

برای هر باری که تأخیر برایش مهم است — پایگاه داده، ماشین مجازی، دیسک سیستم‌عامل — SSD انتخاب درست است. اختلاف با دیسک مکانیکی در IOPS دو تا سه رقم است، نه چند درصد.

دو نکتهٔ عملی: اول اینکه دیسک باید با کدی (caddy) همان نسل بیاید — کدی نسل ۸ تا ۱۰ با کدی Basic Carrier نسل ۱۰ به بعد جابه‌جا نمی‌شود و دیسک بی‌کدی در جایگاه هات‌پلاگ نمی‌نشیند. دوم اینکه در آرایهٔ RAID، همهٔ اعضا باید ظرفیت و سرعت یکسان داشته باشند؛ اگر یکی کوچک‌تر باشد، کنترلر همهٔ دیسک‌ها را به اندازهٔ کوچک‌ترین می‌بیند.

هر دیسک پیش از ارسال SMART و تست سطح می‌شود و ساعت کارکردش اعلام می‌گردد.',
                    'en' => 'The Samsung PM983 1.92TB U.2 PCIe Gen3 NVMe SSD 2.5" is a 1.92 TB solid-state drive on a NVMe PCIe 3.0 interface, for HPE ProLiant Gen10 / Gen11 servers.

For anything latency-sensitive — databases, virtual machines, the OS volume — an SSD is the right answer. The gap against a mechanical drive is two to three orders of magnitude in IOPS, not a few percent.

Two practical notes. First, the drive has to come in the caddy for its generation — the Gen8–Gen10 Smart Carrier and the Gen10-and-later Basic Carrier are not interchangeable, and a bare drive will not seat in a hot-plug bay. Second, in a RAID array every member should have the same capacity and speed; if one is smaller, the controller treats every disk as if it were that size.

Every drive is SMART-checked and surface-tested before it ships, and its power-on hours are disclosed.',
                    'tr' => 'Samsung PM983 1.92TB U.2 PCIe Gen3 NVMe SSD 2.5", HPE ProLiant Gen10 / Gen11 sunucuları için NVMe PCIe 3.0 arayüzünde 1.92 TB kapasiteli bir katı hal sürücüsüdir.

Gecikmeye duyarlı her iş için — veritabanı, sanal makine, işletim sistemi birimi — doğru cevap SSD’dir. Mekanik diskle arasındaki fark IOPS’ta yüzde birkaç değil, iki-üç kat büyüklük mertebesidir.

İki pratik not: Birincisi, disk kendi nesline ait kızakla gelmelidir — Gen8–Gen10 Smart Carrier ile Gen10 ve sonrası Basic Carrier birbirinin yerine geçmez ve kızaksız disk hot-plug yuvasına oturmaz. İkincisi, RAID dizisinde her üye aynı kapasite ve hızda olmalıdır; biri küçükse denetleyici tüm diskleri o boyutta görür.

Her disk sevkiyattan önce SMART ve yüzey testinden geçer, çalışma saati bildirilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'ظرفیت',
                            'en' => 'Capacity',
                            'tr' => 'Kapasite',
                        ],
                        'value' => [
                            'fa' => '1.92 TB',
                            'en' => '1.92 TB',
                            'tr' => '1.92 TB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'رابط',
                            'en' => 'Interface',
                            'tr' => 'Arayüz',
                        ],
                        'value' => [
                            'fa' => 'NVMe PCIe 3.0',
                            'en' => 'NVMe PCIe 3.0',
                            'tr' => 'NVMe PCIe 3.0',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نوع',
                            'en' => 'Type',
                            'tr' => 'Tip',
                        ],
                        'value' => [
                            'fa' => 'SSD',
                            'en' => 'SSD',
                            'tr' => 'SSD',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'IOPS تخمینی',
                            'en' => 'Estimated IOPS',
                            'tr' => 'Tahmini IOPS',
                        ],
                        'value' => [
                            'fa' => '480,000',
                            'en' => '480,000',
                            'tr' => '480,000',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen10 / Gen11',
                            'en' => 'Gen10 / Gen11',
                            'tr' => 'Gen10 / Gen11',
                        ],
                    ],
                ],
                'attrs' => [
                    'gb' => 1920,
                    'iops' => 480000,
                ],
            ],
            [
                'slug' => 'ssd-nvme-1920gb-u3',
                'category' => 'disk',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen10',
                    'gen11',
                    'gen12',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 42016,
                'in_stock' => true,
                'popular' => false,
                'sort' => 580,
                'name' => [
                    'fa' => 'HPE 1.92TB U.3 PCIe Gen4 Read Intensive NVMe SSD',
                    'en' => 'HPE 1.92TB U.3 PCIe Gen4 Read Intensive NVMe SSD',
                    'tr' => 'HPE 1.92TB U.3 PCIe Gen4 Read Intensive NVMe SSD',
                ],
                'tagline' => [
                    'fa' => '1.92 TB · NVMe PCIe 4.0 · SSD',
                    'en' => '1.92 TB · NVMe PCIe 4.0 · SSD',
                    'tr' => '1.92 TB · NVMe PCIe 4.0 · SSD',
                ],
                'summary' => [
                    'fa' => 'درایو حالت‌جامد (SSD) با ظرفیت 1.92 TB و رابط NVMe PCIe 4.0 برای سرورهای Gen10 / Gen11 / Gen12.',
                    'en' => 'A 1.92 TB solid-state drive on a NVMe PCIe 4.0 interface for Gen10 / Gen11 / Gen12 servers.',
                    'tr' => 'Gen10 / Gen11 / Gen12 sunucular için NVMe PCIe 4.0 arayüzünde 1.92 TB katı hal sürücüsü.',
                ],
                'body' => [
                    'fa' => 'HPE 1.92TB U.3 PCIe Gen4 Read Intensive NVMe SSD یک درایو حالت‌جامد (SSD) با ظرفیت 1.92 TB و رابط NVMe PCIe 4.0 است که روی سرورهای HPE ProLiant Gen10 / Gen11 / Gen12 نصب می‌شود.

برای هر باری که تأخیر برایش مهم است — پایگاه داده، ماشین مجازی، دیسک سیستم‌عامل — SSD انتخاب درست است. اختلاف با دیسک مکانیکی در IOPS دو تا سه رقم است، نه چند درصد.

دو نکتهٔ عملی: اول اینکه دیسک باید با کدی (caddy) همان نسل بیاید — کدی نسل ۸ تا ۱۰ با کدی Basic Carrier نسل ۱۰ به بعد جابه‌جا نمی‌شود و دیسک بی‌کدی در جایگاه هات‌پلاگ نمی‌نشیند. دوم اینکه در آرایهٔ RAID، همهٔ اعضا باید ظرفیت و سرعت یکسان داشته باشند؛ اگر یکی کوچک‌تر باشد، کنترلر همهٔ دیسک‌ها را به اندازهٔ کوچک‌ترین می‌بیند.

هر دیسک پیش از ارسال SMART و تست سطح می‌شود و ساعت کارکردش اعلام می‌گردد.',
                    'en' => 'The HPE 1.92TB U.3 PCIe Gen4 Read Intensive NVMe SSD is a 1.92 TB solid-state drive on a NVMe PCIe 4.0 interface, for HPE ProLiant Gen10 / Gen11 / Gen12 servers.

For anything latency-sensitive — databases, virtual machines, the OS volume — an SSD is the right answer. The gap against a mechanical drive is two to three orders of magnitude in IOPS, not a few percent.

Two practical notes. First, the drive has to come in the caddy for its generation — the Gen8–Gen10 Smart Carrier and the Gen10-and-later Basic Carrier are not interchangeable, and a bare drive will not seat in a hot-plug bay. Second, in a RAID array every member should have the same capacity and speed; if one is smaller, the controller treats every disk as if it were that size.

Every drive is SMART-checked and surface-tested before it ships, and its power-on hours are disclosed.',
                    'tr' => 'HPE 1.92TB U.3 PCIe Gen4 Read Intensive NVMe SSD, HPE ProLiant Gen10 / Gen11 / Gen12 sunucuları için NVMe PCIe 4.0 arayüzünde 1.92 TB kapasiteli bir katı hal sürücüsüdir.

Gecikmeye duyarlı her iş için — veritabanı, sanal makine, işletim sistemi birimi — doğru cevap SSD’dir. Mekanik diskle arasındaki fark IOPS’ta yüzde birkaç değil, iki-üç kat büyüklük mertebesidir.

İki pratik not: Birincisi, disk kendi nesline ait kızakla gelmelidir — Gen8–Gen10 Smart Carrier ile Gen10 ve sonrası Basic Carrier birbirinin yerine geçmez ve kızaksız disk hot-plug yuvasına oturmaz. İkincisi, RAID dizisinde her üye aynı kapasite ve hızda olmalıdır; biri küçükse denetleyici tüm diskleri o boyutta görür.

Her disk sevkiyattan önce SMART ve yüzey testinden geçer, çalışma saati bildirilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'ظرفیت',
                            'en' => 'Capacity',
                            'tr' => 'Kapasite',
                        ],
                        'value' => [
                            'fa' => '1.92 TB',
                            'en' => '1.92 TB',
                            'tr' => '1.92 TB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'رابط',
                            'en' => 'Interface',
                            'tr' => 'Arayüz',
                        ],
                        'value' => [
                            'fa' => 'NVMe PCIe 4.0',
                            'en' => 'NVMe PCIe 4.0',
                            'tr' => 'NVMe PCIe 4.0',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نوع',
                            'en' => 'Type',
                            'tr' => 'Tip',
                        ],
                        'value' => [
                            'fa' => 'SSD',
                            'en' => 'SSD',
                            'tr' => 'SSD',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'IOPS تخمینی',
                            'en' => 'Estimated IOPS',
                            'tr' => 'Tahmini IOPS',
                        ],
                        'value' => [
                            'fa' => '800,000',
                            'en' => '800,000',
                            'tr' => '800,000',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen10 / Gen11 / Gen12',
                            'en' => 'Gen10 / Gen11 / Gen12',
                            'tr' => 'Gen10 / Gen11 / Gen12',
                        ],
                    ],
                ],
                'attrs' => [
                    'gb' => 1920,
                    'iops' => 800000,
                ],
            ],
            [
                'slug' => 'raid-p420i',
                'category' => 'raid',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen8',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 2100,
                'in_stock' => true,
                'popular' => false,
                'sort' => 590,
                'name' => [
                    'fa' => 'HPE Smart Array P420i AROC 2-Port RAID',
                    'en' => 'HPE Smart Array P420i AROC 2-Port RAID',
                    'tr' => 'HPE Smart Array P420i AROC 2-Port RAID',
                ],
                'tagline' => [
                    'fa' => '2 پورت · کش 512MB / 1GB FBWC',
                    'en' => '2-port · 512MB / 1GB FBWC cache',
                    'tr' => '2 port · 512MB / 1GB FBWC önbellek',
                ],
                'summary' => [
                    'fa' => 'کنترلر RAID 2 پورته برای سرورهای Gen8 با حافظهٔ کش 512MB / 1GB FBWC.',
                    'en' => 'A 2-port RAID controller for Gen8 servers with 512MB / 1GB FBWC of cache.',
                    'tr' => 'Gen8 sunucular için 512MB / 1GB FBWC önbellekli 2 portlu RAID denetleyici.',
                ],
                'body' => [
                    'fa' => 'HPE Smart Array P420i AROC 2-Port RAID روی سرورهای HPE ProLiant Gen8 نصب می‌شود و 2 پورت دارد.

این کنترلر RAID سخت‌افزاری است: آرایه در خود کارت ساخته می‌شود و سیستم‌عامل فقط یک دیسک منطقی می‌بیند. حافظهٔ کش 512MB / 1GB FBWC با باتری/خازن پشتیبان یعنی نوشتن‌های در راه، هنگام قطع برق از دست نمی‌روند — همان چیزی که RAID نرم‌افزاری نمی‌دهد.

نکتهٔ سازگاری که گران تمام می‌شود: کارت AROC (نصب روی اسلات اختصاصی مادربرد) با کارت PCIe جای هم نمی‌نشیند و هر نسل اسلات AROC خودش را دارد. پیش از سفارش، نسل سرور را با فهرست سازگاری بالا مطابقت دهید.

کارت با کابل و باتری کش مربوطه تحویل می‌شود و روی همان نسل تست شده است.',
                    'en' => 'The HPE Smart Array P420i AROC 2-Port RAID installs in HPE ProLiant Gen8 servers and provides 2 ports.

This is a hardware RAID controller: the array is built on the card itself and the operating system only sees a logical drive. The 512MB / 1GB FBWC cache with its battery/capacitor backup means in-flight writes survive a power cut — exactly what software RAID cannot give you.

A compatibility detail that costs money to get wrong: an AROC card (which mounts in the board’s dedicated slot) and a PCIe card are not interchangeable, and each generation has its own AROC slot. Match your server generation against the compatibility list above before ordering.

The card ships with its cable and cache battery, tested on the same generation.',
                    'tr' => 'HPE Smart Array P420i AROC 2-Port RAID, HPE ProLiant Gen8 sunucularına takılır ve 2 port sunar.

Bu bir donanımsal RAID denetleyicisidir: dizi kartın üzerinde kurulur ve işletim sistemi yalnızca mantıksal bir sürücü görür. Pil/kondansatör destekli 512MB / 1GB FBWC önbellek, yolda olan yazmaların elektrik kesintisinden sağ çıkması demektir — yazılımsal RAID’in veremediği tam olarak budur.

Yanlış yapılırsa pahalıya patlayan bir uyumluluk ayrıntısı: AROC kart (anakartın özel yuvasına takılan) ile PCIe kart birbirinin yerine geçmez ve her neslin kendi AROC yuvası vardır. Sipariş öncesi sunucu neslinizi yukarıdaki uyumluluk listesiyle karşılaştırın.

Kart, kablosu ve önbellek piliyle birlikte, aynı nesilde test edilmiş olarak gönderilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'نوع',
                            'en' => 'Type',
                            'tr' => 'Tip',
                        ],
                        'value' => [
                            'fa' => 'RAID',
                            'en' => 'RAID',
                            'tr' => 'RAID',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'تعداد پورت',
                            'en' => 'Ports',
                            'tr' => 'Port sayısı',
                        ],
                        'value' => [
                            'fa' => '2',
                            'en' => '2',
                            'tr' => '2',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'حافظهٔ کش',
                            'en' => 'Cache',
                            'tr' => 'Önbellek',
                        ],
                        'value' => [
                            'fa' => '512MB / 1GB FBWC',
                            'en' => '512MB / 1GB FBWC',
                            'tr' => '512MB / 1GB FBWC',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen8',
                            'en' => 'Gen8',
                            'tr' => 'Gen8',
                        ],
                    ],
                ],
                'attrs' => [
                    'ports' => 2,
                ],
            ],
            [
                'slug' => 'raid-p440ar',
                'category' => 'raid',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen9',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 3361,
                'in_stock' => true,
                'popular' => true,
                'sort' => 600,
                'name' => [
                    'fa' => 'HPE Smart Array P440ar AROC 2-Port RAID',
                    'en' => 'HPE Smart Array P440ar AROC 2-Port RAID',
                    'tr' => 'HPE Smart Array P440ar AROC 2-Port RAID',
                ],
                'tagline' => [
                    'fa' => '2 پورت · کش 2GB FBWC',
                    'en' => '2-port · 2GB FBWC cache',
                    'tr' => '2 port · 2GB FBWC önbellek',
                ],
                'summary' => [
                    'fa' => 'کنترلر RAID 2 پورته برای سرورهای Gen9 با حافظهٔ کش 2GB FBWC.',
                    'en' => 'A 2-port RAID controller for Gen9 servers with 2GB FBWC of cache.',
                    'tr' => 'Gen9 sunucular için 2GB FBWC önbellekli 2 portlu RAID denetleyici.',
                ],
                'body' => [
                    'fa' => 'HPE Smart Array P440ar AROC 2-Port RAID روی سرورهای HPE ProLiant Gen9 نصب می‌شود و 2 پورت دارد.

این کنترلر RAID سخت‌افزاری است: آرایه در خود کارت ساخته می‌شود و سیستم‌عامل فقط یک دیسک منطقی می‌بیند. حافظهٔ کش 2GB FBWC با باتری/خازن پشتیبان یعنی نوشتن‌های در راه، هنگام قطع برق از دست نمی‌روند — همان چیزی که RAID نرم‌افزاری نمی‌دهد.

نکتهٔ سازگاری که گران تمام می‌شود: کارت AROC (نصب روی اسلات اختصاصی مادربرد) با کارت PCIe جای هم نمی‌نشیند و هر نسل اسلات AROC خودش را دارد. پیش از سفارش، نسل سرور را با فهرست سازگاری بالا مطابقت دهید.

کارت با کابل و باتری کش مربوطه تحویل می‌شود و روی همان نسل تست شده است.',
                    'en' => 'The HPE Smart Array P440ar AROC 2-Port RAID installs in HPE ProLiant Gen9 servers and provides 2 ports.

This is a hardware RAID controller: the array is built on the card itself and the operating system only sees a logical drive. The 2GB FBWC cache with its battery/capacitor backup means in-flight writes survive a power cut — exactly what software RAID cannot give you.

A compatibility detail that costs money to get wrong: an AROC card (which mounts in the board’s dedicated slot) and a PCIe card are not interchangeable, and each generation has its own AROC slot. Match your server generation against the compatibility list above before ordering.

The card ships with its cable and cache battery, tested on the same generation.',
                    'tr' => 'HPE Smart Array P440ar AROC 2-Port RAID, HPE ProLiant Gen9 sunucularına takılır ve 2 port sunar.

Bu bir donanımsal RAID denetleyicisidir: dizi kartın üzerinde kurulur ve işletim sistemi yalnızca mantıksal bir sürücü görür. Pil/kondansatör destekli 2GB FBWC önbellek, yolda olan yazmaların elektrik kesintisinden sağ çıkması demektir — yazılımsal RAID’in veremediği tam olarak budur.

Yanlış yapılırsa pahalıya patlayan bir uyumluluk ayrıntısı: AROC kart (anakartın özel yuvasına takılan) ile PCIe kart birbirinin yerine geçmez ve her neslin kendi AROC yuvası vardır. Sipariş öncesi sunucu neslinizi yukarıdaki uyumluluk listesiyle karşılaştırın.

Kart, kablosu ve önbellek piliyle birlikte, aynı nesilde test edilmiş olarak gönderilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'نوع',
                            'en' => 'Type',
                            'tr' => 'Tip',
                        ],
                        'value' => [
                            'fa' => 'RAID',
                            'en' => 'RAID',
                            'tr' => 'RAID',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'تعداد پورت',
                            'en' => 'Ports',
                            'tr' => 'Port sayısı',
                        ],
                        'value' => [
                            'fa' => '2',
                            'en' => '2',
                            'tr' => '2',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'حافظهٔ کش',
                            'en' => 'Cache',
                            'tr' => 'Önbellek',
                        ],
                        'value' => [
                            'fa' => '2GB FBWC',
                            'en' => '2GB FBWC',
                            'tr' => '2GB FBWC',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen9',
                            'en' => 'Gen9',
                            'tr' => 'Gen9',
                        ],
                    ],
                ],
                'attrs' => [
                    'ports' => 2,
                ],
            ],
            [
                'slug' => 'raid-h240ar',
                'category' => 'raid',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen9',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 3361,
                'in_stock' => true,
                'popular' => false,
                'sort' => 610,
                'name' => [
                    'fa' => 'HPE H240ar Smart HBA AROC 2-Port',
                    'en' => 'HPE H240ar Smart HBA AROC 2-Port',
                    'tr' => 'HPE H240ar Smart HBA AROC 2-Port',
                ],
                'tagline' => [
                    'fa' => '2 پورت · کش —',
                    'en' => '2-port · — cache',
                    'tr' => '2 port · — önbellek',
                ],
                'summary' => [
                    'fa' => 'آداپتور HBA 2 پورته برای سرورهای Gen9 با حافظهٔ کش —.',
                    'en' => 'A 2-port HBA for Gen9 servers with — of cache.',
                    'tr' => 'Gen9 sunucular için — önbellekli 2 portlu HBA.',
                ],
                'body' => [
                    'fa' => 'HPE H240ar Smart HBA AROC 2-Port روی سرورهای HPE ProLiant Gen9 نصب می‌شود و 2 پورت دارد.

این کارت HBA است نه RAID: دیسک‌ها را مستقیم و بدون لایهٔ آرایه به سیستم‌عامل می‌دهد. برای ZFS، Ceph و هر سامانه‌ای که خودش مدیریت افزونگی را بر عهده دارد، دقیقاً همین لازم است — لایهٔ RAID سخت‌افزاری آن‌جا فقط مزاحم است.

نکتهٔ سازگاری که گران تمام می‌شود: کارت AROC (نصب روی اسلات اختصاصی مادربرد) با کارت PCIe جای هم نمی‌نشیند و هر نسل اسلات AROC خودش را دارد. پیش از سفارش، نسل سرور را با فهرست سازگاری بالا مطابقت دهید.

کارت با کابل و باتری کش مربوطه تحویل می‌شود و روی همان نسل تست شده است.',
                    'en' => 'The HPE H240ar Smart HBA AROC 2-Port installs in HPE ProLiant Gen9 servers and provides 2 ports.

This is an HBA, not a RAID card: it presents the disks straight through to the operating system with no array layer. That is precisely what ZFS, Ceph and any system that manages its own redundancy need — a hardware RAID layer only gets in the way there.

A compatibility detail that costs money to get wrong: an AROC card (which mounts in the board’s dedicated slot) and a PCIe card are not interchangeable, and each generation has its own AROC slot. Match your server generation against the compatibility list above before ordering.

The card ships with its cable and cache battery, tested on the same generation.',
                    'tr' => 'HPE H240ar Smart HBA AROC 2-Port, HPE ProLiant Gen9 sunucularına takılır ve 2 port sunar.

Bu bir RAID kartı değil HBA’dır: diskleri dizi katmanı olmadan doğrudan işletim sistemine verir. ZFS, Ceph ve yedekliliği kendi yöneten her sistemin ihtiyacı tam olarak budur — orada donanımsal RAID katmanı yalnızca engeldir.

Yanlış yapılırsa pahalıya patlayan bir uyumluluk ayrıntısı: AROC kart (anakartın özel yuvasına takılan) ile PCIe kart birbirinin yerine geçmez ve her neslin kendi AROC yuvası vardır. Sipariş öncesi sunucu neslinizi yukarıdaki uyumluluk listesiyle karşılaştırın.

Kart, kablosu ve önbellek piliyle birlikte, aynı nesilde test edilmiş olarak gönderilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'نوع',
                            'en' => 'Type',
                            'tr' => 'Tip',
                        ],
                        'value' => [
                            'fa' => 'HBA',
                            'en' => 'HBA',
                            'tr' => 'HBA',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'تعداد پورت',
                            'en' => 'Ports',
                            'tr' => 'Port sayısı',
                        ],
                        'value' => [
                            'fa' => '2',
                            'en' => '2',
                            'tr' => '2',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'حافظهٔ کش',
                            'en' => 'Cache',
                            'tr' => 'Önbellek',
                        ],
                        'value' => [
                            'fa' => '—',
                            'en' => '—',
                            'tr' => '—',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen9',
                            'en' => 'Gen9',
                            'tr' => 'Gen9',
                        ],
                    ],
                ],
                'attrs' => [
                    'ports' => 2,
                ],
            ],
            [
                'slug' => 'raid-e208i-a',
                'category' => 'raid',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen10',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 6722,
                'in_stock' => true,
                'popular' => false,
                'sort' => 620,
                'name' => [
                    'fa' => 'HPE Smart Array E208i-a SR Gen10 AROC 2-Port',
                    'en' => 'HPE Smart Array E208i-a SR Gen10 AROC 2-Port',
                    'tr' => 'HPE Smart Array E208i-a SR Gen10 AROC 2-Port',
                ],
                'tagline' => [
                    'fa' => '2 پورت · کش —',
                    'en' => '2-port · — cache',
                    'tr' => '2 port · — önbellek',
                ],
                'summary' => [
                    'fa' => 'آداپتور HBA 2 پورته برای سرورهای Gen10 با حافظهٔ کش —.',
                    'en' => 'A 2-port HBA for Gen10 servers with — of cache.',
                    'tr' => 'Gen10 sunucular için — önbellekli 2 portlu HBA.',
                ],
                'body' => [
                    'fa' => 'HPE Smart Array E208i-a SR Gen10 AROC 2-Port روی سرورهای HPE ProLiant Gen10 نصب می‌شود و 2 پورت دارد.

این کارت HBA است نه RAID: دیسک‌ها را مستقیم و بدون لایهٔ آرایه به سیستم‌عامل می‌دهد. برای ZFS، Ceph و هر سامانه‌ای که خودش مدیریت افزونگی را بر عهده دارد، دقیقاً همین لازم است — لایهٔ RAID سخت‌افزاری آن‌جا فقط مزاحم است.

نکتهٔ سازگاری که گران تمام می‌شود: کارت AROC (نصب روی اسلات اختصاصی مادربرد) با کارت PCIe جای هم نمی‌نشیند و هر نسل اسلات AROC خودش را دارد. پیش از سفارش، نسل سرور را با فهرست سازگاری بالا مطابقت دهید.

کارت با کابل و باتری کش مربوطه تحویل می‌شود و روی همان نسل تست شده است.',
                    'en' => 'The HPE Smart Array E208i-a SR Gen10 AROC 2-Port installs in HPE ProLiant Gen10 servers and provides 2 ports.

This is an HBA, not a RAID card: it presents the disks straight through to the operating system with no array layer. That is precisely what ZFS, Ceph and any system that manages its own redundancy need — a hardware RAID layer only gets in the way there.

A compatibility detail that costs money to get wrong: an AROC card (which mounts in the board’s dedicated slot) and a PCIe card are not interchangeable, and each generation has its own AROC slot. Match your server generation against the compatibility list above before ordering.

The card ships with its cable and cache battery, tested on the same generation.',
                    'tr' => 'HPE Smart Array E208i-a SR Gen10 AROC 2-Port, HPE ProLiant Gen10 sunucularına takılır ve 2 port sunar.

Bu bir RAID kartı değil HBA’dır: diskleri dizi katmanı olmadan doğrudan işletim sistemine verir. ZFS, Ceph ve yedekliliği kendi yöneten her sistemin ihtiyacı tam olarak budur — orada donanımsal RAID katmanı yalnızca engeldir.

Yanlış yapılırsa pahalıya patlayan bir uyumluluk ayrıntısı: AROC kart (anakartın özel yuvasına takılan) ile PCIe kart birbirinin yerine geçmez ve her neslin kendi AROC yuvası vardır. Sipariş öncesi sunucu neslinizi yukarıdaki uyumluluk listesiyle karşılaştırın.

Kart, kablosu ve önbellek piliyle birlikte, aynı nesilde test edilmiş olarak gönderilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'نوع',
                            'en' => 'Type',
                            'tr' => 'Tip',
                        ],
                        'value' => [
                            'fa' => 'HBA',
                            'en' => 'HBA',
                            'tr' => 'HBA',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'تعداد پورت',
                            'en' => 'Ports',
                            'tr' => 'Port sayısı',
                        ],
                        'value' => [
                            'fa' => '2',
                            'en' => '2',
                            'tr' => '2',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'حافظهٔ کش',
                            'en' => 'Cache',
                            'tr' => 'Önbellek',
                        ],
                        'value' => [
                            'fa' => '—',
                            'en' => '—',
                            'tr' => '—',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen10',
                            'en' => 'Gen10',
                            'tr' => 'Gen10',
                        ],
                    ],
                ],
                'attrs' => [
                    'ports' => 2,
                ],
            ],
            [
                'slug' => 'raid-p408i-a',
                'category' => 'raid',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen10',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 16806,
                'in_stock' => true,
                'popular' => true,
                'sort' => 630,
                'name' => [
                    'fa' => 'HPE Smart Array P408i-a SR Gen10 AROC 2-Port RAID',
                    'en' => 'HPE Smart Array P408i-a SR Gen10 AROC 2-Port RAID',
                    'tr' => 'HPE Smart Array P408i-a SR Gen10 AROC 2-Port RAID',
                ],
                'tagline' => [
                    'fa' => '2 پورت · کش 2GB Flash-Backed',
                    'en' => '2-port · 2GB Flash-Backed cache',
                    'tr' => '2 port · 2GB Flash-Backed önbellek',
                ],
                'summary' => [
                    'fa' => 'کنترلر RAID 2 پورته برای سرورهای Gen10 با حافظهٔ کش 2GB Flash-Backed.',
                    'en' => 'A 2-port RAID controller for Gen10 servers with 2GB Flash-Backed of cache.',
                    'tr' => 'Gen10 sunucular için 2GB Flash-Backed önbellekli 2 portlu RAID denetleyici.',
                ],
                'body' => [
                    'fa' => 'HPE Smart Array P408i-a SR Gen10 AROC 2-Port RAID روی سرورهای HPE ProLiant Gen10 نصب می‌شود و 2 پورت دارد.

این کنترلر RAID سخت‌افزاری است: آرایه در خود کارت ساخته می‌شود و سیستم‌عامل فقط یک دیسک منطقی می‌بیند. حافظهٔ کش 2GB Flash-Backed با باتری/خازن پشتیبان یعنی نوشتن‌های در راه، هنگام قطع برق از دست نمی‌روند — همان چیزی که RAID نرم‌افزاری نمی‌دهد.

نکتهٔ سازگاری که گران تمام می‌شود: کارت AROC (نصب روی اسلات اختصاصی مادربرد) با کارت PCIe جای هم نمی‌نشیند و هر نسل اسلات AROC خودش را دارد. پیش از سفارش، نسل سرور را با فهرست سازگاری بالا مطابقت دهید.

کارت با کابل و باتری کش مربوطه تحویل می‌شود و روی همان نسل تست شده است.',
                    'en' => 'The HPE Smart Array P408i-a SR Gen10 AROC 2-Port RAID installs in HPE ProLiant Gen10 servers and provides 2 ports.

This is a hardware RAID controller: the array is built on the card itself and the operating system only sees a logical drive. The 2GB Flash-Backed cache with its battery/capacitor backup means in-flight writes survive a power cut — exactly what software RAID cannot give you.

A compatibility detail that costs money to get wrong: an AROC card (which mounts in the board’s dedicated slot) and a PCIe card are not interchangeable, and each generation has its own AROC slot. Match your server generation against the compatibility list above before ordering.

The card ships with its cable and cache battery, tested on the same generation.',
                    'tr' => 'HPE Smart Array P408i-a SR Gen10 AROC 2-Port RAID, HPE ProLiant Gen10 sunucularına takılır ve 2 port sunar.

Bu bir donanımsal RAID denetleyicisidir: dizi kartın üzerinde kurulur ve işletim sistemi yalnızca mantıksal bir sürücü görür. Pil/kondansatör destekli 2GB Flash-Backed önbellek, yolda olan yazmaların elektrik kesintisinden sağ çıkması demektir — yazılımsal RAID’in veremediği tam olarak budur.

Yanlış yapılırsa pahalıya patlayan bir uyumluluk ayrıntısı: AROC kart (anakartın özel yuvasına takılan) ile PCIe kart birbirinin yerine geçmez ve her neslin kendi AROC yuvası vardır. Sipariş öncesi sunucu neslinizi yukarıdaki uyumluluk listesiyle karşılaştırın.

Kart, kablosu ve önbellek piliyle birlikte, aynı nesilde test edilmiş olarak gönderilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'نوع',
                            'en' => 'Type',
                            'tr' => 'Tip',
                        ],
                        'value' => [
                            'fa' => 'RAID',
                            'en' => 'RAID',
                            'tr' => 'RAID',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'تعداد پورت',
                            'en' => 'Ports',
                            'tr' => 'Port sayısı',
                        ],
                        'value' => [
                            'fa' => '2',
                            'en' => '2',
                            'tr' => '2',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'حافظهٔ کش',
                            'en' => 'Cache',
                            'tr' => 'Önbellek',
                        ],
                        'value' => [
                            'fa' => '2GB Flash-Backed',
                            'en' => '2GB Flash-Backed',
                            'tr' => '2GB Flash-Backed',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen10',
                            'en' => 'Gen10',
                            'tr' => 'Gen10',
                        ],
                    ],
                ],
                'attrs' => [
                    'ports' => 2,
                ],
            ],
            [
                'slug' => 'raid-p408i-p',
                'category' => 'raid',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen10',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 13445,
                'in_stock' => true,
                'popular' => false,
                'sort' => 640,
                'name' => [
                    'fa' => 'HPE Smart Array P408i-p SR Gen10 PCIe 2-Port RAID',
                    'en' => 'HPE Smart Array P408i-p SR Gen10 PCIe 2-Port RAID',
                    'tr' => 'HPE Smart Array P408i-p SR Gen10 PCIe 2-Port RAID',
                ],
                'tagline' => [
                    'fa' => '2 پورت · کش 2GB Flash-Backed',
                    'en' => '2-port · 2GB Flash-Backed cache',
                    'tr' => '2 port · 2GB Flash-Backed önbellek',
                ],
                'summary' => [
                    'fa' => 'کنترلر RAID 2 پورته برای سرورهای Gen10 با حافظهٔ کش 2GB Flash-Backed.',
                    'en' => 'A 2-port RAID controller for Gen10 servers with 2GB Flash-Backed of cache.',
                    'tr' => 'Gen10 sunucular için 2GB Flash-Backed önbellekli 2 portlu RAID denetleyici.',
                ],
                'body' => [
                    'fa' => 'HPE Smart Array P408i-p SR Gen10 PCIe 2-Port RAID روی سرورهای HPE ProLiant Gen10 نصب می‌شود و 2 پورت دارد.

این کنترلر RAID سخت‌افزاری است: آرایه در خود کارت ساخته می‌شود و سیستم‌عامل فقط یک دیسک منطقی می‌بیند. حافظهٔ کش 2GB Flash-Backed با باتری/خازن پشتیبان یعنی نوشتن‌های در راه، هنگام قطع برق از دست نمی‌روند — همان چیزی که RAID نرم‌افزاری نمی‌دهد.

نکتهٔ سازگاری که گران تمام می‌شود: کارت AROC (نصب روی اسلات اختصاصی مادربرد) با کارت PCIe جای هم نمی‌نشیند و هر نسل اسلات AROC خودش را دارد. پیش از سفارش، نسل سرور را با فهرست سازگاری بالا مطابقت دهید.

کارت با کابل و باتری کش مربوطه تحویل می‌شود و روی همان نسل تست شده است.',
                    'en' => 'The HPE Smart Array P408i-p SR Gen10 PCIe 2-Port RAID installs in HPE ProLiant Gen10 servers and provides 2 ports.

This is a hardware RAID controller: the array is built on the card itself and the operating system only sees a logical drive. The 2GB Flash-Backed cache with its battery/capacitor backup means in-flight writes survive a power cut — exactly what software RAID cannot give you.

A compatibility detail that costs money to get wrong: an AROC card (which mounts in the board’s dedicated slot) and a PCIe card are not interchangeable, and each generation has its own AROC slot. Match your server generation against the compatibility list above before ordering.

The card ships with its cable and cache battery, tested on the same generation.',
                    'tr' => 'HPE Smart Array P408i-p SR Gen10 PCIe 2-Port RAID, HPE ProLiant Gen10 sunucularına takılır ve 2 port sunar.

Bu bir donanımsal RAID denetleyicisidir: dizi kartın üzerinde kurulur ve işletim sistemi yalnızca mantıksal bir sürücü görür. Pil/kondansatör destekli 2GB Flash-Backed önbellek, yolda olan yazmaların elektrik kesintisinden sağ çıkması demektir — yazılımsal RAID’in veremediği tam olarak budur.

Yanlış yapılırsa pahalıya patlayan bir uyumluluk ayrıntısı: AROC kart (anakartın özel yuvasına takılan) ile PCIe kart birbirinin yerine geçmez ve her neslin kendi AROC yuvası vardır. Sipariş öncesi sunucu neslinizi yukarıdaki uyumluluk listesiyle karşılaştırın.

Kart, kablosu ve önbellek piliyle birlikte, aynı nesilde test edilmiş olarak gönderilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'نوع',
                            'en' => 'Type',
                            'tr' => 'Tip',
                        ],
                        'value' => [
                            'fa' => 'RAID',
                            'en' => 'RAID',
                            'tr' => 'RAID',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'تعداد پورت',
                            'en' => 'Ports',
                            'tr' => 'Port sayısı',
                        ],
                        'value' => [
                            'fa' => '2',
                            'en' => '2',
                            'tr' => '2',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'حافظهٔ کش',
                            'en' => 'Cache',
                            'tr' => 'Önbellek',
                        ],
                        'value' => [
                            'fa' => '2GB Flash-Backed',
                            'en' => '2GB Flash-Backed',
                            'tr' => '2GB Flash-Backed',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen10',
                            'en' => 'Gen10',
                            'tr' => 'Gen10',
                        ],
                    ],
                ],
                'attrs' => [
                    'ports' => 2,
                ],
            ],
            [
                'slug' => 'raid-mr416i-a',
                'category' => 'raid',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen11',
                    'gen12',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 37814,
                'in_stock' => true,
                'popular' => false,
                'sort' => 650,
                'name' => [
                    'fa' => 'HPE MR416i-a Gen11 Tri-Mode AROC 2-Port RAID',
                    'en' => 'HPE MR416i-a Gen11 Tri-Mode AROC 2-Port RAID',
                    'tr' => 'HPE MR416i-a Gen11 Tri-Mode AROC 2-Port RAID',
                ],
                'tagline' => [
                    'fa' => '2 پورت · کش 4GB Flash-Backed',
                    'en' => '2-port · 4GB Flash-Backed cache',
                    'tr' => '2 port · 4GB Flash-Backed önbellek',
                ],
                'summary' => [
                    'fa' => 'کنترلر RAID 2 پورته برای سرورهای Gen11 / Gen12 با حافظهٔ کش 4GB Flash-Backed.',
                    'en' => 'A 2-port RAID controller for Gen11 / Gen12 servers with 4GB Flash-Backed of cache.',
                    'tr' => 'Gen11 / Gen12 sunucular için 4GB Flash-Backed önbellekli 2 portlu RAID denetleyici.',
                ],
                'body' => [
                    'fa' => 'HPE MR416i-a Gen11 Tri-Mode AROC 2-Port RAID روی سرورهای HPE ProLiant Gen11 / Gen12 نصب می‌شود و 2 پورت دارد.

این کنترلر RAID سخت‌افزاری است: آرایه در خود کارت ساخته می‌شود و سیستم‌عامل فقط یک دیسک منطقی می‌بیند. حافظهٔ کش 4GB Flash-Backed با باتری/خازن پشتیبان یعنی نوشتن‌های در راه، هنگام قطع برق از دست نمی‌روند — همان چیزی که RAID نرم‌افزاری نمی‌دهد.

نکتهٔ سازگاری که گران تمام می‌شود: کارت AROC (نصب روی اسلات اختصاصی مادربرد) با کارت PCIe جای هم نمی‌نشیند و هر نسل اسلات AROC خودش را دارد. پیش از سفارش، نسل سرور را با فهرست سازگاری بالا مطابقت دهید.

کارت با کابل و باتری کش مربوطه تحویل می‌شود و روی همان نسل تست شده است.',
                    'en' => 'The HPE MR416i-a Gen11 Tri-Mode AROC 2-Port RAID installs in HPE ProLiant Gen11 / Gen12 servers and provides 2 ports.

This is a hardware RAID controller: the array is built on the card itself and the operating system only sees a logical drive. The 4GB Flash-Backed cache with its battery/capacitor backup means in-flight writes survive a power cut — exactly what software RAID cannot give you.

A compatibility detail that costs money to get wrong: an AROC card (which mounts in the board’s dedicated slot) and a PCIe card are not interchangeable, and each generation has its own AROC slot. Match your server generation against the compatibility list above before ordering.

The card ships with its cable and cache battery, tested on the same generation.',
                    'tr' => 'HPE MR416i-a Gen11 Tri-Mode AROC 2-Port RAID, HPE ProLiant Gen11 / Gen12 sunucularına takılır ve 2 port sunar.

Bu bir donanımsal RAID denetleyicisidir: dizi kartın üzerinde kurulur ve işletim sistemi yalnızca mantıksal bir sürücü görür. Pil/kondansatör destekli 4GB Flash-Backed önbellek, yolda olan yazmaların elektrik kesintisinden sağ çıkması demektir — yazılımsal RAID’in veremediği tam olarak budur.

Yanlış yapılırsa pahalıya patlayan bir uyumluluk ayrıntısı: AROC kart (anakartın özel yuvasına takılan) ile PCIe kart birbirinin yerine geçmez ve her neslin kendi AROC yuvası vardır. Sipariş öncesi sunucu neslinizi yukarıdaki uyumluluk listesiyle karşılaştırın.

Kart, kablosu ve önbellek piliyle birlikte, aynı nesilde test edilmiş olarak gönderilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'نوع',
                            'en' => 'Type',
                            'tr' => 'Tip',
                        ],
                        'value' => [
                            'fa' => 'RAID',
                            'en' => 'RAID',
                            'tr' => 'RAID',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'تعداد پورت',
                            'en' => 'Ports',
                            'tr' => 'Port sayısı',
                        ],
                        'value' => [
                            'fa' => '2',
                            'en' => '2',
                            'tr' => '2',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'حافظهٔ کش',
                            'en' => 'Cache',
                            'tr' => 'Önbellek',
                        ],
                        'value' => [
                            'fa' => '4GB Flash-Backed',
                            'en' => '4GB Flash-Backed',
                            'tr' => '4GB Flash-Backed',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen11 / Gen12',
                            'en' => 'Gen11 / Gen12',
                            'tr' => 'Gen11 / Gen12',
                        ],
                    ],
                ],
                'attrs' => [
                    'ports' => 2,
                ],
            ],
            [
                'slug' => 'raid-sas-expander',
                'category' => 'raid',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen9',
                    'gen10',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 11764,
                'in_stock' => true,
                'popular' => false,
                'sort' => 660,
                'name' => [
                    'fa' => 'HPE 12G SAS Expander Card 9-Port',
                    'en' => 'HPE 12G SAS Expander Card 9-Port',
                    'tr' => 'HPE 12G SAS Expander Card 9-Port',
                ],
                'tagline' => [
                    'fa' => '9 پورت · کش —',
                    'en' => '9-port · — cache',
                    'tr' => '9 port · — önbellek',
                ],
                'summary' => [
                    'fa' => 'آداپتور HBA 9 پورته برای سرورهای Gen9 / Gen10 با حافظهٔ کش —.',
                    'en' => 'A 9-port HBA for Gen9 / Gen10 servers with — of cache.',
                    'tr' => 'Gen9 / Gen10 sunucular için — önbellekli 9 portlu HBA.',
                ],
                'body' => [
                    'fa' => 'HPE 12G SAS Expander Card 9-Port روی سرورهای HPE ProLiant Gen9 / Gen10 نصب می‌شود و 9 پورت دارد.

این کارت HBA است نه RAID: دیسک‌ها را مستقیم و بدون لایهٔ آرایه به سیستم‌عامل می‌دهد. برای ZFS، Ceph و هر سامانه‌ای که خودش مدیریت افزونگی را بر عهده دارد، دقیقاً همین لازم است — لایهٔ RAID سخت‌افزاری آن‌جا فقط مزاحم است.

نکتهٔ سازگاری که گران تمام می‌شود: کارت AROC (نصب روی اسلات اختصاصی مادربرد) با کارت PCIe جای هم نمی‌نشیند و هر نسل اسلات AROC خودش را دارد. پیش از سفارش، نسل سرور را با فهرست سازگاری بالا مطابقت دهید.

کارت با کابل و باتری کش مربوطه تحویل می‌شود و روی همان نسل تست شده است.',
                    'en' => 'The HPE 12G SAS Expander Card 9-Port installs in HPE ProLiant Gen9 / Gen10 servers and provides 9 ports.

This is an HBA, not a RAID card: it presents the disks straight through to the operating system with no array layer. That is precisely what ZFS, Ceph and any system that manages its own redundancy need — a hardware RAID layer only gets in the way there.

A compatibility detail that costs money to get wrong: an AROC card (which mounts in the board’s dedicated slot) and a PCIe card are not interchangeable, and each generation has its own AROC slot. Match your server generation against the compatibility list above before ordering.

The card ships with its cable and cache battery, tested on the same generation.',
                    'tr' => 'HPE 12G SAS Expander Card 9-Port, HPE ProLiant Gen9 / Gen10 sunucularına takılır ve 9 port sunar.

Bu bir RAID kartı değil HBA’dır: diskleri dizi katmanı olmadan doğrudan işletim sistemine verir. ZFS, Ceph ve yedekliliği kendi yöneten her sistemin ihtiyacı tam olarak budur — orada donanımsal RAID katmanı yalnızca engeldir.

Yanlış yapılırsa pahalıya patlayan bir uyumluluk ayrıntısı: AROC kart (anakartın özel yuvasına takılan) ile PCIe kart birbirinin yerine geçmez ve her neslin kendi AROC yuvası vardır. Sipariş öncesi sunucu neslinizi yukarıdaki uyumluluk listesiyle karşılaştırın.

Kart, kablosu ve önbellek piliyle birlikte, aynı nesilde test edilmiş olarak gönderilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'نوع',
                            'en' => 'Type',
                            'tr' => 'Tip',
                        ],
                        'value' => [
                            'fa' => 'HBA',
                            'en' => 'HBA',
                            'tr' => 'HBA',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'تعداد پورت',
                            'en' => 'Ports',
                            'tr' => 'Port sayısı',
                        ],
                        'value' => [
                            'fa' => '9',
                            'en' => '9',
                            'tr' => '9',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'حافظهٔ کش',
                            'en' => 'Cache',
                            'tr' => 'Önbellek',
                        ],
                        'value' => [
                            'fa' => '—',
                            'en' => '—',
                            'tr' => '—',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen9 / Gen10',
                            'en' => 'Gen9 / Gen10',
                            'tr' => 'Gen9 / Gen10',
                        ],
                    ],
                ],
                'attrs' => [
                    'ports' => 9,
                ],
            ],
            [
                'slug' => 'nic-331flr',
                'category' => 'nic',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen8',
                    'gen9',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 1260,
                'in_stock' => true,
                'popular' => false,
                'sort' => 670,
                'name' => [
                    'fa' => 'HPE 331FLR Quad Port 1G RJ45 FlexibleLOM',
                    'en' => 'HPE 331FLR Quad Port 1G RJ45 FlexibleLOM',
                    'tr' => 'HPE 331FLR Quad Port 1G RJ45 FlexibleLOM',
                ],
                'tagline' => [
                    'fa' => '4 پورت 1 گیگابیت RJ45',
                    'en' => '4 × 1G RJ45',
                    'tr' => '4 × 1G RJ45',
                ],
                'summary' => [
                    'fa' => 'کارت شبکهٔ 4 پورته با سرعت 1 گیگابیت بر ثانیه و رابط RJ45 برای سرورهای Gen8 / Gen9.',
                    'en' => 'A 4-port 1 Gb/s RJ45 network adapter for Gen8 / Gen9 servers.',
                    'tr' => 'Gen8 / Gen9 sunucular için 4 portlu 1 Gb/s RJ45 ağ adaptörü.',
                ],
                'body' => [
                    'fa' => 'HPE 331FLR Quad Port 1G RJ45 FlexibleLOM یک کارت شبکهٔ سرور با 4 پورت 1 گیگابیتی و رابط RJ45 است و روی سرورهای HPE ProLiant Gen8 / Gen9 کار می‌کند.

پهنای باند کل این کارت 4 گیگابیت بر ثانیه است. اگر سرور شما ماشین مجازی میزبانی می‌کند یا به شبکهٔ ذخیره‌سازی وصل است، پورت یک‌گیگابیتی روی‌برد معمولاً اولین گلوگاهی است که به آن می‌خورید — و رفعش ارزان‌ترین ارتقای ممکن است.

رابط RJ45 با کابل مسی استاندارد کار می‌کند و ماژول جدا نمی‌خواهد — ساده‌ترین راه رسیدن به ده گیگابیت اگر فاصله کوتاه است.

کارت‌های FlexibleLOM روی اسلات اختصاصی مادربرد می‌نشینند و اسلات PCIe را آزاد نگه می‌دارند؛ کارت PCIe جای FlexibleLOM نمی‌نشیند و برعکس.',
                    'en' => 'The HPE 331FLR Quad Port 1G RJ45 FlexibleLOM is a server network adapter with 4 × 1 Gb/s RJ45 ports for HPE ProLiant Gen8 / Gen9 servers.

Total bandwidth is 4 Gb/s. If your server hosts virtual machines or attaches to a storage network, the onboard gigabit port is usually the first bottleneck you hit — and fixing it is the cheapest upgrade available.

The RJ45 interface runs on ordinary copper cabling with no separate module — the simplest route to ten gigabit over short distances.

FlexibleLOM cards sit in the board’s dedicated slot and leave your PCIe slots free; a PCIe card will not fit a FlexibleLOM slot, or the other way round.',
                    'tr' => 'HPE 331FLR Quad Port 1G RJ45 FlexibleLOM, HPE ProLiant Gen8 / Gen9 sunucuları için 4 × 1 Gb/s RJ45 portlu bir sunucu ağ adaptörüdür.

Toplam bant genişliği 4 Gb/s’dir. Sunucunuz sanal makine barındırıyorsa veya bir depolama ağına bağlıysa, yerleşik gigabit port genelde çarptığınız ilk darboğazdır — ve bunu gidermek mevcut en ucuz yükseltmedir.

RJ45 arayüzü sıradan bakır kabloyla çalışır, ayrı modül istemez — kısa mesafede on gigabite en basit yol.

FlexibleLOM kartlar anakartın özel yuvasına oturur ve PCIe yuvalarınızı boş bırakır; PCIe kart FlexibleLOM yuvasına, FlexibleLOM da PCIe yuvasına takılmaz.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'تعداد پورت',
                            'en' => 'Ports',
                            'tr' => 'Port sayısı',
                        ],
                        'value' => [
                            'fa' => '4',
                            'en' => '4',
                            'tr' => '4',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سرعت هر پورت',
                            'en' => 'Port speed',
                            'tr' => 'Port hızı',
                        ],
                        'value' => [
                            'fa' => '1 Gb/s',
                            'en' => '1 Gb/s',
                            'tr' => '1 Gb/s',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'رابط',
                            'en' => 'Media',
                            'tr' => 'Arayüz',
                        ],
                        'value' => [
                            'fa' => 'RJ45',
                            'en' => 'RJ45',
                            'tr' => 'RJ45',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'پهنای باند کل',
                            'en' => 'Total bandwidth',
                            'tr' => 'Toplam bant genişliği',
                        ],
                        'value' => [
                            'fa' => '4 Gb/s',
                            'en' => '4 Gb/s',
                            'tr' => '4 Gb/s',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen8 / Gen9',
                            'en' => 'Gen8 / Gen9',
                            'tr' => 'Gen8 / Gen9',
                        ],
                    ],
                ],
                'attrs' => [
                    'ports' => 4,
                    'gbps' => 1,
                ],
            ],
            [
                'slug' => 'nic-561flr-t',
                'category' => 'nic',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen9',
                    'gen10',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 2520,
                'in_stock' => true,
                'popular' => true,
                'sort' => 680,
                'name' => [
                    'fa' => 'HPE 561FLR-T Dual Port 10G RJ45 FlexibleLOM',
                    'en' => 'HPE 561FLR-T Dual Port 10G RJ45 FlexibleLOM',
                    'tr' => 'HPE 561FLR-T Dual Port 10G RJ45 FlexibleLOM',
                ],
                'tagline' => [
                    'fa' => '2 پورت 10 گیگابیت RJ45',
                    'en' => '2 × 10G RJ45',
                    'tr' => '2 × 10G RJ45',
                ],
                'summary' => [
                    'fa' => 'کارت شبکهٔ 2 پورته با سرعت 10 گیگابیت بر ثانیه و رابط RJ45 برای سرورهای Gen9 / Gen10.',
                    'en' => 'A 2-port 10 Gb/s RJ45 network adapter for Gen9 / Gen10 servers.',
                    'tr' => 'Gen9 / Gen10 sunucular için 2 portlu 10 Gb/s RJ45 ağ adaptörü.',
                ],
                'body' => [
                    'fa' => 'HPE 561FLR-T Dual Port 10G RJ45 FlexibleLOM یک کارت شبکهٔ سرور با 2 پورت 10 گیگابیتی و رابط RJ45 است و روی سرورهای HPE ProLiant Gen9 / Gen10 کار می‌کند.

پهنای باند کل این کارت 20 گیگابیت بر ثانیه است. اگر سرور شما ماشین مجازی میزبانی می‌کند یا به شبکهٔ ذخیره‌سازی وصل است، پورت یک‌گیگابیتی روی‌برد معمولاً اولین گلوگاهی است که به آن می‌خورید — و رفعش ارزان‌ترین ارتقای ممکن است.

رابط RJ45 با کابل مسی استاندارد کار می‌کند و ماژول جدا نمی‌خواهد — ساده‌ترین راه رسیدن به ده گیگابیت اگر فاصله کوتاه است.

کارت‌های FlexibleLOM روی اسلات اختصاصی مادربرد می‌نشینند و اسلات PCIe را آزاد نگه می‌دارند؛ کارت PCIe جای FlexibleLOM نمی‌نشیند و برعکس.',
                    'en' => 'The HPE 561FLR-T Dual Port 10G RJ45 FlexibleLOM is a server network adapter with 2 × 10 Gb/s RJ45 ports for HPE ProLiant Gen9 / Gen10 servers.

Total bandwidth is 20 Gb/s. If your server hosts virtual machines or attaches to a storage network, the onboard gigabit port is usually the first bottleneck you hit — and fixing it is the cheapest upgrade available.

The RJ45 interface runs on ordinary copper cabling with no separate module — the simplest route to ten gigabit over short distances.

FlexibleLOM cards sit in the board’s dedicated slot and leave your PCIe slots free; a PCIe card will not fit a FlexibleLOM slot, or the other way round.',
                    'tr' => 'HPE 561FLR-T Dual Port 10G RJ45 FlexibleLOM, HPE ProLiant Gen9 / Gen10 sunucuları için 2 × 10 Gb/s RJ45 portlu bir sunucu ağ adaptörüdür.

Toplam bant genişliği 20 Gb/s’dir. Sunucunuz sanal makine barındırıyorsa veya bir depolama ağına bağlıysa, yerleşik gigabit port genelde çarptığınız ilk darboğazdır — ve bunu gidermek mevcut en ucuz yükseltmedir.

RJ45 arayüzü sıradan bakır kabloyla çalışır, ayrı modül istemez — kısa mesafede on gigabite en basit yol.

FlexibleLOM kartlar anakartın özel yuvasına oturur ve PCIe yuvalarınızı boş bırakır; PCIe kart FlexibleLOM yuvasına, FlexibleLOM da PCIe yuvasına takılmaz.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'تعداد پورت',
                            'en' => 'Ports',
                            'tr' => 'Port sayısı',
                        ],
                        'value' => [
                            'fa' => '2',
                            'en' => '2',
                            'tr' => '2',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سرعت هر پورت',
                            'en' => 'Port speed',
                            'tr' => 'Port hızı',
                        ],
                        'value' => [
                            'fa' => '10 Gb/s',
                            'en' => '10 Gb/s',
                            'tr' => '10 Gb/s',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'رابط',
                            'en' => 'Media',
                            'tr' => 'Arayüz',
                        ],
                        'value' => [
                            'fa' => 'RJ45',
                            'en' => 'RJ45',
                            'tr' => 'RJ45',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'پهنای باند کل',
                            'en' => 'Total bandwidth',
                            'tr' => 'Toplam bant genişliği',
                        ],
                        'value' => [
                            'fa' => '20 Gb/s',
                            'en' => '20 Gb/s',
                            'tr' => '20 Gb/s',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen9 / Gen10',
                            'en' => 'Gen9 / Gen10',
                            'tr' => 'Gen9 / Gen10',
                        ],
                    ],
                ],
                'attrs' => [
                    'ports' => 2,
                    'gbps' => 10,
                ],
            ],
            [
                'slug' => 'nic-530sfp',
                'category' => 'nic',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen8',
                    'gen9',
                    'gen10',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 4201,
                'in_stock' => true,
                'popular' => true,
                'sort' => 690,
                'name' => [
                    'fa' => 'HPE 530SFP+ Dual Port 10G SFP+ PCIe',
                    'en' => 'HPE 530SFP+ Dual Port 10G SFP+ PCIe',
                    'tr' => 'HPE 530SFP+ Dual Port 10G SFP+ PCIe',
                ],
                'tagline' => [
                    'fa' => '2 پورت 10 گیگابیت SFP+',
                    'en' => '2 × 10G SFP+',
                    'tr' => '2 × 10G SFP+',
                ],
                'summary' => [
                    'fa' => 'کارت شبکهٔ 2 پورته با سرعت 10 گیگابیت بر ثانیه و رابط SFP+ برای سرورهای Gen8 / Gen9 / Gen10.',
                    'en' => 'A 2-port 10 Gb/s SFP+ network adapter for Gen8 / Gen9 / Gen10 servers.',
                    'tr' => 'Gen8 / Gen9 / Gen10 sunucular için 2 portlu 10 Gb/s SFP+ ağ adaptörü.',
                ],
                'body' => [
                    'fa' => 'HPE 530SFP+ Dual Port 10G SFP+ PCIe یک کارت شبکهٔ سرور با 2 پورت 10 گیگابیتی و رابط SFP+ است و روی سرورهای HPE ProLiant Gen8 / Gen9 / Gen10 کار می‌کند.

پهنای باند کل این کارت 20 گیگابیت بر ثانیه است. اگر سرور شما ماشین مجازی میزبانی می‌کند یا به شبکهٔ ذخیره‌سازی وصل است، پورت یک‌گیگابیتی روی‌برد معمولاً اولین گلوگاهی است که به آن می‌خورید — و رفعش ارزان‌ترین ارتقای ممکن است.

رابط SFP+ ماژول SFP جدا می‌خواهد؛ ماژول را متناسب با فاصله و نوع فیبر انتخاب کنید و دقت کنید سوییچ شما همان ماژول را قبول کند. بعضی سوییچ‌ها فقط ماژول برند خودشان را می‌پذیرند.

کارت‌های FlexibleLOM روی اسلات اختصاصی مادربرد می‌نشینند و اسلات PCIe را آزاد نگه می‌دارند؛ کارت PCIe جای FlexibleLOM نمی‌نشیند و برعکس.',
                    'en' => 'The HPE 530SFP+ Dual Port 10G SFP+ PCIe is a server network adapter with 2 × 10 Gb/s SFP+ ports for HPE ProLiant Gen8 / Gen9 / Gen10 servers.

Total bandwidth is 20 Gb/s. If your server hosts virtual machines or attaches to a storage network, the onboard gigabit port is usually the first bottleneck you hit — and fixing it is the cheapest upgrade available.

The SFP+ interface needs separate SFP modules; pick the module for your distance and fibre type, and check that your switch accepts it — some switches only take their own brand.

FlexibleLOM cards sit in the board’s dedicated slot and leave your PCIe slots free; a PCIe card will not fit a FlexibleLOM slot, or the other way round.',
                    'tr' => 'HPE 530SFP+ Dual Port 10G SFP+ PCIe, HPE ProLiant Gen8 / Gen9 / Gen10 sunucuları için 2 × 10 Gb/s SFP+ portlu bir sunucu ağ adaptörüdür.

Toplam bant genişliği 20 Gb/s’dir. Sunucunuz sanal makine barındırıyorsa veya bir depolama ağına bağlıysa, yerleşik gigabit port genelde çarptığınız ilk darboğazdır — ve bunu gidermek mevcut en ucuz yükseltmedir.

SFP+ arayüzü ayrı SFP modülleri ister; modülü mesafenize ve fiber tipinize göre seçin ve anahtarınızın kabul ettiğinden emin olun — bazı anahtarlar yalnızca kendi markasını kabul eder.

FlexibleLOM kartlar anakartın özel yuvasına oturur ve PCIe yuvalarınızı boş bırakır; PCIe kart FlexibleLOM yuvasına, FlexibleLOM da PCIe yuvasına takılmaz.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'تعداد پورت',
                            'en' => 'Ports',
                            'tr' => 'Port sayısı',
                        ],
                        'value' => [
                            'fa' => '2',
                            'en' => '2',
                            'tr' => '2',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سرعت هر پورت',
                            'en' => 'Port speed',
                            'tr' => 'Port hızı',
                        ],
                        'value' => [
                            'fa' => '10 Gb/s',
                            'en' => '10 Gb/s',
                            'tr' => '10 Gb/s',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'رابط',
                            'en' => 'Media',
                            'tr' => 'Arayüz',
                        ],
                        'value' => [
                            'fa' => 'SFP+',
                            'en' => 'SFP+',
                            'tr' => 'SFP+',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'پهنای باند کل',
                            'en' => 'Total bandwidth',
                            'tr' => 'Toplam bant genişliği',
                        ],
                        'value' => [
                            'fa' => '20 Gb/s',
                            'en' => '20 Gb/s',
                            'tr' => '20 Gb/s',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen8 / Gen9 / Gen10',
                            'en' => 'Gen8 / Gen9 / Gen10',
                            'tr' => 'Gen8 / Gen9 / Gen10',
                        ],
                    ],
                ],
                'attrs' => [
                    'ports' => 2,
                    'gbps' => 10,
                ],
            ],
            [
                'slug' => 'nic-562flr-sfp',
                'category' => 'nic',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen9',
                    'gen10',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 5041,
                'in_stock' => true,
                'popular' => false,
                'sort' => 700,
                'name' => [
                    'fa' => 'HPE 562FLR-SFP+ Dual Port 10G SFP+ FlexibleLOM',
                    'en' => 'HPE 562FLR-SFP+ Dual Port 10G SFP+ FlexibleLOM',
                    'tr' => 'HPE 562FLR-SFP+ Dual Port 10G SFP+ FlexibleLOM',
                ],
                'tagline' => [
                    'fa' => '2 پورت 10 گیگابیت SFP+',
                    'en' => '2 × 10G SFP+',
                    'tr' => '2 × 10G SFP+',
                ],
                'summary' => [
                    'fa' => 'کارت شبکهٔ 2 پورته با سرعت 10 گیگابیت بر ثانیه و رابط SFP+ برای سرورهای Gen9 / Gen10.',
                    'en' => 'A 2-port 10 Gb/s SFP+ network adapter for Gen9 / Gen10 servers.',
                    'tr' => 'Gen9 / Gen10 sunucular için 2 portlu 10 Gb/s SFP+ ağ adaptörü.',
                ],
                'body' => [
                    'fa' => 'HPE 562FLR-SFP+ Dual Port 10G SFP+ FlexibleLOM یک کارت شبکهٔ سرور با 2 پورت 10 گیگابیتی و رابط SFP+ است و روی سرورهای HPE ProLiant Gen9 / Gen10 کار می‌کند.

پهنای باند کل این کارت 20 گیگابیت بر ثانیه است. اگر سرور شما ماشین مجازی میزبانی می‌کند یا به شبکهٔ ذخیره‌سازی وصل است، پورت یک‌گیگابیتی روی‌برد معمولاً اولین گلوگاهی است که به آن می‌خورید — و رفعش ارزان‌ترین ارتقای ممکن است.

رابط SFP+ ماژول SFP جدا می‌خواهد؛ ماژول را متناسب با فاصله و نوع فیبر انتخاب کنید و دقت کنید سوییچ شما همان ماژول را قبول کند. بعضی سوییچ‌ها فقط ماژول برند خودشان را می‌پذیرند.

کارت‌های FlexibleLOM روی اسلات اختصاصی مادربرد می‌نشینند و اسلات PCIe را آزاد نگه می‌دارند؛ کارت PCIe جای FlexibleLOM نمی‌نشیند و برعکس.',
                    'en' => 'The HPE 562FLR-SFP+ Dual Port 10G SFP+ FlexibleLOM is a server network adapter with 2 × 10 Gb/s SFP+ ports for HPE ProLiant Gen9 / Gen10 servers.

Total bandwidth is 20 Gb/s. If your server hosts virtual machines or attaches to a storage network, the onboard gigabit port is usually the first bottleneck you hit — and fixing it is the cheapest upgrade available.

The SFP+ interface needs separate SFP modules; pick the module for your distance and fibre type, and check that your switch accepts it — some switches only take their own brand.

FlexibleLOM cards sit in the board’s dedicated slot and leave your PCIe slots free; a PCIe card will not fit a FlexibleLOM slot, or the other way round.',
                    'tr' => 'HPE 562FLR-SFP+ Dual Port 10G SFP+ FlexibleLOM, HPE ProLiant Gen9 / Gen10 sunucuları için 2 × 10 Gb/s SFP+ portlu bir sunucu ağ adaptörüdür.

Toplam bant genişliği 20 Gb/s’dir. Sunucunuz sanal makine barındırıyorsa veya bir depolama ağına bağlıysa, yerleşik gigabit port genelde çarptığınız ilk darboğazdır — ve bunu gidermek mevcut en ucuz yükseltmedir.

SFP+ arayüzü ayrı SFP modülleri ister; modülü mesafenize ve fiber tipinize göre seçin ve anahtarınızın kabul ettiğinden emin olun — bazı anahtarlar yalnızca kendi markasını kabul eder.

FlexibleLOM kartlar anakartın özel yuvasına oturur ve PCIe yuvalarınızı boş bırakır; PCIe kart FlexibleLOM yuvasına, FlexibleLOM da PCIe yuvasına takılmaz.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'تعداد پورت',
                            'en' => 'Ports',
                            'tr' => 'Port sayısı',
                        ],
                        'value' => [
                            'fa' => '2',
                            'en' => '2',
                            'tr' => '2',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سرعت هر پورت',
                            'en' => 'Port speed',
                            'tr' => 'Port hızı',
                        ],
                        'value' => [
                            'fa' => '10 Gb/s',
                            'en' => '10 Gb/s',
                            'tr' => '10 Gb/s',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'رابط',
                            'en' => 'Media',
                            'tr' => 'Arayüz',
                        ],
                        'value' => [
                            'fa' => 'SFP+',
                            'en' => 'SFP+',
                            'tr' => 'SFP+',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'پهنای باند کل',
                            'en' => 'Total bandwidth',
                            'tr' => 'Toplam bant genişliği',
                        ],
                        'value' => [
                            'fa' => '20 Gb/s',
                            'en' => '20 Gb/s',
                            'tr' => '20 Gb/s',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen9 / Gen10',
                            'en' => 'Gen9 / Gen10',
                            'tr' => 'Gen9 / Gen10',
                        ],
                    ],
                ],
                'attrs' => [
                    'ports' => 2,
                    'gbps' => 10,
                ],
            ],
            [
                'slug' => 'nic-x710-da2',
                'category' => 'nic',
                'brand' => 'Intel',
                'compat_gens' => [
                    'gen9',
                    'gen10',
                    'gen11',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 6722,
                'in_stock' => true,
                'popular' => false,
                'sort' => 710,
                'name' => [
                    'fa' => 'Intel X710-DA2 Dual Port 10G SFP+ PCIe',
                    'en' => 'Intel X710-DA2 Dual Port 10G SFP+ PCIe',
                    'tr' => 'Intel X710-DA2 Dual Port 10G SFP+ PCIe',
                ],
                'tagline' => [
                    'fa' => '2 پورت 10 گیگابیت SFP+',
                    'en' => '2 × 10G SFP+',
                    'tr' => '2 × 10G SFP+',
                ],
                'summary' => [
                    'fa' => 'کارت شبکهٔ 2 پورته با سرعت 10 گیگابیت بر ثانیه و رابط SFP+ برای سرورهای Gen9 / Gen10 / Gen11.',
                    'en' => 'A 2-port 10 Gb/s SFP+ network adapter for Gen9 / Gen10 / Gen11 servers.',
                    'tr' => 'Gen9 / Gen10 / Gen11 sunucular için 2 portlu 10 Gb/s SFP+ ağ adaptörü.',
                ],
                'body' => [
                    'fa' => 'Intel X710-DA2 Dual Port 10G SFP+ PCIe یک کارت شبکهٔ سرور با 2 پورت 10 گیگابیتی و رابط SFP+ است و روی سرورهای HPE ProLiant Gen9 / Gen10 / Gen11 کار می‌کند.

پهنای باند کل این کارت 20 گیگابیت بر ثانیه است. اگر سرور شما ماشین مجازی میزبانی می‌کند یا به شبکهٔ ذخیره‌سازی وصل است، پورت یک‌گیگابیتی روی‌برد معمولاً اولین گلوگاهی است که به آن می‌خورید — و رفعش ارزان‌ترین ارتقای ممکن است.

رابط SFP+ ماژول SFP جدا می‌خواهد؛ ماژول را متناسب با فاصله و نوع فیبر انتخاب کنید و دقت کنید سوییچ شما همان ماژول را قبول کند. بعضی سوییچ‌ها فقط ماژول برند خودشان را می‌پذیرند.

کارت‌های FlexibleLOM روی اسلات اختصاصی مادربرد می‌نشینند و اسلات PCIe را آزاد نگه می‌دارند؛ کارت PCIe جای FlexibleLOM نمی‌نشیند و برعکس.',
                    'en' => 'The Intel X710-DA2 Dual Port 10G SFP+ PCIe is a server network adapter with 2 × 10 Gb/s SFP+ ports for HPE ProLiant Gen9 / Gen10 / Gen11 servers.

Total bandwidth is 20 Gb/s. If your server hosts virtual machines or attaches to a storage network, the onboard gigabit port is usually the first bottleneck you hit — and fixing it is the cheapest upgrade available.

The SFP+ interface needs separate SFP modules; pick the module for your distance and fibre type, and check that your switch accepts it — some switches only take their own brand.

FlexibleLOM cards sit in the board’s dedicated slot and leave your PCIe slots free; a PCIe card will not fit a FlexibleLOM slot, or the other way round.',
                    'tr' => 'Intel X710-DA2 Dual Port 10G SFP+ PCIe, HPE ProLiant Gen9 / Gen10 / Gen11 sunucuları için 2 × 10 Gb/s SFP+ portlu bir sunucu ağ adaptörüdür.

Toplam bant genişliği 20 Gb/s’dir. Sunucunuz sanal makine barındırıyorsa veya bir depolama ağına bağlıysa, yerleşik gigabit port genelde çarptığınız ilk darboğazdır — ve bunu gidermek mevcut en ucuz yükseltmedir.

SFP+ arayüzü ayrı SFP modülleri ister; modülü mesafenize ve fiber tipinize göre seçin ve anahtarınızın kabul ettiğinden emin olun — bazı anahtarlar yalnızca kendi markasını kabul eder.

FlexibleLOM kartlar anakartın özel yuvasına oturur ve PCIe yuvalarınızı boş bırakır; PCIe kart FlexibleLOM yuvasına, FlexibleLOM da PCIe yuvasına takılmaz.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'تعداد پورت',
                            'en' => 'Ports',
                            'tr' => 'Port sayısı',
                        ],
                        'value' => [
                            'fa' => '2',
                            'en' => '2',
                            'tr' => '2',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سرعت هر پورت',
                            'en' => 'Port speed',
                            'tr' => 'Port hızı',
                        ],
                        'value' => [
                            'fa' => '10 Gb/s',
                            'en' => '10 Gb/s',
                            'tr' => '10 Gb/s',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'رابط',
                            'en' => 'Media',
                            'tr' => 'Arayüz',
                        ],
                        'value' => [
                            'fa' => 'SFP+',
                            'en' => 'SFP+',
                            'tr' => 'SFP+',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'پهنای باند کل',
                            'en' => 'Total bandwidth',
                            'tr' => 'Toplam bant genişliği',
                        ],
                        'value' => [
                            'fa' => '20 Gb/s',
                            'en' => '20 Gb/s',
                            'tr' => '20 Gb/s',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen9 / Gen10 / Gen11',
                            'en' => 'Gen9 / Gen10 / Gen11',
                            'tr' => 'Gen9 / Gen10 / Gen11',
                        ],
                    ],
                ],
                'attrs' => [
                    'ports' => 2,
                    'gbps' => 10,
                ],
            ],
            [
                'slug' => 'nic-640sfp28',
                'category' => 'nic',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen10',
                    'gen11',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 5041,
                'in_stock' => true,
                'popular' => true,
                'sort' => 720,
                'name' => [
                    'fa' => 'HPE 640SFP28 Dual Port 25G SFP28 PCIe',
                    'en' => 'HPE 640SFP28 Dual Port 25G SFP28 PCIe',
                    'tr' => 'HPE 640SFP28 Dual Port 25G SFP28 PCIe',
                ],
                'tagline' => [
                    'fa' => '2 پورت 25 گیگابیت SFP28',
                    'en' => '2 × 25G SFP28',
                    'tr' => '2 × 25G SFP28',
                ],
                'summary' => [
                    'fa' => 'کارت شبکهٔ 2 پورته با سرعت 25 گیگابیت بر ثانیه و رابط SFP28 برای سرورهای Gen10 / Gen11.',
                    'en' => 'A 2-port 25 Gb/s SFP28 network adapter for Gen10 / Gen11 servers.',
                    'tr' => 'Gen10 / Gen11 sunucular için 2 portlu 25 Gb/s SFP28 ağ adaptörü.',
                ],
                'body' => [
                    'fa' => 'HPE 640SFP28 Dual Port 25G SFP28 PCIe یک کارت شبکهٔ سرور با 2 پورت 25 گیگابیتی و رابط SFP28 است و روی سرورهای HPE ProLiant Gen10 / Gen11 کار می‌کند.

پهنای باند کل این کارت 50 گیگابیت بر ثانیه است. اگر سرور شما ماشین مجازی میزبانی می‌کند یا به شبکهٔ ذخیره‌سازی وصل است، پورت یک‌گیگابیتی روی‌برد معمولاً اولین گلوگاهی است که به آن می‌خورید — و رفعش ارزان‌ترین ارتقای ممکن است.

رابط SFP28 ماژول SFP جدا می‌خواهد؛ ماژول را متناسب با فاصله و نوع فیبر انتخاب کنید و دقت کنید سوییچ شما همان ماژول را قبول کند. بعضی سوییچ‌ها فقط ماژول برند خودشان را می‌پذیرند.

کارت‌های FlexibleLOM روی اسلات اختصاصی مادربرد می‌نشینند و اسلات PCIe را آزاد نگه می‌دارند؛ کارت PCIe جای FlexibleLOM نمی‌نشیند و برعکس.',
                    'en' => 'The HPE 640SFP28 Dual Port 25G SFP28 PCIe is a server network adapter with 2 × 25 Gb/s SFP28 ports for HPE ProLiant Gen10 / Gen11 servers.

Total bandwidth is 50 Gb/s. If your server hosts virtual machines or attaches to a storage network, the onboard gigabit port is usually the first bottleneck you hit — and fixing it is the cheapest upgrade available.

The SFP28 interface needs separate SFP modules; pick the module for your distance and fibre type, and check that your switch accepts it — some switches only take their own brand.

FlexibleLOM cards sit in the board’s dedicated slot and leave your PCIe slots free; a PCIe card will not fit a FlexibleLOM slot, or the other way round.',
                    'tr' => 'HPE 640SFP28 Dual Port 25G SFP28 PCIe, HPE ProLiant Gen10 / Gen11 sunucuları için 2 × 25 Gb/s SFP28 portlu bir sunucu ağ adaptörüdür.

Toplam bant genişliği 50 Gb/s’dir. Sunucunuz sanal makine barındırıyorsa veya bir depolama ağına bağlıysa, yerleşik gigabit port genelde çarptığınız ilk darboğazdır — ve bunu gidermek mevcut en ucuz yükseltmedir.

SFP28 arayüzü ayrı SFP modülleri ister; modülü mesafenize ve fiber tipinize göre seçin ve anahtarınızın kabul ettiğinden emin olun — bazı anahtarlar yalnızca kendi markasını kabul eder.

FlexibleLOM kartlar anakartın özel yuvasına oturur ve PCIe yuvalarınızı boş bırakır; PCIe kart FlexibleLOM yuvasına, FlexibleLOM da PCIe yuvasına takılmaz.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'تعداد پورت',
                            'en' => 'Ports',
                            'tr' => 'Port sayısı',
                        ],
                        'value' => [
                            'fa' => '2',
                            'en' => '2',
                            'tr' => '2',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سرعت هر پورت',
                            'en' => 'Port speed',
                            'tr' => 'Port hızı',
                        ],
                        'value' => [
                            'fa' => '25 Gb/s',
                            'en' => '25 Gb/s',
                            'tr' => '25 Gb/s',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'رابط',
                            'en' => 'Media',
                            'tr' => 'Arayüz',
                        ],
                        'value' => [
                            'fa' => 'SFP28',
                            'en' => 'SFP28',
                            'tr' => 'SFP28',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'پهنای باند کل',
                            'en' => 'Total bandwidth',
                            'tr' => 'Toplam bant genişliği',
                        ],
                        'value' => [
                            'fa' => '50 Gb/s',
                            'en' => '50 Gb/s',
                            'tr' => '50 Gb/s',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen10 / Gen11',
                            'en' => 'Gen10 / Gen11',
                            'tr' => 'Gen10 / Gen11',
                        ],
                    ],
                ],
                'attrs' => [
                    'ports' => 2,
                    'gbps' => 25,
                ],
            ],
            [
                'slug' => 'nic-544flr-qsfp',
                'category' => 'nic',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen9',
                    'gen10',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 1008,
                'in_stock' => true,
                'popular' => false,
                'sort' => 730,
                'name' => [
                    'fa' => 'HPE 544+FLR-QSFP Dual Port 40G QSFP+ FlexibleLOM',
                    'en' => 'HPE 544+FLR-QSFP Dual Port 40G QSFP+ FlexibleLOM',
                    'tr' => 'HPE 544+FLR-QSFP Dual Port 40G QSFP+ FlexibleLOM',
                ],
                'tagline' => [
                    'fa' => '2 پورت 40 گیگابیت QSFP+',
                    'en' => '2 × 40G QSFP+',
                    'tr' => '2 × 40G QSFP+',
                ],
                'summary' => [
                    'fa' => 'کارت شبکهٔ 2 پورته با سرعت 40 گیگابیت بر ثانیه و رابط QSFP+ برای سرورهای Gen9 / Gen10.',
                    'en' => 'A 2-port 40 Gb/s QSFP+ network adapter for Gen9 / Gen10 servers.',
                    'tr' => 'Gen9 / Gen10 sunucular için 2 portlu 40 Gb/s QSFP+ ağ adaptörü.',
                ],
                'body' => [
                    'fa' => 'HPE 544+FLR-QSFP Dual Port 40G QSFP+ FlexibleLOM یک کارت شبکهٔ سرور با 2 پورت 40 گیگابیتی و رابط QSFP+ است و روی سرورهای HPE ProLiant Gen9 / Gen10 کار می‌کند.

پهنای باند کل این کارت 80 گیگابیت بر ثانیه است. اگر سرور شما ماشین مجازی میزبانی می‌کند یا به شبکهٔ ذخیره‌سازی وصل است، پورت یک‌گیگابیتی روی‌برد معمولاً اولین گلوگاهی است که به آن می‌خورید — و رفعش ارزان‌ترین ارتقای ممکن است.

رابط QSFP+ ماژول SFP جدا می‌خواهد؛ ماژول را متناسب با فاصله و نوع فیبر انتخاب کنید و دقت کنید سوییچ شما همان ماژول را قبول کند. بعضی سوییچ‌ها فقط ماژول برند خودشان را می‌پذیرند.

کارت‌های FlexibleLOM روی اسلات اختصاصی مادربرد می‌نشینند و اسلات PCIe را آزاد نگه می‌دارند؛ کارت PCIe جای FlexibleLOM نمی‌نشیند و برعکس.',
                    'en' => 'The HPE 544+FLR-QSFP Dual Port 40G QSFP+ FlexibleLOM is a server network adapter with 2 × 40 Gb/s QSFP+ ports for HPE ProLiant Gen9 / Gen10 servers.

Total bandwidth is 80 Gb/s. If your server hosts virtual machines or attaches to a storage network, the onboard gigabit port is usually the first bottleneck you hit — and fixing it is the cheapest upgrade available.

The QSFP+ interface needs separate SFP modules; pick the module for your distance and fibre type, and check that your switch accepts it — some switches only take their own brand.

FlexibleLOM cards sit in the board’s dedicated slot and leave your PCIe slots free; a PCIe card will not fit a FlexibleLOM slot, or the other way round.',
                    'tr' => 'HPE 544+FLR-QSFP Dual Port 40G QSFP+ FlexibleLOM, HPE ProLiant Gen9 / Gen10 sunucuları için 2 × 40 Gb/s QSFP+ portlu bir sunucu ağ adaptörüdür.

Toplam bant genişliği 80 Gb/s’dir. Sunucunuz sanal makine barındırıyorsa veya bir depolama ağına bağlıysa, yerleşik gigabit port genelde çarptığınız ilk darboğazdır — ve bunu gidermek mevcut en ucuz yükseltmedir.

QSFP+ arayüzü ayrı SFP modülleri ister; modülü mesafenize ve fiber tipinize göre seçin ve anahtarınızın kabul ettiğinden emin olun — bazı anahtarlar yalnızca kendi markasını kabul eder.

FlexibleLOM kartlar anakartın özel yuvasına oturur ve PCIe yuvalarınızı boş bırakır; PCIe kart FlexibleLOM yuvasına, FlexibleLOM da PCIe yuvasına takılmaz.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'تعداد پورت',
                            'en' => 'Ports',
                            'tr' => 'Port sayısı',
                        ],
                        'value' => [
                            'fa' => '2',
                            'en' => '2',
                            'tr' => '2',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سرعت هر پورت',
                            'en' => 'Port speed',
                            'tr' => 'Port hızı',
                        ],
                        'value' => [
                            'fa' => '40 Gb/s',
                            'en' => '40 Gb/s',
                            'tr' => '40 Gb/s',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'رابط',
                            'en' => 'Media',
                            'tr' => 'Arayüz',
                        ],
                        'value' => [
                            'fa' => 'QSFP+',
                            'en' => 'QSFP+',
                            'tr' => 'QSFP+',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'پهنای باند کل',
                            'en' => 'Total bandwidth',
                            'tr' => 'Toplam bant genişliği',
                        ],
                        'value' => [
                            'fa' => '80 Gb/s',
                            'en' => '80 Gb/s',
                            'tr' => '80 Gb/s',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen9 / Gen10',
                            'en' => 'Gen9 / Gen10',
                            'tr' => 'Gen9 / Gen10',
                        ],
                    ],
                ],
                'attrs' => [
                    'ports' => 2,
                    'gbps' => 40,
                ],
            ],
            [
                'slug' => 'nic-mcx562a',
                'category' => 'nic',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen10',
                    'gen11',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 10083,
                'in_stock' => true,
                'popular' => false,
                'sort' => 740,
                'name' => [
                    'fa' => 'HPE MCX562A-ACAI Dual Port 25G SFP28 OCP',
                    'en' => 'HPE MCX562A-ACAI Dual Port 25G SFP28 OCP',
                    'tr' => 'HPE MCX562A-ACAI Dual Port 25G SFP28 OCP',
                ],
                'tagline' => [
                    'fa' => '2 پورت 25 گیگابیت SFP28',
                    'en' => '2 × 25G SFP28',
                    'tr' => '2 × 25G SFP28',
                ],
                'summary' => [
                    'fa' => 'کارت شبکهٔ 2 پورته با سرعت 25 گیگابیت بر ثانیه و رابط SFP28 برای سرورهای Gen10 / Gen11.',
                    'en' => 'A 2-port 25 Gb/s SFP28 network adapter for Gen10 / Gen11 servers.',
                    'tr' => 'Gen10 / Gen11 sunucular için 2 portlu 25 Gb/s SFP28 ağ adaptörü.',
                ],
                'body' => [
                    'fa' => 'HPE MCX562A-ACAI Dual Port 25G SFP28 OCP یک کارت شبکهٔ سرور با 2 پورت 25 گیگابیتی و رابط SFP28 است و روی سرورهای HPE ProLiant Gen10 / Gen11 کار می‌کند.

پهنای باند کل این کارت 50 گیگابیت بر ثانیه است. اگر سرور شما ماشین مجازی میزبانی می‌کند یا به شبکهٔ ذخیره‌سازی وصل است، پورت یک‌گیگابیتی روی‌برد معمولاً اولین گلوگاهی است که به آن می‌خورید — و رفعش ارزان‌ترین ارتقای ممکن است.

رابط SFP28 ماژول SFP جدا می‌خواهد؛ ماژول را متناسب با فاصله و نوع فیبر انتخاب کنید و دقت کنید سوییچ شما همان ماژول را قبول کند. بعضی سوییچ‌ها فقط ماژول برند خودشان را می‌پذیرند.

کارت‌های FlexibleLOM روی اسلات اختصاصی مادربرد می‌نشینند و اسلات PCIe را آزاد نگه می‌دارند؛ کارت PCIe جای FlexibleLOM نمی‌نشیند و برعکس.',
                    'en' => 'The HPE MCX562A-ACAI Dual Port 25G SFP28 OCP is a server network adapter with 2 × 25 Gb/s SFP28 ports for HPE ProLiant Gen10 / Gen11 servers.

Total bandwidth is 50 Gb/s. If your server hosts virtual machines or attaches to a storage network, the onboard gigabit port is usually the first bottleneck you hit — and fixing it is the cheapest upgrade available.

The SFP28 interface needs separate SFP modules; pick the module for your distance and fibre type, and check that your switch accepts it — some switches only take their own brand.

FlexibleLOM cards sit in the board’s dedicated slot and leave your PCIe slots free; a PCIe card will not fit a FlexibleLOM slot, or the other way round.',
                    'tr' => 'HPE MCX562A-ACAI Dual Port 25G SFP28 OCP, HPE ProLiant Gen10 / Gen11 sunucuları için 2 × 25 Gb/s SFP28 portlu bir sunucu ağ adaptörüdür.

Toplam bant genişliği 50 Gb/s’dir. Sunucunuz sanal makine barındırıyorsa veya bir depolama ağına bağlıysa, yerleşik gigabit port genelde çarptığınız ilk darboğazdır — ve bunu gidermek mevcut en ucuz yükseltmedir.

SFP28 arayüzü ayrı SFP modülleri ister; modülü mesafenize ve fiber tipinize göre seçin ve anahtarınızın kabul ettiğinden emin olun — bazı anahtarlar yalnızca kendi markasını kabul eder.

FlexibleLOM kartlar anakartın özel yuvasına oturur ve PCIe yuvalarınızı boş bırakır; PCIe kart FlexibleLOM yuvasına, FlexibleLOM da PCIe yuvasına takılmaz.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'تعداد پورت',
                            'en' => 'Ports',
                            'tr' => 'Port sayısı',
                        ],
                        'value' => [
                            'fa' => '2',
                            'en' => '2',
                            'tr' => '2',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'سرعت هر پورت',
                            'en' => 'Port speed',
                            'tr' => 'Port hızı',
                        ],
                        'value' => [
                            'fa' => '25 Gb/s',
                            'en' => '25 Gb/s',
                            'tr' => '25 Gb/s',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'رابط',
                            'en' => 'Media',
                            'tr' => 'Arayüz',
                        ],
                        'value' => [
                            'fa' => 'SFP28',
                            'en' => 'SFP28',
                            'tr' => 'SFP28',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'پهنای باند کل',
                            'en' => 'Total bandwidth',
                            'tr' => 'Toplam bant genişliği',
                        ],
                        'value' => [
                            'fa' => '50 Gb/s',
                            'en' => '50 Gb/s',
                            'tr' => '50 Gb/s',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen10 / Gen11',
                            'en' => 'Gen10 / Gen11',
                            'tr' => 'Gen10 / Gen11',
                        ],
                    ],
                ],
                'attrs' => [
                    'ports' => 2,
                    'gbps' => 25,
                ],
            ],
            [
                'slug' => 'psu-460w-cs',
                'category' => 'psu',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen8',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 1260,
                'in_stock' => true,
                'popular' => false,
                'sort' => 750,
                'name' => [
                    'fa' => 'HP 460W Common Slot Platinum Hot-Plug',
                    'en' => 'HP 460W Common Slot Platinum Hot-Plug',
                    'tr' => 'HP 460W Common Slot Platinum Hot-Plug',
                ],
                'tagline' => [
                    'fa' => '460 وات · 80 PLUS Platinum',
                    'en' => '460 W · 80 PLUS Platinum',
                    'tr' => '460 W · 80 PLUS Platinum',
                ],
                'summary' => [
                    'fa' => 'منبع تغذیهٔ هات‌پلاگ 460 وات با گواهی 80 PLUS Platinum برای سرورهای Gen8.',
                    'en' => 'A 460 W hot-plug power supply, 80 PLUS Platinum rated, for Gen8 servers.',
                    'tr' => 'Gen8 sunucular için 80 PLUS Platinum sertifikalı 460 W hot-plug güç kaynağı.',
                ],
                'body' => [
                    'fa' => 'HP 460W Common Slot Platinum Hot-Plug یک منبع تغذیهٔ هات‌پلاگ 460 وات با گواهی بازده 80 PLUS Platinum برای سرورهای HPE ProLiant Gen8 است.

سرور را همیشه با دو منبع تغذیه ببندید، حتی اگر یکی برای بارتان کافی است. منبع تغذیه پرتکرارترین قطعه‌ای است که در سرور می‌سوزد، و با دو تا، سوختن یکی یعنی یک چراغ قرمز — نه یک قطعی.

بازده 80 PLUS Platinum فقط عدد روی برچسب نیست: در توان مصرفی واقعی سرور و در گرمایی که باید از رک بیرون برود، خودش را نشان می‌دهد.

دو نکتهٔ سازگاری: منبع Common Slot نسل‌های G6 تا Gen8 با منبع Flex Slot نسل ۹ به بعد جای هم نمی‌نشیند. و اگر دو منبع با توان متفاوت در یک سرور بگذارید، سرور هشدار می‌دهد و افزونگی را تضمین‌شده نمی‌داند.',
                    'en' => 'The HP 460W Common Slot Platinum Hot-Plug is a 460 W hot-plug power supply, 80 PLUS Platinum rated, for HPE ProLiant Gen8 servers.

Always fit two supplies, even when one covers your load. The PSU is the component that fails most often in a server, and with two of them a failure is a red light rather than an outage.

80 PLUS Platinum efficiency is not just a label: it shows up in the server’s real power draw and in the heat your rack has to remove.

Two compatibility notes: the Common Slot supply of the G6–Gen8 era and the Flex Slot supply from Gen9 onwards are not interchangeable. And if you fit two supplies of different wattage in one server, it will warn you and will not treat the redundancy as guaranteed.',
                    'tr' => 'HP 460W Common Slot Platinum Hot-Plug, HPE ProLiant Gen8 sunucuları için 80 PLUS Platinum sertifikalı 460 W hot-plug güç kaynağıdır.

Yükünüz için biri yetse bile daima iki güç kaynağı takın. Güç kaynağı bir sunucuda en sık arızalanan bileşendir ve iki taneyle arıza, bir kesinti değil kırmızı bir ışıktır.

80 PLUS Platinum verimlilik yalnızca bir etiket değildir: sunucunun gerçek güç tüketiminde ve rafınızdan atılması gereken ısıda kendini gösterir.

İki uyumluluk notu: G6–Gen8 dönemindeki Common Slot güç kaynağı ile Gen9 ve sonrasındaki Flex Slot güç kaynağı birbirinin yerine geçmez. Ayrıca aynı sunucuya farklı güçte iki kaynak takarsanız sunucu uyarı verir ve yedekliliği garantili saymaz.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'توان',
                            'en' => 'Output',
                            'tr' => 'Güç',
                        ],
                        'value' => [
                            'fa' => '460 W',
                            'en' => '460 W',
                            'tr' => '460 W',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'گواهی بازده',
                            'en' => 'Efficiency rating',
                            'tr' => 'Verimlilik sertifikası',
                        ],
                        'value' => [
                            'fa' => '80 PLUS Platinum',
                            'en' => '80 PLUS Platinum',
                            'tr' => '80 PLUS Platinum',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'هات‌پلاگ',
                            'en' => 'Hot-plug',
                            'tr' => 'Hot-plug',
                        ],
                        'value' => [
                            'fa' => 'بله',
                            'en' => 'Yes',
                            'tr' => 'Evet',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen8',
                            'en' => 'Gen8',
                            'tr' => 'Gen8',
                        ],
                    ],
                ],
                'attrs' => [
                    'watt' => 460,
                ],
            ],
            [
                'slug' => 'psu-750w-cs',
                'category' => 'psu',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen8',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 2100,
                'in_stock' => true,
                'popular' => true,
                'sort' => 760,
                'name' => [
                    'fa' => 'HP 750W Common Slot Platinum Hot-Plug',
                    'en' => 'HP 750W Common Slot Platinum Hot-Plug',
                    'tr' => 'HP 750W Common Slot Platinum Hot-Plug',
                ],
                'tagline' => [
                    'fa' => '750 وات · 80 PLUS Platinum',
                    'en' => '750 W · 80 PLUS Platinum',
                    'tr' => '750 W · 80 PLUS Platinum',
                ],
                'summary' => [
                    'fa' => 'منبع تغذیهٔ هات‌پلاگ 750 وات با گواهی 80 PLUS Platinum برای سرورهای Gen8.',
                    'en' => 'A 750 W hot-plug power supply, 80 PLUS Platinum rated, for Gen8 servers.',
                    'tr' => 'Gen8 sunucular için 80 PLUS Platinum sertifikalı 750 W hot-plug güç kaynağı.',
                ],
                'body' => [
                    'fa' => 'HP 750W Common Slot Platinum Hot-Plug یک منبع تغذیهٔ هات‌پلاگ 750 وات با گواهی بازده 80 PLUS Platinum برای سرورهای HPE ProLiant Gen8 است.

سرور را همیشه با دو منبع تغذیه ببندید، حتی اگر یکی برای بارتان کافی است. منبع تغذیه پرتکرارترین قطعه‌ای است که در سرور می‌سوزد، و با دو تا، سوختن یکی یعنی یک چراغ قرمز — نه یک قطعی.

بازده 80 PLUS Platinum فقط عدد روی برچسب نیست: در توان مصرفی واقعی سرور و در گرمایی که باید از رک بیرون برود، خودش را نشان می‌دهد.

دو نکتهٔ سازگاری: منبع Common Slot نسل‌های G6 تا Gen8 با منبع Flex Slot نسل ۹ به بعد جای هم نمی‌نشیند. و اگر دو منبع با توان متفاوت در یک سرور بگذارید، سرور هشدار می‌دهد و افزونگی را تضمین‌شده نمی‌داند.',
                    'en' => 'The HP 750W Common Slot Platinum Hot-Plug is a 750 W hot-plug power supply, 80 PLUS Platinum rated, for HPE ProLiant Gen8 servers.

Always fit two supplies, even when one covers your load. The PSU is the component that fails most often in a server, and with two of them a failure is a red light rather than an outage.

80 PLUS Platinum efficiency is not just a label: it shows up in the server’s real power draw and in the heat your rack has to remove.

Two compatibility notes: the Common Slot supply of the G6–Gen8 era and the Flex Slot supply from Gen9 onwards are not interchangeable. And if you fit two supplies of different wattage in one server, it will warn you and will not treat the redundancy as guaranteed.',
                    'tr' => 'HP 750W Common Slot Platinum Hot-Plug, HPE ProLiant Gen8 sunucuları için 80 PLUS Platinum sertifikalı 750 W hot-plug güç kaynağıdır.

Yükünüz için biri yetse bile daima iki güç kaynağı takın. Güç kaynağı bir sunucuda en sık arızalanan bileşendir ve iki taneyle arıza, bir kesinti değil kırmızı bir ışıktır.

80 PLUS Platinum verimlilik yalnızca bir etiket değildir: sunucunun gerçek güç tüketiminde ve rafınızdan atılması gereken ısıda kendini gösterir.

İki uyumluluk notu: G6–Gen8 dönemindeki Common Slot güç kaynağı ile Gen9 ve sonrasındaki Flex Slot güç kaynağı birbirinin yerine geçmez. Ayrıca aynı sunucuya farklı güçte iki kaynak takarsanız sunucu uyarı verir ve yedekliliği garantili saymaz.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'توان',
                            'en' => 'Output',
                            'tr' => 'Güç',
                        ],
                        'value' => [
                            'fa' => '750 W',
                            'en' => '750 W',
                            'tr' => '750 W',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'گواهی بازده',
                            'en' => 'Efficiency rating',
                            'tr' => 'Verimlilik sertifikası',
                        ],
                        'value' => [
                            'fa' => '80 PLUS Platinum',
                            'en' => '80 PLUS Platinum',
                            'tr' => '80 PLUS Platinum',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'هات‌پلاگ',
                            'en' => 'Hot-plug',
                            'tr' => 'Hot-plug',
                        ],
                        'value' => [
                            'fa' => 'بله',
                            'en' => 'Yes',
                            'tr' => 'Evet',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen8',
                            'en' => 'Gen8',
                            'tr' => 'Gen8',
                        ],
                    ],
                ],
                'attrs' => [
                    'watt' => 750,
                ],
            ],
            [
                'slug' => 'psu-500w-fs',
                'category' => 'psu',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen9',
                    'gen10',
                    'gen11',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 2520,
                'in_stock' => true,
                'popular' => false,
                'sort' => 770,
                'name' => [
                    'fa' => 'HPE 500W Flex Slot Platinum Hot-Plug',
                    'en' => 'HPE 500W Flex Slot Platinum Hot-Plug',
                    'tr' => 'HPE 500W Flex Slot Platinum Hot-Plug',
                ],
                'tagline' => [
                    'fa' => '500 وات · 80 PLUS Platinum',
                    'en' => '500 W · 80 PLUS Platinum',
                    'tr' => '500 W · 80 PLUS Platinum',
                ],
                'summary' => [
                    'fa' => 'منبع تغذیهٔ هات‌پلاگ 500 وات با گواهی 80 PLUS Platinum برای سرورهای Gen9 / Gen10 / Gen11.',
                    'en' => 'A 500 W hot-plug power supply, 80 PLUS Platinum rated, for Gen9 / Gen10 / Gen11 servers.',
                    'tr' => 'Gen9 / Gen10 / Gen11 sunucular için 80 PLUS Platinum sertifikalı 500 W hot-plug güç kaynağı.',
                ],
                'body' => [
                    'fa' => 'HPE 500W Flex Slot Platinum Hot-Plug یک منبع تغذیهٔ هات‌پلاگ 500 وات با گواهی بازده 80 PLUS Platinum برای سرورهای HPE ProLiant Gen9 / Gen10 / Gen11 است.

سرور را همیشه با دو منبع تغذیه ببندید، حتی اگر یکی برای بارتان کافی است. منبع تغذیه پرتکرارترین قطعه‌ای است که در سرور می‌سوزد، و با دو تا، سوختن یکی یعنی یک چراغ قرمز — نه یک قطعی.

بازده 80 PLUS Platinum فقط عدد روی برچسب نیست: در توان مصرفی واقعی سرور و در گرمایی که باید از رک بیرون برود، خودش را نشان می‌دهد.

دو نکتهٔ سازگاری: منبع Common Slot نسل‌های G6 تا Gen8 با منبع Flex Slot نسل ۹ به بعد جای هم نمی‌نشیند. و اگر دو منبع با توان متفاوت در یک سرور بگذارید، سرور هشدار می‌دهد و افزونگی را تضمین‌شده نمی‌داند.',
                    'en' => 'The HPE 500W Flex Slot Platinum Hot-Plug is a 500 W hot-plug power supply, 80 PLUS Platinum rated, for HPE ProLiant Gen9 / Gen10 / Gen11 servers.

Always fit two supplies, even when one covers your load. The PSU is the component that fails most often in a server, and with two of them a failure is a red light rather than an outage.

80 PLUS Platinum efficiency is not just a label: it shows up in the server’s real power draw and in the heat your rack has to remove.

Two compatibility notes: the Common Slot supply of the G6–Gen8 era and the Flex Slot supply from Gen9 onwards are not interchangeable. And if you fit two supplies of different wattage in one server, it will warn you and will not treat the redundancy as guaranteed.',
                    'tr' => 'HPE 500W Flex Slot Platinum Hot-Plug, HPE ProLiant Gen9 / Gen10 / Gen11 sunucuları için 80 PLUS Platinum sertifikalı 500 W hot-plug güç kaynağıdır.

Yükünüz için biri yetse bile daima iki güç kaynağı takın. Güç kaynağı bir sunucuda en sık arızalanan bileşendir ve iki taneyle arıza, bir kesinti değil kırmızı bir ışıktır.

80 PLUS Platinum verimlilik yalnızca bir etiket değildir: sunucunun gerçek güç tüketiminde ve rafınızdan atılması gereken ısıda kendini gösterir.

İki uyumluluk notu: G6–Gen8 dönemindeki Common Slot güç kaynağı ile Gen9 ve sonrasındaki Flex Slot güç kaynağı birbirinin yerine geçmez. Ayrıca aynı sunucuya farklı güçte iki kaynak takarsanız sunucu uyarı verir ve yedekliliği garantili saymaz.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'توان',
                            'en' => 'Output',
                            'tr' => 'Güç',
                        ],
                        'value' => [
                            'fa' => '500 W',
                            'en' => '500 W',
                            'tr' => '500 W',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'گواهی بازده',
                            'en' => 'Efficiency rating',
                            'tr' => 'Verimlilik sertifikası',
                        ],
                        'value' => [
                            'fa' => '80 PLUS Platinum',
                            'en' => '80 PLUS Platinum',
                            'tr' => '80 PLUS Platinum',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'هات‌پلاگ',
                            'en' => 'Hot-plug',
                            'tr' => 'Hot-plug',
                        ],
                        'value' => [
                            'fa' => 'بله',
                            'en' => 'Yes',
                            'tr' => 'Evet',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen9 / Gen10 / Gen11',
                            'en' => 'Gen9 / Gen10 / Gen11',
                            'tr' => 'Gen9 / Gen10 / Gen11',
                        ],
                    ],
                ],
                'attrs' => [
                    'watt' => 500,
                ],
            ],
            [
                'slug' => 'psu-800w-fs',
                'category' => 'psu',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen9',
                    'gen10',
                    'gen11',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 3361,
                'in_stock' => true,
                'popular' => true,
                'sort' => 780,
                'name' => [
                    'fa' => 'HPE 800W Flex Slot Platinum Hot-Plug',
                    'en' => 'HPE 800W Flex Slot Platinum Hot-Plug',
                    'tr' => 'HPE 800W Flex Slot Platinum Hot-Plug',
                ],
                'tagline' => [
                    'fa' => '800 وات · 80 PLUS Platinum',
                    'en' => '800 W · 80 PLUS Platinum',
                    'tr' => '800 W · 80 PLUS Platinum',
                ],
                'summary' => [
                    'fa' => 'منبع تغذیهٔ هات‌پلاگ 800 وات با گواهی 80 PLUS Platinum برای سرورهای Gen9 / Gen10 / Gen11.',
                    'en' => 'A 800 W hot-plug power supply, 80 PLUS Platinum rated, for Gen9 / Gen10 / Gen11 servers.',
                    'tr' => 'Gen9 / Gen10 / Gen11 sunucular için 80 PLUS Platinum sertifikalı 800 W hot-plug güç kaynağı.',
                ],
                'body' => [
                    'fa' => 'HPE 800W Flex Slot Platinum Hot-Plug یک منبع تغذیهٔ هات‌پلاگ 800 وات با گواهی بازده 80 PLUS Platinum برای سرورهای HPE ProLiant Gen9 / Gen10 / Gen11 است.

سرور را همیشه با دو منبع تغذیه ببندید، حتی اگر یکی برای بارتان کافی است. منبع تغذیه پرتکرارترین قطعه‌ای است که در سرور می‌سوزد، و با دو تا، سوختن یکی یعنی یک چراغ قرمز — نه یک قطعی.

بازده 80 PLUS Platinum فقط عدد روی برچسب نیست: در توان مصرفی واقعی سرور و در گرمایی که باید از رک بیرون برود، خودش را نشان می‌دهد.

دو نکتهٔ سازگاری: منبع Common Slot نسل‌های G6 تا Gen8 با منبع Flex Slot نسل ۹ به بعد جای هم نمی‌نشیند. و اگر دو منبع با توان متفاوت در یک سرور بگذارید، سرور هشدار می‌دهد و افزونگی را تضمین‌شده نمی‌داند.',
                    'en' => 'The HPE 800W Flex Slot Platinum Hot-Plug is a 800 W hot-plug power supply, 80 PLUS Platinum rated, for HPE ProLiant Gen9 / Gen10 / Gen11 servers.

Always fit two supplies, even when one covers your load. The PSU is the component that fails most often in a server, and with two of them a failure is a red light rather than an outage.

80 PLUS Platinum efficiency is not just a label: it shows up in the server’s real power draw and in the heat your rack has to remove.

Two compatibility notes: the Common Slot supply of the G6–Gen8 era and the Flex Slot supply from Gen9 onwards are not interchangeable. And if you fit two supplies of different wattage in one server, it will warn you and will not treat the redundancy as guaranteed.',
                    'tr' => 'HPE 800W Flex Slot Platinum Hot-Plug, HPE ProLiant Gen9 / Gen10 / Gen11 sunucuları için 80 PLUS Platinum sertifikalı 800 W hot-plug güç kaynağıdır.

Yükünüz için biri yetse bile daima iki güç kaynağı takın. Güç kaynağı bir sunucuda en sık arızalanan bileşendir ve iki taneyle arıza, bir kesinti değil kırmızı bir ışıktır.

80 PLUS Platinum verimlilik yalnızca bir etiket değildir: sunucunun gerçek güç tüketiminde ve rafınızdan atılması gereken ısıda kendini gösterir.

İki uyumluluk notu: G6–Gen8 dönemindeki Common Slot güç kaynağı ile Gen9 ve sonrasındaki Flex Slot güç kaynağı birbirinin yerine geçmez. Ayrıca aynı sunucuya farklı güçte iki kaynak takarsanız sunucu uyarı verir ve yedekliliği garantili saymaz.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'توان',
                            'en' => 'Output',
                            'tr' => 'Güç',
                        ],
                        'value' => [
                            'fa' => '800 W',
                            'en' => '800 W',
                            'tr' => '800 W',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'گواهی بازده',
                            'en' => 'Efficiency rating',
                            'tr' => 'Verimlilik sertifikası',
                        ],
                        'value' => [
                            'fa' => '80 PLUS Platinum',
                            'en' => '80 PLUS Platinum',
                            'tr' => '80 PLUS Platinum',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'هات‌پلاگ',
                            'en' => 'Hot-plug',
                            'tr' => 'Hot-plug',
                        ],
                        'value' => [
                            'fa' => 'بله',
                            'en' => 'Yes',
                            'tr' => 'Evet',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen9 / Gen10 / Gen11',
                            'en' => 'Gen9 / Gen10 / Gen11',
                            'tr' => 'Gen9 / Gen10 / Gen11',
                        ],
                    ],
                ],
                'attrs' => [
                    'watt' => 800,
                ],
            ],
            [
                'slug' => 'psu-1400w-fs',
                'category' => 'psu',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen9',
                    'gen10',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 2940,
                'in_stock' => true,
                'popular' => false,
                'sort' => 790,
                'name' => [
                    'fa' => 'HPE 1400W Flex Slot Platinum Hot-Plug',
                    'en' => 'HPE 1400W Flex Slot Platinum Hot-Plug',
                    'tr' => 'HPE 1400W Flex Slot Platinum Hot-Plug',
                ],
                'tagline' => [
                    'fa' => '1400 وات · 80 PLUS Platinum',
                    'en' => '1400 W · 80 PLUS Platinum',
                    'tr' => '1400 W · 80 PLUS Platinum',
                ],
                'summary' => [
                    'fa' => 'منبع تغذیهٔ هات‌پلاگ 1400 وات با گواهی 80 PLUS Platinum برای سرورهای Gen9 / Gen10.',
                    'en' => 'A 1400 W hot-plug power supply, 80 PLUS Platinum rated, for Gen9 / Gen10 servers.',
                    'tr' => 'Gen9 / Gen10 sunucular için 80 PLUS Platinum sertifikalı 1400 W hot-plug güç kaynağı.',
                ],
                'body' => [
                    'fa' => 'HPE 1400W Flex Slot Platinum Hot-Plug یک منبع تغذیهٔ هات‌پلاگ 1400 وات با گواهی بازده 80 PLUS Platinum برای سرورهای HPE ProLiant Gen9 / Gen10 است.

سرور را همیشه با دو منبع تغذیه ببندید، حتی اگر یکی برای بارتان کافی است. منبع تغذیه پرتکرارترین قطعه‌ای است که در سرور می‌سوزد، و با دو تا، سوختن یکی یعنی یک چراغ قرمز — نه یک قطعی.

بازده 80 PLUS Platinum فقط عدد روی برچسب نیست: در توان مصرفی واقعی سرور و در گرمایی که باید از رک بیرون برود، خودش را نشان می‌دهد.

دو نکتهٔ سازگاری: منبع Common Slot نسل‌های G6 تا Gen8 با منبع Flex Slot نسل ۹ به بعد جای هم نمی‌نشیند. و اگر دو منبع با توان متفاوت در یک سرور بگذارید، سرور هشدار می‌دهد و افزونگی را تضمین‌شده نمی‌داند.',
                    'en' => 'The HPE 1400W Flex Slot Platinum Hot-Plug is a 1400 W hot-plug power supply, 80 PLUS Platinum rated, for HPE ProLiant Gen9 / Gen10 servers.

Always fit two supplies, even when one covers your load. The PSU is the component that fails most often in a server, and with two of them a failure is a red light rather than an outage.

80 PLUS Platinum efficiency is not just a label: it shows up in the server’s real power draw and in the heat your rack has to remove.

Two compatibility notes: the Common Slot supply of the G6–Gen8 era and the Flex Slot supply from Gen9 onwards are not interchangeable. And if you fit two supplies of different wattage in one server, it will warn you and will not treat the redundancy as guaranteed.',
                    'tr' => 'HPE 1400W Flex Slot Platinum Hot-Plug, HPE ProLiant Gen9 / Gen10 sunucuları için 80 PLUS Platinum sertifikalı 1400 W hot-plug güç kaynağıdır.

Yükünüz için biri yetse bile daima iki güç kaynağı takın. Güç kaynağı bir sunucuda en sık arızalanan bileşendir ve iki taneyle arıza, bir kesinti değil kırmızı bir ışıktır.

80 PLUS Platinum verimlilik yalnızca bir etiket değildir: sunucunun gerçek güç tüketiminde ve rafınızdan atılması gereken ısıda kendini gösterir.

İki uyumluluk notu: G6–Gen8 dönemindeki Common Slot güç kaynağı ile Gen9 ve sonrasındaki Flex Slot güç kaynağı birbirinin yerine geçmez. Ayrıca aynı sunucuya farklı güçte iki kaynak takarsanız sunucu uyarı verir ve yedekliliği garantili saymaz.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'توان',
                            'en' => 'Output',
                            'tr' => 'Güç',
                        ],
                        'value' => [
                            'fa' => '1400 W',
                            'en' => '1400 W',
                            'tr' => '1400 W',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'گواهی بازده',
                            'en' => 'Efficiency rating',
                            'tr' => 'Verimlilik sertifikası',
                        ],
                        'value' => [
                            'fa' => '80 PLUS Platinum',
                            'en' => '80 PLUS Platinum',
                            'tr' => '80 PLUS Platinum',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'هات‌پلاگ',
                            'en' => 'Hot-plug',
                            'tr' => 'Hot-plug',
                        ],
                        'value' => [
                            'fa' => 'بله',
                            'en' => 'Yes',
                            'tr' => 'Evet',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen9 / Gen10',
                            'en' => 'Gen9 / Gen10',
                            'tr' => 'Gen9 / Gen10',
                        ],
                    ],
                ],
                'attrs' => [
                    'watt' => 1400,
                ],
            ],
            [
                'slug' => 'psu-1600w-fs',
                'category' => 'psu',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen9',
                    'gen10',
                    'gen11',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 7562,
                'in_stock' => true,
                'popular' => false,
                'sort' => 800,
                'name' => [
                    'fa' => 'HPE 1600W Flex Slot Platinum Hot-Plug',
                    'en' => 'HPE 1600W Flex Slot Platinum Hot-Plug',
                    'tr' => 'HPE 1600W Flex Slot Platinum Hot-Plug',
                ],
                'tagline' => [
                    'fa' => '1600 وات · 80 PLUS Platinum',
                    'en' => '1600 W · 80 PLUS Platinum',
                    'tr' => '1600 W · 80 PLUS Platinum',
                ],
                'summary' => [
                    'fa' => 'منبع تغذیهٔ هات‌پلاگ 1600 وات با گواهی 80 PLUS Platinum برای سرورهای Gen9 / Gen10 / Gen11.',
                    'en' => 'A 1600 W hot-plug power supply, 80 PLUS Platinum rated, for Gen9 / Gen10 / Gen11 servers.',
                    'tr' => 'Gen9 / Gen10 / Gen11 sunucular için 80 PLUS Platinum sertifikalı 1600 W hot-plug güç kaynağı.',
                ],
                'body' => [
                    'fa' => 'HPE 1600W Flex Slot Platinum Hot-Plug یک منبع تغذیهٔ هات‌پلاگ 1600 وات با گواهی بازده 80 PLUS Platinum برای سرورهای HPE ProLiant Gen9 / Gen10 / Gen11 است.

سرور را همیشه با دو منبع تغذیه ببندید، حتی اگر یکی برای بارتان کافی است. منبع تغذیه پرتکرارترین قطعه‌ای است که در سرور می‌سوزد، و با دو تا، سوختن یکی یعنی یک چراغ قرمز — نه یک قطعی.

بازده 80 PLUS Platinum فقط عدد روی برچسب نیست: در توان مصرفی واقعی سرور و در گرمایی که باید از رک بیرون برود، خودش را نشان می‌دهد.

دو نکتهٔ سازگاری: منبع Common Slot نسل‌های G6 تا Gen8 با منبع Flex Slot نسل ۹ به بعد جای هم نمی‌نشیند. و اگر دو منبع با توان متفاوت در یک سرور بگذارید، سرور هشدار می‌دهد و افزونگی را تضمین‌شده نمی‌داند.',
                    'en' => 'The HPE 1600W Flex Slot Platinum Hot-Plug is a 1600 W hot-plug power supply, 80 PLUS Platinum rated, for HPE ProLiant Gen9 / Gen10 / Gen11 servers.

Always fit two supplies, even when one covers your load. The PSU is the component that fails most often in a server, and with two of them a failure is a red light rather than an outage.

80 PLUS Platinum efficiency is not just a label: it shows up in the server’s real power draw and in the heat your rack has to remove.

Two compatibility notes: the Common Slot supply of the G6–Gen8 era and the Flex Slot supply from Gen9 onwards are not interchangeable. And if you fit two supplies of different wattage in one server, it will warn you and will not treat the redundancy as guaranteed.',
                    'tr' => 'HPE 1600W Flex Slot Platinum Hot-Plug, HPE ProLiant Gen9 / Gen10 / Gen11 sunucuları için 80 PLUS Platinum sertifikalı 1600 W hot-plug güç kaynağıdır.

Yükünüz için biri yetse bile daima iki güç kaynağı takın. Güç kaynağı bir sunucuda en sık arızalanan bileşendir ve iki taneyle arıza, bir kesinti değil kırmızı bir ışıktır.

80 PLUS Platinum verimlilik yalnızca bir etiket değildir: sunucunun gerçek güç tüketiminde ve rafınızdan atılması gereken ısıda kendini gösterir.

İki uyumluluk notu: G6–Gen8 dönemindeki Common Slot güç kaynağı ile Gen9 ve sonrasındaki Flex Slot güç kaynağı birbirinin yerine geçmez. Ayrıca aynı sunucuya farklı güçte iki kaynak takarsanız sunucu uyarı verir ve yedekliliği garantili saymaz.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'توان',
                            'en' => 'Output',
                            'tr' => 'Güç',
                        ],
                        'value' => [
                            'fa' => '1600 W',
                            'en' => '1600 W',
                            'tr' => '1600 W',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'گواهی بازده',
                            'en' => 'Efficiency rating',
                            'tr' => 'Verimlilik sertifikası',
                        ],
                        'value' => [
                            'fa' => '80 PLUS Platinum',
                            'en' => '80 PLUS Platinum',
                            'tr' => '80 PLUS Platinum',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'هات‌پلاگ',
                            'en' => 'Hot-plug',
                            'tr' => 'Hot-plug',
                        ],
                        'value' => [
                            'fa' => 'بله',
                            'en' => 'Yes',
                            'tr' => 'Evet',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen9 / Gen10 / Gen11',
                            'en' => 'Gen9 / Gen10 / Gen11',
                            'tr' => 'Gen9 / Gen10 / Gen11',
                        ],
                    ],
                ],
                'attrs' => [
                    'watt' => 1600,
                ],
            ],
            [
                'slug' => 'chassis-dl380p-gen8-8sff',
                'category' => 'chassis',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen8',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 8403,
                'in_stock' => true,
                'popular' => false,
                'sort' => 810,
                'name' => [
                    'fa' => 'HPE ProLiant DL380p Gen8 8SFF Barebone',
                    'en' => 'HPE ProLiant DL380p Gen8 8SFF Barebone',
                    'tr' => 'HPE ProLiant DL380p Gen8 8SFF Barebone',
                ],
                'tagline' => [
                    'fa' => '2U · 8 جایگاه SFF',
                    'en' => '2U · 8 × SFF bays',
                    'tr' => '2U · 8 × SFF yuva',
                ],
                'summary' => [
                    'fa' => 'شاسی بربون 2U با 8 جایگاه دیسک SFF — بدون پردازنده، حافظه و دیسک.',
                    'en' => 'A 2U barebone chassis with 8 × SFF drive bays — no processor, memory or drives.',
                    'tr' => '8 × SFF disk yuvalı 2U barebone kasa — işlemci, bellek ve disk hariç.',
                ],
                'body' => [
                    'fa' => 'HPE ProLiant DL380p Gen8 8SFF Barebone یک شاسی بربون 2U با 8 جایگاه دیسک SFF است: مادربرد، بک‌پلین، فن‌ها و شاسی — بدون پردازنده، حافظه، دیسک و کنترلر.

بربون وقتی منطقی است که قطعات را خودتان دارید یا می‌خواهید دقیقاً پیکربندی دلخواهتان را بچینید. اگر سرور آمادهٔ کارکرده می‌خواهید، فهرست سرورهای کامل ما گزینهٔ ساده‌تری است.

برای راه‌اندازی دست‌کم به این‌ها نیاز دارید: یک پردازندهٔ سازگار با Gen8، حافظهٔ ECC مناسب همان نسل، کنترلر ذخیره‌سازی، دیسک همراه کدی نسل درست، و دو منبع تغذیه.

شاسی پیش از ارسال روشن و از نظر بک‌پلین، فن و iLO تست می‌شود.',
                    'en' => 'The HPE ProLiant DL380p Gen8 8SFF Barebone is a 2U barebone chassis with 8 × SFF drive bays: motherboard, backplane, fans and enclosure — no processor, memory, drives or controller.

A barebone makes sense when you already hold the parts, or when you want to build exactly the configuration you have in mind. If you want a ready-to-run used server instead, our complete-server listing is the simpler option.

To bring it up you will need at least: a processor compatible with Gen8, ECC memory for that generation, a storage controller, drives in the correct-generation caddies, and two power supplies.

Every chassis is powered on and checked for backplane, fan and iLO health before it ships.',
                    'tr' => 'HPE ProLiant DL380p Gen8 8SFF Barebone, 8 × SFF disk yuvalı 2U barebone bir kasadır: anakart, backplane, fanlar ve muhafaza — işlemci, bellek, disk ve denetleyici hariç.

Barebone, parçalara zaten sahipseniz veya tam olarak aklınızdaki yapılandırmayı kurmak istiyorsanız mantıklıdır. Bunun yerine çalışmaya hazır ikinci el bir sunucu istiyorsanız, komple sunucu listemiz daha basit bir seçenektir.

Çalıştırmak için en az şunlar gerekir: Gen8 ile uyumlu bir işlemci, o nesle uygun ECC bellek, bir depolama denetleyicisi, doğru nesil kızaklarıyla diskler ve iki güç kaynağı.

Her kasa sevkiyattan önce açılır; backplane, fan ve iLO sağlığı kontrol edilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'ارتفاع رک',
                            'en' => 'Rack height',
                            'tr' => 'Raf yüksekliği',
                        ],
                        'value' => [
                            'fa' => '2U',
                            'en' => '2U',
                            'tr' => '2U',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'جایگاه دیسک',
                            'en' => 'Drive bays',
                            'tr' => 'Disk yuvası',
                        ],
                        'value' => [
                            'fa' => '8 × SFF',
                            'en' => '8 × SFF',
                            'tr' => '8 × SFF',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'محتویات',
                            'en' => 'Included',
                            'tr' => 'İçerik',
                        ],
                        'value' => [
                            'fa' => 'مادربرد، بک‌پلین، فن، شاسی',
                            'en' => 'Motherboard, backplane, fans, enclosure',
                            'tr' => 'Anakart, backplane, fanlar, muhafaza',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen8',
                            'en' => 'Gen8',
                            'tr' => 'Gen8',
                        ],
                    ],
                ],
                'attrs' => [
                    'u' => 2,
                    'bays' => 8,
                ],
            ],
            [
                'slug' => 'chassis-dl360p-gen8-8sff',
                'category' => 'chassis',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen8',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 7562,
                'in_stock' => true,
                'popular' => false,
                'sort' => 820,
                'name' => [
                    'fa' => 'HPE ProLiant DL360p Gen8 8SFF Barebone',
                    'en' => 'HPE ProLiant DL360p Gen8 8SFF Barebone',
                    'tr' => 'HPE ProLiant DL360p Gen8 8SFF Barebone',
                ],
                'tagline' => [
                    'fa' => '1U · 8 جایگاه SFF',
                    'en' => '1U · 8 × SFF bays',
                    'tr' => '1U · 8 × SFF yuva',
                ],
                'summary' => [
                    'fa' => 'شاسی بربون 1U با 8 جایگاه دیسک SFF — بدون پردازنده، حافظه و دیسک.',
                    'en' => 'A 1U barebone chassis with 8 × SFF drive bays — no processor, memory or drives.',
                    'tr' => '8 × SFF disk yuvalı 1U barebone kasa — işlemci, bellek ve disk hariç.',
                ],
                'body' => [
                    'fa' => 'HPE ProLiant DL360p Gen8 8SFF Barebone یک شاسی بربون 1U با 8 جایگاه دیسک SFF است: مادربرد، بک‌پلین، فن‌ها و شاسی — بدون پردازنده، حافظه، دیسک و کنترلر.

بربون وقتی منطقی است که قطعات را خودتان دارید یا می‌خواهید دقیقاً پیکربندی دلخواهتان را بچینید. اگر سرور آمادهٔ کارکرده می‌خواهید، فهرست سرورهای کامل ما گزینهٔ ساده‌تری است.

برای راه‌اندازی دست‌کم به این‌ها نیاز دارید: یک پردازندهٔ سازگار با Gen8، حافظهٔ ECC مناسب همان نسل، کنترلر ذخیره‌سازی، دیسک همراه کدی نسل درست، و دو منبع تغذیه.

شاسی پیش از ارسال روشن و از نظر بک‌پلین، فن و iLO تست می‌شود.',
                    'en' => 'The HPE ProLiant DL360p Gen8 8SFF Barebone is a 1U barebone chassis with 8 × SFF drive bays: motherboard, backplane, fans and enclosure — no processor, memory, drives or controller.

A barebone makes sense when you already hold the parts, or when you want to build exactly the configuration you have in mind. If you want a ready-to-run used server instead, our complete-server listing is the simpler option.

To bring it up you will need at least: a processor compatible with Gen8, ECC memory for that generation, a storage controller, drives in the correct-generation caddies, and two power supplies.

Every chassis is powered on and checked for backplane, fan and iLO health before it ships.',
                    'tr' => 'HPE ProLiant DL360p Gen8 8SFF Barebone, 8 × SFF disk yuvalı 1U barebone bir kasadır: anakart, backplane, fanlar ve muhafaza — işlemci, bellek, disk ve denetleyici hariç.

Barebone, parçalara zaten sahipseniz veya tam olarak aklınızdaki yapılandırmayı kurmak istiyorsanız mantıklıdır. Bunun yerine çalışmaya hazır ikinci el bir sunucu istiyorsanız, komple sunucu listemiz daha basit bir seçenektir.

Çalıştırmak için en az şunlar gerekir: Gen8 ile uyumlu bir işlemci, o nesle uygun ECC bellek, bir depolama denetleyicisi, doğru nesil kızaklarıyla diskler ve iki güç kaynağı.

Her kasa sevkiyattan önce açılır; backplane, fan ve iLO sağlığı kontrol edilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'ارتفاع رک',
                            'en' => 'Rack height',
                            'tr' => 'Raf yüksekliği',
                        ],
                        'value' => [
                            'fa' => '1U',
                            'en' => '1U',
                            'tr' => '1U',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'جایگاه دیسک',
                            'en' => 'Drive bays',
                            'tr' => 'Disk yuvası',
                        ],
                        'value' => [
                            'fa' => '8 × SFF',
                            'en' => '8 × SFF',
                            'tr' => '8 × SFF',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'محتویات',
                            'en' => 'Included',
                            'tr' => 'İçerik',
                        ],
                        'value' => [
                            'fa' => 'مادربرد، بک‌پلین، فن، شاسی',
                            'en' => 'Motherboard, backplane, fans, enclosure',
                            'tr' => 'Anakart, backplane, fanlar, muhafaza',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen8',
                            'en' => 'Gen8',
                            'tr' => 'Gen8',
                        ],
                    ],
                ],
                'attrs' => [
                    'u' => 1,
                    'bays' => 8,
                ],
            ],
            [
                'slug' => 'chassis-dl380-gen9-8sff',
                'category' => 'chassis',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen9',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 16806,
                'in_stock' => true,
                'popular' => true,
                'sort' => 830,
                'name' => [
                    'fa' => 'HPE ProLiant DL380 Gen9 8SFF Barebone',
                    'en' => 'HPE ProLiant DL380 Gen9 8SFF Barebone',
                    'tr' => 'HPE ProLiant DL380 Gen9 8SFF Barebone',
                ],
                'tagline' => [
                    'fa' => '2U · 8 جایگاه SFF',
                    'en' => '2U · 8 × SFF bays',
                    'tr' => '2U · 8 × SFF yuva',
                ],
                'summary' => [
                    'fa' => 'شاسی بربون 2U با 8 جایگاه دیسک SFF — بدون پردازنده، حافظه و دیسک.',
                    'en' => 'A 2U barebone chassis with 8 × SFF drive bays — no processor, memory or drives.',
                    'tr' => '8 × SFF disk yuvalı 2U barebone kasa — işlemci, bellek ve disk hariç.',
                ],
                'body' => [
                    'fa' => 'HPE ProLiant DL380 Gen9 8SFF Barebone یک شاسی بربون 2U با 8 جایگاه دیسک SFF است: مادربرد، بک‌پلین، فن‌ها و شاسی — بدون پردازنده، حافظه، دیسک و کنترلر.

بربون وقتی منطقی است که قطعات را خودتان دارید یا می‌خواهید دقیقاً پیکربندی دلخواهتان را بچینید. اگر سرور آمادهٔ کارکرده می‌خواهید، فهرست سرورهای کامل ما گزینهٔ ساده‌تری است.

برای راه‌اندازی دست‌کم به این‌ها نیاز دارید: یک پردازندهٔ سازگار با Gen9، حافظهٔ ECC مناسب همان نسل، کنترلر ذخیره‌سازی، دیسک همراه کدی نسل درست، و دو منبع تغذیه.

شاسی پیش از ارسال روشن و از نظر بک‌پلین، فن و iLO تست می‌شود.',
                    'en' => 'The HPE ProLiant DL380 Gen9 8SFF Barebone is a 2U barebone chassis with 8 × SFF drive bays: motherboard, backplane, fans and enclosure — no processor, memory, drives or controller.

A barebone makes sense when you already hold the parts, or when you want to build exactly the configuration you have in mind. If you want a ready-to-run used server instead, our complete-server listing is the simpler option.

To bring it up you will need at least: a processor compatible with Gen9, ECC memory for that generation, a storage controller, drives in the correct-generation caddies, and two power supplies.

Every chassis is powered on and checked for backplane, fan and iLO health before it ships.',
                    'tr' => 'HPE ProLiant DL380 Gen9 8SFF Barebone, 8 × SFF disk yuvalı 2U barebone bir kasadır: anakart, backplane, fanlar ve muhafaza — işlemci, bellek, disk ve denetleyici hariç.

Barebone, parçalara zaten sahipseniz veya tam olarak aklınızdaki yapılandırmayı kurmak istiyorsanız mantıklıdır. Bunun yerine çalışmaya hazır ikinci el bir sunucu istiyorsanız, komple sunucu listemiz daha basit bir seçenektir.

Çalıştırmak için en az şunlar gerekir: Gen9 ile uyumlu bir işlemci, o nesle uygun ECC bellek, bir depolama denetleyicisi, doğru nesil kızaklarıyla diskler ve iki güç kaynağı.

Her kasa sevkiyattan önce açılır; backplane, fan ve iLO sağlığı kontrol edilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'ارتفاع رک',
                            'en' => 'Rack height',
                            'tr' => 'Raf yüksekliği',
                        ],
                        'value' => [
                            'fa' => '2U',
                            'en' => '2U',
                            'tr' => '2U',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'جایگاه دیسک',
                            'en' => 'Drive bays',
                            'tr' => 'Disk yuvası',
                        ],
                        'value' => [
                            'fa' => '8 × SFF',
                            'en' => '8 × SFF',
                            'tr' => '8 × SFF',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'محتویات',
                            'en' => 'Included',
                            'tr' => 'İçerik',
                        ],
                        'value' => [
                            'fa' => 'مادربرد، بک‌پلین، فن، شاسی',
                            'en' => 'Motherboard, backplane, fans, enclosure',
                            'tr' => 'Anakart, backplane, fanlar, muhafaza',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen9',
                            'en' => 'Gen9',
                            'tr' => 'Gen9',
                        ],
                    ],
                ],
                'attrs' => [
                    'u' => 2,
                    'bays' => 8,
                ],
            ],
            [
                'slug' => 'chassis-dl380-gen9-12lff',
                'category' => 'chassis',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen9',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 21008,
                'in_stock' => true,
                'popular' => false,
                'sort' => 840,
                'name' => [
                    'fa' => 'HPE ProLiant DL380 Gen9 12LFF Barebone',
                    'en' => 'HPE ProLiant DL380 Gen9 12LFF Barebone',
                    'tr' => 'HPE ProLiant DL380 Gen9 12LFF Barebone',
                ],
                'tagline' => [
                    'fa' => '2U · 12 جایگاه LFF',
                    'en' => '2U · 12 × LFF bays',
                    'tr' => '2U · 12 × LFF yuva',
                ],
                'summary' => [
                    'fa' => 'شاسی بربون 2U با 12 جایگاه دیسک LFF — بدون پردازنده، حافظه و دیسک.',
                    'en' => 'A 2U barebone chassis with 12 × LFF drive bays — no processor, memory or drives.',
                    'tr' => '12 × LFF disk yuvalı 2U barebone kasa — işlemci, bellek ve disk hariç.',
                ],
                'body' => [
                    'fa' => 'HPE ProLiant DL380 Gen9 12LFF Barebone یک شاسی بربون 2U با 12 جایگاه دیسک LFF است: مادربرد، بک‌پلین، فن‌ها و شاسی — بدون پردازنده، حافظه، دیسک و کنترلر.

بربون وقتی منطقی است که قطعات را خودتان دارید یا می‌خواهید دقیقاً پیکربندی دلخواهتان را بچینید. اگر سرور آمادهٔ کارکرده می‌خواهید، فهرست سرورهای کامل ما گزینهٔ ساده‌تری است.

برای راه‌اندازی دست‌کم به این‌ها نیاز دارید: یک پردازندهٔ سازگار با Gen9، حافظهٔ ECC مناسب همان نسل، کنترلر ذخیره‌سازی، دیسک همراه کدی نسل درست، و دو منبع تغذیه.

شاسی پیش از ارسال روشن و از نظر بک‌پلین، فن و iLO تست می‌شود.',
                    'en' => 'The HPE ProLiant DL380 Gen9 12LFF Barebone is a 2U barebone chassis with 12 × LFF drive bays: motherboard, backplane, fans and enclosure — no processor, memory, drives or controller.

A barebone makes sense when you already hold the parts, or when you want to build exactly the configuration you have in mind. If you want a ready-to-run used server instead, our complete-server listing is the simpler option.

To bring it up you will need at least: a processor compatible with Gen9, ECC memory for that generation, a storage controller, drives in the correct-generation caddies, and two power supplies.

Every chassis is powered on and checked for backplane, fan and iLO health before it ships.',
                    'tr' => 'HPE ProLiant DL380 Gen9 12LFF Barebone, 12 × LFF disk yuvalı 2U barebone bir kasadır: anakart, backplane, fanlar ve muhafaza — işlemci, bellek, disk ve denetleyici hariç.

Barebone, parçalara zaten sahipseniz veya tam olarak aklınızdaki yapılandırmayı kurmak istiyorsanız mantıklıdır. Bunun yerine çalışmaya hazır ikinci el bir sunucu istiyorsanız, komple sunucu listemiz daha basit bir seçenektir.

Çalıştırmak için en az şunlar gerekir: Gen9 ile uyumlu bir işlemci, o nesle uygun ECC bellek, bir depolama denetleyicisi, doğru nesil kızaklarıyla diskler ve iki güç kaynağı.

Her kasa sevkiyattan önce açılır; backplane, fan ve iLO sağlığı kontrol edilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'ارتفاع رک',
                            'en' => 'Rack height',
                            'tr' => 'Raf yüksekliği',
                        ],
                        'value' => [
                            'fa' => '2U',
                            'en' => '2U',
                            'tr' => '2U',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'جایگاه دیسک',
                            'en' => 'Drive bays',
                            'tr' => 'Disk yuvası',
                        ],
                        'value' => [
                            'fa' => '12 × LFF',
                            'en' => '12 × LFF',
                            'tr' => '12 × LFF',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'محتویات',
                            'en' => 'Included',
                            'tr' => 'İçerik',
                        ],
                        'value' => [
                            'fa' => 'مادربرد، بک‌پلین، فن، شاسی',
                            'en' => 'Motherboard, backplane, fans, enclosure',
                            'tr' => 'Anakart, backplane, fanlar, muhafaza',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen9',
                            'en' => 'Gen9',
                            'tr' => 'Gen9',
                        ],
                    ],
                ],
                'attrs' => [
                    'u' => 2,
                    'bays' => 12,
                ],
            ],
            [
                'slug' => 'chassis-dl360-gen9-8sff',
                'category' => 'chassis',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen9',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 15126,
                'in_stock' => true,
                'popular' => false,
                'sort' => 850,
                'name' => [
                    'fa' => 'HPE ProLiant DL360 Gen9 8SFF Barebone',
                    'en' => 'HPE ProLiant DL360 Gen9 8SFF Barebone',
                    'tr' => 'HPE ProLiant DL360 Gen9 8SFF Barebone',
                ],
                'tagline' => [
                    'fa' => '1U · 8 جایگاه SFF',
                    'en' => '1U · 8 × SFF bays',
                    'tr' => '1U · 8 × SFF yuva',
                ],
                'summary' => [
                    'fa' => 'شاسی بربون 1U با 8 جایگاه دیسک SFF — بدون پردازنده، حافظه و دیسک.',
                    'en' => 'A 1U barebone chassis with 8 × SFF drive bays — no processor, memory or drives.',
                    'tr' => '8 × SFF disk yuvalı 1U barebone kasa — işlemci, bellek ve disk hariç.',
                ],
                'body' => [
                    'fa' => 'HPE ProLiant DL360 Gen9 8SFF Barebone یک شاسی بربون 1U با 8 جایگاه دیسک SFF است: مادربرد، بک‌پلین، فن‌ها و شاسی — بدون پردازنده، حافظه، دیسک و کنترلر.

بربون وقتی منطقی است که قطعات را خودتان دارید یا می‌خواهید دقیقاً پیکربندی دلخواهتان را بچینید. اگر سرور آمادهٔ کارکرده می‌خواهید، فهرست سرورهای کامل ما گزینهٔ ساده‌تری است.

برای راه‌اندازی دست‌کم به این‌ها نیاز دارید: یک پردازندهٔ سازگار با Gen9، حافظهٔ ECC مناسب همان نسل، کنترلر ذخیره‌سازی، دیسک همراه کدی نسل درست، و دو منبع تغذیه.

شاسی پیش از ارسال روشن و از نظر بک‌پلین، فن و iLO تست می‌شود.',
                    'en' => 'The HPE ProLiant DL360 Gen9 8SFF Barebone is a 1U barebone chassis with 8 × SFF drive bays: motherboard, backplane, fans and enclosure — no processor, memory, drives or controller.

A barebone makes sense when you already hold the parts, or when you want to build exactly the configuration you have in mind. If you want a ready-to-run used server instead, our complete-server listing is the simpler option.

To bring it up you will need at least: a processor compatible with Gen9, ECC memory for that generation, a storage controller, drives in the correct-generation caddies, and two power supplies.

Every chassis is powered on and checked for backplane, fan and iLO health before it ships.',
                    'tr' => 'HPE ProLiant DL360 Gen9 8SFF Barebone, 8 × SFF disk yuvalı 1U barebone bir kasadır: anakart, backplane, fanlar ve muhafaza — işlemci, bellek, disk ve denetleyici hariç.

Barebone, parçalara zaten sahipseniz veya tam olarak aklınızdaki yapılandırmayı kurmak istiyorsanız mantıklıdır. Bunun yerine çalışmaya hazır ikinci el bir sunucu istiyorsanız, komple sunucu listemiz daha basit bir seçenektir.

Çalıştırmak için en az şunlar gerekir: Gen9 ile uyumlu bir işlemci, o nesle uygun ECC bellek, bir depolama denetleyicisi, doğru nesil kızaklarıyla diskler ve iki güç kaynağı.

Her kasa sevkiyattan önce açılır; backplane, fan ve iLO sağlığı kontrol edilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'ارتفاع رک',
                            'en' => 'Rack height',
                            'tr' => 'Raf yüksekliği',
                        ],
                        'value' => [
                            'fa' => '1U',
                            'en' => '1U',
                            'tr' => '1U',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'جایگاه دیسک',
                            'en' => 'Drive bays',
                            'tr' => 'Disk yuvası',
                        ],
                        'value' => [
                            'fa' => '8 × SFF',
                            'en' => '8 × SFF',
                            'tr' => '8 × SFF',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'محتویات',
                            'en' => 'Included',
                            'tr' => 'İçerik',
                        ],
                        'value' => [
                            'fa' => 'مادربرد، بک‌پلین، فن، شاسی',
                            'en' => 'Motherboard, backplane, fans, enclosure',
                            'tr' => 'Anakart, backplane, fanlar, muhafaza',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen9',
                            'en' => 'Gen9',
                            'tr' => 'Gen9',
                        ],
                    ],
                ],
                'attrs' => [
                    'u' => 1,
                    'bays' => 8,
                ],
            ],
            [
                'slug' => 'chassis-dl380-gen10-8sff',
                'category' => 'chassis',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen10',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 37814,
                'in_stock' => true,
                'popular' => true,
                'sort' => 860,
                'name' => [
                    'fa' => 'HPE ProLiant DL380 Gen10 8SFF Barebone',
                    'en' => 'HPE ProLiant DL380 Gen10 8SFF Barebone',
                    'tr' => 'HPE ProLiant DL380 Gen10 8SFF Barebone',
                ],
                'tagline' => [
                    'fa' => '2U · 8 جایگاه SFF',
                    'en' => '2U · 8 × SFF bays',
                    'tr' => '2U · 8 × SFF yuva',
                ],
                'summary' => [
                    'fa' => 'شاسی بربون 2U با 8 جایگاه دیسک SFF — بدون پردازنده، حافظه و دیسک.',
                    'en' => 'A 2U barebone chassis with 8 × SFF drive bays — no processor, memory or drives.',
                    'tr' => '8 × SFF disk yuvalı 2U barebone kasa — işlemci, bellek ve disk hariç.',
                ],
                'body' => [
                    'fa' => 'HPE ProLiant DL380 Gen10 8SFF Barebone یک شاسی بربون 2U با 8 جایگاه دیسک SFF است: مادربرد، بک‌پلین، فن‌ها و شاسی — بدون پردازنده، حافظه، دیسک و کنترلر.

بربون وقتی منطقی است که قطعات را خودتان دارید یا می‌خواهید دقیقاً پیکربندی دلخواهتان را بچینید. اگر سرور آمادهٔ کارکرده می‌خواهید، فهرست سرورهای کامل ما گزینهٔ ساده‌تری است.

برای راه‌اندازی دست‌کم به این‌ها نیاز دارید: یک پردازندهٔ سازگار با Gen10، حافظهٔ ECC مناسب همان نسل، کنترلر ذخیره‌سازی، دیسک همراه کدی نسل درست، و دو منبع تغذیه.

شاسی پیش از ارسال روشن و از نظر بک‌پلین، فن و iLO تست می‌شود.',
                    'en' => 'The HPE ProLiant DL380 Gen10 8SFF Barebone is a 2U barebone chassis with 8 × SFF drive bays: motherboard, backplane, fans and enclosure — no processor, memory, drives or controller.

A barebone makes sense when you already hold the parts, or when you want to build exactly the configuration you have in mind. If you want a ready-to-run used server instead, our complete-server listing is the simpler option.

To bring it up you will need at least: a processor compatible with Gen10, ECC memory for that generation, a storage controller, drives in the correct-generation caddies, and two power supplies.

Every chassis is powered on and checked for backplane, fan and iLO health before it ships.',
                    'tr' => 'HPE ProLiant DL380 Gen10 8SFF Barebone, 8 × SFF disk yuvalı 2U barebone bir kasadır: anakart, backplane, fanlar ve muhafaza — işlemci, bellek, disk ve denetleyici hariç.

Barebone, parçalara zaten sahipseniz veya tam olarak aklınızdaki yapılandırmayı kurmak istiyorsanız mantıklıdır. Bunun yerine çalışmaya hazır ikinci el bir sunucu istiyorsanız, komple sunucu listemiz daha basit bir seçenektir.

Çalıştırmak için en az şunlar gerekir: Gen10 ile uyumlu bir işlemci, o nesle uygun ECC bellek, bir depolama denetleyicisi, doğru nesil kızaklarıyla diskler ve iki güç kaynağı.

Her kasa sevkiyattan önce açılır; backplane, fan ve iLO sağlığı kontrol edilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'ارتفاع رک',
                            'en' => 'Rack height',
                            'tr' => 'Raf yüksekliği',
                        ],
                        'value' => [
                            'fa' => '2U',
                            'en' => '2U',
                            'tr' => '2U',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'جایگاه دیسک',
                            'en' => 'Drive bays',
                            'tr' => 'Disk yuvası',
                        ],
                        'value' => [
                            'fa' => '8 × SFF',
                            'en' => '8 × SFF',
                            'tr' => '8 × SFF',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'محتویات',
                            'en' => 'Included',
                            'tr' => 'İçerik',
                        ],
                        'value' => [
                            'fa' => 'مادربرد، بک‌پلین، فن، شاسی',
                            'en' => 'Motherboard, backplane, fans, enclosure',
                            'tr' => 'Anakart, backplane, fanlar, muhafaza',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen10',
                            'en' => 'Gen10',
                            'tr' => 'Gen10',
                        ],
                    ],
                ],
                'attrs' => [
                    'u' => 2,
                    'bays' => 8,
                ],
            ],
            [
                'slug' => 'chassis-dl360-gen10-8sff',
                'category' => 'chassis',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen10',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 33613,
                'in_stock' => true,
                'popular' => false,
                'sort' => 870,
                'name' => [
                    'fa' => 'HPE ProLiant DL360 Gen10 8SFF Barebone',
                    'en' => 'HPE ProLiant DL360 Gen10 8SFF Barebone',
                    'tr' => 'HPE ProLiant DL360 Gen10 8SFF Barebone',
                ],
                'tagline' => [
                    'fa' => '1U · 8 جایگاه SFF',
                    'en' => '1U · 8 × SFF bays',
                    'tr' => '1U · 8 × SFF yuva',
                ],
                'summary' => [
                    'fa' => 'شاسی بربون 1U با 8 جایگاه دیسک SFF — بدون پردازنده، حافظه و دیسک.',
                    'en' => 'A 1U barebone chassis with 8 × SFF drive bays — no processor, memory or drives.',
                    'tr' => '8 × SFF disk yuvalı 1U barebone kasa — işlemci, bellek ve disk hariç.',
                ],
                'body' => [
                    'fa' => 'HPE ProLiant DL360 Gen10 8SFF Barebone یک شاسی بربون 1U با 8 جایگاه دیسک SFF است: مادربرد، بک‌پلین، فن‌ها و شاسی — بدون پردازنده، حافظه، دیسک و کنترلر.

بربون وقتی منطقی است که قطعات را خودتان دارید یا می‌خواهید دقیقاً پیکربندی دلخواهتان را بچینید. اگر سرور آمادهٔ کارکرده می‌خواهید، فهرست سرورهای کامل ما گزینهٔ ساده‌تری است.

برای راه‌اندازی دست‌کم به این‌ها نیاز دارید: یک پردازندهٔ سازگار با Gen10، حافظهٔ ECC مناسب همان نسل، کنترلر ذخیره‌سازی، دیسک همراه کدی نسل درست، و دو منبع تغذیه.

شاسی پیش از ارسال روشن و از نظر بک‌پلین، فن و iLO تست می‌شود.',
                    'en' => 'The HPE ProLiant DL360 Gen10 8SFF Barebone is a 1U barebone chassis with 8 × SFF drive bays: motherboard, backplane, fans and enclosure — no processor, memory, drives or controller.

A barebone makes sense when you already hold the parts, or when you want to build exactly the configuration you have in mind. If you want a ready-to-run used server instead, our complete-server listing is the simpler option.

To bring it up you will need at least: a processor compatible with Gen10, ECC memory for that generation, a storage controller, drives in the correct-generation caddies, and two power supplies.

Every chassis is powered on and checked for backplane, fan and iLO health before it ships.',
                    'tr' => 'HPE ProLiant DL360 Gen10 8SFF Barebone, 8 × SFF disk yuvalı 1U barebone bir kasadır: anakart, backplane, fanlar ve muhafaza — işlemci, bellek, disk ve denetleyici hariç.

Barebone, parçalara zaten sahipseniz veya tam olarak aklınızdaki yapılandırmayı kurmak istiyorsanız mantıklıdır. Bunun yerine çalışmaya hazır ikinci el bir sunucu istiyorsanız, komple sunucu listemiz daha basit bir seçenektir.

Çalıştırmak için en az şunlar gerekir: Gen10 ile uyumlu bir işlemci, o nesle uygun ECC bellek, bir depolama denetleyicisi, doğru nesil kızaklarıyla diskler ve iki güç kaynağı.

Her kasa sevkiyattan önce açılır; backplane, fan ve iLO sağlığı kontrol edilir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'ارتفاع رک',
                            'en' => 'Rack height',
                            'tr' => 'Raf yüksekliği',
                        ],
                        'value' => [
                            'fa' => '1U',
                            'en' => '1U',
                            'tr' => '1U',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'جایگاه دیسک',
                            'en' => 'Drive bays',
                            'tr' => 'Disk yuvası',
                        ],
                        'value' => [
                            'fa' => '8 × SFF',
                            'en' => '8 × SFF',
                            'tr' => '8 × SFF',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'محتویات',
                            'en' => 'Included',
                            'tr' => 'İçerik',
                        ],
                        'value' => [
                            'fa' => 'مادربرد، بک‌پلین، فن، شاسی',
                            'en' => 'Motherboard, backplane, fans, enclosure',
                            'tr' => 'Anakart, backplane, fanlar, muhafaza',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen10',
                            'en' => 'Gen10',
                            'tr' => 'Gen10',
                        ],
                    ],
                ],
                'attrs' => [
                    'u' => 1,
                    'bays' => 8,
                ],
            ],
            [
                'slug' => 'gpu-tesla-p4',
                'category' => 'gpu',
                'brand' => 'NVIDIA',
                'compat_gens' => [
                    'gen9',
                    'gen10',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 8403,
                'in_stock' => true,
                'popular' => false,
                'sort' => 880,
                'name' => [
                    'fa' => 'NVIDIA Tesla P4 8GB GDDR5',
                    'en' => 'NVIDIA Tesla P4 8GB GDDR5',
                    'tr' => 'NVIDIA Tesla P4 8GB GDDR5',
                ],
                'tagline' => [
                    'fa' => '8 گیگابایت · 75 وات · بدون فن',
                    'en' => '8 GB · 75 W · passive',
                    'tr' => '8 GB · 75 W · pasif',
                ],
                'summary' => [
                    'fa' => 'شتاب‌دهندهٔ 8 گیگابایتی با مصرف 75 وات و خنک‌کاری غیرفعال برای سرورهای Gen9 / Gen10.',
                    'en' => 'A 8 GB, 75 W passively cooled accelerator for Gen9 / Gen10 servers.',
                    'tr' => 'Gen9 / Gen10 sunucular için 8 GB, 75 W pasif soğutmalı hızlandırıcı.',
                ],
                'body' => [
                    'fa' => 'NVIDIA Tesla P4 8GB GDDR5 یک شتاب‌دهندهٔ سرور با 8 گیگابایت حافظه و توان 75 وات است و روی سرورهای HPE ProLiant Gen9 / Gen10 نصب می‌شود.

کارت‌های سرور فن ندارند: خنک‌کاری را جریان هوای خود شاسی انجام می‌دهد. همین یعنی نمی‌توانید کارت گرافیک دسکتاپ را جایگزینشان کنید و انتظار داشته باشید دوام بیاورد.

کاربرد اصلی: استنتاج مدل‌های یادگیری ماشین، ترنسکد ویدئو و میزبانی دسکتاپ مجازی. برای آموزش مدل‌های بزرگ، کارت‌های رده‌بالاتر لازم است.

پیش از سفارش تأیید کنید که شاسی شما کیت جریان هوای GPU و توان تغذیهٔ کافی دارد؛ بعضی پیکربندی‌ها به منبع تغذیهٔ بزرگ‌تر و کیت فن پرکارایی نیاز پیدا می‌کنند.',
                    'en' => 'The NVIDIA Tesla P4 8GB GDDR5 is a server accelerator with 8 GB of memory and a 75 W power envelope, for HPE ProLiant Gen9 / Gen10 servers.

Server cards have no fan of their own: the chassis airflow cools them. That is also why you cannot drop in a desktop graphics card and expect it to survive.

Main uses: machine-learning inference, video transcoding and virtual-desktop hosting. Training large models needs higher-tier cards.

Before ordering, confirm your chassis has the GPU airflow kit and enough power headroom; some configurations require a larger power supply and the high-performance fan kit.',
                    'tr' => 'NVIDIA Tesla P4 8GB GDDR5, HPE ProLiant Gen9 / Gen10 sunucuları için 8 GB bellekli ve 75 W güç zarflı bir sunucu hızlandırıcısıdır.

Sunucu kartlarının kendi fanı yoktur: onları kasa hava akışı soğutur. Bir masaüstü ekran kartını takıp dayanmasını bekleyememenizin nedeni de budur.

Başlıca kullanımlar: makine öğrenmesi çıkarımı, video kod dönüştürme ve sanal masaüstü barındırma. Büyük model eğitimi daha üst sınıf kartlar ister.

Sipariş öncesi kasanızda GPU hava akış kiti ve yeterli güç payı olduğunu doğrulayın; bazı yapılandırmalar daha büyük güç kaynağı ve yüksek performanslı fan kiti gerektirir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'حافظه',
                            'en' => 'Memory',
                            'tr' => 'Bellek',
                        ],
                        'value' => [
                            'fa' => '8 GB',
                            'en' => '8 GB',
                            'tr' => '8 GB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'توان',
                            'en' => 'Power',
                            'tr' => 'Güç',
                        ],
                        'value' => [
                            'fa' => '75 W',
                            'en' => '75 W',
                            'tr' => '75 W',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'خنک‌کاری',
                            'en' => 'Cooling',
                            'tr' => 'Soğutma',
                        ],
                        'value' => [
                            'fa' => 'غیرفعال (جریان هوای شاسی)',
                            'en' => 'Passive (chassis airflow)',
                            'tr' => 'Pasif (kasa hava akışı)',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen9 / Gen10',
                            'en' => 'Gen9 / Gen10',
                            'tr' => 'Gen9 / Gen10',
                        ],
                    ],
                ],
                'attrs' => [
                    'gb' => 8,
                    'tdp' => 75,
                ],
            ],
            [
                'slug' => 'gpu-tesla-t4',
                'category' => 'gpu',
                'brand' => 'NVIDIA',
                'compat_gens' => [
                    'gen9',
                    'gen10',
                    'gen11',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 50420,
                'in_stock' => true,
                'popular' => false,
                'sort' => 890,
                'name' => [
                    'fa' => 'NVIDIA Tesla T4 16GB GDDR6',
                    'en' => 'NVIDIA Tesla T4 16GB GDDR6',
                    'tr' => 'NVIDIA Tesla T4 16GB GDDR6',
                ],
                'tagline' => [
                    'fa' => '16 گیگابایت · 70 وات · بدون فن',
                    'en' => '16 GB · 70 W · passive',
                    'tr' => '16 GB · 70 W · pasif',
                ],
                'summary' => [
                    'fa' => 'شتاب‌دهندهٔ 16 گیگابایتی با مصرف 70 وات و خنک‌کاری غیرفعال برای سرورهای Gen9 / Gen10 / Gen11.',
                    'en' => 'A 16 GB, 70 W passively cooled accelerator for Gen9 / Gen10 / Gen11 servers.',
                    'tr' => 'Gen9 / Gen10 / Gen11 sunucular için 16 GB, 70 W pasif soğutmalı hızlandırıcı.',
                ],
                'body' => [
                    'fa' => 'NVIDIA Tesla T4 16GB GDDR6 یک شتاب‌دهندهٔ سرور با 16 گیگابایت حافظه و توان 70 وات است و روی سرورهای HPE ProLiant Gen9 / Gen10 / Gen11 نصب می‌شود.

کارت‌های سرور فن ندارند: خنک‌کاری را جریان هوای خود شاسی انجام می‌دهد. همین یعنی نمی‌توانید کارت گرافیک دسکتاپ را جایگزینشان کنید و انتظار داشته باشید دوام بیاورد.

کاربرد اصلی: استنتاج مدل‌های یادگیری ماشین، ترنسکد ویدئو و میزبانی دسکتاپ مجازی. برای آموزش مدل‌های بزرگ، کارت‌های رده‌بالاتر لازم است.

پیش از سفارش تأیید کنید که شاسی شما کیت جریان هوای GPU و توان تغذیهٔ کافی دارد؛ بعضی پیکربندی‌ها به منبع تغذیهٔ بزرگ‌تر و کیت فن پرکارایی نیاز پیدا می‌کنند.',
                    'en' => 'The NVIDIA Tesla T4 16GB GDDR6 is a server accelerator with 16 GB of memory and a 70 W power envelope, for HPE ProLiant Gen9 / Gen10 / Gen11 servers.

Server cards have no fan of their own: the chassis airflow cools them. That is also why you cannot drop in a desktop graphics card and expect it to survive.

Main uses: machine-learning inference, video transcoding and virtual-desktop hosting. Training large models needs higher-tier cards.

Before ordering, confirm your chassis has the GPU airflow kit and enough power headroom; some configurations require a larger power supply and the high-performance fan kit.',
                    'tr' => 'NVIDIA Tesla T4 16GB GDDR6, HPE ProLiant Gen9 / Gen10 / Gen11 sunucuları için 16 GB bellekli ve 70 W güç zarflı bir sunucu hızlandırıcısıdır.

Sunucu kartlarının kendi fanı yoktur: onları kasa hava akışı soğutur. Bir masaüstü ekran kartını takıp dayanmasını bekleyememenizin nedeni de budur.

Başlıca kullanımlar: makine öğrenmesi çıkarımı, video kod dönüştürme ve sanal masaüstü barındırma. Büyük model eğitimi daha üst sınıf kartlar ister.

Sipariş öncesi kasanızda GPU hava akış kiti ve yeterli güç payı olduğunu doğrulayın; bazı yapılandırmalar daha büyük güç kaynağı ve yüksek performanslı fan kiti gerektirir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'حافظه',
                            'en' => 'Memory',
                            'tr' => 'Bellek',
                        ],
                        'value' => [
                            'fa' => '16 GB',
                            'en' => '16 GB',
                            'tr' => '16 GB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'توان',
                            'en' => 'Power',
                            'tr' => 'Güç',
                        ],
                        'value' => [
                            'fa' => '70 W',
                            'en' => '70 W',
                            'tr' => '70 W',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'خنک‌کاری',
                            'en' => 'Cooling',
                            'tr' => 'Soğutma',
                        ],
                        'value' => [
                            'fa' => 'غیرفعال (جریان هوای شاسی)',
                            'en' => 'Passive (chassis airflow)',
                            'tr' => 'Pasif (kasa hava akışı)',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen9 / Gen10 / Gen11',
                            'en' => 'Gen9 / Gen10 / Gen11',
                            'tr' => 'Gen9 / Gen10 / Gen11',
                        ],
                    ],
                ],
                'attrs' => [
                    'gb' => 16,
                    'tdp' => 70,
                ],
            ],
            [
                'slug' => 'gpu-a2',
                'category' => 'gpu',
                'brand' => 'NVIDIA',
                'compat_gens' => [
                    'gen10',
                    'gen11',
                ],
                'condition' => 'refurb',
                'price_contact' => true,
                'price_eur' => null,
                'in_stock' => true,
                'popular' => false,
                'sort' => 900,
                'name' => [
                    'fa' => 'NVIDIA A2 16GB GDDR6',
                    'en' => 'NVIDIA A2 16GB GDDR6',
                    'tr' => 'NVIDIA A2 16GB GDDR6',
                ],
                'tagline' => [
                    'fa' => '16 گیگابایت · 60 وات · بدون فن',
                    'en' => '16 GB · 60 W · passive',
                    'tr' => '16 GB · 60 W · pasif',
                ],
                'summary' => [
                    'fa' => 'شتاب‌دهندهٔ 16 گیگابایتی با مصرف 60 وات و خنک‌کاری غیرفعال برای سرورهای Gen10 / Gen11.',
                    'en' => 'A 16 GB, 60 W passively cooled accelerator for Gen10 / Gen11 servers.',
                    'tr' => 'Gen10 / Gen11 sunucular için 16 GB, 60 W pasif soğutmalı hızlandırıcı.',
                ],
                'body' => [
                    'fa' => 'NVIDIA A2 16GB GDDR6 یک شتاب‌دهندهٔ سرور با 16 گیگابایت حافظه و توان 60 وات است و روی سرورهای HPE ProLiant Gen10 / Gen11 نصب می‌شود.

کارت‌های سرور فن ندارند: خنک‌کاری را جریان هوای خود شاسی انجام می‌دهد. همین یعنی نمی‌توانید کارت گرافیک دسکتاپ را جایگزینشان کنید و انتظار داشته باشید دوام بیاورد.

کاربرد اصلی: استنتاج مدل‌های یادگیری ماشین، ترنسکد ویدئو و میزبانی دسکتاپ مجازی. برای آموزش مدل‌های بزرگ، کارت‌های رده‌بالاتر لازم است.

پیش از سفارش تأیید کنید که شاسی شما کیت جریان هوای GPU و توان تغذیهٔ کافی دارد؛ بعضی پیکربندی‌ها به منبع تغذیهٔ بزرگ‌تر و کیت فن پرکارایی نیاز پیدا می‌کنند.',
                    'en' => 'The NVIDIA A2 16GB GDDR6 is a server accelerator with 16 GB of memory and a 60 W power envelope, for HPE ProLiant Gen10 / Gen11 servers.

Server cards have no fan of their own: the chassis airflow cools them. That is also why you cannot drop in a desktop graphics card and expect it to survive.

Main uses: machine-learning inference, video transcoding and virtual-desktop hosting. Training large models needs higher-tier cards.

Before ordering, confirm your chassis has the GPU airflow kit and enough power headroom; some configurations require a larger power supply and the high-performance fan kit.',
                    'tr' => 'NVIDIA A2 16GB GDDR6, HPE ProLiant Gen10 / Gen11 sunucuları için 16 GB bellekli ve 60 W güç zarflı bir sunucu hızlandırıcısıdır.

Sunucu kartlarının kendi fanı yoktur: onları kasa hava akışı soğutur. Bir masaüstü ekran kartını takıp dayanmasını bekleyememenizin nedeni de budur.

Başlıca kullanımlar: makine öğrenmesi çıkarımı, video kod dönüştürme ve sanal masaüstü barındırma. Büyük model eğitimi daha üst sınıf kartlar ister.

Sipariş öncesi kasanızda GPU hava akış kiti ve yeterli güç payı olduğunu doğrulayın; bazı yapılandırmalar daha büyük güç kaynağı ve yüksek performanslı fan kiti gerektirir.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'حافظه',
                            'en' => 'Memory',
                            'tr' => 'Bellek',
                        ],
                        'value' => [
                            'fa' => '16 GB',
                            'en' => '16 GB',
                            'tr' => '16 GB',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'توان',
                            'en' => 'Power',
                            'tr' => 'Güç',
                        ],
                        'value' => [
                            'fa' => '60 W',
                            'en' => '60 W',
                            'tr' => '60 W',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'خنک‌کاری',
                            'en' => 'Cooling',
                            'tr' => 'Soğutma',
                        ],
                        'value' => [
                            'fa' => 'غیرفعال (جریان هوای شاسی)',
                            'en' => 'Passive (chassis airflow)',
                            'tr' => 'Pasif (kasa hava akışı)',
                        ],
                    ],
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen10 / Gen11',
                            'en' => 'Gen10 / Gen11',
                            'tr' => 'Gen10 / Gen11',
                        ],
                    ],
                ],
                'attrs' => [
                    'gb' => 16,
                    'tdp' => 60,
                ],
            ],
            [
                'slug' => 'caddy-sff-sc',
                'category' => 'other',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen8',
                    'gen9',
                    'gen10',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 1680,
                'in_stock' => true,
                'popular' => false,
                'sort' => 910,
                'name' => [
                    'fa' => 'HPE 2.5" SFF Smart Carrier (SC) Hot-Plug',
                    'en' => 'HPE 2.5" SFF Smart Carrier (SC) Hot-Plug',
                    'tr' => 'HPE 2.5" SFF Smart Carrier (SC) Hot-Plug',
                ],
                'tagline' => [
                    'fa' => 'کدی هات‌پلاگ استاندارد نسل ۸ تا ۱۰ برای دیسک ۲٫۵ اینچی.',
                    'en' => 'Standard hot-plug caddy for 2.5" drives in Gen8–Gen10 servers.',
                    'tr' => 'Gen8–Gen10 sunucularda 2,5" diskler için standart hot-plug kızak.',
                ],
                'summary' => [
                    'fa' => 'کدی هات‌پلاگ استاندارد نسل ۸ تا ۱۰ برای دیسک ۲٫۵ اینچی.',
                    'en' => 'Standard hot-plug caddy for 2.5" drives in Gen8–Gen10 servers.',
                    'tr' => 'Gen8–Gen10 sunucularda 2,5" diskler için standart hot-plug kızak.',
                ],
                'body' => [
                    'fa' => 'کدی (Caddy) قطعه‌ای است که دیسک ۲٫۵ اینچی را در جایگاهِ هات‌پلاگِ سرور نگه می‌دارد و اتصالش را به بک‌پلین برقرار می‌کند. بدونِ آن، دیسک اصلاً در جایگاه نمی‌نشیند — نه اینکه بد بنشیند.

این مدل Smart Carrier (SC) است و روی نسل‌های ۸ تا ۱۰ ProLiant کار می‌کند. چراغِ دوگانهٔ روی دستگیره وضعیتِ دیسک و آرایهٔ RAID را نشان می‌دهد؛ همان چراغی که موقعِ تعویضِ دیسکِ خراب به شما می‌گوید کدام یکی را بیرون بکشید.

⚠️ نکته‌ای که زیاد گران تمام می‌شود: کدیِ Smart Carrier با کدیِ Basic Carrier (BC) نسل ۱۰ به بعد **جای هم نمی‌نشیند**. اگر سرور Gen10 دارید، پیش از سفارش مطمئن شوید کدامش را می‌خواهید؛ ظاهرشان شبیه است و اشتباه‌گرفتنشان آسان.

کدی نو تحویل داده می‌شود و با پیچ‌های مخصوصِ دیسک همراه است.

سازگار با Gen8 / Gen9 / Gen10.',
                    'en' => 'A caddy is the tray that holds a 2.5-inch drive in a server’s hot-plug bay and mates it with the backplane. Without one the drive simply will not seat — it is not a matter of fitting badly.

This is the Smart Carrier (SC) variant, used across ProLiant Gen8 to Gen10. The dual LED on the handle reports drive and RAID-array state — the same light that tells you which disk to pull when one fails.

One detail that costs money to get wrong: the Smart Carrier and the Gen10-and-later Basic Carrier (BC) are **not interchangeable**. If you run a Gen10, confirm which one you need before ordering; they look similar and are easy to confuse.

Supplied new, with the drive screws included.

Compatible with Gen8 / Gen9 / Gen10.',
                    'tr' => 'Caddy, 2,5 inç bir diski sunucunun hot-plug yuvasında tutan ve backplane ile birleştiren taşıyıcıdır. Onsuz disk yuvaya hiç oturmaz — kötü oturması söz konusu değildir.

Bu, ProLiant Gen8–Gen10 boyunca kullanılan Smart Carrier (SC) sürümüdür. Koldaki çift LED, disk ve RAID dizisi durumunu bildirir — bir disk arızalandığında hangisini çekeceğinizi söyleyen ışık budur.

Yanlış yapılırsa pahalıya patlayan bir ayrıntı: Smart Carrier ile Gen10 ve sonrası Basic Carrier (BC) **birbirinin yerine geçmez**. Gen10 kullanıyorsanız sipariş öncesi hangisini istediğinizden emin olun; görünüşleri benzer ve karıştırmak kolaydır.

Yeni olarak, disk vidalarıyla birlikte teslim edilir.

Gen8 / Gen9 / Gen10 ile uyumlu.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen8 / Gen9 / Gen10',
                            'en' => 'Gen8 / Gen9 / Gen10',
                            'tr' => 'Gen8 / Gen9 / Gen10',
                        ],
                    ],
                ],
                'attrs' => [],
            ],
            [
                'slug' => 'caddy-lff-sc',
                'category' => 'other',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen8',
                    'gen9',
                    'gen10',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 1680,
                'in_stock' => true,
                'popular' => false,
                'sort' => 920,
                'name' => [
                    'fa' => 'HPE 3.5" LFF Smart Carrier (SC) Hot-Plug',
                    'en' => 'HPE 3.5" LFF Smart Carrier (SC) Hot-Plug',
                    'tr' => 'HPE 3.5" LFF Smart Carrier (SC) Hot-Plug',
                ],
                'tagline' => [
                    'fa' => 'کدی هات‌پلاگ ۳٫۵ اینچی برای شاسی‌های LFF.',
                    'en' => 'Hot-plug 3.5" caddy for LFF chassis.',
                    'tr' => 'LFF kasalar için 3,5" hot-plug kızak.',
                ],
                'summary' => [
                    'fa' => 'کدی هات‌پلاگ ۳٫۵ اینچی برای شاسی‌های LFF.',
                    'en' => 'Hot-plug 3.5" caddy for LFF chassis.',
                    'tr' => 'LFF kasalar için 3,5" hot-plug kızak.',
                ],
                'body' => [
                    'fa' => 'کدیِ ۳٫۵ اینچی برای شاسی‌های LFF — همان نقشِ کدیِ SFF، در ابعادِ دیسکِ بزرگ.

شاسی‌های LFF (مثلِ DL380 Gen9 با ۱۲ جایگاه) برای ذخیره‌سازیِ حجیم ساخته شده‌اند: دیسک‌های ۴ تا ۱۶ ترابایتیِ ۷۲۰۰ دور که گیگابایتشان ارزان‌تر از هر گزینهٔ دیگری است. آن دیسک‌ها بدونِ کدیِ LFF قابلِ نصب نیستند.

⚠️ کدیِ LFF با کدیِ SFF جای هم نمی‌نشیند و مبدلِ «SFF به LFF» هم قطعهٔ جداگانه‌ای است. اگر می‌خواهید دیسکِ ۲٫۵ اینچی را در جایگاهِ ۳٫۵ اینچی بگذارید، به هر دو نیاز دارید.

چراغِ وضعیت و مکانیزمِ قفل مثلِ نسخهٔ SFF است و روی نسل‌های ۸ تا ۱۰ کار می‌کند.

سازگار با Gen8 / Gen9 / Gen10.',
                    'en' => 'The 3.5-inch caddy for LFF chassis — the same job as the SFF carrier, sized for large-format drives.

LFF chassis (a 12-bay DL380 Gen9, for instance) exist for bulk storage: 4 to 16 TB 7,200 rpm drives whose cost per gigabyte beats every alternative. Those drives cannot be fitted without an LFF caddy.

The LFF and SFF carriers are not interchangeable, and the “SFF to LFF” converter is a separate part again. To put a 2.5-inch drive in a 3.5-inch bay you need both.

Status LED and locking mechanism match the SFF version; fits Gen8 through Gen10.

Compatible with Gen8 / Gen9 / Gen10.',
                    'tr' => 'LFF kasalar için 3,5 inç kızak — SFF taşıyıcıyla aynı iş, büyük formatlı diskler için boyutlandırılmış.

LFF kasalar (örneğin 12 yuvalı bir DL380 Gen9) toplu depolama içindir: gigabayt başına maliyeti her alternatiften iyi olan 4–16 TB 7.200 devir diskler. Bu diskler LFF kızak olmadan takılamaz.

LFF ve SFF taşıyıcılar birbirinin yerine geçmez ve “SFF’den LFF’ye” dönüştürücü de ayrı bir parçadır. 2,5 inç bir diski 3,5 inç yuvaya koymak için ikisine birden ihtiyacınız var.

Durum LED’i ve kilit mekanizması SFF sürümüyle aynıdır; Gen8–Gen10 ile uyumludur.

Gen8 / Gen9 / Gen10 ile uyumlu.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen8 / Gen9 / Gen10',
                            'en' => 'Gen8 / Gen9 / Gen10',
                            'tr' => 'Gen8 / Gen9 / Gen10',
                        ],
                    ],
                ],
                'attrs' => [],
            ],
            [
                'slug' => 'caddy-sff-bc',
                'category' => 'other',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen10',
                    'gen11',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 3781,
                'in_stock' => true,
                'popular' => false,
                'sort' => 930,
                'name' => [
                    'fa' => 'HPE 2.5" SFF Basic Carrier (BC) Hot-Plug',
                    'en' => 'HPE 2.5" SFF Basic Carrier (BC) Hot-Plug',
                    'tr' => 'HPE 2.5" SFF Basic Carrier (BC) Hot-Plug',
                ],
                'tagline' => [
                    'fa' => 'کدی نسل ۱۰ به بعد؛ با کدی Smart Carrier نسل‌های قبل جابه‌جا نمی‌شود.',
                    'en' => 'The Gen10-and-later carrier; it is not interchangeable with the older Smart Carrier.',
                    'tr' => 'Gen10 ve sonrası kızak; eski Smart Carrier ile değiştirilemez.',
                ],
                'summary' => [
                    'fa' => 'کدی نسل ۱۰ به بعد؛ با کدی Smart Carrier نسل‌های قبل جابه‌جا نمی‌شود.',
                    'en' => 'The Gen10-and-later carrier; it is not interchangeable with the older Smart Carrier.',
                    'tr' => 'Gen10 ve sonrası kızak; eski Smart Carrier ile değiştirilemez.',
                ],
                'body' => [
                    'fa' => 'کدیِ Basic Carrier (BC) — نسخهٔ نسل ۱۰ به بعد.

با معرفیِ ProLiant Gen10، اچ‌پی‌ای طراحیِ کدی را عوض کرد: بدنه سبک‌تر شد و چراغِ وضعیت جایش تغییر کرد. نتیجه این است که کدیِ Smart Carrierِ نسل‌های قبل **در جایگاهِ Gen10 نمی‌نشیند** و برعکس.

🔴 این پرتکرارترین اشتباهِ خریدِ قطعهٔ سرور است: کسی دیسکِ Gen9 را با کدیِ خودش برای Gen10 سفارش می‌دهد، بسته می‌رسد، و هیچ‌کدام از دیسک‌ها جا نمی‌روند. پیش از سفارش، نسلِ سرور را بررسی کنید — نه مدلِ دیسک را.

روی DL360/DL380 Gen10 و Gen11 کار می‌کند و نو تحویل داده می‌شود.

سازگار با Gen10 / Gen11.',
                    'en' => 'The Basic Carrier (BC) — the Gen10-and-later design.

With ProLiant Gen10, HPE changed the caddy: a lighter body and a relocated status LED. The consequence is that the older Smart Carrier **will not seat in a Gen10 bay**, and vice versa.

This is the single most common server-parts ordering mistake: someone buys Gen9 drives in their existing carriers for a Gen10, the box arrives, and none of the drives go in. Check the server generation before ordering — not the drive model.

Fits DL360/DL380 Gen10 and Gen11. Supplied new.

Compatible with Gen10 / Gen11.',
                    'tr' => 'Basic Carrier (BC) — Gen10 ve sonrası tasarım.

ProLiant Gen10 ile birlikte HPE kızağı değiştirdi: daha hafif bir gövde ve yeri değişmiş bir durum LED’i. Sonuç olarak eski Smart Carrier **Gen10 yuvasına oturmaz**, tersi de geçerlidir.

Bu, sunucu parçası siparişinde en sık yapılan hatadır: kişi Gen10 için mevcut taşıyıcılarıyla Gen9 diskleri alır, kutu gelir ve disklerin hiçbiri girmez. Sipariş öncesi disk modelini değil, sunucu neslini kontrol edin.

DL360/DL380 Gen10 ve Gen11 ile uyumlu. Yeni olarak teslim edilir.

Gen10 / Gen11 ile uyumlu.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen10 / Gen11',
                            'en' => 'Gen10 / Gen11',
                            'tr' => 'Gen10 / Gen11',
                        ],
                    ],
                ],
                'attrs' => [],
            ],
            [
                'slug' => 'ilo-advanced-licence',
                'category' => 'other',
                'brand' => 'HPE',
                'compat_gens' => null,
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 6722,
                'in_stock' => true,
                'popular' => false,
                'sort' => 940,
                'name' => [
                    'fa' => 'HPE iLO Advanced License',
                    'en' => 'HPE iLO Advanced License',
                    'tr' => 'HPE iLO Advanced License',
                ],
                'tagline' => [
                    'fa' => 'کنسول گرافیکی از راه دور، مانت مجازی رسانه و ضبط رخداد — قابلیت‌هایی که در iLO پایه قفل‌اند.',
                    'en' => 'Remote graphical console, virtual media mount and event capture — the features locked out of base iLO.',
                    'tr' => 'Uzak grafik konsol, sanal medya bağlama ve olay kaydı — temel iLO’da kilitli olan özellikler.',
                ],
                'summary' => [
                    'fa' => 'کنسول گرافیکی از راه دور، مانت مجازی رسانه و ضبط رخداد — قابلیت‌هایی که در iLO پایه قفل‌اند.',
                    'en' => 'Remote graphical console, virtual media mount and event capture — the features locked out of base iLO.',
                    'tr' => 'Uzak grafik konsol, sanal medya bağlama ve olay kaydı — temel iLO’da kilitli olan özellikler.',
                ],
                'body' => [
                    'fa' => 'iLO پردازندهٔ مدیریتِ مستقلِ سرورهای HPE است: حتی وقتی سیستم‌عامل بالا نیامده یا سرور خاموش است، از طریقِ شبکه در دسترس است.

نسخهٔ پایه فقط وضعیتِ سخت‌افزار و روشن/خاموشِ از راه دور را می‌دهد. لایسنس Advanced چیزی را باز می‌کند که واقعاً کارِ اپراتور را عوض می‌کند:

· کنسولِ گرافیکیِ از راه دور (Integrated Remote Console) — یعنی نصبِ سیستم‌عامل، ورود به BIOS و رفعِ خطای بوت، بی‌آنکه کسی جلوی رک باشد.
· مانتِ مجازیِ رسانه — فایلِ ISOِ روی لپ‌تاپتان را به سرور وصل می‌کند، انگار DVD گذاشته‌اید.
· ضبطِ ویدئوییِ رخداد — لحظهٔ کرش را ضبط می‌کند تا بعداً ببینید سرور دقیقاً پیش از قفل‌شدن چه پیامی داده.
· هشدارِ ایمیلی و SNMP و احرازِ هویتِ دومرحله‌ای.

⚠️ لایسنس به **شمارهٔ سریالِ همان سرور** بسته می‌شود و منتقل نمی‌شود. اگر چند سرور دارید، برای هرکدام یکی لازم است. کلید به‌صورتِ دیجیتال تحویل داده می‌شود.

مستقل از نسلِ سرور؛ روی همهٔ نسل‌های فهرست‌شده کار می‌کند.',
                    'en' => 'iLO is the independent management processor in HPE servers: reachable over the network even when the operating system has not booted, or the server is powered down.

The base version gives you hardware health and remote power control. The Advanced licence unlocks what actually changes an operator’s day:

· Integrated Remote Console — install an OS, enter BIOS and fix a boot failure with nobody standing at the rack.
· Virtual media mount — attach an ISO from your own laptop as if you had inserted a DVD.
· Console video capture — records the moment of a crash so you can see exactly what the server said before it locked up.
· Email and SNMP alerting, plus two-factor authentication.

The licence binds to that server’s serial number and is not transferable. With several servers you need one each. The key is delivered digitally.

Generation-independent; works across every listed generation.',
                    'tr' => 'iLO, HPE sunucularındaki bağımsız yönetim işlemcisidir: işletim sistemi açılmamışken, hatta sunucu kapalıyken bile ağ üzerinden erişilebilir.

Temel sürüm donanım durumu ve uzaktan güç kontrolü verir. Advanced lisansı, bir operatörün gününü gerçekten değiştiren şeyleri açar:

· Integrated Remote Console — rafın başında kimse olmadan işletim sistemi kurun, BIOS’a girin ve açılış hatasını giderin.
· Sanal medya bağlama — kendi dizüstünüzdeki bir ISO’yu DVD takmış gibi sunucuya bağlayın.
· Konsol video kaydı — çökme anını kaydeder, böylece sunucunun kilitlenmeden önce tam olarak ne dediğini görürsünüz.
· E-posta ve SNMP uyarıları, ayrıca iki adımlı kimlik doğrulama.

Lisans o sunucunun seri numarasına bağlanır ve devredilemez. Birden çok sunucunuz varsa her biri için bir tane gerekir. Anahtar dijital olarak teslim edilir.

Nesilden bağımsız; listelenen tüm nesillerde çalışır.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'همهٔ نسل‌ها',
                            'en' => 'All generations',
                            'tr' => 'Tüm nesiller',
                        ],
                    ],
                ],
                'attrs' => [],
            ],
            [
                'slug' => 'rack-rail-kit',
                'category' => 'other',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen8',
                    'gen9',
                    'gen10',
                    'gen11',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 3361,
                'in_stock' => true,
                'popular' => false,
                'sort' => 950,
                'name' => [
                    'fa' => 'HPE 1U/2U Rack Rail Kit + CMA',
                    'en' => 'HPE 1U/2U Rack Rail Kit + CMA',
                    'tr' => 'HPE 1U/2U Rack Rail Kit + CMA',
                ],
                'tagline' => [
                    'fa' => 'ریل کشویی با بازوی جمع‌کنندهٔ کابل؛ بی‌این، سرویس سرور یعنی خارج‌کردن کاملش از رک.',
                    'en' => 'Sliding rails with a cable-management arm; without them, servicing means pulling the server out of the rack entirely.',
                    'tr' => 'Kablo yönetim kollu kayar raylar; olmadan servis, sunucuyu raftan tamamen çıkarmak demektir.',
                ],
                'summary' => [
                    'fa' => 'ریل کشویی با بازوی جمع‌کنندهٔ کابل؛ بی‌این، سرویس سرور یعنی خارج‌کردن کاملش از رک.',
                    'en' => 'Sliding rails with a cable-management arm; without them, servicing means pulling the server out of the rack entirely.',
                    'tr' => 'Kablo yönetim kollu kayar raylar; olmadan servis, sunucuyu raftan tamamen çıkarmak demektir.',
                ],
                'body' => [
                    'fa' => 'ریلِ کشویی با بازوی جمع‌کنندهٔ کابل (CMA) برای سرورهای ۱U و ۲U.

ریل فقط «نگه‌داشتنِ سرور در رک» نیست. با ریلِ کشویی می‌توانید سرورِ روشن را تا نیمه بیرون بکشید و در حالی که کار می‌کند دیسک، فن یا رم را عوض کنید. بدونِ ریل — یا با ریلِ ثابت — هر سرویسی یعنی خاموش‌کردن، بازکردنِ همهٔ کابل‌ها و بیرون‌آوردنِ کاملِ دستگاه از رک.

بازوی CMA کابل‌های برق و شبکه را در یک مسیرِ منظم نگه می‌دارد تا هنگامِ بیرون‌کشیدنِ سرور کشیده یا قطع نشوند. همان چیزی که تفاوتِ «تعویضِ یک دیسک در دو دقیقه» و «قطعیِ ناخواستهٔ یک سرورِ دیگر در همان رک» است.

⚠️ عمقِ رکِ خود را اندازه بگیرید. این ریل برای رکِ استانداردِ ۱۹ اینچی با عمقِ ۶۰ تا ۱۰۰ سانتی‌متر است؛ رکِ کم‌عمق جا نمی‌دهد.

سازگار با Gen8 / Gen9 / Gen10 / Gen11.',
                    'en' => 'Sliding rails with a cable-management arm (CMA) for 1U and 2U servers.

Rails are not merely about holding the server in the rack. Sliding rails let you pull a running server halfway out and swap a drive, a fan or a DIMM while it keeps working. Without rails — or with fixed ones — every service job means powering down, unplugging everything and lifting the whole machine out.

The CMA keeps power and network cables on a tidy path so they are not dragged or unplugged as the server slides. That is the difference between “a two-minute drive swap” and “an accidental outage on a different server in the same rack”.

Measure your rack depth before ordering. These fit a standard 19-inch rack of 60–100 cm depth; a shallow rack will not take them.

Compatible with Gen8 / Gen9 / Gen10 / Gen11.',
                    'tr' => '1U ve 2U sunucular için kablo yönetim kollu (CMA) kayar raylar.

Raylar yalnızca sunucuyu rafta tutmakla ilgili değildir. Kayar raylar, çalışan bir sunucuyu yarıya kadar dışarı çekip çalışmaya devam ederken disk, fan veya bellek değiştirmenizi sağlar. Ray olmadan — veya sabit raylarla — her servis işi kapatmak, her şeyi sökmek ve makineyi tamamen çıkarmak demektir.

CMA, güç ve ağ kablolarını düzenli bir yolda tutar; böylece sunucu kayarken çekilmez veya çıkmaz. Bu, “iki dakikalık disk değişimi” ile “aynı raftaki başka bir sunucuda kazara kesinti” arasındaki farktır.

Sipariş öncesi raf derinliğinizi ölçün. Bunlar 60–100 cm derinlikte standart 19 inç rafa uyar; sığ bir raf almaz.

Gen8 / Gen9 / Gen10 / Gen11 ile uyumlu.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen8 / Gen9 / Gen10 / Gen11',
                            'en' => 'Gen8 / Gen9 / Gen10 / Gen11',
                            'tr' => 'Gen8 / Gen9 / Gen10 / Gen11',
                        ],
                    ],
                ],
                'attrs' => [],
            ],
            [
                'slug' => 'heatsink-kit',
                'category' => 'other',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen9',
                    'gen10',
                    'gen11',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 2520,
                'in_stock' => true,
                'popular' => false,
                'sort' => 960,
                'name' => [
                    'fa' => 'HPE High-Performance Heatsink Kit',
                    'en' => 'HPE High-Performance Heatsink Kit',
                    'tr' => 'HPE High-Performance Heatsink Kit',
                ],
                'tagline' => [
                    'fa' => 'هیت‌سینک پرکارایی برای پردازنده‌های بالای ۱۳۵ وات؛ با هیت‌سینک استاندارد، سرور خودش را throttle می‌کند.',
                    'en' => 'High-performance heatsink for processors above 135 W; with the standard heatsink the server throttles itself.',
                    'tr' => '135 W üzeri işlemciler için yüksek performanslı soğutucu; standart soğutucuyla sunucu kendini kısar.',
                ],
                'summary' => [
                    'fa' => 'هیت‌سینک پرکارایی برای پردازنده‌های بالای ۱۳۵ وات؛ با هیت‌سینک استاندارد، سرور خودش را throttle می‌کند.',
                    'en' => 'High-performance heatsink for processors above 135 W; with the standard heatsink the server throttles itself.',
                    'tr' => '135 W üzeri işlemciler için yüksek performanslı soğutucu; standart soğutucuyla sunucu kendini kısar.',
                ],
                'body' => [
                    'fa' => 'هیت‌سینکِ پرکارایی برای پردازنده‌های پرمصرف.

سرورهای ProLiant دو نوع هیت‌سینک دارند: استاندارد و پرکارایی. استاندارد تا حدودِ ۱۳۵ وات را جواب می‌دهد؛ بالاتر از آن، پردازنده به دمای بحرانی می‌رسد و خودش را throttle می‌کند.

🔴 مسئله این است که این اتفاق **خطا نمی‌دهد**. سرور بالا می‌آید، کار می‌کند، هیچ چراغی قرمز نمی‌شود — فقط پردازندهٔ ۲٫۵ گیگاهرتزی‌تان زیرِ بارِ سنگین روی ۱٫۸ گیگاهرتز می‌ماند. یعنی برای کارایی‌ای پول داده‌اید که هرگز نمی‌گیرید، و تنها راهِ فهمیدنش نگاه‌کردن به فرکانسِ واقعی زیرِ بار است.

اگر پردازنده‌ای مثلِ Gold 6248R (۲۰۵ وات)، Platinum 8280 یا Gold 6430 سفارش می‌دهید، این کیت و کیتِ فنِ پرکارایی هر دو لازم‌اند.

کیت شاملِ هیت‌سینک و خمیرِ حرارتیِ از پیش‌زده است.

سازگار با Gen9 / Gen10 / Gen11.',
                    'en' => 'The high-performance heatsink for high-TDP processors.

ProLiant servers ship with one of two heatsinks: standard or high-performance. The standard one copes to roughly 135 W; above that the processor reaches its thermal limit and throttles itself.

The problem is that this **raises no error**. The server boots, it runs, no light turns red — your 2.5 GHz processor simply sits at 1.8 GHz under sustained load. You paid for performance you never receive, and the only way to notice is to watch the actual clock under load.

If you are ordering something like a Gold 6248R (205 W), a Platinum 8280 or a Gold 6430, you need both this kit and the high-performance fan kit.

Supplied with thermal compound pre-applied.

Compatible with Gen9 / Gen10 / Gen11.',
                    'tr' => 'Yüksek TDP’li işlemciler için yüksek performanslı soğutucu.

ProLiant sunucular iki soğutucudan biriyle gelir: standart veya yüksek performanslı. Standart olan yaklaşık 135 W’a kadar yeter; bunun üzerinde işlemci termal sınırına ulaşır ve kendini kısar.

Sorun şu ki bu **hiçbir hata üretmez**. Sunucu açılır, çalışır, hiçbir ışık kırmızıya dönmez — 2,5 GHz işlemciniz sürekli yük altında yalnızca 1,8 GHz’de kalır. Hiç almadığınız bir performans için ödeme yaptınız ve bunu fark etmenin tek yolu yük altındaki gerçek hızı izlemektir.

Gold 6248R (205 W), Platinum 8280 veya Gold 6430 gibi bir işlemci sipariş ediyorsanız, hem bu kite hem de yüksek performanslı fan kitine ihtiyacınız var.

Termal macunu önceden sürülmüş olarak gelir.

Gen9 / Gen10 / Gen11 ile uyumlu.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen9 / Gen10 / Gen11',
                            'en' => 'Gen9 / Gen10 / Gen11',
                            'tr' => 'Gen9 / Gen10 / Gen11',
                        ],
                    ],
                ],
                'attrs' => [],
            ],
            [
                'slug' => 'fan-kit',
                'category' => 'other',
                'brand' => 'HPE',
                'compat_gens' => [
                    'gen9',
                    'gen10',
                    'gen11',
                ],
                'condition' => 'refurb',
                'price_contact' => false,
                'price_eur' => 2940,
                'in_stock' => true,
                'popular' => false,
                'sort' => 970,
                'name' => [
                    'fa' => 'HPE High-Performance Fan Kit',
                    'en' => 'HPE High-Performance Fan Kit',
                    'tr' => 'HPE High-Performance Fan Kit',
                ],
                'tagline' => [
                    'fa' => 'کیت فن پرکارایی؛ برای پردازندهٔ پرمصرف یا شاسی پر از دیسک الزامی است.',
                    'en' => 'High-performance fan kit; required for high-TDP processors or a fully populated drive cage.',
                    'tr' => 'Yüksek performanslı fan kiti; yüksek TDP’li işlemciler veya tam dolu disk kafesi için gereklidir.',
                ],
                'summary' => [
                    'fa' => 'کیت فن پرکارایی؛ برای پردازندهٔ پرمصرف یا شاسی پر از دیسک الزامی است.',
                    'en' => 'High-performance fan kit; required for high-TDP processors or a fully populated drive cage.',
                    'tr' => 'Yüksek performanslı fan kiti; yüksek TDP’li işlemciler veya tam dolu disk kafesi için gereklidir.',
                ],
                'body' => [
                    'fa' => 'کیتِ فنِ پرکارایی — جفتِ همیشگیِ هیت‌سینکِ پرکارایی.

جریانِ هوای شاسی همان چیزی است که هیت‌سینک را کار می‌اندازد؛ هیت‌سینکِ بزرگ با فنِ ضعیف فقط فلزِ داغ است. برای همین اچ‌پی‌ای این دو را جدا نمی‌فروشد به این معنا که جدا لازم باشند — در پیکربندی‌های پرمصرف هر دو الزامی‌اند.

سه حالت این کیت را لازم می‌کند:

· پردازندهٔ بالای ۱۳۵ وات (هر Gold یا Platinumِ رده‌بالا).
· جایگاهِ دیسکِ کاملاً پر — ۲۴ دیسکِ SFF گرمای قابلِ توجهی تولید می‌کنند و مسیرِ هوا را هم تنگ می‌کنند.
· کارتِ گرافیکِ سرور، که خودش فن ندارد و کاملاً به جریانِ هوای شاسی وابسته است.

⚠️ اگر سرور فن‌های کافی نداشته باشد، iLO خطای «Fan Redundancy Lost» می‌دهد و در برخی پیکربندی‌ها اصلاً بوت نمی‌شود. فن‌ها هات‌پلاگ‌اند و بی‌خاموش‌کردنِ سرور عوض می‌شوند.

سازگار با Gen9 / Gen10 / Gen11.',
                    'en' => 'The high-performance fan kit — the constant companion of the high-performance heatsink.

Chassis airflow is what makes a heatsink work; a large heatsink behind weak fans is just hot metal. HPE pairs the two for that reason — in high-power configurations both are required.

Three situations call for this kit:

· A processor above 135 W (any upper-range Gold or Platinum).
· A fully populated drive cage — 24 SFF drives generate real heat and also narrow the air path.
· A server GPU, which has no fan of its own and depends entirely on chassis airflow.

If the server lacks sufficient fans, iLO reports “Fan Redundancy Lost” and some configurations will not boot at all. The fans are hot-plug and swap without powering the server down.

Compatible with Gen9 / Gen10 / Gen11.',
                    'tr' => 'Yüksek performanslı fan kiti — yüksek performanslı soğutucunun değişmez eşi.

Soğutucuyu çalıştıran şey kasa hava akışıdır; zayıf fanların ardındaki büyük bir soğutucu yalnızca sıcak metaldir. HPE ikisini bu yüzden birlikte konumlandırır — yüksek güçlü yapılandırmalarda her ikisi de zorunludur.

Üç durum bu kiti gerektirir:

· 135 W üzerinde bir işlemci (üst sınıf herhangi bir Gold veya Platinum).
· Tamamen dolu bir disk kafesi — 24 SFF disk ciddi ısı üretir ve hava yolunu da daraltır.
· Kendi fanı olmayan ve tamamen kasa hava akışına bağlı bir sunucu GPU’su.

Sunucuda yeterli fan yoksa iLO “Fan Redundancy Lost” bildirir ve bazı yapılandırmalar hiç açılmaz. Fanlar hot-plug’dır ve sunucu kapatılmadan değiştirilir.

Gen9 / Gen10 / Gen11 ile uyumlu.',
                ],
                'specs' => [
                    [
                        'label' => [
                            'fa' => 'نسل سازگار',
                            'en' => 'Compatible generation',
                            'tr' => 'Uyumlu nesil',
                        ],
                        'value' => [
                            'fa' => 'Gen9 / Gen10 / Gen11',
                            'en' => 'Gen9 / Gen10 / Gen11',
                            'tr' => 'Gen9 / Gen10 / Gen11',
                        ],
                    ],
                ],
                'attrs' => [],
            ],
        ];
    }
}
