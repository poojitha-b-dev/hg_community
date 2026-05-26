FROM php:8.4-cli

# Install pdo_mysql extension (built-in installer, always works)
RUN docker-php-ext-install pdo pdo_mysql mysqli

WORKDIR /app
COPY . .

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080"]
