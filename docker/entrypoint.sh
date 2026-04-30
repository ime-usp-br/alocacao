#!/bin/sh
set -e

# Install dependencies if vendor is missing
if [ ! -d "vendor" ]; then
    echo "Installing PHP dependencies..."
    composer install --no-interaction --optimize-autoloader
fi

# Install node_modules and build assets if missing
if [ ! -d "node_modules" ]; then
    echo "Installing Node dependencies..."
    npm install
    echo "Building assets..."
    npm run dev || true
fi

# Copy .env if it does not exist
if [ ! -f ".env" ]; then
    echo "Creating .env file..."
    cp .env.example .env
fi

# Generate app key if missing
if [ -z "$(grep '^APP_KEY=' .env | cut -d '=' -f2)" ]; then
    echo "Generating application key..."
    php artisan key:generate
fi

# Run migrations
php artisan migrate --force || true

# Storage link
php artisan storage:link || true

# Fix permissions for Laravel storage and bootstrap/cache
chmod -R 775 storage bootstrap/cache || true
chown -R www-data:www-data storage bootstrap/cache || true

# Start php-fpm
exec php-fpm
