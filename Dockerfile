FROM php:8.3-apache

LABEL maintainer="Mavoo Gestion" \
    org.label-schema.name="Mavoo Gestion Laminas MVC"

# Dependencias del sistema + extensiones PHP requeridas por Laminas (intl, zip, pdo_mysql)
RUN apt-get update && apt-get install -y \
    git \
    curl \
    unzip \
    libicu-dev \
    libzip-dev \
    zlib1g-dev \
    libmariadb-dev \
    libpq-dev \
    && docker-php-ext-configure intl \
    && docker-php-ext-install intl pdo_mysql zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Apache: mod_rewrite + DocumentRoot /var/www/public + puerto 8080 (Dokploy)
RUN a2enmod rewrite \
    && sed -i 's!/var/www/html!/var/www/public!g' /etc/apache2/sites-available/000-default.conf \
    && sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
    && sed -i 's/:80>/:8080>/' /etc/apache2/sites-available/000-default.conf \
    && sed -i 's/:80\b/:8080/g' /etc/apache2/apache2.conf \
    && mv /var/www/html /var/www/public

# Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /var/www

# Copiar composer.json/lock primero para aprovechar cache de capas de Docker:
# si solo cambia codigo fuente, composer install no se re-ejecuta.
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts

# Copiar el resto del codigo
COPY . .

# Crear cache dir de Laminas con permisos correctos y verificar instalacion
RUN mkdir -p data/cache \
    && composer dump-autoload --optimize --no-dev \
    && chmod -R 777 data/cache \
    && chmod -R 755 /var/www \
    && chown -R www-data:www-data /var/www

EXPOSE 8080

# Verificacion final del build (imprime informacion util en logs de Dokploy)
RUN echo "==== Verificacion final ====" \
    && php -v \
    && php -m | grep -E "pdo_mysql|intl|zip" \
    && echo "==== Composer's vendor ====" \
    && ls -la /var/www/vendor 2>&1 | head -5 \
    && echo "==== Apache ====" \
    && apache2 -v \
    && echo "==== DocumentRoot ====" \
    && grep -i "documentroot\|listen" /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

CMD ["apache2-foreground"]
