#!/bin/sh
set -e

# -----------------------------------------------------------------------------
# Laravel Docker Entrypoint
# Resolve definitivamente problemas de permissão entre root, www-data e host.
# -----------------------------------------------------------------------------

LARAVEL_USER="www-data"
LARAVEL_GROUP="www-data"

# Detecta o UID/GID do host usando um arquivo imutável (mantém owner original)
HOST_UID=$(stat -c '%u' composer.json)
HOST_GID=$(stat -c '%g' composer.json)

# Ensure storage directories exist with correct ownership before ANY command runs
mkdir -p storage/framework/cache/data
mkdir -p storage/framework/sessions
mkdir -p storage/framework/views
mkdir -p storage/framework/testing
mkdir -p storage/logs
mkdir -p storage/app/public
mkdir -p bootstrap/cache

# Fix ownership and permissions BEFORE running artisan commands.
# This prevents root-owned files from being created in directories
# that php-fpm (running as www-data) needs to write to.
chown -R "${LARAVEL_USER}:${LARAVEL_GROUP}" storage bootstrap/cache
chmod -R u+rwx storage bootstrap/cache

# If cache driver is file, ensure existing cache files are not owned by root.
# This handles the case where previous docker exec commands created cache as root.
if [ -d "storage/framework/cache/data" ]; then
    find storage/framework/cache/data -user root -exec chown "${LARAVEL_USER}:${LARAVEL_GROUP}" {} + 2>/dev/null || true
fi

# Install dependencies if vendor is missing (run as root to avoid permission issues with composer cache)
if [ ! -d "vendor" ]; then
    echo "Installing PHP dependencies..."
    composer install --no-interaction --optimize-autoloader
fi

# Install node_modules and build assets if missing
if [ ! -d "node_modules" ]; then
    echo "Installing Node dependencies..."
    npm install
    echo "Building assets..."
    # npm run dev pode falhar por causa do cache npm em /root; usamos npx mix como fallback
    npm run dev 2>/dev/null || PATH="$PATH:/var/www/node_modules/.bin" npx mix || true
fi

# Copy .env if it does not exist
if [ ! -f ".env" ]; then
    echo "Creating .env file..."
    cp .env.example .env
fi

# Ensure .env is writable by the Laravel user before any artisan command that modifies it
chown "${HOST_UID}:${LARAVEL_GROUP}" .env 2>/dev/null || true
chmod u+rwx,g+rw .env 2>/dev/null || true

# Generate app key if missing (run as www-data so .env remains writable by php-fpm)
if [ -z "$(grep '^APP_KEY=' .env | cut -d '=' -f2)" ]; then
    echo "Generating application key..."
    gosu "${LARAVEL_USER}" php artisan key:generate
fi

# Run migrations as www-data so any created files (e.g. SQLite) are not root-owned
echo "Running migrations..."
gosu "${LARAVEL_USER}" php artisan migrate --force || true

# Ensure public directory is writable by www-data for storage:link
chown "${HOST_UID}:${LARAVEL_GROUP}" public 2>/dev/null || true
chmod u+rwx,g+rwxs public 2>/dev/null || true

# Storage link as www-data
gosu "${LARAVEL_USER}" php artisan storage:link || true

# Clear and rebuild cache as www-data so cache files are owned by www-data, not root.
# This is critical when CACHE_DRIVER=file. Even with Redis, it is good hygiene.
echo "Clearing caches to ensure correct ownership..."
gosu "${LARAVEL_USER}" php artisan cache:clear || true
gosu "${LARAVEL_USER}" php artisan config:clear || true
gosu "${LARAVEL_USER}" php artisan view:clear || true

# Final permission fix in case any command above created files
chown -R "${LARAVEL_USER}:${LARAVEL_GROUP}" storage bootstrap/cache
chmod -R u+rwx storage bootstrap/cache

# Restore ownership of vendor/node_modules to the host user so the developer
# can edit/delete files without sudo. Also fix .env ownership.
chown -R "${HOST_UID}:${HOST_GID}" vendor node_modules .env 2>/dev/null || true
chmod -R u+rx vendor node_modules 2>/dev/null || true

# Ensure the main project directory is readable/writeable by both host user and www-data.
# We set the group to www-data and add group-write so php-fpm can operate.
chown "${HOST_UID}:${LARAVEL_GROUP}" . 2>/dev/null || true
chmod u+rwx,g+rxs . 2>/dev/null || true

# Start php-fpm
exec php-fpm
