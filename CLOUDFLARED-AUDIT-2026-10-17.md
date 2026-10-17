# بررسی قطعی Cloudflare Tunnel روی Proxmox — ۱۷ اکتبر ۲۰۲۶

## ثبت در Git با درخواست مالک — ۱۷ اکتبر ۲۰۲۶

این تغییر از `origin/develop` تازه با مبنای
`05aa2d8ec525c45abe65238f20d9055c59479f3a` در checkout ایزوله آماده شد.
فقط کد/واحدها/پیکربندی بازیابی Cloudflare، inventory لازم و مستندات مرتبط
منتقل شدند؛ تغییرات وب و سایر اصلاح‌های شبکهٔ پوشهٔ کاری وارد این کامیت نشدند.
inventory با SSH احرازشدهٔ قبلی `85.9.108.114:2222` تطبیق داده شد.

آزمون‌های این مرحله بدون اتصال به تولید: چهار تست fixture مربوط به
idempotency در دو شکل JSON، rollback محدود و برخورد priority؛ هر چهار موفق.
پنج فایل YAML، رندر واقعی سه config از Jinja، syntax سه فایل Python و ارجاع
handlerهای role نیز تأیید شدند. `git diff --check` موفق بود. اجرای کامل Ansible
و reboot در این مرحله انجام نشد. این نوبت فقط ثبت/انتشار Git است؛ پذیرش زندهٔ
قبلی و محدودیت‌های oyaz/adak در بخش بعد مستندند و deploy دوباره انجام نمی‌شود.

## نتیجهٔ نهایی بازیابی — 08:55 UTC / ۱۲:۲۵ تهران

پس از درخواست صریح مالک برای برقراری همهٔ تونل‌ها طبق تنظیمات خود Cloudflare،
هر سه تونل حساب از حالت `down` به `healthy` رسیدند. استعلام نهایی API در
`2026-10-17T08:55:07Z` برای هرکدام چهار اتصال فعال نشان داد:

| تونل | سرویس ماندگار روی میزبان | وضعیت | محدودیت کاربردی |
|---|---|---|---|
| pve-tunnel | cloudflared.service | healthy، چهار اتصال | originهای admin/proxy/vpn هر سه HTTP200؛ آزمون کاربردی پس از احراز هویت Cloudflare Access انجام نشده |
| oyaz | cloudflared@oyaz.service | healthy، چهار اتصال | مقصد موجود 192.168.129.10:81 از Proxmox timeout است؛ دسترسی شبکهٔ سایت مقصد هنوز لازم است |
| adak | cloudflared@adak.service | healthy، چهار اتصال | config ابری null/version0 و بدون ingress است؛ سرویس مقصدی اضافه نشده |

هر سه واحد `active/running`، `enabled` و `NRestarts=0` بودند. `/ready`
روی metricsهای فقط loopback به‌ترتیب 20241/20243/20244 برای هر سه HTTP200
داد. canaryهای موقت خاتمه یافتند؛ اتصال‌های نهایی متعلق به واحدهای ماندگارند.
سه config ابری پیش و پس از عملیات دقیقاً یکسان‌اند: pve نسخه30، oyaz نسخه10
و adak نسخه0. DNS، Access، routeهای عمومی نام‌ها و سرویس‌های origin تغییر نکردند.

### اصلاح تشخیص اولیه با موجودی واقعی حساب

پس از آماده‌سازی binary رسمی، گواهی موجود اجازهٔ فهرست خواندنی حساب را داد.
تونلی به نام `n8n-tunnel` در حساب وجود ندارد و فایل اعتبار باقی‌مانده در واقع
متعلق به `pve-tunnel` است. هر سه تونل `config_src=cloudflare` دارند؛ بنابراین
config قدیمی flow/storage فعال نشد و هیچ تونل یا DNS تازه‌ای ساخته نشد.
متن بخش اولیهٔ پایین فقط وضعیت پیش از بازیابی را شرح می‌دهد.

### برنامه، اعتبار و پیکربندی

- بستهٔ رسمی amd64 نسخه `2026.10.1` از release خود Cloudflare دریافت و پیش از
  نصب با SHA256 منتشرشده در metadata رسمی GitHub تطبیق شد:
  `200573c872174ab449965655febadef8edfbb144ebcaa391b700b2dedbadb3cd`.
  نصب `dpkg` فقط همین بسته بود؛ helper قدیمی مهاجرت/deployment اجرا نشد.
- tokenهای **موجود** سه تونل از Cloudflare دریافت و فقط روی سرور، در
  `/etc/cloudflared/{pve-tunnel,oyaz,adak}.token`، با owner=root/mode0600
  ذخیره شدند. پوشه mode0700 است؛ token در argv یا مستندات نیست و rotate نشد.
