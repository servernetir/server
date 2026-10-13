# راه‌اندازیِ نیم‌سرورهای DNSِ مدیریت‌شده (PowerDNS)

این راهنما دو نیم‌سروری را می‌سازد که مشتری‌ها دامنه‌شان را به آن‌ها تفویض می‌کنند.
پنل (لاراول) **منبعِ حقیقت** است و فقط با APIِ نیم‌سرورِ اول حرف می‌زند؛ نیم‌سرورِ دوم
با تکثیرِ دیتابیس همیشه همان داده را دارد.

```
پنل سرورنت ──HTTPS/API──►  dns1  (PowerDNS + MariaDB primary)
                              │  MariaDB replication (TLS)
                              ▼
                            dns2  (PowerDNS + MariaDB replica, فقط‌خواندنی)

اینترنت ──UDP/TCP 53──► dns1 و dns2
```

## پیش‌نیازها — تصمیم‌هایی که بعداً نمی‌شود ارزان عوضشان کرد

| مورد | چرا |
|---|---|
| **دو سرور روی دو شبکهٔ جدا** (مثلاً یکی ایران، یکی Hetzner) | هر دو روی یک ماشین/دیتاسنتر = با افتادنِ آن، DNSِ **همهٔ** مشتریان می‌افتد. |
| **نامِ نیم‌سرورها** (پیش‌فرض: `dns1/dns2.servernet.cloud`) | بعد از اولین مشتری، عوض‌کردنِ نام یعنی همهٔ مشتری‌ها باید نیم‌سرورِ دامنه‌شان را دوباره عوض کنند. |
| نام‌ها **نباید** `ns1/ns2.servernet.cloud` باشند | آن دو به BINDِ سرورِ cPanel اشاره می‌کنند و zoneِ هاست‌ها را سرو می‌کنند. |
| ۱ هسته / ۱ گیگ رم / ۲۰ گیگ دیسک برای هر سرور | برای ده‌ها هزار zone کافی است؛ DNS سبک است. |

> اگر نام‌ها زیرِ `servernet.cloud` باشند، رکوردِ A آن‌ها روی Cloudflare ساخته می‌شود
> (**ابرِ خاکستری — DNS only**؛ پروکسیِ نارنجی پورتِ ۵۳ را رد نمی‌کند). در این حالت
> سلامتِ DNSِ مشتری‌ها به در دسترس بودنِ zoneِ `servernet.cloud` هم بسته است. دامنهٔ
> جداگانه (مثلاً `sn-dns.net` با glue record نزدِ رجیسترار) این وابستگی را برمی‌دارد.

## ۱) هر دو سرور — نصب (Debian 12 / Ubuntu 24.04)

```bash
apt update && apt install -y mariadb-server pdns-server pdns-backend-mysql
systemctl disable --now systemd-resolved 2>/dev/null || true   # پورت ۵۳ را آزاد می‌کند
rm -f /etc/powerdns/pdns.d/bind.conf                            # بک‌اندِ پیش‌فرضِ bind لازم نیست
```

دیتابیس و اسکیما (روی **dns1**؛ dns2 آن را از تکثیر می‌گیرد):

```bash
mysql -e "CREATE DATABASE pdns CHARACTER SET latin1;
          CREATE USER 'pdns'@'localhost' IDENTIFIED BY 'یک-رمز-قوی';
          GRANT ALL ON pdns.* TO 'pdns'@'localhost';"
mysql pdns < /usr/share/pdns-backend-mysql/schema/schema.mysql.sql
```

## ۲) پیکربندیِ PowerDNS

`/etc/powerdns/pdns.conf` روی **dns1**:

```ini
launch=gmysql
gmysql-host=127.0.0.1
gmysql-dbname=pdns
gmysql-user=pdns
gmysql-password=یک-رمز-قوی
gmysql-dnssec=yes

local-address=0.0.0.0, ::
default-soa-content=dns1.servernet.cloud hostmaster.servernet.cloud 0 3600 600 604800 3600

# API — فقط برای پنل
api=yes
api-key=یک-کلید-تصادفیِ-بلند          # openssl rand -hex 32
webserver=yes
webserver-address=0.0.0.0
webserver-port=8081
webserver-allow-from=65.109.176.14/32   # فقط IPِ خروجیِ سرورِ سایت

# امنیت و پایداری
version-string=anonymous
disable-axfr=yes
max-tcp-connections=200
```

روی **dns2** همان فایل، با این تفاوت‌ها:

```ini
api=no
webserver=no
default-soa-content=dns1.servernet.cloud hostmaster.servernet.cloud 0 3600 600 604800 3600
```

> 🔴 `webserver-allow-from` تنها قفلِ API نیست و نباید باشد: فایروالِ سرور هم باید
> پورتِ ۸۰۸۱ را **فقط** برای IPِ سایت باز کند. هر کس به این API برسد، DNSِ همهٔ
> مشتری‌ها را در دست دارد. اگر ترافیک از اینترنتِ عمومی می‌گذرد، API را پشتِ
> nginx با TLS بگذارید (یا WireGuard بینِ سایت و dns1) — کلیدِ API در HTTPِ ساده
> خوانا می‌رود.

## ۳) تکثیرِ دیتابیس dns1 → dns2

روی **dns1** (`/etc/mysql/mariadb.conf.d/60-repl.cnf`):

```ini
[mysqld]
server-id=1
log_bin=mysql-bin
binlog_format=ROW
binlog_do_db=pdns
bind-address=0.0.0.0
```

