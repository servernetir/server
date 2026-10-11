<?php

return [
'title' => ':place VPS | Güncel Paketler ve Fiyatlar | ServerNet',
'description' => ':place VPS paketlerini CPU, RAM, disk ve ağ koşullarına göre karşılaştırın. Siparişten önce güncel fiyatları, kapasiteyi ve konum seçimi rehberini inceleyin.',
'h1' => ':place VPS sunucu',
'intro' => ':place VPS seçerken hedef kullanıcıları ve uygulamanın ihtiyaçlarını belirleyin; ardından güncel kaynakları, disk türünü ve ağ koşullarını karşılaştırın. Son özellikler ve tutar sipariş sırasında yeniden kontrol edilir.',
'profile_default' => 'Kullanıcılarınız veya uygulamanın bağlı olduğu bir hizmet :country içindeyse bu konumu ağ testlerinize ekleyin. Kendi konumunuz tek başına seçim ölçütü değildir; gerçek kullanıcı rotalarını ve uygulama kaynak ihtiyaçlarını inceleyin.',
'network_title' => 'Gecikme ve ağ kalitesi nasıl test edilir?',
'network' => 'Bu konum için herkese açık, ölçülmüş bir gecikme sonucu yayımlamadık. Destekten doğrulanmış bir test IP’si isteyin; gerçek kullanıcı bağlantılarından farklı saatlerde ping ve traceroute veya mtr çalıştırın. ICMP tek başına yeterli değildir: HTTP yanıt süresini, paket kaybını ve uygulamanın bağlı olduğu hizmetlerin rotalarını da ölçün. Özel IP, her sitenin kabulünü veya tüm coğrafi IP veritabanlarının aynı ülkeyi göstermesini garanti etmez.',
'check_title' => ':country VPS satın almadan önce neler karşılaştırılmalı?',
'country_link' => ':country VPS konumlarının tümü',
'checks' => ['CPU ve RAM: uygulama yükünü ve eşzamanlı kullanımı ölçün. Paylaşımlı vCPU, ayrılmış CPU kaynağı ve fiziksel sunucu aynı ürün değildir.',
'Disk: seçilen paketin kapasitesini ve disk türünü okuyun; her pakette NVMe veya yedekleme bulunduğunu varsaymayın.',
'Ağ: sabit Mbps bant genişliği ile GB/TB aktarım kotası farklıdır. Hızı, kotayı ve aşım koşullarını inceleyin.',
'İşletim sistemi: yalnızca seçilen paketin uyumlu sipariş seçeneklerini kullanın. Windows veya belirli bir uygulama için sürüm ve lisans koşullarını kontrol edin.',
'Maliyet ve işletim: onaydan önce fatura dönemini, durdurma/silme koşullarını ve güncel kapasiteyi okuyun; kendi yedekleme planınızı hazırlayın.'],
'faq' => [[
'q' => ':country VPS İran’a düşük gecikme garantisi verir mi?',
'a' => 'Coğrafi yakınlık gecikmeyi garanti etmez. Operatör rotası, test saati ve gerçek hedef önemlidir. Seçimden önce hedef kullanıcı bağlantılarından test edin; bu konum için garanti edilmiş bir gecikme değeri vermiyoruz.'
],
[
'q' => ':place için uygun paketi nasıl seçebilirim?',
'a' => 'Bu sayfadaki CPU, RAM, disk ve ağ koşullarını uygulama yüküyle karşılaştırın. Kritik hizmetler için yanıt sürelerini test edin ve bağımsız yedek tutun. Kaynak artışı tek başına ağ rotası sorununu çözmez.'
],
[
'q' => ':country paketlerinin hepsinde Windows veya NVMe var mı?',
'a' => 'Hayır. İşletim sistemi, disk türü ve ağ modeli pakete bağlıdır. Paketin özelliklerini ve uyumlu sipariş seçeneklerini kontrol edin; tek bir paketin özelliğini tüm paketlere genellemeyin.'
],
[
'q' => ':country sunucu fiyatı ve kapasitesi sabit kalır mı?',
'a' => 'Güncel fiyat ve kapasite katalogdan alınır ve değişebilir. Son tutar, fatura dönemi ve özellikler sipariş onayından önce yeniden gösterilir.'
]],
'profiles' => [
'AE' => 'BAE kullanıcılarına hizmet veren mağaza veya API için Dubai’yi değerlendirin. İran kitlesi için Dubai ve İstanbul rotalarını kullanıcı operatörlerinden HTTP testiyle karşılaştırın.',
'TR' => 'Türkiye kullanıcıları ve Avrupa hizmetleriyle çalışan uygulamalar için İstanbul bir test adayıdır. Gerçek bağımlılıklara giden rotayı ölçün; Tahran pingi tüm kullanıcıları temsil etmez.',
'SA' => 'Suudi kullanıcılar için mağaza veya API barındırırken Riyad ile Dubai’yi karşılaştırın. Suudi Arabistan’da barındırma tek başına mevzuat uyumu, lisans veya hedef hizmetin IP kabulünü belgelemez.',
'PH' => 'Filipinli kullanıcılar için Manila’yı Singapur ve Hong Kong ile test edin. Veritabanı veya API başka ülkedeyse uygulama bileşenleri arasındaki gidiş dönüş süresini de ölçün.',
'SG' => 'Güneydoğu Asya’nın birkaç ülkesine hizmet veren API veya siteler için Singapur’u değerlendirin. Kitle ağırlıkla Filipinler, Endonezya veya Malezya’daysa yerel şehri de test edin.',
'HK' => 'Bölgesel kullanıcılar veya Asya’daki uygulama bağımlılıkları için Hong Kong’u değerlendirin. Ağlara göre erişim değişir; bu konum Çin ana karasına erişimi veya hızı garanti etmez.',
'DE' => 'Almanya’daki kullanıcılar için mevcut şehirleri veritabanı ve bağımlılık konumlarıyla karşılaştırın. Yalnızca Frankfurt adına bakmayın; CPU türünü, maliyeti ve yanıt süresini birlikte değerlendirin.',
'US' => 'ABD kitlesi için kıyı ve şehir önemlidir; doğu ve batı kullanıcılarının rotaları farklıdır. Siparişe açık şehirleri API ve veritabanı konumlarıyla karşılaştırın.',
'VN' => 'Vietnam kitlesi için Hanoi ve Ho Chi Minh’i ayrı değerlendirin. Etkileşimli uygulama, mağaza ve API gerçek kullanıcı rotalarına bağlıdır; tek operatör testi iki bölgeyi temsil etmez.',
'FR' => 'Fransa kullanıcıları için paketin gerçek şehrini kontrol edin; Marsilya ve Paris aynı konum değildir. Uygulamayı taşımadan önce Avrupa veritabanlarına yanıt süresini ölçün.',
'FI' => 'Finlandiya kullanıcıları veya Kuzey Avrupa bağımlılıkları için Helsinki’yi değerlendirin. Maliyet öncelikliyse güncel kaynakları, fiyatları ve kullanıcı rotalarını Almanya ile karşılaştırın.',
'GB' => 'Birleşik Krallık kullanıcıları veya buradaki API’lerle çalışan uygulamalar için Londra’yı değerlendirin. VPS finansal hizmet izni veya işlem yürütme garantisi vermez; uyumluluğu ayrı inceleyin.',
'IQ' => 'Bağdat’ı gerçek Irak kullanıcı ağlarından test edin ve bu sayfadaki kesinti duyurularını okuyun. Kritik hizmetler için alternatif rota ve bağımsız yedek planlayın.',
'AR' => 'Arjantin kitlesi için Buenos Aires ile São Paulo seçeneklerini karşılaştırın. Mağaza, API veya geliştirme ortamında kullanıcı ağındaki yanıt süresi ve veritabanı rotası ülke adından daha önemlidir. İkinci konum bir ağ karşılaştırma adayıdır; kapasite ve fiyatını kendi sayfasında kontrol edin.',
'BD' => 'Bangladeş kitlesi için Dakka ile Singapur seçeneklerini karşılaştırın. Mağaza, API veya geliştirme ortamında kullanıcı ağındaki yanıt süresi ve veritabanı rotası ülke adından daha önemlidir. İkinci konum bir ağ karşılaştırma adayıdır; kapasite ve fiyatını kendi sayfasında kontrol edin.',
'BG' => 'Bulgaristan kitlesi için Sofya ile Bükreş seçeneklerini karşılaştırın. Mağaza, API veya geliştirme ortamında kullanıcı ağındaki yanıt süresi ve veritabanı rotası ülke adından daha önemlidir. İkinci konum bir ağ karşılaştırma adayıdır; kapasite ve fiyatını kendi sayfasında kontrol edin.',
'BH' => 'Bahreyn kitlesi için Bahreyn ile Dubai seçeneklerini karşılaştırın. Mağaza, API veya geliştirme ortamında kullanıcı ağındaki yanıt süresi ve veritabanı rotası ülke adından daha önemlidir. İkinci konum bir ağ karşılaştırma adayıdır; kapasite ve fiyatını kendi sayfasında kontrol edin.',
'BR' => 'Brezilya kitlesi için São Paulo ile Buenos Aires seçeneklerini karşılaştırın. Mağaza, API veya geliştirme ortamında kullanıcı ağındaki yanıt süresi ve veritabanı rotası ülke adından daha önemlidir. İkinci konum bir ağ karşılaştırma adayıdır; kapasite ve fiyatını kendi sayfasında kontrol edin.',
'CA' => 'Kanada kitlesi için Toronto ile ABD şehirleri seçeneklerini karşılaştırın. Mağaza, API veya geliştirme ortamında kullanıcı ağındaki yanıt süresi ve veritabanı rotası ülke adından daha önemlidir. İkinci konum bir ağ karşılaştırma adayıdır; kapasite ve fiyatını kendi sayfasında kontrol edin.',
'CL' => 'Şili kitlesi için Santiago ile Buenos Aires seçeneklerini karşılaştırın. Mağaza, API veya geliştirme ortamında kullanıcı ağındaki yanıt süresi ve veritabanı rotası ülke adından daha önemlidir. İkinci konum bir ağ karşılaştırma adayıdır; kapasite ve fiyatını kendi sayfasında kontrol edin.',
'CO' => 'Kolombiya kitlesi için Bogotá ile São Paulo seçeneklerini karşılaştırın. Mağaza, API veya geliştirme ortamında kullanıcı ağındaki yanıt süresi ve veritabanı rotası ülke adından daha önemlidir. İkinci konum bir ağ karşılaştırma adayıdır; kapasite ve fiyatını kendi sayfasında kontrol edin.',
'EC' => 'Ekvador kitlesi için Quito ile Bogotá seçeneklerini karşılaştırın. Mağaza, API veya geliştirme ortamında kullanıcı ağındaki yanıt süresi ve veritabanı rotası ülke adından daha önemlidir. İkinci konum bir ağ karşılaştırma adayıdır; kapasite ve fiyatını kendi sayfasında kontrol edin.',
'EG' => 'Mısır kitlesi için Kahire ile Avrupa şehirleri seçeneklerini karşılaştırın. Mağaza, API veya geliştirme ortamında kullanıcı ağındaki yanıt süresi ve veritabanı rotası ülke adından daha önemlidir. İkinci konum bir ağ karşılaştırma adayıdır; kapasite ve fiyatını kendi sayfasında kontrol edin.',
'GR' => 'Yunanistan kitlesi için Atina ile Sofya seçeneklerini karşılaştırın. Mağaza, API veya geliştirme ortamında kullanıcı ağındaki yanıt süresi ve veritabanı rotası ülke adından daha önemlidir. İkinci konum bir ağ karşılaştırma adayıdır; kapasite ve fiyatını kendi sayfasında kontrol edin.',
'ID' => 'Endonezya kitlesi için Cakarta ile Singapur seçeneklerini karşılaştırın. Mağaza, API veya geliştirme ortamında kullanıcı ağındaki yanıt süresi ve veritabanı rotası ülke adından daha önemlidir. İkinci konum bir ağ karşılaştırma adayıdır; kapasite ve fiyatını kendi sayfasında kontrol edin.',
'JP' => 'Japonya kitlesi için Tokyo ile Seul seçeneklerini karşılaştırın. Mağaza, API veya geliştirme ortamında kullanıcı ağındaki yanıt süresi ve veritabanı rotası ülke adından daha önemlidir. İkinci konum bir ağ karşılaştırma adayıdır; kapasite ve fiyatını kendi sayfasında kontrol edin.',
'KH' => 'Kamboçya kitlesi için Phnom Penh ile Bangkok seçeneklerini karşılaştırın. Mağaza, API veya geliştirme ortamında kullanıcı ağındaki yanıt süresi ve veritabanı rotası ülke adından daha önemlidir. İkinci konum bir ağ karşılaştırma adayıdır; kapasite ve fiyatını kendi sayfasında kontrol edin.',
'KR' => 'Güney Kore kitlesi için Seul ile Tokyo seçeneklerini karşılaştırın. Mağaza, API veya geliştirme ortamında kullanıcı ağındaki yanıt süresi ve veritabanı rotası ülke adından daha önemlidir. İkinci konum bir ağ karşılaştırma adayıdır; kapasite ve fiyatını kendi sayfasında kontrol edin.',
'KW' => 'Kuveyt kitlesi için Kuveyt ile Bahreyn seçeneklerini karşılaştırın. Mağaza, API veya geliştirme ortamında kullanıcı ağındaki yanıt süresi ve veritabanı rotası ülke adından daha önemlidir. İkinci konum bir ağ karşılaştırma adayıdır; kapasite ve fiyatını kendi sayfasında kontrol edin.',
'MM' => 'Myanmar kitlesi için Yangon ile Bangkok seçeneklerini karşılaştırın. Mağaza, API veya geliştirme ortamında kullanıcı ağındaki yanıt süresi ve veritabanı rotası ülke adından daha önemlidir. İkinci konum bir ağ karşılaştırma adayıdır; kapasite ve fiyatını kendi sayfasında kontrol edin.',
'MX' => 'Meksika kitlesi için Mexico City ile ABD şehirleri seçeneklerini karşılaştırın. Mağaza, API veya geliştirme ortamında kullanıcı ağındaki yanıt süresi ve veritabanı rotası ülke adından daha önemlidir. İkinci konum bir ağ karşılaştırma adayıdır; kapasite ve fiyatını kendi sayfasında kontrol edin.',
'MY' => 'Malezya kitlesi için Kuala Lumpur ile Singapur seçeneklerini karşılaştırın. Mağaza, API veya geliştirme ortamında kullanıcı ağındaki yanıt süresi ve veritabanı rotası ülke adından daha önemlidir. İkinci konum bir ağ karşılaştırma adayıdır; kapasite ve fiyatını kendi sayfasında kontrol edin.',
'NP' => 'Nepal kitlesi için Katmandu ile Singapur seçeneklerini karşılaştırın. Mağaza, API veya geliştirme ortamında kullanıcı ağındaki yanıt süresi ve veritabanı rotası ülke adından daha önemlidir. İkinci konum bir ağ karşılaştırma adayıdır; kapasite ve fiyatını kendi sayfasında kontrol edin.',
'QA' => 'Katar kitlesi için Doha ile Dubai seçeneklerini karşılaştırın. Mağaza, API veya geliştirme ortamında kullanıcı ağındaki yanıt süresi ve veritabanı rotası ülke adından daha önemlidir. İkinci konum bir ağ karşılaştırma adayıdır; kapasite ve fiyatını kendi sayfasında kontrol edin.',
'RO' => 'Romanya kitlesi için Bükreş ile Sofya seçeneklerini karşılaştırın. Mağaza, API veya geliştirme ortamında kullanıcı ağındaki yanıt süresi ve veritabanı rotası ülke adından daha önemlidir. İkinci konum bir ağ karşılaştırma adayıdır; kapasite ve fiyatını kendi sayfasında kontrol edin.',
'RU' => 'Rusya kitlesi için Moskova ile Helsinki seçeneklerini karşılaştırın. Mağaza, API veya geliştirme ortamında kullanıcı ağındaki yanıt süresi ve veritabanı rotası ülke adından daha önemlidir. İkinci konum bir ağ karşılaştırma adayıdır; kapasite ve fiyatını kendi sayfasında kontrol edin.',
'TH' => 'Tayland kitlesi için Bangkok ile Singapur seçeneklerini karşılaştırın. Mağaza, API veya geliştirme ortamında kullanıcı ağındaki yanıt süresi ve veritabanı rotası ülke adından daha önemlidir. İkinci konum bir ağ karşılaştırma adayıdır; kapasite ve fiyatını kendi sayfasında kontrol edin.',
'TW' => 'Tayvan kitlesi için Taipei ile Tokyo seçeneklerini karşılaştırın. Mağaza, API veya geliştirme ortamında kullanıcı ağındaki yanıt süresi ve veritabanı rotası ülke adından daha önemlidir. İkinci konum bir ağ karşılaştırma adayıdır; kapasite ve fiyatını kendi sayfasında kontrol edin.',
'IR' => 'İran kitlesi için mevcut İran şehirleri ile İstanbul seçeneklerini karşılaştırın. Mağaza, API veya geliştirme ortamında kullanıcı ağındaki yanıt süresi ve veritabanı rotası ülke adından daha önemlidir. İkinci konum bir ağ karşılaştırma adayıdır; kapasite ve fiyatını kendi sayfasında kontrol edin.',
'NL' => 'Hollanda kitlesi için mevcut Hollanda şehirleri ile Frankfurt seçeneklerini karşılaştırın. Mağaza, API veya geliştirme ortamında kullanıcı ağındaki yanıt süresi ve veritabanı rotası ülke adından daha önemlidir. İkinci konum bir ağ karşılaştırma adayıdır; kapasite ve fiyatını kendi sayfasında kontrol edin.',
'SE' => 'İsveç kitlesi için mevcut İsveç şehirleri ile Helsinki seçeneklerini karşılaştırın. Mağaza, API veya geliştirme ortamında kullanıcı ağındaki yanıt süresi ve veritabanı rotası ülke adından daha önemlidir. İkinci konum bir ağ karşılaştırma adayıdır; kapasite ve fiyatını kendi sayfasında kontrol edin.',
'AT' => 'Avusturya kitlesi için mevcut Avusturya şehirleri ile Frankfurt seçeneklerini karşılaştırın. Mağaza, API veya geliştirme ortamında kullanıcı ağındaki yanıt süresi ve veritabanı rotası ülke adından daha önemlidir. İkinci konum bir ağ karşılaştırma adayıdır; kapasite ve fiyatını kendi sayfasında kontrol edin.'
]
];
