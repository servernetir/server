<?php

/**
 * محتوای مرجعِ APIِ تونلِ سرورِ اکسیت — سه‌زبانه.
 *
 * ساختار:  key => [ locale => string|array ]
 *
 * ═══ 🔴 چرا این فایل جا مانده بود و چه چیزی شکست ═══
 *
 * کامیتِ `1c41ab4` ویوِ `pages/developers-tunnel.blade.php` را اضافه کرد، ولی
 * فایلی که خودش `require` می‌کند — همین فایل — هرگز کامیت نشد. نتیجه‌اش
 * `/developers/tunnel` و `/en/…` و `/tr/…` هر سه با **۵۰۰** بود:
 *
 *     require(resources/content/developers-tunnel.php): Failed to open stream
 *
 * ⚠️ درسش: `require` روی فایلی بیرونِ ویو، یک وابستگیِ نامرئی برای گیت است.
 * هیچ تستِ واحدی و هیچ `php -l`ی نمی‌گیردش؛ فقط بازکردنِ خودِ صفحه.
 * `ContentPagesLinkSweepTest` دقیقاً برای همین هست — هر صفحهٔ محتوایی را در
 * هر سه زبان باز می‌کند.
 *
 * ═══ لحن ═══
 *
 * مرجعِ یکپارچه‌سازی است، نه صفحهٔ بازاریابی. «باید» الزامِ قراردادی است.
 *
 * ⚠️ **هیچ عددی این‌جا نیست.** سقفِ اکانت از `TunnelProfile::MAX_PEERS`،
 * فاصلهٔ پیمایش از `TunnelAgentScript::INTERVAL` و سقف‌های نرخ در خودِ Blade
 * خوانده می‌شوند. عددِ دستی، اولین باری که تنظیمات عوض شود دروغ می‌گوید.
 *
 * ⚠️ کدهای خطا از `TunnelApiController` برداشته شده‌اند، نه از حدس:
 * `not_found` · `service_not_active` · `bad_name` · `name_taken` · `bad_ip` ·
 * `ip_taken` · `limit_reached`. اگر کنترلر کدِ تازه‌ای اضافه کرد، این جدول هم
 * باید به‌روز شود — وگرنه مستندات دربارهٔ چیزی حرف می‌زند که وجود ندارد.
 */

