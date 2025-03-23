FROM php:8.4.5-apache

RUN a2enmod rewrite
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

COPY public/ /var/www/html/
COPY vendor/ /var/www/vendor/
COPY config/ /var/www/config/
COPY src/ /var/www/src/

RUN apt-get update && apt-get install -y libpq-dev postgresql-client \
    && docker-php-ext-install pdo_pgsql \
    && docker-php-ext-enable pdo_pgsql

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

USER www-data
