
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
