FROM php:8.2-cli
 
RUN apt-get update && apt-get install -y \
    libpdo-dev \
    default-mysql-client \
    && docker-php-ext-install pdo pdo_mysql \
    && apt-get clean
 
WORKDIR /var/www/html
 
COPY . .
 
EXPOSE 8080
 
CMD ["php", "-S", "0.0.0.0:8080"]
 
