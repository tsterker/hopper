FROM php:7.4-cli

RUN apt update && \
    apt -qy install git unzip libzip-dev && \
    docker-php-ext-install sockets pcntl zip
