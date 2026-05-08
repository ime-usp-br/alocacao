
<br />
<div align="center">
  <a href="https://monitoria.ime.usp.br">
    <img src="logo_ime_vert.jpg" alt="Logo" width="150" height="150">
  </a>

  <h3 align="center">Sistema de Alocação</h3>

</div>


## Sobre o Projeto

Sistema para distribuição das disciplinas nas salas do IME. 

<br />

## Implementação

### Docker (recomendado)

Clone o repositório

    git clone https://github.com/ime-usp-br/alocacao.git
    cd alocacao

Copie o arquivo de configuração

    cp .env.example .env

Edite o `.env` e ajuste ao menos as seguintes variáveis:

- `APP_URL` — URL da aplicação, **incluindo a porta obrigatoriamente** (ex: `http://localhost:8000` se `DOCKER_APP_PORT=8000`)
- `DOCKER_APP_PORT` — porta exposta no host (default: `8080`)
- `DOCKER_DB_PORT` — porta do MySQL no host (default: `3307`)
- Variáveis do banco de dados (`DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`)
- Variáveis do <a href="https://github.com/uspdev/senhaunica-socialite">senhaunica-socialite</a>
- Variáveis do <a href="https://github.com/uspdev/replicado">replicado</a>

Suba os containers

    docker compose up -d --build

O comando acima sobe automaticamente:
- **app** — PHP 7.4-FPM + Composer + Node
- **nginx** — servidor web
- **mysql:5.7** — banco de dados
- **redis** — cache e filas
- **worker** — queue worker (`php artisan queue:work`)

A aplicação estará disponível em `http://localhost:${DOCKER_APP_PORT}`.

Comandos úteis

    # Ver logs
    docker compose logs -f app

    # Acessar o container da aplicação
    docker compose exec app bash

    # Rodar comandos artisan (sempre como www-data para evitar Permission denied)
    docker compose exec -u www-data app php artisan migrate
    # NUNCA rode queue:work sem -u www-data — isso cria arquivos de cache como root
    # O worker agora é um serviço separado no docker-compose.yml

    # Ou use o script utilitário incluído no projeto:
    ./docker/exec-as-www-data.sh php artisan cache:clear
    ./docker/exec-as-www-data.sh php artisan migrate

    # Parar tudo
    docker compose down

    # Reconstruir imagem (após alterar o Dockerfile)
    docker compose up -d --build

### Instalação manual (sem Docker)

Clone o repositório

    git clone https://github.com/ime-usp-br/alocacao.git

Instale as dependências

    composer install

Restaure o arquivo de configuração

    cp .env.example .env

Além de configurar o banco de dados e o serviço de e-mail, você precisara configurar <a href="https://github.com/uspdev/senhaunica-socialite">senhaunica-socialite</a>

    # SENHAUNICA-SOCIALITE ######################################
    # https://github.com/uspdev/senhaunica-socialite
    SENHAUNICA_KEY=
    SENHAUNICA_SECRET=
    SENHAUNICA_CALLBACK_ID=

    # URL do servidor oauth no ambiente de dev (default: no)
    #SENHAUNICA_DEV="https://dev.uspdigital.usp.br/wsusuario/oauth"

    # URL do servidor oauth para uso com senhaunica-faker
    #SENHAUNICA_DEV="http://127.0.0.1:3141/wsusuario/oauth"

    # Esses usuários terão privilégios especiais
    #SENHAUNICA_ADMINS=11111,22222,33333
    #SENHAUNICA_GERENTES=4444,5555,6666

    # Se os logins forem limitados a usuários cadastrados (onlyLocalUsers=true),
    # pode ser útil cadastrá-los aqui.
    #SENHAUNICA_USERS=777,888

    # Se true, os privilégios especiais serão revogados ao remover da lista (default: false)
    #SENHAUNICA_DROP_PERMISSIONS=true

    # Habilite para salvar o retorno em storage/app/debug/oauth/ (default: false)
    #SENHAUNICA_DEBUG=true

    # SENHAUNICA-SOCIALITE ######################################

