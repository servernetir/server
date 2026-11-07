"""Build an offline, auditable three-language catalogue from the preview copy."""
from html.parser import HTMLParser
from pathlib import Path
import json
ROOT = Path(__file__).resolve().parent
class CopyParser(HTMLParser):
    def __init__(self): super().__init__(); self.current = None; self.copy = {}
    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if 'data-i18n' in attrs: self.current = attrs['data-i18n']
    def handle_data(self, value):
        if self.current and value.strip(): self.copy.setdefault(self.current, value.strip())
    def handle_endtag(self, tag): self.current = None
p = CopyParser(); p.feed((ROOT/'index.html').read_text())
# Each row is key | English | Turkish. No translation service or network is used.
rows = '''
a11y.skip|Skip to content|İçeriğe geç
brand.name|ServerNet|ServerNet
nav.workspace|WORKSPACE|ÇALIŞMA ALANI
nav.dashboard|Overview|Genel bakış
nav.hosting|Web hosting|Web barındırma
nav.cloud|Cloud servers|Bulut sunucular
nav.domains|Domains|Alan adları
nav.storage|Storage|Depolama
nav.account|ACCOUNT|HESAP
nav.invoices|Invoices|Faturalar
nav.wallet|Wallet|Cüzdan
nav.support|Support|Destek
nav.security|Security & access|Güvenlik ve erişim
support.needHelp|We're here to help|Yardıma hazırız
support.always|Support, around the clock|Günün her saati destek
account.initials|DA|DH
account.name|Demo account|Demo hesap
header.console|Management console|Yönetim konsolu
header.personal|Personal workspace|Kişisel çalışma alanı
a11y.language|Interface language|Arayüz dili
preview.label|Preview · demo data|Ön izleme · örnek veriler
preview.description|All data is fictional. Actions are simulated.|Tüm veriler kurgusaldır. İşlemler simüle edilir.
dashboard.eyebrow|YOUR WORKSPACE|ÇALIŞMA ALANINIZ
dashboard.title|Everything, at a glance|Her şey bir bakışta
dashboard.description|Keep track of your services, payments and next steps.|Hizmetlerinizi, ödemelerinizi ve sıradaki işlerinizi takip edin.
actions.newService|New service|Yeni hizmet
stats.services|Active services|Aktif hizmetler
stats.serviceUnit|services|hizmet
stats.servicesNote|2 hosting plans, 4 servers and 2 storage volumes|2 barındırma paketi, 4 sunucu ve 2 depolama birimi
stats.domains|My domains|Alan adlarım
stats.domainUnit|domains|alan adı
stats.domainsNote|Next renewal in 42 days|Sonraki yenilemeye 42 gün
stats.invoices|Unpaid invoices|Ödenmemiş faturalar
stats.invoiceUnit|invoice|fatura
stats.invoiceNote|Payment due November 18|Son ödeme tarihi 18 Kasım
stats.tickets|Open tickets|Açık destek talepleri
stats.ticketUnit|tickets|talep
stats.ticketsNote|Here whenever you need us|İhtiyacınız olduğunda buradayız
dashboard.myServices|Your services|Hizmetleriniz
actions.viewAll|View all|Tümünü gör
service.name|Service|Hizmet
service.resources|Resource usage|Kaynak kullanımı
service.renewal|Next renewal|Sonraki yenileme
service.linuxTitle|Linux Pro hosting|Linux Pro barındırma
date.november18|November 18, 2026|18 Kasım 2026
date.in15Days|In 15 days|15 gün sonra
service.cloudTitle|Standard cloud server|Standart bulut sunucu
date.december2|December 2, 2026|2 Aralık 2026
date.in29Days|In 29 days|29 gün sonra
service.windowsTitle|Windows Business hosting|Windows Business barındırma
date.december16|December 16, 2026|16 Aralık 2026
date.in43Days|In 43 days|43 gün sonra
dashboard.usageNote|Resource figures are sample data for this design.|Kaynak değerleri bu tasarım için örnek verilerdir.
activity.title|Recent activity|Son etkinlikler
actions.history|Full history|Tüm geçmiş
activity.renewal|Windows hosting renewed|Windows barındırma yenilendi
activity.yearly|Annual renewal|Yıllık yenileme
activity.today|Today, 10:24|Bugün, 10:24
activity.wallet|Wallet balance added|Cüzdan bakiyesi yüklendi
activity.walletAmount|1,000,000 toman · payment successful|1.000.000 tümen · ödeme başarılı
activity.yesterday|Yesterday, 16:48|Dün, 16:48
activity.security|Secure account sign-in|Hesaba güvenli giriş
activity.device|Chrome browser · personal device|Chrome tarayıcı · kişisel cihaz
activity.twoDays|2 days ago|2 gün önce
wallet.title|Your wallet|Cüzdanınız
wallet.available|Available balance|Kullanılabilir bakiye
currency.toman|toman|tümen
wallet.note|Ready for payments and service renewals|Ödemeler ve hizmet yenilemeleri için hazır
actions.topup|Add balance|Bakiye yükle
todo.title|Needs your attention|İlginizi bekliyor
todo.dnsTitle|Connect your hosting domain|Barındırma alan adınızı bağlayın
actions.setupDomain|Set up domain connection|Alan adı bağlantısını ayarla
todo.invoiceTitle|One invoice awaiting payment|Bir fatura ödeme bekliyor
todo.invoiceNote|480,000 toman · due November 18|480.000 tümen · son ödeme 18 Kasım
actions.viewInvoice|View invoice|Faturayı gör
support.question|Have a question?|Bir sorunuz mu var?
support.experts|Talk to the ServerNet team|ServerNet ekibiyle görüşün
hosting.eyebrow|WEB HOSTING|WEB BARINDIRMA
hosting.title|Web hosting|Web barındırma
hosting.description|Your websites, with the details that matter.|Web siteleriniz ve ihtiyaç duyduğunuz tüm ayrıntılar.
actions.newHosting|New hosting plan|Yeni barındırma paketi
hosting.activeServices|active services|aktif hizmet
hosting.domainNeedsSetup|domain needs setup|alan adı ayar bekliyor
hosting.demoUsage|Resource usage · demo data|Kaynak kullanımı · örnek veriler
filter.all|All|Tümü
filter.active|Active|Aktif
filter.attention|Needs attention|İlgi gerekiyor
a11y.sort|Sort services|Hizmetleri sırala
sort.newest|Newest first|Önce en yeniler
sort.renewal|Next renewal|En yakın yenileme
sort.name|Service name|Hizmet adı
status.active|Active|Aktif
location.iran|Tehran, Iran|Tahran, İran
plan.pro|Pro plan|Pro paket
resource.disk|Disk space|Disk alanı
resource.bandwidth|Monthly bandwidth|Aylık bant genişliği
resource.email|Email accounts|E-posta hesapları
dns.needsSetup|Domain connection needs setup|Alan adı bağlantısı ayar bekliyor
actions.setup|Set up|Ayarla
actions.manageService|Manage service|Hizmeti yönet
actions.cpanel|Open cPanel|cPanel'i aç
location.germany|Frankfurt, Germany|Frankfurt, Almanya
plan.business|Business plan|Business paket
dns.connectedDemo|DNS connected · sample status|DNS bağlı · örnek durum
actions.plesk|Open Plesk|Plesk'i aç
hosting.emptyTitle|No services found|Hizmet bulunamadı
hosting.emptyNote|Try another search or change the filters.|Başka bir arama yapın veya filtreleri değiştirin.
actions.clearFilters|Clear filters|Filtreleri temizle
hosting.note|All information on this page is fictional. This preview does not manage real services.|Bu sayfadaki tüm bilgiler kurgusaldır. Bu ön izleme gerçek hizmetleri yönetmez.
detail.back|Back to web hosting|Web barındırmaya dön
detail.overview|Overview|Genel bakış
detail.backups|Backups|Yedekler
detail.billing|Billing & renewal|Faturalandırma ve yenileme
detail.settings|Service settings|Hizmet ayarları
detail.demo|Sample data|Örnek veriler
detail.resourceUsage|Resource usage|Kaynak kullanımı
detail.usageTime|Sample usage this month|Bu ayın örnek kullanımı
resource.diskFree|11.6 GB available|11,6 GB kullanılabilir
resource.monthlyReset|Resets at the start of the next period|Sonraki dönemin başında sıfırlanır
actions.manageEmail|Manage email accounts|E-posta hesaplarını yönet
resource.database|Databases|Veritabanları
actions.manageDatabase|Manage databases|Veritabanlarını yönet
dns.title|Domain & DNS connection|Alan adı ve DNS bağlantısı
dns.setupStatus|Setup required|Ayar gerekiyor
dns.primaryDomain|Primary domain|Birincil alan adı
dns.noticeTitle|One step to connect your domain|Alan adınızı bağlamak için bir adım kaldı
dns.noticeDescription|Enter these nameservers at your domain registrar. Changes can take up to 48 hours.|Bu ad sunucularını alan adı kayıt kuruluşunuza girin. Değişiklikler 48 saate kadar sürebilir.
dns.assignedNameservers|Assigned nameservers|Atanan ad sunucuları
actions.copyAll|Copy both|İkisini kopyala
dns.notChecked|No live DNS lookup runs in this preview.|Bu ön izlemede canlı DNS sorgusu yapılmaz.
actions.checkConnection|Check connection|Bağlantıyı kontrol et
dns.guideTitle|Connection guide & important notes|Bağlantı rehberi ve önemli notlar
dns.guideText|If your website or email is live, transfer its current DNS records before changing nameservers. Contact support for custom DNS.|Web siteniz veya e-postanız etkinse ad sunucularını değiştirmeden önce mevcut DNS kayıtlarını aktarın. Özel DNS için desteğe başvurun.
dns.limitText|DNS connection alone does not confirm website, SSL certificate or email health.|DNS bağlantısı tek başına web sitesi, SSL sertifikası veya e-posta sağlığını doğrulamaz.
detail.quickActions|Quick actions|Hızlı işlemler
detail.simulated|Simulated actions|Simüle edilen işlemler
actions.fileManager|File manager|Dosya yöneticisi
actions.filesNote|Website files|Web sitesi dosyaları
actions.databases|Databases|Veritabanları
actions.emailAccounts|Email accounts|E-posta hesapları
actions.emailNote|Email & webmail|E-posta ve web posta
actions.backups|Backups|Yedekler
actions.backupNote|View & restore|Görüntüle ve geri yükle
detail.serviceInfo|Service information|Hizmet bilgileri
service.plan|Hosting plan|Barındırma paketi
plan.linuxPro|Linux Pro · 20 GB|Linux Pro · 20 GB
service.location|Location|Konum
service.panel|Control panel|Kontrol paneli
service.ip|IP address|IP adresi
service.username|Username|Kullanıcı adı
service.password|Password|Parola
actions.show|Show|Göster
renewal.title|Renew service|Hizmeti yenile
renewal.due|November 18, 2026 · in 15 days|18 Kasım 2026 · 15 gün sonra
renewal.nextAmount|Next period amount|Sonraki dönem tutarı
actions.renewService|Renew service|Hizmeti yenile
renewal.demo|This amount and date are samples only.|Bu tutar ve tarih yalnızca örnektir.
support.serviceHelp|Need help with this service?|Bu hizmet için yardıma mı ihtiyacınız var?
support.createTicket|Create a support request|Destek talebi oluştur
footer.copyright|ServerNet · infrastructure for your ideas|ServerNet · fikirleriniz için altyapı
footer.help|Help center|Yardım merkezi
footer.status|Service status|Hizmet durumu
nav.hostingShort|Hosting|Barındırma
nav.invoicesShort|Invoices|Faturalar
nav.accountShort|Account|Hesabım
modal.title|Simulated action|Simüle edilen işlem
modal.description|This action previews the experience only. It does not change any service, account or payment.|Bu işlem yalnızca deneyimi gösterir. Hiçbir hizmeti, hesabı veya ödemeyi değiştirmez.
modal.close|Got it|Anladım
a11y.mainNav|Main navigation|Ana gezinme
a11y.brand|ServerNet overview|ServerNet genel bakış
a11y.collapse|Collapse sidebar|Kenar çubuğunu daralt
a11y.expand|Expand sidebar|Kenar çubuğunu genişlet
a11y.closeMenu|Close menu|Menüyü kapat
a11y.openMenu|Open menu|Menüyü aç
a11y.search|Search console|Konsolda ara
a11y.theme|Toggle light or dark theme|Açık veya koyu temayı değiştir
a11y.notifications|Notifications|Bildirimler
a11y.profile|Your account|Hesabınız
a11y.manageLinux|Manage Linux hosting|Linux barındırmayı yönet
a11y.manageCloud|Manage cloud server|Bulut sunucuyu yönet
a11y.manageWindows|Manage Windows hosting|Windows barındırmayı yönet
a11y.filter|Filter hosting services|Barındırma hizmetlerini filtrele
a11y.hostingSearch|Search hosting services|Barındırma hizmetlerinde ara
hosting.search|Search domain or plan…|Alan adı veya paket ara…
a11y.detailSections|Hosting management sections|Barındırma yönetim bölümleri
a11y.copyDomain|Copy domain|Alan adını kopyala
a11y.copyNameservers|Copy both nameservers|İki ad sunucusunu kopyala
a11y.copyNs1|Copy first nameserver|İlk ad sunucusunu kopyala
a11y.copyNs2|Copy second nameserver|İkinci ad sunucusunu kopyala
a11y.copyIp|Copy IP address|IP adresini kopyala
a11y.copyUsername|Copy username|Kullanıcı adını kopyala
a11y.bottomNav|Quick navigation|Hızlı gezinme
a11y.close|Close|Kapat
page.title|ServerNet console preview|ServerNet konsol ön izlemesi
feedback.copied|Copied to clipboard|Panoya kopyalandı
feedback.copyFailed|Select and copy this value manually:|Bu değeri seçip elle kopyalayın:
feedback.checking|Simulating DNS check…|DNS kontrolü simüle ediliyor…
feedback.dnsPending|Sample result: setup still required. No live lookup was made.|Örnek sonuç: ayar hâlâ gerekli. Canlı sorgu yapılmadı.
feedback.dnsConnected|Sample result: DNS connected. No live lookup was made.|Örnek sonuç: DNS bağlı. Canlı sorgu yapılmadı.
feedback.ready|Prototype only · no live action was performed.|Yalnızca prototip · canlı işlem yapılmadı.
dns.connected|Connected · sample|Bağlı · örnek
dns.connectedTitle|Nameserver connection · sample|Ad sunucusu bağlantısı · örnek
dns.connectedDescription|This service shows a fictional connected state. The reserved .example domain is not a live lookup result.|Bu hizmet kurgusal bir bağlı durum gösterir. Ayrılmış .example alan adı canlı sorgu sonucu değildir.
resource.available|GB available|GB kullanılabilir
modal.nextPhase|This section is planned for the next design phase. This preview covers the overview and hosting experience.|Bu bölüm sonraki tasarım aşaması için planlandı. Bu ön izleme genel bakış ve barındırma deneyimini kapsar.
modal.panel|A live console would open the service's assigned control panel here. This prototype opens no external connection.|Canlı konsolda hizmete atanmış kontrol paneli burada açılır. Bu prototip dış bağlantı açmaz.
modal.password|For review, this prototype uses no real credentials and reveals no password.|Bu prototipte gerçek kimlik bilgisi kullanılmaz ve parola gösterilmez.
modal.support|A live console would open a request linked to this service. No support ticket is sent from this preview.|Canlı konsolda bu hizmete bağlı bir talep açılır. Bu ön izlemeden destek talebi gönderilmez.
'''
messages = {'fa':p.copy, 'en':{}, 'tr':{}}
for row in rows.strip().splitlines():
    key,en,tr=row.split('|')
    messages['en'][key]=en; messages['tr'][key]=tr
