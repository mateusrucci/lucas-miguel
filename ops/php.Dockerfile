FROM php:8.2-fpm-alpine

RUN apk add --no-cache curl-dev \
    && docker-php-ext-install curl
