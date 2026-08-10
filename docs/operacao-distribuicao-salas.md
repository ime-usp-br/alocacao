# Operação do Sistema de Alocação de Salas

Documentação operacional, do início ao fim, do processo de distribuição de salas do IME. Baseada no código-fonte (`app/`, `config/`, `database/migrations/`, `resources/views/`) e em inspeção do banco local. Ao longo do texto, itens marcados como **[confirmado]** foram verificados no código/banco; itens **[a confirmar]** dependem de validação no ambiente real de produção e não devem ser tratados como certos.

Valores entre `<chaves>` são placeholders que dependem do ambiente e precisam ser adaptados.

---

## Pré-requisitos

1. **Docker** com `docker compose` instalado no host. **[confirmado]** (o projeto possui `docker-compose.yml` e `Dockerfile`).
2. **Arquivo `.env`** configurado a partir de `.env.example`. Variáveis mínimas: **[confirmado]**
   - `APP_URL` — deve incluir a porta (ex.: `http://localhost:8000`). Sem a porta, os redirecionamentos do Laravel quebram.
   - `DOCKER_APP_PORT` (default `8080`) e `DOCKER_DB_PORT` (default `3307`).
   - Banco de dados (`DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`).
   - `senhaunica-socialite` (`SENHAUNICA_KEY`, `SENHAUNICA_SECRET`, `SENHAUNICA_CALLBACK_ID`).
   - `replicado` (`REPLICADO_HOST`, `REPLICADO_PORT`, `REPLICADO_DATABASE`, `REPLICADO_USERNAME`, `REPLICADO_PASSWORD`, `REPLICADO_SYBASE`).
   - `UNIDADE` — sigla da unidade usada nas consultas ao Replicado (referenciada como `env("UNIDADE")`). **[a confirmar]** (não encontrei o valor no `.env` local).
3. **Microserviço `alocacao-solver`** (Python/OR-Tools) em execução e alcançável pela URL de `ALOCACAO_SOLVER_URL`. **[confirmado]** — sem ele, o botão "Distribuir Turmas" falha no *health check* e não dispara o solver (existe fallback para a distribuição legada).
4. **Acesso ao banco do Replicado** com usuário de leitura, para importação de turmas e atualização de inscritos (`estmtr`). **[confirmado]**
5. **Usuário com papel `Administrador` ou `Operador`** — necessário para criar/editar semestres, importar, distribuir e reservar. Perfil `Observador` é somente leitura. **[confirmado]**
6. **API Salas** (opcional): se `SALAS_USE_API=true`, a reserva de salas passa a usar a API Salas com credenciais (`SALAS_API_EMAIL`, `SALAS_API_PASSWORD`); caso contrário, usa o fluxo legado (escrita no banco do Urano). **[confirmado]** no `.env` local `SALAS_USE_API=false`.
7. **Pacotes LaTeX** (`texlive`, `texlive-latex-extra`, `texlive-lang-portuguese`) apenas para gerar relatórios. **[confirmado]** no README.

---

## Subindo o Docker

1. Clone o repositório e entre na pasta.
2. Copie a configuração:

   ```bash
   cp .env.example .env
   ```

3. Edite o `.env` conforme o item 2 de **Pré-requisitos**. **[confirmado]**
4. Suba os containers:

   ```bash
   docker compose up -d --build
   ```

   O comando sobe: `app` (PHP-FPM), `nginx`, `mysql:5.7`, `redis` e `worker` (queue worker). **[confirmado]**

5. A aplicação fica em `http://localhost:${DOCKER_APP_PORT}`. **[confirmado]** — no `.env` local, `DOCKER_APP_PORT=8000` e `APP_URL=http://192.168.33.120:8000`. **[a confirmar]** para produção.

6. Comandos úteis (sempre como `www-data` para evitar erros de permissão): **[confirmado]**

   ```bash
   # Logs
   docker compose logs -f app

   # Acessar o container
   docker compose exec app bash

   # Rodar comandos artisan
   ./docker/exec-as-www-data.sh php artisan migrate
   ./docker/exec-as-www-data.sh php artisan cache:clear

   # Reiniciar as filas
   ./docker/exec-as-www-data.sh php artisan queue:restart

   # Parar tudo
   docker compose down
   ```

> **Regra de ouro:** nunca rode `queue:work` nem comandos artisan que toquem `storage/` sem `-u www-data`. O worker já é um serviço separado no `docker-compose.yml`. **[confirmado]**

---

## Análise do banco

### Visão geral

Banco MySQL `laravel` (default). Último semestre com dados no banco local: **[confirmado]**

| id | year | period | dtamaxres |
|----|------|--------|-----------|
| 8  | 2026 | 1° Semestre | 2026-07-04 |
| 9  | 2026 | 2° Semestre | NULL |

