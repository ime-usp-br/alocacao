
<br />
<div align="center">
  <a href="https://monitoria.ime.usp.br">
    <img src="logo_ime_vert.jpg" alt="Logo" width="150" height="150">
  </a>

  <h3 align="center">Sistema da Alocação</h3>

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
