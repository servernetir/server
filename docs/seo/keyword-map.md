# نقشه intent به URL

منبع انتخاب اولویت، ردیف‌های واقعی GSC و کاتالوگ موجود است. عبارت‌های ستون‌های زبان، brief تحریریه‌اند؛ برای آن‌ها حجم، difficulty یا رتبه رقبا ادعا نشده است. تکرار مکانیکی ترجمه برای انتشار کافی نیست.

| قصد | URL مرجع | عبارت/نیاز |
|---|---|---|
| پرداخت ساعتی | /vps/hourly، /en/vps/hourly، /tr/vps/hourly | سرور مجازی ساعتی؛ hourly VPS؛ saatlik VPS |
| اجاره GPU برنامه‌محور | /gpu و معادل هر زبان | اجاره GPU، سرور گرافیکی ساعتی، hourly GPU، saatlik GPU kiralama |
| GPU با کنترل سیستم‌عامل | /vps/gpu موجود | تفاوت محیط برنامه با VPS؛ از ادعای دسترسی یا سخت‌افزار تأییدنشده خودداری شود |
| سرور کشور | /vps/{نگاشت موجود} | «سرور مجازی + کشور»؛ country VPS؛ ülke VPS |
| سیستم‌عامل | /vps/linux و /vps/windows | سازگاری سیستم‌عامل، دسترسی و منابع واقعی |
| مقایسه دوره | /vps/hourly#hourly-calculator و جدول کشور | هزینه منابع ساعتی در برابر دوره‌ای، شرایط خاموشی/حذف |

## کاتالوگ کامل هاست موجود

| URL فارسی | فارسی | English brief | Türkçe brief | محتوای لازم |
|---|---|---|---|---|
| /hosting/wordpress | خرید هاست وردپرس | WordPress hosting | WordPress hosting | نسخه PHP، ظرفیت، منابع و بازیابی |
| /hosting/woocommerce | هاست ووکامرس | WooCommerce hosting | WooCommerce hosting | منابع فروشگاه و دیتابیس؛ معیار واقعی |
| /hosting/python | هاست پایتون | Python hosting | Python hosting satın al | runtime و مدل اجرا؛ راهنمای موجود حفظ شود |
| /hosting/download | هاست دانلود | Download hosting | Dosya hosting | فضا، ترافیک و محدودیت واقعی |
| /hosting/email | هاست ایمیل | Email hosting | E-posta hosting | quota، ارسال و راه‌اندازی دامنه |
| /hosting/backup | هاست بکاپ | Backup hosting | Yedekleme hosting | فضا، retention و آزمون restore |
| /hosting/linux | خرید هاست لینوکس | Linux PHP hosting | Linux hosting | راهنمای جدید PHP/WordPress |
| /hosting/windows | خرید هاست ویندوز | ASP.NET MSSQL Plesk hosting | Windows hosting | راهنمای جدید IIS/.NET/SSMS |
| /hosting/reseller-wordpress | نمایندگی هاست وردپرس | WordPress reseller hosting | WordPress hosting bayiliği | پنل تحویلی و quota واقعی |
| /hosting/reseller-linux | نمایندگی سی‌پنل | cPanel reseller hosting | cPanel bayi hosting | WHM و سیاست تحویل واقعی |
| /hosting/reseller-directadmin | نمایندگی دایرکت ادمین | DirectAdmin reseller hosting | DirectAdmin bayi hosting | وجود واقعی پنل/پلن پیش از تبلیغ |
| /hosting/reseller-windows | نمایندگی هاست ویندوز | Plesk reseller hosting | Plesk bayi hosting | امکانات و quota پلن نمایندگی |

هر 12 مسیر در سه زبان در CI رندر و canonical، H1، متا و JSON-LD بررسی شدند. این بررسی، صحت همه ادعاهای بازاریابی تاریخی درباره benchmark، SLA یا پنل واقعی تحویل‌داده‌شده را ثابت نمی‌کند. این ادعاها باید با پیکربندی و تحویل هر محصول تطبیق یابند.

## کشورها

[جدول 51 نگاشت و عملکرد](country-audit.md) مرجع دقیق URL است. برای آلمان، فنلاند، آمریکا، ایران، هلند و ترکیه ابتدا همان URLها تقویت می‌شوند. «ساعتی + کشور» فقط روی عرضه واجد شرایطِ همان صفحه توضیح می‌گیرد. برای شهرهایی مثل استانبول/نیویورک، مقصد همان کشور با شهر واقعی در جدول است؛ صفحه باریک مستقل بدون تمایز محصول ساخته نمی‌شود.

محتوای راهنما به صفحه تجاری مناسب لینک می‌دهد و صفحه تجاری به راهنمای منتشرشده و مرتبط. مقصدِ نهاییِ خرید از قیمت و ظرفیت جاری می‌آید؛ از ایجاد لینک ثابت به پلن حذف‌شده خودداری شود.
