FROM dunglas/frankenphp:latest-php8.2

RUN docker-php-ext-install mysqli pdo_mysql

WORKDIR /app

COPY . .

ENV SERVER_NAME=:${PORT:-8080}

EXPOSE 8080


CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