```bash
systemctl restart mariadb
mysql -e "CREATE USER 'repl'@'IP_DNS2' IDENTIFIED BY 'رمز-تکثیر' REQUIRE SSL;
          GRANT REPLICATION SLAVE ON *.* TO 'repl'@'IP_DNS2';"
mysqldump --single-transaction --master-data=1 pdns > /root/pdns.sql   # به dns2 منتقل شود
```

روی **dns2**:

```ini
[mysqld]
server-id=2
read_only=1
relay_log=relay-bin
```

```bash
systemctl restart mariadb
mysql -e "CREATE DATABASE pdns CHARACTER SET latin1;
          CREATE USER 'pdns'@'localhost' IDENTIFIED BY 'یک-رمز-قوی';
          GRANT SELECT ON pdns.* TO 'pdns'@'localhost';"
mysql -e "CHANGE MASTER TO MASTER_HOST='IP_DNS1', MASTER_USER='repl',
          MASTER_PASSWORD='رمز-تکثیر', MASTER_SSL=1;"
mysql pdns < /root/pdns.sql
mysql -e "START SLAVE; SHOW SLAVE STATUS\G" | grep -E "Running|Behind"
```

هر دو خطِ `Slave_IO_Running` و `Slave_SQL_Running` باید `Yes` باشند.

> چرا تکثیرِ دیتابیس و نه AXFR/NOTIFY: پنل zoneها را با نوعِ `Native` می‌سازد
> (`PowerDnsClient::createZone`). با تکثیرِ دیتابیس، zoneِ تازه **بدونِ هیچ کارِ
> اضافه** روی dns2 هم هست؛ با AXFR باید برای هر zone نیم‌سرورِ دوم را هم خبر کرد
> و یک NOTIFYِ گم‌شده یعنی dns2 جوابِ کهنه می‌دهد — خرابیِ متناوب و سخت‌یاب.

## ۴) فایروال (هر دو سرور)

```bash
ufw allow 53/udp && ufw allow 53/tcp
ufw allow from 65.109.176.14 to any port 8081 proto tcp     # فقط dns1
ufw allow from IP_DNS2 to any port 3306 proto tcp            # فقط dns1
ufw enable
```

⚠️ TCP/53 را فراموش نکنید: پاسخ‌های بزرگ (TXTِ بلند، DNSSEC) روی TCP می‌آیند و
بدونِ آن، فقط **بعضی** رکوردها برای **بعضی** resolverها از کار می‌افتند.

## ۵) رکوردهای خودِ نیم‌سرورها

روی zoneِ `servernet.cloud` (Cloudflare، ابرِ خاکستری):

```
dns1   A      IP_DNS1
dns2   A      IP_DNS2
```

## ۶) وصل‌کردن به پنل

در `/admin/dns`:

1. **آدرسِ API**: `http://IP_DNS1:8081`
2. **کلیدِ API**: همان `api-key`
3. **نیم‌سرورها**: `dns1.servernet.cloud` و `dns2.servernet.cloud`
4. «آزمایشِ اتصال» → باید نسخهٔ PowerDNS را نشان دهد.

## ۷) آزمونِ پذیرش — پیش از اعلام به مشتری

یک دامنهٔ آزمایشی در پنلِ مشتری اضافه کنید، یک رکوردِ A بسازید، سپس:

```bash
dig +short A test.example.com @IP_DNS1      # باید همان IP را بدهد
dig +short A test.example.com @IP_DNS2      # 🔴 این هم — وگرنه تکثیر کار نمی‌کند
dig +tcp +short TXT example.com @IP_DNS1    # TCP/53 باز است؟
dig +short NS example.com @IP_DNS1          # باید dns1 و dns2 باشد
```

رکورد را در پنل **حذف** کنید و دوباره `dig` بزنید: باید خالی برگردد. (نسخهٔ اولِ
این سیستم رکوردِ حذف‌شده را روی سرور جا می‌گذاشت؛ این آزمون همان را می‌گیرد.)

## نگهداری

| کار | فرمان |
|---|---|
| فرستادنِ دوبارهٔ zoneهای همگام‌نشده | `php artisan dns:reconcile` (کرون: هر ۱۰ دقیقه) |
| بازسازیِ کاملِ سرورِ DNS از روی پنل | `php artisan dns:reconcile --all` |
| سنجشِ تفویض + پاک‌کردنِ zoneهای رهاشده | `php artisan dns:verify-pending` (کرون: هر ساعت) |
| وضعیتِ تکثیر | روی dns2: `mysql -e "SHOW SLAVE STATUS\G"` |

چون پنل منبعِ حقیقت است، **بکاپِ دیتابیسِ PowerDNS حیاتی نیست**: اگر هر دو سرور از
دست بروند، سرورِ تازه + `dns:reconcile --all` همه‌چیز را برمی‌گرداند.

## آنچه عمداً در این فاز نیست

- **DNSSEC** — ستونش (`cdn_zones.dnssec`) هست؛ فعال‌سازی نیازمندِ ثبتِ DS نزدِ رجیستری است.
- **dnsdist / محدودیتِ نرخ** — جلوی PowerDNS برای مقاومت در برابرِ حملهٔ حجمی؛ فازِ بعد.
- **پاسخِ جغرافیایی، پروکسی (CDN) و WAF** — ستونِ `cdn_records.proxied` از حالا هست و
  `PowerDnsClient::answers()` آن را می‌فهمد؛ با آمدنِ نودهای لبه روشن می‌شود.