- فایل‌های `config.yml`، `oyaz.yml` و `adak.yml` فقط transport و metrics
  را تعیین می‌کنند؛ ingress از Cloudflare دریافت می‌شود. HTTP/2، IPv4 و
  `edge-bind-address: 10.10.10.1` تنظیم شدند. اصل `/root/.cloudflared` محفوظ است.
- drop-in اصلی از `--token-file` استفاده می‌کند؛ برای دو تونل دیگر template
  `cloudflared@.service` نصب شد. واحدهای update قبلی دست‌نخورده و timer آن
  همچنان disabled است. خودکارسازی ارتقای برنامه فعال نشد.

### مانع دوم: مسیر مستقیم تا Cloudflare

پس از نصب و تأمین credential صحیح، اتصال مستقیم HTTP/2 با وجود TCP/TLS
برقرار، در مرحلهٔ ثبت connector پیش نرفت و `/ready` برابر503 ماند. QUIC نیز
در pre-check موفق نشد. canary نسخه رسمی `2026.10.0` با hash معتبر هم روی
همان مسیر مستقیم ثبت نشد؛ downgrade تولید انجام نشد. تعیین عامل دقیق
drop/اختلال در شبکهٔ بالادست انجام نشده است.

همان نسخهٔ `2026.10.1` با خروج محدود از رلهٔ **موجود** آلمان بلافاصله چهار
اتصال HTTP/2 در fra ثبت و config ابری را دریافت کرد. برای همین، مسیر زیر
به‌طور محدود ماندگار شد؛ این تغییر مسیر خروج عمومی host یا مهمان‌ها نیست:

1. تنها source متعلق به میزبان `10.10.10.1/32`، تنها TCP با مقصد پورت7844،
   و تنها دو شبکهٔ edge فعلی `198.41.192.0/24` و `198.41.200.0/24` مشمول‌اند.
2. در host، دو `ip rule` با priority900 به جدول7844 اشاره می‌کنند؛ این جدول
   default از `10.10.10.20 dev vmbr1` با metric10 و blackhole پشتیبان1000 دارد.
3. در CT113، دو rule دقیقاً هم‌دامنه با priority900 از جدول **موجود**119
   استفاده می‌کنند؛ default آن `tun119` و blackhole قبلی است و دست‌نخورده ماند.
4. فقط دو MASQUERADE محدود به همین source/destination/TCP7844/out=tun119،
   با comment `servernet-cloudflared-egress` به NAT کانتینر افزوده شدند.
   قوانین NAT/firewall میزبان هیچ تغییری نکردند؛ هیچ قاعده‌ای حذف نشد.
5. helper `/usr/local/sbin/servernet-cloudflared-egress` و service/timer هم‌نام
   نصب شدند. oneshot بعد از شبکه و `pve-guests.service`، با retry5s در خطا و
   timer `OnUnitInactiveSec=60s`، routeهای مختص تونل را بازتطبیق می‌دهد.

منبع agent کشور بررسی شد: کشف و مدیریت آن به sourceهای دارای fwmark0xc0 و
state فعلی مشتریان محدود است؛ قواعد جدید بدون fwmark، در priority900 و خارج
از chainهای agent/PF هستند. `country-local.routes`، assignments، table50،
مسیرهای109/119 و desired state کنترلر تغییر نکردند. اجرای طبیعی بعدی هر دو
agent با Result=success و تکرار طبیعی helper جدید نیز با exit0 مشاهده شد؛
قواعد محدود حفظ شدند. میزبان/CT113 یا هیچ برنامه و تونل قدیمی دیگری reboot یا
restart نشد؛ فقط واحدهای cloudflared و واحد جدید خودمان مدیریت شدند.

### ماندگاری، منبع نگهداری و اعتبارسنجی

- منبع helper، واحدها، template transport و task جداگانهٔ Cloudflare در role
  `infra/ansible/roles/proxmox_host` ثبت شد. `pve_cloudflared_manage` به‌طور
  پیش‌فرض false و فقط برای pve-node فعلی true است. role پیش از فعال‌سازی
  tokenهای موجود با مجوز خصوصی را لازم می‌داند؛ token در Git قرار نمی‌گیرد.
- صحت YAML پنج فایل تغییرکرده و syntax Python دو اسکریپت تأیید شد؛ هفت فایل
  اجرایی/unit/drop-in با منبع دقیقاً یکسان‌اند. `systemd-analyze verify` موفق
  بود. اجرای کامل Ansible/syntax-check آن انجام نشد؛ ansible روی host نصب نیست.
