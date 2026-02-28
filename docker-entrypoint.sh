#!/bin/bash
set -e

CONFIG_FILE="/var/www/html/config.php"

# Wait for DB to be truly ready (beyond healthcheck)
echo "Waiting for MariaDB..."
for i in $(seq 1 30); do
    if php -r "
        try {
            new PDO('mysql:host=${DB_HOST};port=${DB_PORT}', '${DB_USER}', '${DB_PASS}');
            exit(0);
        } catch (Exception \$e) {
            exit(1);
        }
    " 2>/dev/null; then
        echo "MariaDB is ready."
        break
    fi
    sleep 1
done

# Generate config.php if missing
if [ ! -f "$CONFIG_FILE" ]; then
    echo "Generating config.php..."
    SECRET_KEY=$(php -r 'echo bin2hex(random_bytes(32));')

    # Determine IMAP/SMTP settings from environment (Mailpit in dev, or custom)
    IMAP_ENABLED_VAL="${IMAP_ENABLED:-false}"
    IMAP_HOST_VAL="${IMAP_HOST:-}"
    IMAP_PORT_VAL="${IMAP_PORT:-993}"
    IMAP_ENC_VAL="${IMAP_ENCRYPTION:-ssl}"
    IMAP_USER_VAL="${IMAP_USERNAME:-}"
    IMAP_PASS_VAL="${IMAP_PASSWORD:-}"

    cat > "$CONFIG_FILE" <<PHPEOF
<?php
define('DB_HOST', '${DB_HOST:-db}');
define('DB_PORT', '${DB_PORT:-3306}');
define('DB_NAME', '${DB_NAME:-foxdesk}');
define('DB_USER', '${DB_USER:-foxdesk}');
define('DB_PASS', '${DB_PASS:-foxdesk123}');

define('SECRET_KEY', '${SECRET_KEY}');

define('APP_NAME', 'FoxDesk');
define('APP_URL', '${APP_URL:-http://localhost:8888}');

define('IMAP_ENABLED', ${IMAP_ENABLED_VAL});
define('IMAP_HOST', '${IMAP_HOST_VAL}');
define('IMAP_PORT', ${IMAP_PORT_VAL});
define('IMAP_ENCRYPTION', '${IMAP_ENC_VAL}');
define('IMAP_VALIDATE_CERT', false);
define('IMAP_USERNAME', '${IMAP_USER_VAL}');
define('IMAP_PASSWORD', '${IMAP_PASS_VAL}');
define('IMAP_FOLDER', 'INBOX');
define('IMAP_PROCESSED_FOLDER', 'Processed');
define('IMAP_FAILED_FOLDER', 'Failed');
define('IMAP_MAX_EMAILS_PER_RUN', 50);
define('IMAP_MAX_ATTACHMENT_SIZE', 10 * 1024 * 1024);
define('IMAP_DENY_EXTENSIONS', 'php,phtml,php3,php4,php5,phar,exe,bat,cmd,js,vbs,ps1,sh');
define('IMAP_STORAGE_BASE', 'storage/tickets');
define('IMAP_MARK_SEEN_ON_SKIP', true);
define('IMAP_ALLOW_UNKNOWN_SENDERS', false);

define('UPLOAD_DIR', 'uploads/');
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024);

date_default_timezone_set('Europe/Prague');
PHPEOF
    chown www-data:www-data "$CONFIG_FILE"

    # Run schema + seed
    echo "Running database setup..."
    php /var/www/html/docker-setup.php

    # Configure SMTP/IMAP/seed via a single PHP script
    php /var/www/html/bin/docker-post-setup.php

    echo "FoxDesk installed successfully!"
else
    echo "config.php exists, skipping installation."
fi

# Ensure permissions
chown -R www-data:www-data /var/www/html/uploads /var/www/html/backups /var/www/html/storage 2>/dev/null || true

# Pass control to Apache
apache2-foreground
