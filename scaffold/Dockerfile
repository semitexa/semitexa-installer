FROM phpswoole/swoole:php8.4-alpine

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN apk add --no-cache unzip \
    && docker-php-ext-install pdo pdo_mysql

WORKDIR /var/www/html

CMD ["php", "server.php"]
