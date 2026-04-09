FROM dunglas/frankenphp:latest-php8.2

RUN docker-php-ext-install mysqli pdo_mysql

COPY . /app/public
