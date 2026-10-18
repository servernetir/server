# نیم‌سرورهای DNSِ مدیریت‌شده — راهنمای بهره‌برداری (v2)

قرارداد طراحی: [`docs/dns/MANAGED-DNS-DESIGN.md`](../../docs/dns/MANAGED-DNS-DESIGN.md).
این فایل جای تصمیم‌های مالک را نمی‌گیرد؛ هر گامِ تولیدی این‌جا **پس از مجوزِ صریح** اجرا می‌شود.

> نسخهٔ v1 این راهنما (تکثیرِ دیتابیسِ MariaDB بین دو نود و نصب روی WHM) کنار گذاشته شد:
> روی WHMِ آلمان PowerDNSِ cPanel پورتِ ۵۳ را دارد و نباید بازپیکربندی شود، و بازکردنِ
> MariaDBِ میزبانی به ایران خلافِ سخت‌سازیِ اوت ۲۰۲۶ است.

## معماری

```
برنامهٔ لاراول (منبعِ حقیقت)  ──HTTPS + کلیدِ API──►  primaryِ پنهان (PowerDNS، gsqlite3، API، catalog producer)
                                                          │  NOTIFY + AXFRِ امضاشده با TSIG (فایروال: فقط IPِ دو secondary)
                                                          ├──► نیم‌سرورِ A  (عمومی :53، gsqlite3، catalog consumer)
                                                          └──► نیم‌سرورِ B  (عمومی :53، همان)
```

- هر نیم‌سرورِ عمومی دادهٔ محلیِ خودش را دارد و بی‌نیاز از برنامه، primary یا نودِ دیگر پاسخ می‌دهد.
- قطعِ primary: نودها با آخرین داده ادامه می‌دهند تا **SOA expire = ۲۸ روز**؛ بعد از آن برای همان zone SERVFAIL.
  دو نود تضمینِ ادامهٔ نامحدودِ سرویس نیست.
- هر نیم‌سرور فقط authoritative است؛ recursion و AXFRِ عمومی بسته‌اند.

## فایل‌ها

| مسیر | نقش |
|---|---|
| `primary/pdns.conf.example` | primaryِ پنهان: پورتِ ۵۳۰۰، API روی loopback، TSIG-only AXFR، catalog |
| `primary/api-tls-proxy.Caddyfile.example` | TLS + محدودیتِ مبدأ و مسیر برای API |
| `secondary/pdns.conf.example` | نیم‌سرورِ عمومی |
| `secondary/pdns-tsig-reconcile.sh` + `.service` + `.timer` | 🔴 الزامی — بخشِ «یافته‌های آزمایشگاه» |
| `iran/servernet-dns-dnat.sh` | هدایتِ 53 از `85.9.108.118` به VMِ داخلی (پیش‌فرض DRY-RUN) |
| `precheck/iran-host-readonly.sh` | بررسیِ فقط‌خواندنیِ میزبانِ ایران پیش از هر تصمیم |
| `precheck/de-whm-readonly.sh` | بررسیِ فقط‌خواندنیِ WHMِ آلمان (پورتِ ۵۳ و آدرس‌ها) |
| `lab/lab-up.sh`, `lab/lab-acceptance.sh`, `lab/netns-dnat-test.sh` | آزمایشگاهِ محلی و آزمون‌های پذیرش |

## یافته‌های آزمایشگاه (PowerDNS 5.0.2) که طراحی را شکل دادند

1. **catalog consumer کلیدِ TSIG را روی zoneهای عضو نمی‌گذارد.** AXFRِ عضو بی‌امضا می‌رود و primary ردش می‌کند؛ zone
   هرگز روی نیم‌سرور نمی‌آید و هیچ خطای آشکاری هم نیست. `pdns-tsig-reconcile` هر دقیقه این را درست می‌کند.
2. zoneی که هنوز منتقل نشده در `pdnsutil list-all-zones` و `pdns_control list-zones` دیده نمی‌شود؛ reconciler از
   خودِ دیتابیسِ gsqlite3 می‌خواند.
3. بدونِ `zone-metadata-cache-ttl=0` روی secondary، کلیدِ تازه تا ۶۰ ثانیه دیده نمی‌شود.
4. serialِ catalog روی primary فقط در چرخهٔ `xfr-cycle-interval` بالا می‌رود (پیش‌فرض ۶۰ ثانیه).
5. APIِ PowerDNS بک‌اسلش را در TXT دور می‌ریزد (`a\\c` → `ac`) و `\200` را به `C2 80` تبدیل می‌کند؛ پنل این
   نویسه‌ها را در TXT نمی‌پذیرد. UTF-8، `"` و چندرشته‌ای سالم می‌مانند.

نتیجهٔ پذیرش با reconciler: zoneِ تازه روی هر دو نود ۷ ثانیه، به‌روزرسانی ۴ ثانیه، بازگشتِ نودِ قطع‌شده ۳ ثانیه،
حذف ۱ ثانیه؛ AXFRِ بی‌امضا رد، recursion → REFUSED.

## نصب (پس از تصمیمِ مالک دربارهٔ محلِ ماشین‌ها)

### primary
```bash
apt install pdns-server pdns-backend-sqlite3 sqlite3
sqlite3 /var/lib/powerdns/pdns.sqlite3 < /usr/share/pdns-backend-sqlite3/schema/schema.sqlite3.sql
chown pdns:pdns /var/lib/powerdns/pdns.sqlite3
cp primary/pdns.conf.example /etc/powerdns/pdns.conf   # جای‌گزینی‌ها؛ کلید با openssl rand -hex 32
pdnsutil generate-tsig-key xfr-servernet hmac-sha256    # راز را فقط به دو secondary منتقل کنید
pdnsutil create-zone catalog.servernet.invalid && pdnsutil set-kind catalog.servernet.invalid producer
pdnsutil set-meta catalog.servernet.invalid TSIG-ALLOW-AXFR xfr-servernet
systemctl restart pdns
```
فایروالِ primary: TCP و UDP 5300 فقط از IPِ دو secondary؛ 443 فقط از IPِ خروجیِ واقعیِ برنامه.

