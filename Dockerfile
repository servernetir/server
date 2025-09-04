# انتخاب PHP + Apache
FROM php:8.0-apache

# نصب افزونه‌های لازم برای MySQL
RUN docker-php-ext-install pdo pdo_mysql

# کپی کردن کدهای پروژه داخل کانتینر
COPY . /var/www/html

# تنظیم دسترسی‌ها
RUN chown -R www-data:www-data /var/www/html

# باز کردن پورت 80
EXPOSE 80

COPY scripts /scripts
ENTRYPOINT ["/scripts/docker-entrypoint.sh"]
CMD ["apache2-foreground"]