return [

    // ── سربرگ ───────────────────────────────────────────────────────────────
    'title' => [
        'fa' => 'مرجع API تونل سرور اکسیت',
        'en' => 'Exit Server Tunnel API reference',
        'tr' => 'Çıkış Sunucusu Tünel API referansı',
    ],
    'badge' => [
        'fa' => 'مستندات توسعه‌دهنده',
        'en' => 'Developer documentation',
        'tr' => 'Geliştirici dokümantasyonu',
    ],
    'lead' => [
        'fa' => 'ساخت و حذف کاربر WireGuard روی سرور اکسیت، از داخل کد خودتان. '
              . 'اگر ایجنت روتر را فعال کرده باشید، تغییر خودکار روی میکروتیک اعمال می‌شود؛ '
              . 'در غیر این صورت همان دستور روتر برایتان برگردانده می‌شود تا دستی اجرا کنید.',
        'en' => 'Create and delete WireGuard users on your exit server from your own code. '
              . 'With the router agent enabled the change is applied on the router automatically; '
              . 'otherwise the router command is returned for you to run yourself.',
        'tr' => 'Çıkış sunucunuzdaki WireGuard kullanıcılarını kendi kodunuzdan oluşturun ve silin. '
              . 'Router ajanı etkinse değişiklik router üzerinde otomatik uygulanır; '
              . 'aksi hâlde ilgili router komutu kendiniz çalıştırmanız için döndürülür.',
    ],
    'meta_desc' => [
        'fa' => 'مرجع API تونل سرورنت: فهرست سرورها، ساخت و حذف کاربر WireGuard، '
              . 'ایجنت خودکار روتر، وضعیت‌ها، کدهای خطا و سقف نرخ.',
        'en' => 'ServerNet tunnel API reference: list servers, create and delete WireGuard users, '
              . 'the automatic router agent, states, error codes and rate limits.',
        'tr' => 'ServerNet tünel API referansı: sunucu listesi, WireGuard kullanıcısı oluşturma ve silme, '
              . 'otomatik router ajanı, durumlar, hata kodları ve hız sınırları.',
    ],
    'toc_title' => [
        'fa' => 'در این صفحه',
        'en' => 'On this page',
        'tr' => 'Bu sayfada',
    ],
    'back' => [
        'fa' => 'بازگشت به مرجع API',
        'en' => 'Back to the API reference',
        'tr' => 'API referansına dön',
    ],
    'print' => [
        'fa' => 'نسخه چاپی',
        'en' => 'Printable version',
        'tr' => 'Yazdırılabilir sürüm',
    ],
    'print_hint' => [
        'fa' => 'باز شدن در تب تازه، بدون منو و بدون رنگ پس‌زمینه',
        'en' => 'Opens in a new tab, without navigation or background colour',
        'tr' => 'Yeni sekmede, menü ve arka plan rengi olmadan açılır',
    ],

    // ── ۱ ───────────────────────────────────────────────────────────────────
    's1' => [
        'fa' => 'شروع و احراز هویت',
        'en' => 'Getting started and authentication',
        'tr' => 'Başlangıç ve kimlik doğrulama',
    ],
    's1_p' => [
        'fa' => 'همه درخواست‌ها با توکن Bearer احراز می‌شوند؛ نشستی در کار نیست. '
              . 'توکن را در پنل کاربری، صفحه امنیت می‌سازید و دسترسی‌هایش را همان‌جا انتخاب می‌کنید. '
              . 'توکن فقط یک بار نمایش داده می‌شود.',
        'en' => 'Every request is authenticated with a Bearer token; there is no session. '
              . 'You create the token on the Security page of your account panel and pick its abilities there. '
              . 'The token is shown once and never again.',
        'tr' => 'Her istek Bearer token ile doğrulanır; oturum kullanılmaz. '
              . 'Token’ı hesap panelinizin Güvenlik sayfasında oluşturur ve yetkilerini orada seçersiniz. '
              . 'Token yalnızca bir kez gösterilir.',
    ],
    's1_steps' => [
        'fa' => [
            'در پنل کاربری وارد شوید و به صفحه امنیت بروید.',
            'توکن تازه بسازید و دسترسی tunnel:read و در صورت نیاز tunnel:write را تیک بزنید.',
            'توکن را همان لحظه ذخیره کنید — دیگر نشان داده نمی‌شود.',
            'اگر سرور شما IP ثابت دارد، محدوده مجاز IP را روی همان توکن تنظیم کنید.',
            'با هدر Authorization: Bearer … درخواست بزنید.',
        ],
        'en' => [
            'Sign in to your account panel and open the Security page.',
            'Create a new token and tick tunnel:read, plus tunnel:write if you need to make changes.',
            'Store the token immediately — it is never shown again.',
            'If your server has a fixed IP, set the allowed IP range on that token.',
            'Send requests with the Authorization: Bearer … header.',
        ],
        'tr' => [
            'Hesap panelinize giriş yapın ve Güvenlik sayfasını açın.',
            'Yeni bir token oluşturun; tunnel:read ve değişiklik yapacaksanız tunnel:write yetkilerini işaretleyin.',
            'Token’ı hemen kaydedin — bir daha gösterilmez.',
            'Sunucunuzun sabit IP’si varsa, izin verilen IP aralığını o token üzerinde tanımlayın.',
            'İstekleri Authorization: Bearer … başlığı ile gönderin.',
        ],
    ],
    's1_warn' => [
        'fa' => 'محدوده IP روی خود توکن تنظیم می‌شود، نه روی حساب. قواعد IP حساب '
              . 'کل حساب را قفل می‌کند و شما را از مرورگر خودتان هم بیرون می‌اندازد.',
        'en' => 'The IP allow-list lives on the token itself, not on the account. Account-level IP rules '
              . 'lock the whole account and would also lock you out of your own browser.',
        'tr' => 'IP izin listesi hesapta değil, token’ın kendisinde tutulur. Hesap düzeyindeki IP kuralları '
              . 'tüm hesabı kilitler ve sizi kendi tarayıcınızdan da çıkarır.',
    ],

    // ── ۲ ───────────────────────────────────────────────────────────────────
    's2' => [
        'fa' => 'فهرست سرورهای تونل',
        'en' => 'Listing tunnel servers',
        'tr' => 'Tünel sunucularını listeleme',
    ],
    's2_p' => [
        'fa' => 'فقط سرورهای اکسیتی برمی‌گردند که تونل TCP دارند. شناسه سرویس همان چیزی است '
              . 'که در بقیه مسیرها به جای {service} می‌گذارید.',
        'en' => 'Only exit servers that have a TCP tunnel are returned. The service id is what you put '
              . 'in place of {service} in every other path.',
        'tr' => 'Yalnızca TCP tüneli olan çıkış sunucuları döner. Servis kimliği, diğer tüm yollarda '
              . '{service} yerine yazacağınız değerdir.',
    ],
    's2_fields' => [
        'fa' => [
            'writable'          => 'آیا همین حالا می‌شود کاربر ساخت یا حذف کرد. سرویس معلق یا لغوشده false می‌دهد.',
            'next_ip'           => 'IP بعدی که به کاربر تازه داده می‌شود. تضمینی نیست — تا لحظه ساخت ممکن است عوض شود.',
            'accounts / max'    => 'تعداد کاربران فعلی و سقف مجاز.',
            'agent.installed'   => 'ایجنت روی روتر نصب شده یا نه.',
            'agent.alive'       => 'ایجنت اخیراً پیمایش کرده. false یعنی روتر خاموش یا بی‌اینترنت است.',
            'agent.pending_jobs'=> 'کارهایی که هنوز روی روتر اعمال نشده‌اند.',
        ],
        'en' => [
            'writable'          => 'Whether users can be created or deleted right now. A suspended or cancelled service returns false.',
            'next_ip'           => 'The IP the next user would get. Not a reservation — it can change before you create.',
            'accounts / max'    => 'Current user count and the allowed ceiling.',
            'agent.installed'   => 'Whether the agent is installed on the router.',
            'agent.alive'       => 'The agent has polled recently. false means the router is off or has no internet.',
            'agent.pending_jobs'=> 'Jobs not yet applied on the router.',
        ],
        'tr' => [
            'writable'          => 'Şu anda kullanıcı oluşturulup silinebilir mi. Askıya alınmış veya iptal edilmiş serviste false döner.',
            'next_ip'           => 'Sonraki kullanıcının alacağı IP. Rezervasyon değildir — oluşturmadan önce değişebilir.',
            'accounts / max'    => 'Mevcut kullanıcı sayısı ve izin verilen üst sınır.',
            'agent.installed'   => 'Ajan router üzerinde kurulu mu.',
            'agent.alive'       => 'Ajan yakın zamanda yoklama yaptı. false ise router kapalı veya internetsiz.',
            'agent.pending_jobs'=> 'Router üzerinde henüz uygulanmamış işler.',
        ],
    ],
    's2_warn' => [
        'fa' => 'به next_ip به چشم رزرو نگاه نکنید. اگر دو ساخت هم‌زمان بفرستید، دومی '
              . 'IP بعدی را می‌گیرد؛ IP واقعی همیشه در پاسخ خود ساخت است.',
        'en' => 'Do not treat next_ip as a reservation. Two concurrent creates get consecutive IPs; '
              . 'the real address is always the one in the create response.',
        'tr' => 'next_ip’i rezervasyon olarak görmeyin. Eş zamanlı iki oluşturma ardışık IP alır; '
              . 'gerçek adres her zaman oluşturma yanıtındaki adrestir.',
    ],

    // ── ۳ ───────────────────────────────────────────────────────────────────
    's3' => [
        'fa' => 'ایجنت روتر — اعمال خودکار',
        'en' => 'The router agent — automatic apply',
        'tr' => 'Router ajanı — otomatik uygulama',
    ],
    's3_p' => [
        'fa' => 'بدون ایجنت، API فقط دستور روتر را برمی‌گرداند و اجرایش با شماست. '
              . 'با ایجنت، هر ساخت و حذف در صف می‌نشیند و روتر خودش در پیمایش بعدی برش می‌دارد.',
        'en' => 'Without the agent the API only returns the router command and running it is up to you. '
              . 'With the agent, every create and delete is queued and the router picks it up on its next poll.',
        'tr' => 'Ajan olmadan API yalnızca router komutunu döndürür ve çalıştırmak size kalır. '
              . 'Ajan varsa her oluşturma ve silme kuyruğa alınır, router bir sonraki yoklamada uygular.',
    ],
    's3_steps' => [
        'fa' => [
            'با tunnel:write یک POST به مسیر agent بزنید.',
            'توکن ایجنت را بردارید — فقط همین یک بار برمی‌گردد.',
            'دو خط install را در ترمینال میکروتیک اجرا کنید.',
            'با GET همان مسیر، alive شدن ایجنت را ببینید.',
        ],
        'en' => [
            'Send a POST to the agent path with tunnel:write.',
            'Take the agent token — it is returned only this once.',
            'Run the two install lines in the MikroTik terminal.',
            'GET the same path to watch the agent come alive.',
        ],
        'tr' => [
            'tunnel:write ile agent yoluna bir POST gönderin.',
            'Ajan token’ını alın — yalnızca bu bir kez döner.',
            'İki install satırını MikroTik terminalinde çalıştırın.',
            'Aynı yola GET atarak ajanın canlandığını görün.',
        ],
    ],
    's3_warn' => [
        'fa' => 'POST دوباره روی همین مسیر، توکن قبلی را باطل می‌کند و replaced را true برمی‌گرداند. '
              . 'اگر اسکریپت نصب را دوباره اجرا نکنید، روتر با توکن باطل پیمایش می‌کند و بی‌صدا از کار می‌افتد.',
        'en' => 'Posting again to this path revokes the previous token and returns replaced as true. '
              . 'If you do not re-run the install script, the router keeps polling with a dead token and silently stops working.',
        'tr' => 'Bu yola tekrar POST atmak önceki token’ı iptal eder ve replaced değerini true döndürür. '
              . 'Kurulum betiğini yeniden çalıştırmazsanız router geçersiz token ile yoklama yapar ve sessizce çalışmaz olur.',
    ],
    's3_alive' => [
        'fa' => 'وضعیت ایجنت را هر وقت خواستید بخوانید:',
        'en' => 'Read the agent state whenever you need it:',
        'tr' => 'Ajan durumunu istediğiniz zaman okuyun:',
    ],
    's3_safe' => [
        'fa' => 'ایجنت فقط کارهای همین سرویس را برمی‌دارد و توکنش هیچ دسترسی دیگری به حساب شما ندارد.',
        'en' => 'The agent only picks up jobs for this one service, and its token grants no other access to your account.',
        'tr' => 'Ajan yalnızca bu servise ait işleri alır ve token’ı hesabınıza başka hiçbir erişim vermez.',
    ],

    // ── ۴ ───────────────────────────────────────────────────────────────────
    's4' => [
        'fa' => 'ساخت کاربر',
        'en' => 'Creating a user',
        'tr' => 'Kullanıcı oluşturma',
    ],
    's4_in' => [
        'fa' => 'فیلدهای ورودی',
        'en' => 'Request fields',
        'tr' => 'İstek alanları',
    ],
    's4_fields' => [
        'fa' => [
            'name'   => 'نام کاربر. حروف انگلیسی، رقم، خط تیره و زیرخط. در هر سرویس یکتاست.',
            'ip'     => 'اختیاری. اگر ندهید، اولین IP آزاد از زیرشبکه داده می‌شود.',
            'format' => 'اختیاری. singbox پیش‌فرض است؛ legacy پیکربندی قدیمی را می‌دهد.',
        ],
        'en' => [
            'name'   => 'User name. Latin letters, digits, dash and underscore. Unique within the service.',
            'ip'     => 'Optional. If omitted, the first free address in the subnet is assigned.',
            'format' => 'Optional. singbox is the default; legacy returns the older configuration.',
        ],
        'tr' => [
            'name'   => 'Kullanıcı adı. Latin harf, rakam, tire ve alt çizgi. Servis içinde benzersizdir.',
            'ip'     => 'İsteğe bağlı. Verilmezse alt ağdaki ilk boş adres atanır.',
            'format' => 'İsteğe bağlı. Varsayılan singbox; legacy eski yapılandırmayı döndürür.',
        ],
    ],
    's4_key' => [
        'fa' => 'کلید خصوصی فقط در همین یک پاسخ برمی‌گردد و هیچ‌جا ذخیره نمی‌شود. '
              . 'اگر گمش کنید، تنها راه حذف کاربر و ساخت دوباره است.',
        'en' => 'The private key is returned in this one response only and is stored nowhere. '
              . 'If you lose it, the only way back is to delete the user and create it again.',
        'tr' => 'Özel anahtar yalnızca bu tek yanıtta döner ve hiçbir yerde saklanmaz. '
              . 'Kaybederseniz tek yol kullanıcıyı silip yeniden oluşturmaktır.',
    ],
    's4_cfg' => [
        'fa' => 'فیلد config یک پیکربندی آماده sing-box است که می‌توانید مستقیم به کاربر نهایی بدهید.',
        'en' => 'The config field is a ready sing-box configuration you can hand straight to the end user.',
        'tr' => 'config alanı, son kullanıcıya doğrudan verebileceğiniz hazır bir sing-box yapılandırmasıdır.',
    ],
    's4_delivery' => [
        'fa' => 'فیلد delivery می‌گوید تغییر چطور به روتر می‌رسد: agent یعنی در صف نشسته '
              . 'و خودکار اعمال می‌شود، manual یعنی باید router_command را خودتان اجرا کنید.',
        'en' => 'The delivery field says how the change reaches the router: agent means it is queued '
              . 'and applied automatically, manual means you must run router_command yourself.',
        'tr' => 'delivery alanı değişikliğin router’a nasıl ulaştığını söyler: agent kuyruğa alınıp '
              . 'otomatik uygulandığı, manual ise router_command’i kendinizin çalıştırması gerektiği anlamına gelir.',
    ],
    's4_201' => [
        'fa' => 'ساخت با delivery.status برابر pending موفق است، نه ناتمام. کاربر روی سرور ساخته شده '
              . 'و فقط اعمالش روی روتر در صف است. برای دیدن نتیجه، وضعیت همان کاربر را بخوانید.',
        'en' => 'A create with delivery.status of pending is a success, not a half-failure. The user exists on the server; '
              . 'only the router-side apply is queued. Read that user’s state to see the outcome.',
        'tr' => 'delivery.status değeri pending olan bir oluşturma başarılıdır, yarım kalmış değildir. Kullanıcı sunucuda vardır; '
              . 'yalnızca router tarafındaki uygulama kuyruktadır. Sonucu görmek için o kullanıcının durumunu okuyun.',
    ],

    // ── ۵ ───────────────────────────────────────────────────────────────────
    's5' => [
        'fa' => 'فهرست کاربران و وضعیتشان',
        'en' => 'Listing users and their state',
        'tr' => 'Kullanıcıları ve durumlarını listeleme',
    ],
    's5_p' => [
        'fa' => 'کلید عمومی هر کاربر برمی‌گردد، ولی کلید خصوصی هرگز. وضعیت هر ردیف از صف ایجنت می‌آید.',
        'en' => 'Each user’s public key is returned, but the private key never is. Each row’s state comes from the agent queue.',
        'tr' => 'Her kullanıcının açık anahtarı döner, özel anahtarı asla dönmez. Satır durumu ajan kuyruğundan gelir.',
    ],
    's5_states' => [
        'fa' => [
            'active'  => 'روی سرور و روی روتر هست.',
            'pending' => 'ساخته شده، ولی روتر هنوز برش نداشته.',
            'failed'  => 'روتر کار را رد کرد — لاگ روتر را ببینید.',
        ],
        'en' => [
            'active'  => 'Present on the server and on the router.',
            'pending' => 'Created, but the router has not picked it up yet.',
            'failed'  => 'The router rejected the job — check the router log.',
        ],
        'tr' => [
            'active'  => 'Sunucuda ve router üzerinde mevcut.',
            'pending' => 'Oluşturuldu, ancak router henüz almadı.',
            'failed'  => 'Router işi reddetti — router günlüğüne bakın.',
        ],
    ],
    's5_legacy' => [
        'fa' => 'کاربری که پیش از نصب ایجنت ساخته شده active است، چون دستورش دستی اجرا شده بوده.',
        'en' => 'A user created before the agent was installed shows as active, because its command was run by hand.',
        'tr' => 'Ajan kurulmadan önce oluşturulan kullanıcı active görünür, çünkü komutu elle çalıştırılmıştır.',
    ],

    // ── ۶ ───────────────────────────────────────────────────────────────────
    's6' => [
        'fa' => 'حذف کاربر',
        'en' => 'Deleting a user',
        'tr' => 'Kullanıcı silme',
    ],
    's6_p' => [
        'fa' => 'حذف برگشت‌ناپذیر است. کلید خصوصی آن کاربر جایی ذخیره نشده، پس همان کاربر را '
              . 'دوباره نمی‌شود ساخت — فقط یک کاربر تازه با کلید تازه.',
        'en' => 'Deletion is irreversible. That user’s private key was stored nowhere, so the same user cannot be '
              . 'recreated — only a new user with a new key.',
        'tr' => 'Silme geri alınamaz. O kullanıcının özel anahtarı hiçbir yerde saklanmadığı için aynı kullanıcı '
              . 'yeniden oluşturulamaz — yalnızca yeni anahtarlı yeni bir kullanıcı.',
    ],

    // ── ۷ ───────────────────────────────────────────────────────────────────
    's7' => [
        'fa' => 'خطاها و سقف نرخ',
        'en' => 'Errors and rate limits',
        'tr' => 'Hatalar ve hız sınırları',
    ],
    's7_p' => [
        'fa' => 'خطا همیشه با ok برابر false و یک کد ماشین‌خوان برمی‌گردد. روی متن پیام شرط نگذارید — '
              . 'متن ممکن است عوض شود، کد نه.',
        'en' => 'An error always comes back with ok false and a machine-readable code. Never branch on the message text — '
              . 'the text may change, the code will not.',
        'tr' => 'Hata her zaman ok değeri false ve makine tarafından okunabilir bir kod ile döner. Mesaj metnine göre dallanmayın — '
              . 'metin değişebilir, kod değişmez.',
    ],
    's7_errors' => [
        'fa' => [
            'not_found'          => 'سرویس مال شما نیست، یا اصلاً تونل ندارد.',
            'service_not_active' => 'سرویس معلق، لغوشده، منقضی یا هنوز تحویل‌نشده است.',
            'bad_name'           => 'نام کاربر قالب مجاز را ندارد.',
            'name_taken'         => 'کاربری با همین نام در این سرویس هست.',
            'bad_ip'             => 'IP خواسته‌شده بیرون زیرشبکه این تونل است.',
            'ip_taken'           => 'آن IP به کاربر دیگری داده شده.',
            'limit_reached'      => 'به سقف تعداد کاربر این سرویس رسیده‌اید.',
        ],
        'en' => [
            'not_found'          => 'The service is not yours, or it has no tunnel at all.',
            'service_not_active' => 'The service is suspended, cancelled, expired or not yet delivered.',
            'bad_name'           => 'The user name does not match the allowed format.',
            'name_taken'         => 'A user with that name already exists on this service.',
            'bad_ip'             => 'The requested IP is outside this tunnel’s subnet.',
            'ip_taken'           => 'That IP is already assigned to another user.',
            'limit_reached'      => 'You have reached this service’s user ceiling.',
        ],
        'tr' => [
            'not_found'          => 'Servis size ait değil veya hiç tüneli yok.',
            'service_not_active' => 'Servis askıda, iptal edilmiş, süresi dolmuş veya henüz teslim edilmemiş.',
            'bad_name'           => 'Kullanıcı adı izin verilen biçime uymuyor.',
            'name_taken'         => 'Bu serviste aynı adda bir kullanıcı zaten var.',
            'bad_ip'             => 'İstenen IP bu tünelin alt ağının dışında.',
            'ip_taken'           => 'Bu IP başka bir kullanıcıya atanmış.',
            'limit_reached'      => 'Bu servisin kullanıcı üst sınırına ulaştınız.',
        ],
    ],
    's7_limits' => [
        'fa' => 'سقف نرخ',
        'en' => 'Rate limits',
        'tr' => 'Hız sınırları',
    ],
    's7_rows' => [
        'fa' => [
            'read'  => 'درخواست‌های خواندنی',
            'write' => 'ساخت و حذف کاربر',
            'agent' => 'ساخت توکن ایجنت',
            'peers' => 'سقف کاربر در هر سرویس',
            'min'   => 'دقیقه',
        ],
        'en' => [
            'read'  => 'Read requests',
            'write' => 'Creating and deleting users',
            'agent' => 'Issuing an agent token',
            'peers' => 'User ceiling per service',
            'min'   => 'min',
        ],
        'tr' => [
            'read'  => 'Okuma istekleri',
            'write' => 'Kullanıcı oluşturma ve silme',
            'agent' => 'Ajan token’ı oluşturma',
            'peers' => 'Servis başına kullanıcı sınırı',
            'min'   => 'dk',
        ],
    ],
];