Configure as variaveis do <a href="https://github.com/uspdev/replicado">replicado</a>

    REPLICADO_HOST=
    REPLICADO_PORT=
    REPLICADO_DATABASE=
    REPLICADO_USERNAME=
    REPLICADO_PASSWORD=
    REPLICADO_SYBASE=

Gere uma nova chave

    php artisan key:generate

Crie as tabelas do banco de dados

    php artisan migrate --seed

Instale o supervisor

    apt install supervisor

Copie o arquivo de configuração do supervisor, lembre-se de alterar o diretório do projeto

    cp supervisor.conf.example /etc/supervisor/conf.d/laravel-worker.conf

Indique ao supervisor que há um novo arquivo de configuração

    supervisorctl reread
    supervisorctl update

Instale os pacotes LaTeX para gerar os relatórios

    sudo apt install texlive texlive-latex-extra texlive-lang-portuguese

## Troubleshooting Docker

### Worker reiniciando em loop na primeira subida

Na primeira subida (`docker compose up -d --build`), o container `worker` pode demorar alguns segundos para iniciar porque o `app` ainda está instalando dependências (`composer install`). O entrypoint do worker aguarda automaticamente até que `vendor/autoload.php` e o MySQL estejam prontos.

**Se o worker não iniciar sozinho após 1 minuto, reinicie manualmente:**

```bash
docker compose restart worker
```

Ou use o script utilitário para reiniciar as filas:
```bash
./docker/exec-as-www-data.sh php artisan queue:restart
```

---

### Erro no `npm run dev`: Invalid options object. Progress Plugin

Se o build de assets falhar com erro do `ProgressPlugin`, isso é causado por incompatibilidade entre o `webpack` instalado e o `laravel-mix@6.0.49`.

**Solução rápida dentro do container:**
```bash
docker compose exec app npm install webpack@5.76.0 --save-dev
docker compose exec app npm run dev
```

O `package.json` já inclui `webpack@^5.76.0` como dependência fixa para evitar esse problema em novas instalações.

---

### Permission denied em `storage/framework/cache`

Se o Laravel retornar erros como:

```
file_put_contents(/var/www/storage/framework/cache/data/...): failed to open stream: Permission denied
```

**Causa mais comum:** o **queue worker** (`php artisan queue:work`) foi iniciado como `root` (via `docker exec` sem `-u www-data` ou via `docker compose exec` sem `-u`). Quando o worker processa jobs que escrevem no cache, cria arquivos como `root`. Depois, o php-fpm (que roda como `www-data`) não consegue sobrescrever esses arquivos quando os webhooks do solver chegam.

Outra causa: qualquer comando `docker exec` ou `docker compose exec` rodado sem `-u www-data` que toque no cache/storage.

**Solução definitiva já aplicada:**
1. O `entrypoint.sh` agora cria diretórios, ajusta permissões **antes** de rodar qualquer comando e executa todos os comandos `artisan` como `www-data` (via `gosu`).
2. O Dockerfile inclui `gosu` para garantir que comandos internos rodem com o usuário correto.
3. Novos arquivos de cache são criados como `www-data`, evitando conflitos com o php-fpm.

> **Nota:** se quiser usar Redis para cache no ambiente local, basta alterar `CACHE_DRIVER=file` para `CACHE_DRIVER=redis` no `.env`. O `docker-compose.yml` já sobe um container Redis pronto para uso, mas isso é opcional.

**Se o problema persistir** (por exemplo, após rodar comandos manualmente como root):

```bash
# 1. Identifique e mate qualquer queue worker rodando como root
docker exec alocacao-app sh -c "for pid in /proc/[0-9]*/cmdline; do if grep -q 'queue:work' \$pid 2>/dev/null; then kill \$(echo \$pid | cut -d/ -f3); fi; done"

# 2. Limpe o cache e corrija permissões (como root, pois os arquivos são root)
docker exec alocacao-app sh -c "rm -rf /var/www/storage/framework/cache/data/*"
docker exec alocacao-app chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

# 3. Reinicie o worker como www-data (agora o docker-compose.yml já tem o serviço worker)
docker compose up -d worker
```