- diff نرمال‌شدهٔ host iptables خالی، PID/موجودی تمام QEMUها و CTها برابر قبل،
  و diff شبکه فقط همان چهار policy rule، جدول7844 host و دو NAT محدود CT113 بود.
  جدول‌های route کانتینر هیچ diff نداشتند.
- originهای pve از خود میزبان با Host متناظر HTTP200 دادند؛ noTLSVerify دو
  origin خصوصی مطابق تنظیم قبلی Cloudflare حفظ شد. دامنه‌های تونلی عمومی
  بدون نشست احرازشده به Cloudflare Access هدایت می‌شوند؛ HTTP302 صفحهٔ ورود
  به‌تنهایی قبولی انتهابه‌انتهای برنامه محسوب نشد.
- reboot یا قطع عمدی رله برای آزمون failover انجام نشد. این مسیر به CT113،
  tun119 و رلهٔ آلمان وابسته است؛ اگر مقصدهای edge آینده از دو subnet فعلی
  خارج شوند، منبع محدود باید پس از تأیید مقصدهای رسمی به‌روز شود.

### پشتیبان و بازگشت محدود

پیش از تغییر، واحدها، اعتبارهای root و snapshotهای network در پوشهٔ root-only
`/root/servernet-cloudflared-recovery-20261017` حفظ شدند؛ snapshot مستقل CT113
نیز پیش از قواعد egress گرفته شد. بسته و source قابل بررسی در همین پوشه‌اند.
نسخهٔ بازیابی **پس از اصلاح**، شامل config/tokenها، root credentialها، واحدها،
helper و بستهٔ تأییدشده، روی ZFS سالم در
`/vmstore/backup/cloudflared-restored-20261017.tar.gz` با mode0600 ذخیره شد.
SHA256 آن `73424a27d1d296b6d6b56db78ce702964bcecb6592c3e7292324764815387c55` است.
این archive محرمانه است و محتوایش نباید در repo یا گزارش منتشر شود.

برای مهاجرت آینده، این archive خصوصی و فایل‌های سرویس باید کنترل‌شده منتقل
شوند و بستهٔ رسمی نصب گردد؛ کپی symlink `/usr/local/bin/cloudflared` کافی نیست.
وجود `/etc/cloudflared`، tokenها، executable و سرویس‌های egress بخشی از پذیرش
مهاجرت است. helperهای قدیمی آماده‌سازی SSD بدون بازبینی اجرا نشوند.

بازگشت در صورت نیاز: ابتدا سه connector و timer/service اختصاصی egress متوقف
و واحدهای تازه disable شوند؛ سپس helper فقط با `--remove` چهار policy rule،
دو NAT دارای comment و دو default جدول7844 خودمان را برمی‌دارد. فقط drop-in
اصلی از archive `before-config.tar.gz` برگردد و واحد/فایل‌های تازهٔ همین تغییر
کنترل‌شده حذف شوند. restore کامل iptables/routes یا تغییر table119، country/PF
و مهمان‌ها لازم نیست. حذف بستهٔ cloudflared بازگشت به وضعیت **خراب قبل** است
و برای rollback معمول توصیه نمی‌شود. هیچ rollback اجرا نشده است.

