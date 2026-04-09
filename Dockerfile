FROM dunglas/frankenphp:latest-php8.2

RUN docker-php-ext-install mysqli pdo pdo_mysql

WORKDIR /app

COPY . .

COPY Caddyfile /etc/caddy/Caddyfile

EXPOSE 8080

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
