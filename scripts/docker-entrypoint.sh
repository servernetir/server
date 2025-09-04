#!/bin/bash
# منتظر می‌مونه تا MySQL آماده بشه
/scripts/wait-for-it.sh db:3306 --timeout=30 --strict -- echo "MySQL is up"

# سپس migrate لاراول اجرا می‌شه
cd /var/www/html/app
php artisan migrate

# در نهایت وارد شل پیش‌فرض کانتینر می‌شه
exec "$@"