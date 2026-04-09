FROM dunglas/frankenphp:latest-php8.2

RUN docker-php-ext-install mysqli pdo_mysql

COPY . /app/public

ENV SERVER_NAME=:${PORT:-8080}

EXPOSE 8080
