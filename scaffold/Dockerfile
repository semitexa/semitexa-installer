FROM phpswoole/swoole:php8.4-alpine

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN apk add --no-cache unzip \
    && docker-php-ext-install pdo pdo_mysql

RUN apk add --no-cache imagemagick \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS imagemagick-dev \
    && pecl install imagick \
    && docker-php-ext-enable imagick \
    && apk del .build-deps \
    && rm -rf /tmp/pear

WORKDIR /var/www/html

CMD ["php", "server.php"]