مرجع نصب: [Cloudflare Downloads](https://developers.cloudflare.com/tunnel/downloads/)،
[release رسمی 2026.10.1](https://github.com/cloudflare/cloudflared/releases/tag/2026.10.1)
و [راهنمای اتصال/عیب‌یابی رسمی](https://developers.cloudflare.com/tunnel/troubleshooting/).

## نتیجهٔ زنده

بررسی خواندنی از SSH میزبان `ir.servernet.cloud`، حدود 08:29–08:31 UTC
(۱۱:۵۹–۱۲:۰۱ تهران)، دو مانع محلی قطعی را احراز کرد:

- واحد `cloudflared.service` فعال برای boot است، اما در وضعیت
  `activating/auto-restart` با `ExecMainStatus=203` قرار دارد. شمار تلاش مجدد
  در آخرین نمونه 33351 بود؛ این عدد شمار تونل‌ها یا اتصال‌های موفق نیست.
- journal صریحاً `Unable to locate executable '/usr/bin/cloudflared'` و
  `203/EXEC` دارد. بستهٔ cloudflared در dpkg نصب نیست و فایل اجرایی وجود ندارد.
  `/usr/local/bin/cloudflared` فقط symlink شکسته به همان فایل است.
- فرمان مؤثر واحد و drop-in هر دو پیکربندی
  `/etc/cloudflared/config.yml` را می‌خوانند، ولی کل پوشهٔ `/etc/cloudflared`
  وجود ندارد. نصب برنامه به‌تنهایی این مانع دوم را رفع نمی‌کند.

بنابراین سرویس فعلاً حتی به مرحلهٔ اتصال و احراز هویت در Cloudflare نمی‌رسد؛
این لاگ شاهد خطای token، محدودیت QUIC یا مسدودی Cloudflare نیست.

## تنظیمات و پشتیبان باقی‌مانده

در `/root/.cloudflared` فایل config، فایل اعتبار JSON و `cert.pem` موجودند.
ساختار JSON معتبر است، شناسهٔ داخلی با نام فایل مطابقت دارد و فیلدهای
اعتبار خالی نیستند؛ معتبر بودن آن‌ها در حساب Cloudflare آزموده نشده است.
هیچ محتوای محرمانه یا شناسهٔ اعتبار در این گزارش ثبت نشده است.

config باقی‌مانده نام `n8n-tunnel` و دو ingress دارد:

| نام | origin تنظیم‌شده | آزمون فعلی از میزبان |
|---|---|---|
| flow.servernet.cloud | HTTP 10.10.10.6:5678 | HTTP200 |
| storage.servernet.cloud | HTTP 10.10.10.5:80 با Host متناظر | HTTP302 |

یک probe اولیهٔ storage اتصال را refused دید؛ تکرار TCP80/443 و سپس HTTP
با Host درست موفق شد. خرابی ماندگار origin از آن نمونهٔ اولیه نتیجه نمی‌شود.

آرشیو موجود `/vmstore/backup/pve-host-recovery-2026-10-14_10-35.tar.gz`
واحد systemd، drop-inها، symlink و `/root/.cloudflared` را دارد، اما
`/usr/bin/cloudflared` و `/etc/cloudflared` را ندارد. واحد پایه و config
باقی‌مانده با نسخهٔ آرشیو یکسان‌اند. بوت جاری میزبان از ۱۵ اکتبر است.
این شواهد با جاافتادن برنامه و مسیر config در مهاجرت root به SSD سازگارند؛
زمان دقیق حذف یا سلامت آخرین config پیش از مهاجرت احراز نشده است.

در هر شش CT روشن 105/113/114/115/124/125 واحد یا پردازش cloudflared دیده نشد.
داخل تمام VMهای فاقد دسترسی اجرایی و موجودی حساب Cloudflare ممیزی نشده است؛
این گزارش موجودی کامل همهٔ تونل‌های حساب را ادعا نمی‌کند.

## مسیر عمومی فعلی و حدود پذیرش

DNS هر دو نام اکنون A مستقیم به `85.9.108.118` است. آزمون مستقل از رایانهٔ
کنترل برای flow پاسخ HTTPS200 و برای storage پاسخ HTTPS302 به login داد،
با هدر openresty. این دامنه‌ها اکنون از مسیر مستقیم reverse proxy پاسخ
می‌دهند و موفقیت آن‌ها شاهد بازیابی تونل نیست. خطای اتصال به همین نام‌های
عمومی از خود میزبان به‌تنهایی خرابی عمومی سایت را ثابت نمی‌کند.

timerهای country-routing و port-forward و سرویس‌های egress-SNAT و web-DNAT
هنگام بررسی active بودند. هیچ شواهدی از اجرای خود cloudflared یا connector
فعال از این میزبان وجود ندارد. آزمون واقعی تونل پس از بازیابی و نیز وضعیت
تونل‌های دیگر حساب هنوز انجام نشده است.

## اصلاح لازم، تغییرات و بازگشت

برای بازیابی باید executable رسمی و تأییدشده نصب و config صحیحِ همین تونل
در مسیر مورد انتظار سرویس تأمین یا مسیر سرویس به config تأییدشده اصلاح شود؛
پس از آن ثبت اتصال، وضعیت connector، ingressها و پاسخ از مسیر واقعی تونل
باید بررسی شوند. داشتن فایل اعتبار، پذیرش آن توسط Cloudflare را تضمین نمی‌کند.
تغییر DNS برای رفع خطای EXEC لازم نیست و در این بررسی مجاز/انجام نشده است.

این نوبت فقط بررسی بود: هیچ نصب، start/restart/stop، تغییر config، DNS،
route، NAT/firewall، guest یا state عامل‌ها صورت نگرفت. فقط مستندات محلی
به‌روز شدند. هیچ helper مهاجرت یا deployment دوباره اجرا نشد. ماندگاری فعلی
همان واحد enabled با تلاش ناموفق هر ۵ ثانیه است؛ rollback زنده لازم نیست.