### هر secondary
```bash
apt install pdns-server pdns-backend-sqlite3 sqlite3
# پایگاه‌داده مثلِ بالا، سپس:
cp secondary/pdns.conf.example /etc/powerdns/pdns.conf
pdnsutil import-tsig-key xfr-servernet hmac-sha256 '<secret>'
pdnsutil create-secondary-zone catalog.servernet.invalid PRIMARY_IP:5300
pdnsutil set-kind catalog.servernet.invalid consumer
pdnsutil set-meta catalog.servernet.invalid AXFR-MASTER-TSIG xfr-servernet
install -m 755 secondary/pdns-tsig-reconcile.sh /usr/local/sbin/pdns-tsig-reconcile
cp secondary/pdns-tsig-reconcile.{service,timer} /etc/systemd/system/
systemctl daemon-reload && systemctl enable --now pdns pdns-tsig-reconcile.timer
```

### نیم‌سرورِ ایران (VMِ داخلی پشتِ DNAT)
1. `precheck/iran-host-readonly.sh` اجرا و خروجی بازبینی شود (شنونده‌های ۵۳، زنجیره‌های nat/raw/FORWARD، conntrack،
   split.state، ظرفیت). **آزاد بودنِ پورت به معنیِ آزاد بودنِ IP نیست**: `.118` هم آدرسِ میزبان است هم SNATِ خروجیِ مهمان‌ها.
2. VM (Debian، ۱ هسته، ۱GB، ۱۰GB) فقط روی storageِ سالمِ تأییدشده (`vmstoreid` — ZFS، ONLINE)؛ SSDِ تازه (`local-lvm`) تا تأییدِ
   وضعیتِ پارتیشن/ZFS استفاده نشود. IP روی vmbr1 از فهرستِ آزادِ تأییدشده.
3. VMِ DNS نباید در `/etc/servernet/split.state` یا هیچ `ip rule` باشد (اسکریپت می‌سنجد).
4. `APPLY=0 DNS_VM_IP=10.10.10.N iran/servernet-dns-dnat.sh` (DRY-RUN) → بازبینی → `APPLY=1 …` → واحدِ systemd + timer مثلِ
   عامل‌های موجود. IPv6 منتشر نمی‌شود: `split-boot` فورواردِ IPv6 روی vmbr1 را DROP می‌کند.
5. خطرِ شناخته‌شده: جدولِ conntrackِ میزبان مشترکِ همهٔ مهمان‌هاست؛ سقفِ نرخِ raw (پیش‌فرض ۶۰ پرس‌وجو/ثانیه برای هر /24) آن را
   محافظت می‌کند — در آزمونِ netns، سیلِ ۴۰۰ پرس‌وجو به ۸۵ پاسخ و conntrackِ محدود رسید. این وعدهٔ ضدِ DDoS نیست.

## اتصال به پنل
`/admin/dns`: آدرسِ HTTPSِ API، کلید، نامِ کلیدِ TSIG (فقط نام)، نامِ catalog، نیم‌سرورها با IPv4 (و IPv6 فقط پس از آزمون)،
«آزمایشِ اتصال»، سپس روشن‌کردنِ feature flag. پیش از روشن‌کردن: آزمونِ پذیرش (پایین) روی یک دامنهٔ آزمایشیِ متعلق به خودمان.

## آزمونِ پذیرشِ تولید (از نقاطِ مستقل، نه از شبکهٔ این ایستگاه که به هر پورتی «وصل» می‌شود)
```bash
dig +norec @NS_A_IP test-domain SOA      # flags: aa ؛ serial = serialِ primary
dig +norec +tcp @NS_B_IP test-domain SOA # همین روی TCP
dig @NS_A_IP google.com A                # REFUSED (بدونِ recursion)
dig @NS_A_IP test-domain AXFR            # Transfer failed
curl -sk https://DNS_API_HOSTNAME/api/v1/servers  # از مبدأیی غیر از برنامه: 404
```
یک رکورد حذف کنید و ببینید رکوردهای هم‌نامِ دیگر می‌مانند؛ یک نود را موقت متوقف کنید، تغییر بدهید، برگردانید و هم‌گامی را ببینید.

## پشتیبان، بازیابی، بازگشت
- **منبعِ حقیقت دیتابیسِ برنامه است.** پایگاهِ gsqlite3 روی هر نود بازساختنی است: `php artisan dns:publish --all`
  (یا دکمهٔ «انتشارِ دوباره» در مدیریت) zoneها را روی primary می‌سازد و catalog آنها را به نودها می‌برد.
- با این حال روزانه: `sqlite3 /var/lib/powerdns/pdns.sqlite3 ".backup /var/backups/pdns-$(date +%F).sqlite3"` با دسترسیِ
  `600 root` (شاملِ کلیدهای TSIG است).
- **بازگشتِ نسخهٔ برنامه هرگز DNSِ دامنه‌های تفویض‌شده را پاک نمی‌کند**: حذف فقط از مسیرِ صریحِ حذف/تعلیق انجام می‌شود و
  نودها با دادهٔ موجود ادامه می‌دهند. خاموش‌کردنِ feature flag فقط افزودن/ویرایش را می‌بندد.
- بازگشتِ DNAT: `iran/servernet-dns-dnat.sh remove` — فقط زنجیره‌های خودش.
