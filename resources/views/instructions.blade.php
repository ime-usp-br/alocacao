@extends('main')

@section('title', 'Sistema de Alocação')

@section('content')
  @parent
<div id="layout_conteudo">
    <div class="justify-content-center">
        <div class="col-md-12">

            <h1 class='text-center mb-2'>Operação do Sistema de Alocação de Salas</h1>
            <p class="text-center text-muted mb-4">Guia passo a passo para distribuir as turmas do semestre nas salas do IME, do cadastro do período até a reserva no Urano.</p>

            <div class="alert" style="background-color:#eef2f7;border-color:#d5dde8;color:#33475b;">
                <strong>Visão geral do processo:</strong> o semestre passa por <strong>7 etapas</strong>. Ao concluir cada uma, você avança para a próxima. O processo começa no cadastro do período letivo e termina com a reserva das salas no Urano, após as trocas de turma se estabilizarem (em geral após as duas primeiras semanas de aula).
            </div>

            <div class="row mb-3">
                <div class="col-md-12">
                    <ol class="list-group list-group-horizontal-md text-center">
                        <li class="list-group-item flex-fill">1. Semestre</li>
                        <li class="list-group-item flex-fill">2. Importar turmas</li>
                        <li class="list-group-item flex-fill">3. Dobradinhas</li>
                        <li class="list-group-item flex-fill">4. Externas</li>
                        <li class="list-group-item flex-fill">5. Distribuir</li>
                        <li class="list-group-item flex-fill">6. Validar</li>
                        <li class="list-group-item flex-fill">7. Reservar no Urano</li>
                    </ol>
                </div>
            </div>

            <!-- ============================ ETAPA 1 ============================ -->
            <div class="card mb-4">
                <div class="card-header text-white" style="background-color:#5b6b7f;">
                    <h5 class="mb-0">Etapa 1 — Cadastrar o semestre letivo</h5>
                </div>
                <div class="card-body">
                    <ol>
                        <li>No <strong>menu lateral esquerdo</strong>, clique em <strong>Períodos Letivos</strong>.</li>
                        <li>Clique em <strong>Cadastrar</strong>.</li>
                        <li>Preencha:
                            <ul>
                                <li><strong>Ano</strong> — ex.: <code>2026</code>.</li>
                                <li><strong>Período</strong> — <code>1° Semestre</code> ou <code>2° Semestre</code>.</li>
                                <li><strong>Data final de reserva</strong> — a data limite até quando serão feitas as reservas no Urano na última etapa. Este campo é obrigatório.</li>
                            </ul>
                        </li>
                        <li>Clique em <strong>Salvar</strong>.</li>
                    </ol>
                    <div class="alert mb-0" style="background-color:#fbf5ea;border-color:#e9dcbe;color:#6b5426;">
                        <strong>Atenção:</strong> a <strong>data final de reserva</strong> é importante, pois define até quando o sistema fará reservas no Urano. Escolha uma data que cubra todo o período em que as turmas precisam de sala.
                    </div>
                </div>
            </div>

            <!-- ============================ ETAPA 2 ============================ -->
            <div class="card mb-4">
                <div class="card-header text-white" style="background-color:#5b6b7f;">
                    <h5 class="mb-0">Etapa 2 — Importar as turmas</h5>
                </div>
                <div class="card-body">
                    <ol>
                        <li>Verifique no <strong>calendário escolar</strong> se as informações das turmas já estão disponíveis no sistema de origem.</li>
                        <li>No <strong>menu lateral esquerdo</strong>, clique em <strong>Turmas Internas</strong>.</li>
                        <li>Clique no botão <strong>Importar do Jupiter</strong>.</li>
                        <li>Aguarde a importação terminar. Nesse momento o sistema busca, no Replicado, as turmas de graduação do IME, as turmas de outras unidades que usam salas do IME e as turmas de pós-graduação.</li>
                    </ol>
                    <div class="alert alert-light border mb-0">
                        <strong>Dica:</strong> enquanto a importação roda, a página <strong>Turmas Internas</strong> exibe uma <strong>barra de progresso</strong> logo abaixo do título. Ao concluir, a página é recarregada automaticamente.
                    </div>
                </div>
            </div>

            <!-- ============================ ETAPA 3 ============================ -->
            <div class="card mb-4">
                <div class="card-header text-white" style="background-color:#5b6b7f;">
                    <h5 class="mb-0">Etapa 3 — Verificar as Dobradinhas</h5>
                </div>
                <div class="card-body">
                    <ol>
                        <li>No <strong>menu lateral esquerdo</strong>, clique em <strong>Dobradinhas</strong>.</li>
                        <li>Confira se não há nenhuma inconsistência no agrupamento das turmas.</li>
                    </ol>
                    <div class="alert alert-light border mb-0">
                        Uma <strong>dobradinha</strong> é um grupo de turmas que ocupa a mesma sala e o mesmo horário (por exemplo, turmas de disciplinas diferentes dadas pelo mesmo docente em horário igual). Ao alocar uma dobradinha, todas as turmas do grupo são atendidas na mesma sala.
                    </div>
                </div>
            </div>

            <!-- ============================ ETAPA 4 ============================ -->
            <div class="card mb-4">
                <div class="card-header text-white" style="background-color:#5b6b7f;">
                    <h5 class="mb-0">Etapa 4 — Ajustar as turmas externas</h5>
                </div>
                <div class="card-body">
                    <p>As turmas externas (de outras unidades) <strong>não são alocadas nas salas</strong> e <strong>não aparecem no "Horário das Disciplinas"</strong> — o sistema as ignora automaticamente. Por isso, <strong>não é necessário excluí-las</strong>. Basta tratá-las quando precisarem de sala no IME:</p>
                    <ol>
                        <li>No <strong>menu lateral esquerdo</strong>, clique em <strong>Turmas Externas</strong>.</li>
                        <li>Verifique se há turmas externas que <strong>serão ministradas no IME</strong> e precisam de sala. Se houver, selecione a(s) turma(s) na coluna <em>Tornar Interno</em> e clique no botão <strong>Tornar Interno</strong>.</li>
                        <li>Turmas que <strong>não existem no sistema de origem</strong> mas precisam de sala (ex.: <code>cineIME</code>, <code>cursinho do IME</code>) devem ser <strong>cadastradas manualmente</strong> na página de turmas.</li>
                    </ol>
                </div>
            </div>

            <!-- ==================== OBSERVAÇÃO CURSO DE VERÃO ==================== -->
            <div class="card mb-4">
                <div class="card-header text-white" style="background-color:#7a6a52;">
                    <h5 class="mb-0">Observação — Turmas de curso de verão</h5>
                </div>
                <div class="card-body">
                    <p>É comum que, no <strong>primeiro semestre</strong>, as turmas do <strong>curso de verão</strong> apareçam indevidamente como <strong>turmas internas de graduação</strong>. Elas não fazem parte do semestre letivo e <strong>precisam ser tratadas</strong> para não receberem sala.</p>
                    <p>Para identificá-las na lista de turmas, observe que:</p>
                    <ul>
                        <li>costumam aparecer como as <strong>últimas turmas de graduação</strong>, logo antes das de pós-graduação;</li>
                        <li>são facilmente reconhecidas pelas <strong>datas de início e fim muito fora do semestre letivo</strong>.</li>
                    </ul>
                    <p>Essas turmas devem ser <strong>excluídas ou tornadas externas</strong>. A forma mais simples e segura é <strong>torná-las externas</strong>: na página <strong>Turmas Internas</strong>, selecione as turmas de verão e clique no botão <strong>Tornar Externo</strong> — a operação é feita em lote, sem precisar confirmar turma por turma. Ao serem marcadas como externas, elas são automaticamente ignoradas na distribuição de salas e no "Horário das Disciplinas".</p>
                    <div class="alert mb-0" style="background-color:#eef2f7;border-color:#d5dde8;color:#33475b;">
                        <strong>Por que não excluir automaticamente:</strong> como essas turmas não são facilmente distinguíveis por um critério simples e confiável, a exclusão automática correria o risco de remover alguma turma que deveria ser mantida. Por isso, a conferência manual dessas turmas é recomendada.
                    </div>
                </div>
            </div>

            <!-- ============================ ETAPA 5 ============================ -->
            <div class="card mb-4">
                <div class="card-header text-white" style="background-color:#5b6b7f;">
                    <h5 class="mb-0">Etapa 5 — Distribuir as turmas nas salas</h5>
                </div>
                <div class="card-body">
                    <ol>
                        <li>No <strong>menu lateral esquerdo</strong>, clique em <strong>Salas</strong>.</li>
                        <li>Marque, na coluna <strong>Distribuir nas Salas</strong>, as salas que podem ser usadas na distribuição.</li>
                        <li>Clique no botão <strong>Distribuir Turmas</strong>.</li>
                        <li>No modal, revise as opções e clique em <strong>confirmar</strong> para iniciar.</li>
                    </ol>
                    <p><strong>Opções da distribuição:</strong></p>
                    <table class="table table-bordered table-sm">
                        <thead>
                            <tr><th style="width:25%">Opção</th><th>O que faz</th></tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Atualizar número de inscritos</td>
                                <td>Busca no sistema de origem o número atualizado de inscritos (<code>estmtr</code>) de cada turma antes de distribuir. Deixe marcado para usar os dados mais recentes.</td>
                            </tr>
                            <tr>
                                <td>Usar previsão de inscritos (Skuld)</td>
                                <td>Usa a previsão de matrícula do microsserviço Skuld para estimar a demanda. Opcional; não altera os dados salvos.</td>
                            </tr>
                            <tr>
                                <td>Estimativa histórica</td>
                                <td>Para turmas de 1º semestre (calouros), usa a média histórica de inscritos para estimar a demanda da sala. Recomendado no primeiro semestre.</td>
                            </tr>
                            <tr>
                                <td>Distribuição legada (sem solver)</td>
                                <td>Usa o método antigo de distribuição, sem o otimizador. Use apenas se o otimizador estiver indisponível.</td>
                            </tr>
                        </tbody>
                    </table>
                    <div class="alert mb-0" style="background-color:#fbf5ea;border-color:#e9dcbe;color:#6b5426;">
                        <strong>Atenção:</strong> a distribuição roda em segundo plano. Acompanhe o progresso pelo botão de status da distribuição. Turmas que não puderem ser alocadas automaticamente ficam listadas nas salas para ajuste manual (veja a Etapa 6).
                    </div>
                </div>
            </div>

            <!-- ============================ ETAPA 6 ============================ -->
            <div class="card mb-4">
                <div class="card-header text-white" style="background-color:#5b6b7f;">
                    <h5 class="mb-0">Etapa 6 — Validar e ajustar manualmente</h5>
                </div>
                <div class="card-body">
                    <ol>
                        <li>Volte à página <strong>Salas</strong> e clique em <strong>Ver Sala</strong> para conferir a distribuição de cada sala.</li>
                        <li>Na página de cada sala, é exibida, logo abaixo da tabela de horários, a lista das <strong>turmas ainda não alocadas</strong>.</li>
                        <li>Ao passar o cursor sobre o botão <strong>Ver Sala</strong>, aparece um informativo com as turmas ainda não alocadas que são <strong>compatíveis</strong> com aquela sala.</li>
                        <li>Para alocar uma turma manualmente: abra a sala, clique em <strong>Alocar Turma</strong>, escolha a turma e clique em <strong>Alocar</strong>.</li>
                        <li>Use a página <strong>Salas livres por horário</strong> (botão disponível na página de Salas) para verificar os horários disponíveis e evitar conflitos.</li>
                    </ol>
                    <div class="alert alert-light border mb-0">
                        <strong>Objetivo:</strong> ao final desta etapa, todas as turmas que precisam de sala devem estar alocadas. O próprio sistema já evita automaticamente conflitos de horário ao distribuir as turmas — a validação manual serve apenas para conferir o resultado.
                    </div>
                </div>
            </div>

            <!-- ============================ ETAPA 7 ============================ -->
            <div class="card mb-4">
                <div class="card-header text-white" style="background-color:#5b6b7f;">
                    <h5 class="mb-0">Etapa 7 — Reservar as salas no Urano</h5>
                </div>
                <div class="card-body">
                    <div class="alert" style="background-color:#fbf5ea;border-color:#e9dcbe;color:#6b5426;">
                        Faça esta etapa <strong>somente depois</strong> de as trocas de turma se estabilizarem (em geral após as duas primeiras semanas de aula) e com todas as salas validadas.
                    </div>
                    <ol>
                        <li>No <strong>menu lateral esquerdo</strong>, clique em <strong>Salas</strong>.</li>
                        <li>Marque, na coluna <strong>Reservar nas Salas</strong>, as salas que serão reservadas no Urano.</li>
                        <li>Clique no botão <strong>Reservar Salas no Urano</strong>.</li>
                        <li>Aguarde o processamento. O sistema verifica, para cada turma, se não há <strong>conflito</strong> com reservas já existentes no Urano e então cria as reservas até a <strong>data final de reserva</strong> cadastrada na Etapa 1.</li>
                    </ol>
                    <div class="alert mb-0" style="background-color:#fbefee;border-color:#e7c9c6;color:#6d2f2b;">
                        <strong>Se houver conflito:</strong> o processo é interrompido e o sistema informa a primeira ocorrência. Entre em contato com o administrador do Urano solicitando que a sala em questão seja disponibilizada e tente novamente.
                    </div>
                </div>
            </div>

            <!-- ============================ CONCLUÍDO ============================ -->
            <div class="card mb-4">
                <div class="card-header text-white" style="background-color:#5b6b7f;">
                    <h5 class="mb-0">Processo concluído</h5>
                </div>
                <div class="card-body">
                    <p>Com as reservas confirmadas no Urano, o ciclo do semestre está completo. Quando o próximo semestre se aproximar, o processo recomeça na <strong>Etapa 1</strong>, com o cadastro do novo período letivo.</p>
                    <p class="mb-0">Lembre-se de verificar no calendário escolar os prazos e a disponibilidade das informações antes de importar as turmas do semestre seguinte.</p>
                </div>
            </div>

        </div>
    </div>
</div>
@endsection
