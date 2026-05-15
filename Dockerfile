FROM php:8.1-apache

RUN docker-php-ext-install pdo_mysql

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

RUN a2enmod rewrite headers && a2dissite 000-default
COPY docker/apache/studentwallet.conf /etc/apache2/sites-available/
RUN a2ensite studentwallet

COPY docker/php/custom.ini /usr/local/etc/php/conf.d/

WORKDIR /var/www/html