O semestre "atual" é definido por `SchoolTerm::getLatest()`, que retorna o maior `year` e, dentro dele, o maior `period`. **[confirmado]** — portanto o 2026, 2° Semestre (id 9) é o semestre ativo em construção; o 2026, 1° (id 8) é o último semestre concluído com `dtamaxres`.

### Tabelas principais

| Tabela | Papel no fluxo | Colunas relevantes **[confirmado]** |
|--------|----------------|--------------------------------------|
| `school_terms` | Semestres letivos | `id`, `year`, `period`, `dtamaxres` |
| `school_classes` | Turmas | `id`, `codtur`, `coddis`, `numseqdis`, `numofe`, `tiptur`, `nomdis`, `estmtr`, `numvag`, `externa`, `dtainitur`, `dtafimtur`, `historical_avg_applied_at`, `historical_avg_metadata`, `school_term_id`, `room_id`, `fusion_id` |
| `rooms` | Salas | `id`, `nome`, `assentos` |
| `class_schedules` | Horários | `id`, `diasmnocp`, `horent`, `horsai` |
| `class_schedule_school_class` | Pivot turma↔horário | `class_schedule_id`, `school_class_id` |
| `instructors` + `instructor_school_class` | Docentes e pivot | `nome`, `numpes`, ... |
| `course_informations` + `course_information_school_class` | Info. de curso (semestre obrigatório) | `coddis`, `numsemidl`, `tipobg` |
| `courses` | Cursos (sufixos de turma) | `sufixo_codtur` |
| `fusions` | "Dobradinhas" | `id`, `master_id` |
| `priorities` | Prioridades | `id`, ... |
| `special_offers`, `observations` | Ofertas especiais e observações | ... |
| `allocation_states` | Estados de alocação (snapshots) | `id`, `school_term_id`, `name`, `allocations` (JSON), `solver_log_id` |
| `solver_logs` | Logs de chamadas ao solver | `id`, `school_term_id`, `job_id`, `payload`, `response`, `status`, `allocations_count`, `unassigned_count`, `manual_count`, `dispatched_at`, `responded_at` |
| `comparison_reports` | Comparação solver vs legado | `id`, `school_term_id`, `base_allocation_state_id`, `status`, `legacy_metrics`, `solver_metrics`, `legacy_raw_allocations`, `solver_raw_allocations` |

### Relacionamentos **[confirmado]**

- `school_term` **1:N** `school_class` (`school_terms.id` → `school_classes.school_term_id`).
- `school_class` **N:1** `room` (`school_classes.room_id` → `rooms.id`). Turmas externas normalmente não têm sala.
- `school_class` **N:1** `fusion` (`school_classes.fusion_id` → `fusions.id`); a "dobradinha" tem um `master_id` que referencia a turma principal e é quem recebe a sala.
- `school_class` **N:M** `class_schedule`, **N:M** `instructor`, **N:M** `course_information`.
- `allocation_state` **N:1** `school_term` e **N:1** `solver_log`.
- `comparison_report` **N:1** `school_term` e **N:1** `allocation_state` (estado base).

### Observações da análise local

- No banco local, o semestre 8 (2026, 1°) não possui registros em `solver_logs`, `allocation_states` nem `comparison_reports`; das 206 turmas internas, 205 estão sem sala. **[confirmado]** — sugere que este é um banco de desenvolvimento/seed, não a base de produção completa. A análise estrutural acima é o que importa para operação; os números não devem ser tratados como produção.

---

## Processo de distribuição

O fluxo, do início ao fim, é o seguinte (cada passo indica o ponto de código correspondente).

### 1. Criar o semestre letivo **[confirmado]**

Acesse **Configurações → Períodos Letivos** e cadastre ano, período e a **data final de reserva** (`dtamaxres`).

- `dtamaxres` é **obrigatório** (`StoreSchoolTermRequest` / `UpdateSchoolTermRequest`, formato `d/m/Y`). **[confirmado]**
- `dtamaxres` define a **data final das reservas no Urano** na última etapa: é usada como `data_fim` das reservas em `app/Models/Requisition.php:83`, `app/Models/Reservation.php:33`, `app/Services/ReservationMapper.php:338` e `app/Services/ReservationApiService.php:407`. **[confirmado]**

### 2. Importar turmas do Replicado **[confirmado]**

Acesse **Configurações → Turmas Internas** e clique em **"Importar do Jupiter"** (rota `schoolclasses/import`). O método `SchoolClass::getFromReplicadoBySchoolTerm()` consulta o Replicado e importa:

- turmas de **Graduação** do IME (pela sigla `UNIDADE`);
- turmas **externas** de outras unidades (pelos sufixos de turma de `CourseInformation::$codtur_by_course`);
- turmas de **Pós-Graduação** (pela área da unidade, `codare`).

