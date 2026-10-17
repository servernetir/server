# PEETA / VM116: بازیابی اتصال Cloudflare با رلهٔ موجود آلمان

## نتیجهٔ اجرایی ۱۷ اکتبر ۲۰۲۶

این گزارش جایگزین نتیجهٔ «رله اعمال نشده» در ممیزی قبلی
[VM116-CLOUDFLARE-7844-AUDIT-2026-10-17.md](VM116-CLOUDFLARE-7844-AUDIT-2026-10-17.md)
است. با دستور صریح مالک، فقط TCP خروجی 7844 از VM116/PEETA/Selmi
با IP `10.10.10.61` به بیست IPv4 رسمی Cloudflare Edge از رلهٔ موجود
CT113 / tun119 / آلمان عبور داده شد. اینترنت عمومی VM، DNS، حساب و
تنظیمات cloudflared مشتری تغییر نکردند؛ هیچ تونل یا VPS تازه ساخته نشد.

لاگ واقعی مهمان، پس از تغییر مسیر، برای **سه سرویس مستقل** هرکدام
چهار پیام `Registered tunnel connection` با connIndex صفر تا سه ثبت کرد:

| سرویس با نام مستعار | ثبت چهار اتصال، UTC | Edge location |
|---|---|---|
| connector-1 | 10:05:58–10:06:01 | fra15 / fra08 / fra16 / fra08 |
| connector-2 | 10:07:16–10:07:18 | fra03 / fra14 / fra16 / fra08 |
| connector-3 | 10:07:16–10:07:19 | fra20 / fra03 / fra08 / fra18 |

نام سرویس‌ها و شناسه‌های تونل مشتری عمداً در خروجی ثبت نشدند. شواهد
ثبت اتصال از journal خود VM است، نه استنتاج از بازبودن پورت یا conntrack.
در پذیرش نهایی `10:18:28Z` هر دوازده جریان روی میزبان و فضای شبکهٔ CT113
`ESTABLISHED/ASSURED` بودند؛ در CT، reply tuple با مقصد `10.119.0.1`
اعمال MASQUERADE روی tun119 را تأیید کرد. capture فقط header، رفت‌وبرگشت
TLS و سپس keepalive و ACK را در TAP مهمان، bridge، ورودی CT و tun119 ثبت کرد؛
هیچ payload کاربردی یا PCAP ذخیره نشد.

## مسیر و مالکیت دقیق قواعد

VM116 با gateway `10.10.10.1` روی vmbr1 است. قواعد میزبان برای source
`10.10.10.1` از بازیابی سه تونل مالک، برای این مهمان قابل استفاده نبودند.
قواعد تازه کاملاً جدا هستند:

| محل | قواعد جدید | مسیر |
|---|---|---|
| میزبان Proxmox | ۲۰ policy با priority901، source .61/32، destination Edge/32، TCP dport7844 | table17844، default via10.10.10.20 devvmbr1 metric10، blackhole default metric1000 |
| CT113، رفت | ۲۰ policy با priority901 و همان پنج‌تایی محدود | table119 موجود، default devtun119؛ محتوای table119 تغییر نکرد |
| CT113، NAT | ۲۰ MASQUERADE فقط source .61/32، Edge/32، TCP dport7844، outtun119 | comment ثابت `servernet-vm116-cloudflare` |
| CT113، برگشت | ۲۰ policy با priority902، source Edge/32، destination .61/32، TCP sport7844 | table17845، .61/32 via10.10.10.1 deveth0، blackhole default metric1000 |

پس از reverse NAT در CT، policy برگشت ترافیک همان اتصال را به میزبان
می‌فرستد و میزبان از مسیر LAN موجود به VM116 تحویل می‌دهد. مسیر عمومی
CT به .61 یا default آن عوض نشد. NAT روی میزبان برای این مسیر جدید اضافه نشد.
قواعد priority900 و table7844 مخصوص سه تونل مالک ثابت ماندند.

