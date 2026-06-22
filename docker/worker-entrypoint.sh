#!/bin/sh
set -e

# -----------------------------------------------------------------------------
# Worker Entrypoint
# Aguarda o app terminar de instalar dependências (vendor/autoload.php)
# e o MySQL ficar pronto antes de iniciar o queue:work.
# Isso evita crash loops na primeira subida do projeto.
# -----------------------------------------------------------------------------

LARAVEL_USER="www-data"
LARAVEL_GROUP="www-data"

# Aguarda vendor/autoload.php existir (app pode ainda estar no composer install)
echo "[worker] Aguardando vendor/autoload.php..."
until [ -f "vendor/autoload.php" ]; do
    sleep 2
done
echo "[worker] vendor encontrado."

# Aguarda MySQL estar acessível antes de iniciar o worker.
# Usa DB_HOST do ambiente (default: mysql) para conectar via PDO.
echo "[worker] Aguardando MySQL..."
until php -r "new PDO('mysql:host=${DB_HOST:-mysql};port=${DB_PORT:-3306}', '${DB_USERNAME:-laravel}', '${DB_PASSWORD:-root}');" 2>/dev/null; do
    sleep 2
done
echo "[worker] MySQL pronto. Iniciando queue:listen..."

# Usa queue:listen em vez de queue:work para desenvolvimento: o listen
# relê o código a cada job, então mudanças nos arquivos PHP entram em
# vigor sem precisar reiniciar o container.
exec gosu "${LARAVEL_USER}" php artisan queue:listen --tries=3 --sleep=3
