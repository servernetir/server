<?php

/*
|--------------------------------------------------------------------------
| مستندات سرورنت — بخش‌بندی (Information Architecture)
|--------------------------------------------------------------------------
| هر بخش یک دسته در سایدبار درختی مستندات است. مقاله‌های هر بخش از دیتابیس
| خوانده می‌شوند (posts با type='kb' و category برابر کلید بخش).
| ترتیب بخش‌ها همین ترتیب آرایه است.
*/

return [
    'sections' => [
        'getting-started' => [
            'icon' => 'rocket',
            'fa' => ['t' => 'شروع کار', 'd' => 'ثبت سفارش، ناحیه کاربری و اولین قدم‌ها'],
            'en' => ['t' => 'Getting Started', 'd' => 'Ordering, client area and first steps'],
            'tr' => ['t' => 'Başlarken', 'd' => 'Sipariş, müşteri paneli ve ilk adımlar'],
        ],
        'hosting' => [
            'icon' => 'server',
            'fa' => ['t' => 'هاست وب', 'd' => 'کنترل‌پنل، آپلود سایت، دیتابیس و وردپرس'],
            'en' => ['t' => 'Web Hosting', 'd' => 'Control panel, uploads, databases and WordPress'],
            'tr' => ['t' => 'Web Hosting', 'd' => 'Kontrol paneli, yükleme, veritabanı ve WordPress'],
        ],
        'servers' => [
            'icon' => 'cpu',
            'fa' => ['t' => 'سرور مجازی و اختصاصی', 'd' => 'اتصال، راه‌اندازی اولیه و مدیریت سرور'],
            'en' => ['t' => 'VPS & Dedicated', 'd' => 'Connecting, initial setup and server management'],
            'tr' => ['t' => 'VPS & Fiziksel Sunucu', 'd' => 'Bağlantı, ilk kurulum ve sunucu yönetimi'],
        ],
        'domains' => [
            'icon' => 'globe',
            'fa' => ['t' => 'دامنه و DNS', 'd' => 'ثبت، انتقال، نیم‌سرور و رکوردهای DNS'],
            'en' => ['t' => 'Domains & DNS', 'd' => 'Registration, transfer, nameservers and DNS records'],
            'tr' => ['t' => 'Alan Adı & DNS', 'd' => 'Kayıt, transfer, nameserver ve DNS kayıtları'],
        ],
        'email' => [
            'icon' => 'mail',
            'fa' => ['t' => 'ایمیل', 'd' => 'ساخت اکانت، تنظیم کلاینت و احراز فرستنده'],
            'en' => ['t' => 'Email', 'd' => 'Mailboxes, client setup and sender authentication'],
            'tr' => ['t' => 'E-posta', 'd' => 'Hesap oluşturma, istemci kurulumu ve gönderen doğrulama'],
        ],
        'cloud' => [
            'icon' => 'cloud',
            'fa' => ['t' => 'سرویس‌های ابری', 'd' => 'زیرساخت ابری، کوبرنتیز، فضای ذخیره‌سازی و CDN'],
            'en' => ['t' => 'Cloud Services', 'd' => 'Cloud infrastructure, Kubernetes, storage and CDN'],
            'tr' => ['t' => 'Bulut Hizmetleri', 'd' => 'Bulut altyapısı, Kubernetes, depolama ve CDN'],
        ],
        'security' => [
            'icon' => 'shield',
            'fa' => ['t' => 'امنیت و SSL', 'd' => 'گواهینامه، فایروال، بکاپ و سخت‌سازی'],
            'en' => ['t' => 'Security & SSL', 'd' => 'Certificates, firewall, backups and hardening'],
            'tr' => ['t' => 'Güvenlik & SSL', 'd' => 'Sertifika, güvenlik duvarı, yedekleme ve sıkılaştırma'],
        ],
        'tools' => [
            'icon' => 'wrench',
            'fa' => ['t' => 'ابزارهای سرورنت', 'd' => 'بررسی DNS و شبکه، سایت‌ساز، دسکتاپ ریموت و تلفن ابری'],
            'en' => ['t' => 'ServerNet Tools', 'd' => 'DNS & network checks, site builder, remote desktop, cloud phone'],
            'tr' => ['t' => 'ServerNet Araçları', 'd' => 'DNS & ağ kontrolü, site kurucu, uzak masaüstü, bulut telefon'],
        ],
        'billing' => [
            'icon' => 'coins',
            'fa' => ['t' => 'حساب کاربری و مالی', 'd' => 'تمدید، ارتقا، فاکتور و تیکت پشتیبانی'],
            'en' => ['t' => 'Account & Billing', 'd' => 'Renewals, upgrades, invoices and support tickets'],
            'tr' => ['t' => 'Hesap & Faturalama', 'd' => 'Yenileme, yükseltme, fatura ve destek talepleri'],
        ],
    ],
];
