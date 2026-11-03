<?php

return [
    'windows' => [
        'title' => 'Windows Hosting | ASP.NET, MSSQL ve Plesk',
        'description' => 'ASP.NET ve MSSQL için Plesk Windows hosting planlarını karşılaştırın. Siparişten önce veritabanı kotasını, disk alanını ve yayınlama gereksinimlerini inceleyin.',
        'guide_title' => 'ASP.NET hosting mi Windows VPS mi?',
        'guide_intro' => 'IIS ve MSSQL gereksinimlerini Windows hosting planıyla karşılaştırın. Paylaşımlı hosting, tam işletim sistemi yönetimi veya RDP masaüstü erişimi sağlamaz.',
        'checks' => [
            '.NET sürümünü ve yayınlama biçimini belirleyin: framework-dependent veya self-contained. Siparişten önce runtime uyumluluğunu ve gerekli IIS ayarlarını inceleyin.',
            'Veritabanı sayısını, alan kotasını ve bağlantı gereksinimlerini planla karşılaştırın. SSMS bağlantısı, Windows yönetici erişimi anlamına gelmez.',
            'Sistem yazılımı, Docker, kalıcı servisler veya port yönetimi için VPS planlarını ve siparişte sunulan işletim sistemlerini karşılaştırın.',
        ],
        'related_label' => 'Hosting seçeneklerini karşılaştırın',
        'other' => 'PHP ve WordPress için Linux hosting',
        'other_slug' => 'linux',
        'vps' => 'Saatlik VPS maliyetlerini karşılaştırın',
        'contact' => 'Uygulama uyumluluğunu desteğe sorun',
    ],
    'linux' => [
        'title' => 'Linux Hosting | PHP ve WordPress Planları',
        'description' => 'PHP ve WordPress için Linux hosting planlarını karşılaştırın. Siparişten önce disk alanını, veritabanı kotasını ve kontrol paneli koşullarını inceleyin.',
        'guide_title' => 'PHP, WordPress ve veritabanı için Linux hosting seçimi',
        'guide_intro' => 'Bilgisayarınızın işletim sistemi hosting türünü belirlemez. Uygulamanın dilini, veritabanını, kaynak tüketimini ve yayınlama erişimini planla karşılaştırın.',
        'checks' => [
            'Tema, CMS veya framework için gereken PHP sürümünü ve eklentileri inceleyin. Kontrol paneli adı tek başına yazılım uyumluluğunu garanti etmez.',
            'Dosya, veritabanı ve e-posta alanlarını ve kaynak sınırlarını gerçek kullanımla karşılaştırın. Bağımsız bir geri yükleme planı tutun.',
            'IIS veya MSSQL bağımlılıkları için Windows hostingi karşılaştırın. Sistem yazılımı ve servislerin tam yönetimi için VPS seçeneğini değerlendirin.',
        ],
        'related_label' => 'Hosting seçeneklerini karşılaştırın',
        'other' => 'ASP.NET ve MSSQL için Windows hosting',
        'other_slug' => 'windows',
        'vps' => 'Saatlik VPS maliyetlerini karşılaştırın',
        'contact' => 'Plan seçimi için destek alın',
    ],
];

