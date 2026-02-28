FROM php:8.3-apache-bookworm

# Install PHP extensions needed by FoxDesk
RUN apt-get update && apt-get install -y \
        libicu-dev \
        libzip-dev \
        libc-client-dev \
        libkrb5-dev \
        unzip \
    && docker-php-ext-configure imap --with-kerberos --with-imap-ssl \
    && docker-php-ext-install \
        pdo_mysql \
        intl \
        zip \
        imap \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# PHP config
RUN echo "upload_max_filesize = 20M\npost_max_size = 25M\nmax_execution_time = 120" \
    > /usr/local/etc/php/conf.d/foxdesk.ini

# Apache config — allow .htaccess
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Copy application
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && mkdir -p /var/www/html/uploads /var/www/html/backups /var/www/html/storage \
    && chown -R www-data:www-data /var/www/html/uploads /var/www/html/backups /var/www/html/storage

# Entrypoint for auto-configuration
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
