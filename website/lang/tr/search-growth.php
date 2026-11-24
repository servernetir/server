<?php

return [
    'ai' => [
        'title' => 'Yapay zekâ API, saatlik GPU veya n8n: projenize hangisi uygun?',
        'intro' => 'Önce ihtiyacınız olan sonucu belirleyin: hazır bir modelden yanıt almak, kendi kodunuzu GPU üzerinde çalıştırmak veya birkaç servisi bir iş akışında birleştirmek. Her seçeneğin kullanım maliyeti ve bakım sorumluluğu farklıdır.',
        'cards' => [
            ['title' => 'Uygulamanız için hazır model API’si', 'text' => 'Sohbet, özetleme veya anlamsal arama için ilgili işlemi destekleyen kullanılabilir bir model seçin. Model sayfasında ücret birimini, girdi sınırlarını ve kullanım hesaplayıcısını inceleyin. Bir model her işlemi desteklemeyebilir.', 'route' => 'ai.models', 'link' => 'Modelleri ve tahmini maliyeti karşılaştırın'],
            ['title' => 'Kendi kodunuz ve modeliniz için GPU', 'text' => 'PyTorch, özel model ağırlıkları veya GPU ile veri işleme için uyumlu hazır uygulamayı inceleyin. VRAM ihtiyacını belirleyin, dosyaları geçici disk dışında tutun ve kesilen işleri nasıl sürdüreceğinizi planlayın.', 'route' => 'gpu', 'link' => 'Saatlik GPU kiralama seçenekleri'],
            ['title' => 'İş süreçlerini bağlamak için n8n', 'text' => 'n8n ile form, CRM ve yapay zekâ servisini bir akışta birleştirebilirsiniz. Barındırma ve harici API kullanımı ayrı ücretlendirilir. Servis anahtarını iş akışı çıktısında paylaşmak yerine kimlik bilgileri bölümünde tutun.', 'route' => 'cloud.location', 'parameters' => ['location' => 'n8n'], 'link' => 'n8n barındırmayı inceleyin'],
        ],
        'checks' => ['Bakiye yüklemeden önce kullanılabilir modeli ve işlemi kontrol edin; anahtarın ilgili işlem yetkisi olmalıdır.', 'İlk isteği kısa bir metin ve düşük çıktı sınırıyla gönderin. İstek kimliğini ve gerçek ücreti kullanım geçmişinde inceleyin.', 'Her mantıksal istek için yeni bir Idempotency-Key oluşturun. Aynı isteği tekrarlarken aynı anahtarı ve gövdeyi koruyun.'],
    ],
    'gpu' => [
        'title' => 'GPU kiralamaya nereden başlamalı? Uygulamayı seçin, çıktınızı koruyun',
        'intro' => 'Ekran kartından önce iş yükünü seçin. Saatlik GPU hazır bir uygulama ortamı sunar; siparişten önce belleği, erişim yöntemini ve çalışma koşullarını değerlendirin.',
        'cards' => [
            ['title' => 'ComfyUI ile görsel iş akışları', 'text' => 'Kullanacağınız modelin ve iş akışının hazır uygulamayla uyumunu kontrol edin. Başka dağıtımlardaki bütün eklentiler ve arayüzler pakete dahil değildir. Küçük bir çıktı alarak başlayın ve sonucu kaydedin.', 'route' => 'blog', 'parameters' => ['slug' => 'comfyui-hourly-gpu-guide'], 'link' => 'ComfyUI başlangıç rehberini okuyun'],
            ['title' => 'Jupyter ile Python ve PyTorch', 'text' => 'Veri analizi veya model çalışmaları için kodunuzu ve bağımlılıkları hazırlayın. Uzun bir işe başlamadan önce CUDA algılamasını, disk alanını ve checkpoint dosyalarını dışarı kaydetme yolunu kontrol edin.', 'route' => 'blog', 'parameters' => ['slug' => 'jupyter-hourly-gpu-guide'], 'link' => 'Jupyter ve checkpoint rehberini okuyun'],
            ['title' => 'Yalnızca hazır model yanıtı mı gerekiyor?', 'text' => 'Uygulamanızı hazır bir modele bağlamak istiyorsanız yapay zekâ API’sini de karşılaştırın. API’de modelin ücret birimine; GPU’da ortamın çalışma süresine ve işi yönetme sorumluluğuna bakın.', 'route' => 'ai.home', 'link' => 'Yapay zekâ API’sini inceleyin'],
        ],
        'checks' => ['Başlangıç bakiyesini, gösterilen ücreti, seçilen uygulamayı ve hazır olma durumunu hesabınızda kontrol edin.', 'Bu hizmet geleneksel VPS gibi root parolası veya herkese açık SSH sunmaz. Entegrasyondan önce uygulamanın HTTPS yolunu ve sınırlarını doğrulayın.', 'Dosya ve çıktıları ortam dışında saklayın. Kullanımı bitirmek için panelden durdurun veya iptal edin; son durumu kontrol edin.'],
    ],
    'python' => [
        'title' => 'Django veya Flask kodundan yayına hazır web uygulamasına',
        'intro' => 'Python hosting seçmeden önce çalışma ortamını, veritabanını ve arka plan işlerini belirleyin. Küçük, kontrol edilebilir bir uygulama kurulum hatalarını uygulama hatalarından ayırmayı kolaylaştırır.',
        'cards' => [
            ['title' => 'WSGI kurulumunu adım adım yapın', 'text' => 'Sanal ortam, bağımlılıklar ve uygulama giriş noktası birbiriyle uyumlu olmalıdır. Django ve Flask rehberinde basit bir sağlık yolu örneğini ve yayın kontrol listesini inceleyin.', 'route' => 'blog', 'parameters' => ['slug' => 'python-hosting-django-flask-deployment'], 'link' => 'Django ve Flask yayınlama rehberi'],
            ['title' => 'Paketi gerçek ihtiyacınıza göre seçin', 'text' => 'Uygulama sayısını, RAM’i, dosya alanını ve MySQL/MariaDB ihtiyacını karşılaştırın. Yalnızca dosya uzantısı veya framework adı kaynak ihtiyacını belirlemez.', 'fragment' => '#pricing', 'link' => 'Python hosting paketlerini karşılaştırın'],
            ['title' => 'Worker ve WebSocket için VPS’yi değerlendirin', 'text' => 'Sürekli Celery worker, WebSocket, SSE veya tam işletim sistemi kontrolü uygun bir ortam gerektirir. VPS ile paylaşımlı hosting arasında karar verirken yazılım yığınının yönetimini de hesaba katın.', 'route' => 'vps.hourly', 'link' => 'Saatlik VPS seçenekleri'],
        ],
        'checks' => ['Yayından önce DEBUG’u kapatın; gizli bilgileri kod deposundan ve herkese açık dizinlerden uzak tutun.', 'HTTPS, static dosyalar, veritabanı bağlantısı ve sağlık yolunu ayrı kontrol edin. Ana sayfanın açılması hepsinin çalıştığını göstermez.', '500 hatasında zamanı, istek yolunu ve gizli bilgilerden temizlenmiş hatayı desteğe iletin; parola veya ortam dosyasını göndermeyin.'],
    ],
    'n8n' => [
        'title' => 'İlk n8n webhook’u: gerçek işe bağlamadan önce neyi kontrol etmelisiniz?',
        'intro' => 'HTTPS adresini aldıktan sonra sabit yanıt döndüren bir test akışı oluşturun. Bu ilk denemeye mesaj gönderimi, ödeme veya müşteri verisi değişikliği bağlamayın.',
        'cards' => [
            ['title' => 'Test ve Production URL’lerini ayırın', 'text' => 'Test URL’sini çağırmadan önce Listen for test event seçeneğini açın. Production URL için akış yayımlanmış veya etkin olmalıdır. İstek yöntemini ve tam yolu Webhook düğümünden kopyalayın.', 'fragment' => '#n8n-faq', 'link' => 'n8n erişimini ve hizmet koşullarını inceleyin'],
            ['title' => 'Yanıtı ve çalıştırma kaydını birlikte kontrol edin', 'text' => 'Örnek veri gönderin, sabit yanıtı doğrulayın ve aynı çalışmayı Executions altında bulun. 404 hatası yanlış yol, yöntem veya test/production durumundan kaynaklanabilir. HTTPS sertifikasını da kontrol edin.', 'route' => 'blog', 'parameters' => ['slug' => 'n8n-backup-migration-guide'], 'link' => 'Çalıştırma ve taşıma rehberi'],
            ['title' => 'Önemli akışlardan önce geri yüklemeyi deneyin', 'text' => 'Veritabanını, iş akışlarını, kimlik bilgilerini ve şifreleme anahtarını yedek planına dahil edin. Dışarıda bir kopya tutup geri yüklemeyi sınayın. Yalnızca workflow JSON çıktısı tam yedek değildir.', 'route' => 'blog', 'parameters' => ['slug' => 'n8n-backup-migration-guide'], 'link' => 'n8n yedekleme kontrol listesi'],
        ],
        'checks' => ['Hassas veriler işleyen herkese açık webhook’lar için uygun kimlik doğrulama ayarlayın. Uzun bir URL, erişim izni politikasının yerini tutmaz.', 'Bağlı servislerin sınırları ve yapay zekâ API tüketimi dahil ücretleri, barındırma fiyatından ayrıdır.', 'İş akışı geliştirme ve yönetilen düzenli yedekleme temel pakette vaat edilmez. Siparişten önce destek kapsamını okuyun.'],
    ],
    'curl_note' => 'Bu örnek gerçek kullanım ücreti doğurur. Anahtarı ortam değişkeninde tutun. Her mantıksal istek için bir kimlik oluşturun; aynı isteği tekrarlarken bu kimliği koruyun.',
];
