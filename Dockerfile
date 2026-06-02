FROM php:8.3-apache

# System deps commonly needed by Laravel
RUN apt-get update \
  && apt-get install -y --no-install-recommends \
    git \
    unzip \
    libpq-dev \
    libzip-dev \
  && rm -rf /var/lib/apt/lists/*

# PHP extensions
RUN docker-php-ext-install \
  pdo \
  pdo_pgsql \
  zip

# Apache config for Laravel
RUN a2enmod rewrite headers \
  && sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
  && sed -ri 's!/var/www/!/var/www/html/public!g' /etc/apache2/apache2.conf \
  && sed -ri 's/AllowOverride\s+None/AllowOverride All/g' /etc/apache2/apache2.conf

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

# Important: composer scripts call `php artisan package:discover` which requires runtime env.
# We run scripts at container start after Render environment variables are available.
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader --no-scripts \
  && chown -R www-data:www-data storage bootstrap/cache

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENV APP_ENV=production \
  APP_DEBUG=false

ENTRYPOINT ["/entrypoint.sh"]
