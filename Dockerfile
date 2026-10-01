# PHP 8.2 with Apache
FROM php:8.2-apache

# Enable Apache mod_rewrite (needed for .htaccess routing)
RUN a2enmod rewrite

# Install PHP extensions needed for Google Sheets API (curl, json, openssl)
RUN apt-get update && apt-get install -y \
    libssl-dev \
    curl \
    unzip \
    git \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install opcache

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy project files
COPY . .

# Install PHP dependencies (if composer.json exists)
RUN if [ -f composer.json ]; then composer install --no-dev --optimize-autoloader; fi

# Apache config: Allow .htaccess overrides
RUN echo '<Directory /var/www/html>\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/hms.conf \
    && a2enconf hms

# Fix permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Render uses port 10000 by default, but Apache default is 80
# We expose 80 and Render will map it
EXPOSE 80

# Start Apache
CMD ["apache2-foreground"]
