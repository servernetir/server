#!/bin/bash
# منتظر می‌مونه تا MySQL آماده بشه
/scripts/wait-for-it.sh db:3306 --timeout=30 --strict -- echo "MySQL is up"

# Install Laravel dependencies
cd /var/www/html/app
composer install --no-interaction --optimize-autoloader

# Generate application key if not exists
if [ ! -f .env ]; then
    cp .env.example .env
fi
php artisan key:generate

# سپس migrate لاراول اجرا می‌شه
php artisan migrate --force

# در نهایت وارد شل پیش‌فرض کانتینر می‌شه
exec "$@"