messages['fa'].update({
'a11y.mainNav':'منوی اصلی','a11y.brand':'سرورنت، پیشخوان','a11y.collapse':'جمع‌کردن منو','a11y.expand':'بازکردن منوی کناری','a11y.closeMenu':'بستن منو','a11y.openMenu':'بازکردن منو','a11y.search':'جستجو در کنسول','a11y.theme':'تغییر تم روشن و تاریک','a11y.notifications':'اعلان‌ها','a11y.profile':'حساب کاربری','a11y.manageLinux':'مدیریت هاست لینوکس','a11y.manageCloud':'مدیریت سرور ابری','a11y.manageWindows':'مدیریت هاست ویندوز','a11y.filter':'فیلتر سرویس‌های هاست','a11y.hostingSearch':'جستجوی سرویس هاست','hosting.search':'جستجوی دامنه یا پلن…','a11y.detailSections':'بخش‌های مدیریت هاست','a11y.copyDomain':'کپی دامنه','a11y.copyNameservers':'کپی هر دو نیم‌سرور','a11y.copyNs1':'کپی نیم‌سرور اول','a11y.copyNs2':'کپی نیم‌سرور دوم','a11y.copyIp':'کپی نشانی IP','a11y.copyUsername':'کپی نام کاربری','a11y.bottomNav':'ناوبری سریع','a11y.close':'بستن',
'page.title':'پیش‌نمایش پنل سرورنت','date.november18':'۲۷ آبان ۱۴۰۵','stats.invoiceNote':'مهلت پرداخت تا ۲۷ آبان','todo.invoiceNote':'۴۸۰٬۰۰۰ تومان · تا ۲۷ آبان','renewal.due':'۲۷ آبان ۱۴۰۵ · ۱۵ روز دیگر',
'feedback.copied':'در حافظه کپی شد','feedback.copyFailed':'این مقدار را انتخاب و دستی کپی کنید:','feedback.checking':'شبیه‌سازی بررسی اتصال…','feedback.dnsPending':'نتیجهٔ نمایشی: اتصال همچنان نیازمند تنظیم است. استعلام واقعی انجام نشد.','feedback.dnsConnected':'نتیجهٔ نمایشی: DNS متصل است. استعلام واقعی انجام نشد.','feedback.ready':'فقط پیش‌نمایش؛ عملیاتی روی سرویس واقعی انجام نشد.',
'dns.connected':'متصل · نمونه','dns.connectedTitle':'اتصال نیم‌سرورها · نمونه','dns.connectedDescription':'این سرویس وضعیت متصلِ ساختگی را نمایش می‌دهد. دامنهٔ رزروشدهٔ example نتیجهٔ استعلام واقعی نیست.','resource.available':'گیگابایت فضای آزاد',
'modal.nextPhase':'طراحی این بخش در مرحلهٔ بعد انجام می‌شود. این پیش‌نمایش، پیشخوان و تجربهٔ مدیریت هاست را پوشش می‌دهد.','modal.panel':'در کنسول واقعی، کنترل‌پنل اختصاص‌یافته به سرویس از این بخش باز می‌شود. این پیش‌نمایش هیچ اتصال خارجی ایجاد نمی‌کند.','modal.password':'در این پیش‌نمایش از اطلاعات ورود واقعی استفاده نمی‌شود و رمز عبوری نمایش داده نمی‌شود.','modal.support':'در کنسول واقعی، درخواست مرتبط با این سرویس باز می‌شود. از این پیش‌نمایش تیکتی ارسال نمی‌شود.'})
assert set(messages['fa'])==set(messages['en'])==set(messages['tr']), {lang:sorted(set(messages['fa'])^set(vals)) for lang,vals in messages.items()}
(ROOT/'locales.js').write_text('/* Offline preview copy. Generated by build-locales.py. */\nwindow.SN_PREVIEW_MESSAGES = '+json.dumps(messages,ensure_ascii=False,indent=2)+';\n')
print('Translation keys:',len(messages['fa']))
