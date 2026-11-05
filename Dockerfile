FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev libonig-dev libxml2-dev \
    && docker-php-ext-install -j"$(nproc)" curl mbstring pdo_mysql simplexml \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

COPY render-entrypoint.sh /usr/local/bin/render-entrypoint
ENTRYPOINT ["bash", "/usr/local/bin/render-entrypoint"]
