FROM php:8.4-cli

# Install pdo_mysql extension
RUN docker-php-ext-install pdo pdo_mysql mysqli

WORKDIR /app
COPY . .

EXPOSE 8080

# Use shell form (not exec form) so $PORT gets expanded by the shell
CMD php -S 0.0.0.0:$PORT