مقصدها طبق [مستند رسمی firewall Cloudflare Tunnel](https://developers.cloudflare.com/cloudflare-one/networks/connectors/cloudflare-tunnel/configure-tunnels/tunnel-with-firewall/)
در ۱۷ اکتبر بررسی و به صورت /32 محدود شدند:

- `198.41.192.{167,67,57,107,27,7,227,47,37,77}`
- `198.41.200.{13,193,33,233,53,63,113,73,43,23}`

هیچ /24 کامل، UDP7844، IPv6 یا مقصد دلخواهی از رله عبور داده نشد.
برای پاک‌کردن NAT کهنه تنها conntrack مربوط به source .61، TCP dport7844
و همین بیست IP روی میزبان و فضای شبکهٔ CT پاک شد؛ flush عمومی انجام نشد.
مشتری خودش اتصال‌ها را دوباره برقرار کرد؛ سرویس مهمان restart نشد.

## پشتیبان، اجرای موقت و دسترسی به شواهد

پیش از تغییر، rules/routes/default/iptables و موجودی PID مهمان‌ها در
`/root/servernet-vm116-cloudflare-20261017/before-{host,ct113}.json`
با دسترسی خصوصی ثبت شد. مسیر ابتدا موقت بود و یک timer برگشت خودکار
۲۰دقیقه‌ای داشت. در تلاش اولیهٔ refresh، نبود ابزار conntrack داخل CT
تشخیص داده شد؛ قواعد اختصاصی خود این کار برگشت داده شدند و helper اصلاح شد
تا ابزار میزبان را با `nsenter` در netns کانتینر اجرا کند. سپس apply/verify/refresh
موفق شد. timer موقت فقط بعد از اثبات ثبت اتصال و موفقیت نصب ماندگار متوقف شد.

ورود root مشتری با اطلاعات قبلی ممکن نبود و QEMU guest agent اجرا نمی‌شود.
هیچ رمز، SSH config، حساب، agent یا فایل مهمان تغییر نکرد. برای خواندن محدود
لاگ، snapshot موقت ZFS فقط از `vmstore/vm-116-disk-0` ساخته شد؛ پارتیشن ext4
با loop read-only و `ro,noload,noexec,nosuid,nodev` خوانده شد. خروجی فقط زمان،
connIndex و Edge ثبت اتصال بود. پس از خواندن، mount/loop/snapshot پاک و
`snapdev=hidden` با source قبلی `default` بازیابی شد. این snapshot crash-consistent
است؛ وجود پیام‌ها اثبات ثبت اتصال است و از نبود پیام احتمالی نتیجه‌گیری نشد.
هیچ snapshot موجود یا دادهٔ مهمان حذف نشد.

پشتیبان نهایی شامل قبل/بعد، helper، unitها و رسید validation، بدون credential،
روی ZFS در `/vmstore/backup/vm116-cloudflare-relay-20261017.tar.gz` با mode0600
ذخیره شد. نسخهٔ خصوصی روی root نیز موجود است.

## ماندگاری و هماهنگی عامل‌ها

دامنهٔ Git فقط همین helper/unit/timer/task/flagها، آزمون و ابزارهای خواندن لاگ
و پذیرش، گزارش قبلی و جدید و دو نمایه است؛ SHA پایهٔ develop برای ثبت این کار
`1bf11f52d3408816b0f1ab732dccb7c65b44c188` است. تغییرهای دیگر workspace
و تنظیمات عمومی شبکه وارد این انتشار نشدند.

- `/usr/local/sbin/servernet-vm116-cloudflare-egress`: helper مستقل، source در
  `infra/ansible/roles/proxmox_host/files/servernet-vm116-cloudflare-egress.py`.
- `servernet-vm116-cloudflare-egress.service`: enabled، oneshot، apply سپس verify،
  بعد از network-online و pve-guests؛ در خطا retry می‌کند.
- `servernet-vm116-cloudflare-egress.timer`: enabled/active؛ ۳۰ثانیه پس از boot
  و ۶۰ثانیه پس از پایان اجرای قبلی reconcile می‌کند. flock اجرای هم‌زمان را می‌بندد.
- Ansible با flag مستقل `pve_vm116_cloudflare_egress_manage`، پیش‌فرض false و
  فقط برای pve-node برابر true؛ task مستقل هیچ credential یا unit مهمان را لمس نمی‌کند.

این helper فقط قواعد دقیق خودش را مالک است. country-routing و port-forward
عامل‌های قبلی، state آن‌ها و chainهای اختصاصی‌شان تغییر نکردند؛ قواعد جدید
بیرون از آن chainها هستند. اجرای طبیعی country-agent، helper قبلی و چرخهٔ
helper تازه در طول پذیرش طی شد و قواعد/جریان‌ها باقی ماندند. helper سه تونل
میزبان از نظر SHA256 همان نسخهٔ پیش از تغییر است. وابستگی به CT113/tun119 و
رلهٔ آلمان موجود باقی است؛ رلهٔ جایگزین یا مسیر failover تازه ایجاد نشده است.

## اعتبارسنجی و حدود

- تست route برای هر ۲۰ مقصد در رفت و برگشت موفق بود؛ ordinary TCP443،
  UDP7844، TCP7844 به مقصد غیرEdge و source مهمان دیگر همچنان WAN قبلی دارند.
- default میزبان، تمام قواعد firewall/NAT قبلی میزبان، موجودی/PID مهمان‌ها و
  SHA256 helper قبلی با backup برابرند. CT هیچ قاعدهٔ قبلی را از دست نداده و
  تنها بیست NAT محدود افزوده شده است؛ timestamp/counterهای iptables مقایسه نمی‌شوند.
- `/ready` سه connector مالک روی 20241/20243/20244 همگی HTTP200 ماندند.
- DNAT عمومی `85.9.108.118:20000` به `.61:22` enabled/active ماند؛ پس از
  نصب ماندگار، banner واقعی OpenSSH از بیرون دوباره دریافت شد.
- چهار آزمون offline برای محدودهٔ policy، برگشت، جداسازی و rollback موفق‌اند؛
  systemd-analyze verify و اجرا/verify واقعی واحد و اجرای دوره‌ای موفق بود.
- reboot عمداً انجام نشد؛ ماندگاری از unit/timer فعال و اجرای واقعی آن‌ها
  تأیید شده، نه آزمایش reboot. سلامت تمام وب‌سایت‌ها/برنامه‌های مشتری یا DNS
  و وضعیت dashboard حساب مشتری خارج از این شواهد است؛ ثبت تونل از خود لاگ تأیید شد.

## برگشت محدود

روی میزبان Proxmox، با root:

```sh
systemctl disable --now servernet-vm116-cloudflare-egress.timer servernet-vm116-cloudflare-egress.service
/usr/local/sbin/servernet-vm116-cloudflare-egress rollback
```

در منبع Ansible نیز flag مخصوص VM116 را false کنید تا اجرای بعدی دوباره فعالش نکند.
rollback فقط ۶۰ policy، بیست NAT و چهار route متعلق به این کار و conntrack
محدود خودش را حذف می‌کند؛ default، NAT عمومی، سه تونل مالک و سایر VMها حفظ می‌شوند.
اگر helper نصب‌شده در دسترس نبود، نسخهٔ مستقل
`python3 /root/servernet-vm116-cloudflare-20261017/network.py rollback`
همان تغییرهای محدود را برمی‌گرداند. پس از rollback مسیر مستقیم قبلی که ثبت
تونل در آن متوقف می‌شد بازمی‌گردد؛ بهبود ارتباط روی مسیر مستقیم ادعا نمی‌شود.