**Regra de ouro:** sempre execute comandos artisan dentro do container como `www-data`:

```bash
docker compose exec -u www-data app php artisan <comando>
# ou
./docker/exec-as-www-data.sh php artisan <comando>
```

## Configuração da API Salas

O sistema utiliza a **API Salas** para gerenciamento moderno de reservas, substituindo a escrita direta no banco Urano. A configuração é realizada através de variáveis de ambiente no arquivo `.env`.

### Variáveis Obrigatórias

```bash
# URL da API Salas (produção)
SALAS_API_URL=https://salas.ime.usp.br

# Credenciais de acesso (usuário dedicado recomendado)
SALAS_API_EMAIL=seu-email@ime.usp.br
SALAS_API_PASSWORD=sua-senha-segura

# Ativação da integração
SALAS_USE_API=true
```

### Estratégia de Tratamento de Erros

**⚠️ Importante**: O sistema implementa **erro explícito** quando a API Salas está indisponível:

```bash
# Estratégia recomendada (AC5)
SALAS_FALLBACK_TO_URANO=false
```

**Comportamento:**
- ✅ **API Disponível**: Reservas processadas normalmente via API Salas
- ❌ **API Indisponível**: Sistema informa erro explícito ao usuário e falha a operação
- 📝 **Logs**: Todos os erros são registrados para monitoramento
- 🔔 **Alertas**: Administradores são notificados via logs (configurar email em produção)

### Configurações Avançadas

Consulte o arquivo `.env.example` para documentação detalhada de todas as configurações disponíveis:
- **Timeouts e Retry**: Configurações de resiliência
- **Rate Limiting**: Proteção contra sobrecarga
- **Cache**: Otimização de performance  
- **Circuit Breaker**: Proteção contra falhas em cascata
- **Monitoramento**: Logs e notificações

### Troubleshooting

**API Indisponível:**
- Verificar logs: `tail -f storage/logs/laravel.log | grep -i salas`
- Verificar conectividade: `curl -I https://salas.ime.usp.br`
- Validar credenciais via interface web da API Salas

**Performance:**
- Monitorar cache hit rate via logs
- Ajustar `SALAS_API_RATE_LIMIT` conforme necessário
- Verificar circuit breaker status nos logs

---

## Microserviço de Otimização (alocacao-solver)

A funcionalidade **"Distribuir Turmas"** depende de um microserviço Python separado que executa o solver de otimização matemática (OR-Tools CP-SAT).

### Configuração no `.env`

```bash
# URL do microserviço Python
ALOCACAO_SOLVER_URL=http://localhost:5000

# Token compartilhado para autenticação dos webhooks (Laravel ↔ Python)
ALOCACAO_SOLVER_API_TOKEN=seu-token-seguro

# Timeout para requisições de dispatch ao solver (segundos)
ALOCACAO_SOLVER_TIMEOUT=60
```

> **Atenção:** sem o solver configurado e em execução, o botão "Distribuir Turmas" falhará com erro de conexão.

### Subindo o solver localmente

O solver é mantido em repositório separado. Para desenvolvimento local, você pode executá-lo via Docker (se disponível) ou Python diretamente:

```bash
# Exemplo com Docker (ajuste a imagem conforme o repositório oficial)
docker run -d -p 5000:5000 -e API_TOKEN=seu-token-seguro alocacao-solver
```

Consulte a documentação do repositório `alocacao-solver` para instruções detalhadas de instalação, dependências Python e variáveis de ambiente.

---

### Ajuste obrigatório do `APP_URL`

O `.env.example` define `APP_URL=http://localhost`, mas no ambiente Docker a porta exposta deve ser incluída:

```bash
# Se DOCKER_APP_PORT=8000
APP_URL=http://localhost:8000
```

Sem a porta correta, redirecionamentos do Laravel (ex: após login) e geração de URLs absolutas falharão.
