# ServerNet - Laravel + Docker

![GitHub last commit](https://img.shields.io/github/last-commit/servernetir/server)
![GitHub issues](https://img.shields.io/github/issues/servernetir/server)
![Docker](https://img.shields.io/badge/Docker-ready-brightgreen)
![PHP](https://img.shields.io/badge/PHP-8.0-blue)
![Laravel](https://img.shields.io/badge/Laravel-10-red)

---

## 🚀 معرفی پروژه

این مخزن شامل پروژه‌ی **Laravel** همراه با محیط **Docker** هست.  
هدف پروژه: ایجاد یک وب‌سایت فروشگاهی واسطه‌ای با اتصال به MySQL و توسعه راحت توسط تیم توسعه.

---

## 🏗️ ساختار پروژه

- `app/` → کدهای اصلی لاراول  
- `docker-compose.yml` → کانتینرهای وب و دیتابیس  
- `Dockerfile` → ساخت کانتینر وب  
- `scripts/` → اسکریپت‌های راه‌اندازی و wait-for-it  
- `.gitignore` → فایل‌ها و پوشه‌هایی که نباید push بشن  

---

## ⚡ شروع سریع برای توسعه‌دهنده‌ها

1. کلون کردن مخزن:
```bash
git clone git@github.com:servernetir/server.git
cd server
```

2. بالا آوردن کانتینرها:
```bash
docker-compose up -d --build
```

3. نصب وابستگی‌های PHP و لاراول خودکار انجام می‌شود.

4. کانفیگ `.env` لاراول برای MySQL داخل Docker به صورت پیش‌فرض:
```env
DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=servernet_db
DB_USERNAME=serveruser
DB_PASSWORD=SuperStrongPass456
```

5. اجرای migrations برای ایجاد جدول‌ها:
```bash
docker exec -it servernet-web-1 bash
cd /var/www/html/app
php artisan migrate
```

---

## 🔧 نکات مهم

- هر برنامه‌نویس روی branch خودش کار کند.  
- Volume دیتابیس (`db_data`) مستقل و امن است.  
- تغییرات در پوشه‌ی `app` مستقیم روی کانتینر منعکس می‌شوند.  
- فایل `.env` حاوی اطلاعات حساس است و نباید push شود.  

---

## 📦 اتوماسیون Docker و Laravel

- اسکریپت‌های موجود در `scripts/` شامل wait-for-it هستند تا Laravel قبل از بالا آمدن MySQL صبر کند.  
- با اجرای Docker Compose، محیط کامل توسعه آماده می‌شود و نیاز به تنظیمات دستی نیست.

---

## 📌 منابع و تماس

- مستندات Laravel: [https://laravel.com/docs](https://laravel.com/docs)  
- مستندات Docker: [https://docs.docker.com](https://docs.docker.com)  
- برای هرگونه سوال یا راهنمایی درباره‌ی Docker یا پروژه، با من در تماس باشید.