A importação roda em fila (job `ProcessImportSchoolClasses` / comando espelho `alocacao:update`). **[confirmado]**

> **Recomendação do próprio sistema** (view de instruções): verificar no calendário escolar se as informações já estão disponíveis antes de importar. **[confirmado]**

### 3. Verificar as dobradinhas (fusões) **[confirmado]**

Acesse **Configurações → Dobradinhas** (`fusions`). Verifique se não há inconsistências. Uma "dobradinha" agrupa turmas que ocupam a mesma sala/horário; a turma `master` é a que recebe a sala. Pode-se desagregar uma dobradinha (método `FusionController@disjoint`).

### 4. Comparar com o semestre anterior e ajustar Interna/Externa **[confirmado]**

O objetivo é decidir quais turmas internas são, na verdade, externas, e vice-versa, além de identificar turmas que existem historicamente mas não vieram do Replicado (ex.: `cineIME`, `cursinho do IME`).

Comandos de auditoria (rodar via `docker compose exec -u www-data app php artisan ...`):

```bash
# Relatório de mudanças Interna/Externa + turmas históricas ausentes (vs. ano anterior)
semester:report-discrepancies

# Compara inscritos (estmtr) do semestre atual vs. anos anteriores
schoolclass:compare-enrollments

# Atualiza o número estimado de inscritos (estmtr) das turmas
schoolclass:update-enrollments

# Verifica conflitos de sala/horário e estmtr acima da capacidade
alocacao:verificar
```

Ajustes manuais na interface (Turmas Externas): **[confirmado]**
- Turmas **externas que serão ministradas no IME** → selecionar em **"Tornar Interno"** e clicar em **"Tornar Interno"** (`makeInternalInBatch`).
- Turmas **externas que não devem aparecer** no "Horário das Disciplinas" → selecionar em **"Excluir"** e clicar em **"Excluir"** (`destroyInBatch`).
- Turmas que **não existem no Replicado** mas precisam de sala (ex.: `cineIME`, `cursinho do IME`) são **criadas/cadastradas manualmente** na interface de turmas. **[a confirmar]** o passo exato de criação (recurso `schoolclasses`).

### 5. Distribuir turmas nas salas (solver) **[confirmado]**

Acesse **Configurações → Salas**, marque as salas na coluna **"Distribuir nas Salas"** e clique em **"Distribuir Turmas"** (rota `rooms/distributes`).

Antes de disparar, o controller verifica se o solver responde (`/health`). **[confirmado]** Opções do modal de distribuição:

| Opção | Comportamento | Default observado |
|-------|---------------|-------------------|
| `sync_enrollment` | Atualiza `estmtr` de todas as turmas direto do Replicado antes de executar o solver (`calcEstimadedEnrollment()`) | **Marcado por padrão** na UI **[confirmado]**; mas o construtor do job tem default `false` **[a confirmar]** |
| `use_skuld_prediction` | Consulta o microsserviço Skuld para inferir `estmtr` e substitui **apenas no payload** (não toca o banco); desativa a estimativa histórica | Desmarcado **[confirmado]** |
| Estimativa histórica | Método `average_plus_stddev` (média + desvio padrão) para ajustar demanda a partir de histórico de matriculados | `average_plus_stddev` **[confirmado]** |
| `use_legacy` | Distribuição legada (sem solver) | Desmarcado **[confirmado]** |
| `compare_algorithms` | Gera `comparison_reports` comparando solver vs. legado, sem alterar a produção | Desmarcado **[confirmado]** |
| Pesos de penalidade | `room_allocation` em `config/alocacao.php` (calibrados via Optuna) | Defaults calibrados **[confirmado]** |

O job `ProcessRoomDistribution` monta o payload (`RoomAllocationPayloadBuilder`), envia ao solver (`POST /api/v1/solve`) e registra automaticamente:
- um `SolverLog` com o payload; **[confirmado]**
- um snapshot pré-solver em `allocation_states` via `AllocationStateService::capture`. **[confirmado]**

O resultado é devolvido por **webhook** (`AllocationResultWebhookController`) e aplica `room_id` nas turmas. Há também ações de apoio: **cancelar distribuição** (`rooms/distribution/stop`) e **resgatar resultado** manualmente (`rooms/distribution/fallback`). **[confirmado]**

**Primeiro semestre (calouros):** há um comando específico para alocar turmas obrigatórias de 1º semestre **nas mesmas salas do ano anterior**, com opção de recalcular a **média histórica** de inscritos dos calouros:

```bash
# Simular antes de aplicar
alocacao:allocate-first-semesters --dry-run

# Aplicar recalculando a média histórica de inscritos
alocacao:allocate-first-semesters --recalculate-historical-average
```

