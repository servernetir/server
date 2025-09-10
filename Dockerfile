# Base image: PHP 8.2 with Apache HTTP server
FROM php:8.2-apache

# ------------------------------------------------------------------------
# Install system dependencies
# ------------------------------------------------------------------------
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    zip \
    && rm -rf /var/lib/apt/lists/*

# ------------------------------------------------------------------------
# Install required PHP extensions for Laravel and database interaction
# ------------------------------------------------------------------------
RUN docker-php-ext-install pdo pdo_mysql bcmath

# ------------------------------------------------------------------------
# Install Composer
# ------------------------------------------------------------------------
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# ------------------------------------------------------------------------
# Configure Apache to serve the Laravel application from the "public" folder
# This ensures that only the public entry point is exposed to the web server
# ------------------------------------------------------------------------
ENV APACHE_DOCUMENT_ROOT=/var/www/html/app/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf

# ------------------------------------------------------------------------
# Enable Apache modules required by Laravel
# - rewrite: enables URL rewriting (used by Laravel routing)
# - AllowOverride All: allows .htaccess to work inside the public directory
# ------------------------------------------------------------------------
RUN a2enmod rewrite \
 && printf '<Directory "${APACHE_DOCUMENT_ROOT}">\nAllowOverride All\nRequire all granted\n</Directory>\n' \
    > /etc/apache2/conf-available/laravel.conf \
 && a2enconf laravel

# ------------------------------------------------------------------------
# Copy the project source code into the container
# ------------------------------------------------------------------------
COPY . /var/www/html

# ------------------------------------------------------------------------
# Copy helper scripts (entrypoint, wait-for-it, etc.) into the container
# ------------------------------------------------------------------------
COPY scripts/ /scripts/

# ------------------------------------------------------------------------
# Ensure scripts are Unix-formatted (LF line endings) and executable
# - Converts CRLF (Windows-style) to LF
# - Adds execution permissions to all .sh files
# ------------------------------------------------------------------------
RUN set -eux; \
    if [ -d /scripts ]; then \
      find /scripts -type f -name '*.sh' -exec sed -i 's/\r$//' {} \; ; \
      find /scripts -type f -name '*.sh' -exec chmod +x {} \; ; \
    fi

# ------------------------------------------------------------------------
# Set correct ownership for project files to Apache user (www-data)
# ------------------------------------------------------------------------
RUN chown -R www-data:www-data /var/www/html

# ------------------------------------------------------------------------
# Define entrypoint and default command
# - Entrypoint: custom script (runs migrations, waits for DB, etc.)
# - CMD: start Apache in the foreground
# ------------------------------------------------------------------------
ENTRYPOINT ["/scripts/docker-entrypoint.sh"]
CMD ["apache2-foreground"]