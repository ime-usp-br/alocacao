#!/bin/bash
# -----------------------------------------------------------------------------
# Script utilitário: executa comandos dentro do container Laravel como www-data
#
# USO:
#   ./docker/exec-as-www-data.sh php artisan cache:clear
#   ./docker/exec-as-www-data.sh php artisan migrate
#   ./docker/exec-as-www-data.sh php artisan queue:work
#   ./docker/exec-as-www-data.sh bash
#
# POR QUE:
#   O container alocacao-app roda php-fpm como www-data. Se você usar
#   "docker exec alocacao-app php artisan ..." diretamente, o comando roda
#   como root e cria arquivos em storage/ com owner root:root. Isso causa
#   "Permission denied" quando o Laravel (www-data) tenta escrever depois.
#
#   Este script garante que todos os comandos artisan rodem como www-data,
#   prevenindo o problema de permissões de forma definitiva.
# -----------------------------------------------------------------------------

CONTAINER_NAME="alocacao-app"

if [ $# -eq 0 ]; then
    echo "Erro: nenhum comando fornecido."
    echo "Uso: $0 <comando> [args...]"
    echo "Exemplo: $0 php artisan cache:clear"
    exit 1
fi

# Check if container is running
if ! docker ps --format '{{.Names}}' | grep -q "^${CONTAINER_NAME}$"; then
    echo "Erro: container '${CONTAINER_NAME}' não está rodando."
    echo "Inicie os containers com: docker compose up -d"
    exit 1
fi

# Execute command as www-data inside the container
docker exec -u www-data "${CONTAINER_NAME}" "$@"