O comando só aloca turmas sem sala (a menos de `--force`). **[confirmado]** — este comando é uma etapa **alternativa/complementar** ao solver, pois usa a sala do ano anterior e não o otimizador.

### 6. Validar e alocar manualmente **[confirmado]**

- **Ver Sala** (`rooms.show`) e **Tempo Livre** (`rooms.showFreeTime`) mostram as turmas não alocadas e as salas compatíveis.
- Para alocação manual em sala predeterminada, use **"Alocar Turma"** em cada sala (`rooms.allocate`). Se a turma fizer parte de dobradinha, o `master` também recebe a sala.
- **Esvaziar salas** antes de uma nova rodada (`rooms.empty`), que também captura um estado pré-esvaziamento em `allocation_states`. **[confirmado]**

---

## Transferência para o Urano

Quando a distribuição estiver consolidada — **após as duas primeiras semanas de aula, quando as trocas estabilizam** **[a confirmar]** — e todas as salas estiverem validadas, faça a reserva:

1. Acesse **Configurações → Salas**.
2. Marque as salas na coluna **"Reservar nas Salas"**.
3. Clique em **"Reservar Salas no Urano"** (rota `rooms/reservation` → job `ProcessReservation`). **[confirmado]**

O job `ProcessReservation` faz duas fases: **[confirmado]**
- **Fase 1 – Disponibilidade:** verifica conflitos com o que já está reservado. Se houver conflito, o processo falha e informa a primeira ocorrência — o operador deve contatar o administrador do Urano para liberar a sala. **[confirmado]**
- **Fase 2 – Criação:** cria as reservas para cada turma alocada.

`dtamaxres` do semestre define até quando as reservas são feitas (a `data_fim` de cada reserva). **[confirmado]**

> **Modo de escrita:** com `SALAS_USE_API=true`, as reservas são criadas via **API Salas**; senão, usam o fluxo legado (escrita direta). Pela política AC5, a API é usada com **erro explícito e sem fallback** quando indisponível. **[confirmado]**

---

## Pontos a confirmar

Incertezas levantadas nesta análise, que precisam de validação no ambiente real:

1. **Default do solver (`sync_enrollment`):** o *checkbox* vem **marcado por padrão** na interface (`rooms/index.blade.php`), mas o construtor do job `ProcessRoomDistribution` tem default `false`. Confirmar qual valor o formulário realmente envia em produção (na prática, via UI ele envia `1`). O contexto assumia "provável default true" — confirmar para não depender disso.
2. **Método de estimativa histórica:** default é `average_plus_stddev` (média + multiplicador de desvio padrão), não "média simples". Confirmar se a operação usa esse método ou `none`.
3. **Sigla `UNIDADE`:** a env `UNIDADE` é usada nas consultas ao Replicado; não está configurada no `.env` local. Preencher com a sigla correta.
4. **Conexão com o banco do Urano:** as variáveis `DB_*_URANO` não estão no `.env` local; a conexão `urano` usa defaults. Confirmar credenciais/host em produção.
5. **API Salas:** `SALAS_API_EMAIL`/`SALAS_API_PASSWORD` estão vazios e `SALAS_USE_API=false` no `.env` local. Confirmar qual modo é usado em produção para a etapa de reserva.
6. **URLs do ambiente:** `APP_URL=http://192.168.33.120:8000`, `ALOCACAO_SOLVER_URL=http://192.168.33.120:8001` (solver **fora** do compose), `REPLICADO_HOST=200.144.255.63`. Confirmar conectividade desses destinos no ambiente de operação e que o solver está rodando.
7. **Terminologia da importação:** a interface usa "Importar do Jupiter"; o contexto fala em "Replicado". Confirmar se o passo é o mesmo (o código consulta o Replicado via pacote `uspdev/replicado`).
8. **Criação de turmas fora do Replicado** (ex.: `cineIME`, `cursinho do IME): confirmar o procedimento exato de cadastro manual na interface.
9. **"Após as duas primeiras semanas de aula":** não há trava no código que imponha esse prazo; é uma convenção operacional. Confirmar o momento real de disparar a reserva.
10. **Comando de primeiro semestre vs. solver:** `alocacao:allocate-first-semesters` usa a sala do ano anterior e **não** o solver. Confirmar qual etapa é usada em cada semestre (o contexto indica o uso no 1º semestre para calouros).
11. **Comandos de Urano:** `reservas:urano-to-salas` importa reservas **do Urano para a API Salas** (fluxo inverso ao da operação descrita). Confirmar se algum desses comandos é usado no ciclo operacional ou se são utilitários pontuais.
12. **Dados do banco local:** o semestre 2026/1 no banco de desenvolvimento não tem `solver_logs`/`allocation_states` e tem 205/206 turmas internas sem sala — não usar esses números como referência de produção.
