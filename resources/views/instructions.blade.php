@extends('main')

@section('title', 'Sistema de Alocação')

@section('content')
  @parent
<div id="layout_conteudo">
    <div class="justify-content-center">
        <div class="col-md-12">
            <h1 class='text-center mb-5'>Instruções</h1>

            <li>Cadastre um período letivo informando ano e semestre no menu superior direito <i class="fas fa-cog"></i>->Perídos Letivos.</li><br>
            <li>Acesse a pagina das turmas em <i class="fas fa-cog"></i>->Turmas Internas e clique no botão "Importar do Jupiter". Lembre-se de verificar no calendário escolar se as
            informações ja estão disponiveis.</li><br>
            <li>Acesse a pagina das Dobradinhas em <i class="fas fa-cog"></i>->Dobradinhas e verifique se não há nenhuma inconsistência".</li><br>
            <li>Acesse a pagina das turmas de outras unidades em <i class="fas fa-cog"></i>->Turmas Externas. As turmas externas que serão ministradas no IME
                precisam ser convertidas em internas, você pode fazer isso selecionando a(s) turma(s) na coluna "Tornar Interno" e clicando no botão "Tornar Interno"
                As turmas externas que não devem aparecer no "Horário das Disciplinas" precisam ser excluidas, você pode fazer isso selecionando a(s) turma(s) na 
                coluna "Excluir" e clicando no botão "Excluir".</li><br>
            <li>Acesse a pagina das salas em <i class="fas fa-cog"></i>->Salas.</li><br> 
            <li>Para alocar turmas em salas predeterminadas clique no botão "Ver Sala" da respectiva sala, clique no botão "Alocar Turma", escolha a turma a ser alocada
                e clique no botão "Alocar".</li><br>
            <li>Para alocar as turmas nas salas automaticamente volte para a pagina das salas, escolha as salas na coluna "Distribuir nas Salas" e clique no botão 
                "Distribuir Turmas". As vezes algumas turmas não podem ser alocadas automaticamente, ao entrar em alguma sala será apresentado, logo abaixo
                da tabela de horários, uma lista com as turmas não alocadas. Na pagina das salas, ao passar o cursor sobre o botão "Ver Sala" de uma determinada sala aparecerá 
                um informativo com as turmas ainda não alocadas compativeis com a sala em questão. Você pode alocar uma turma manualmente, como foi explicado no item anterior.</li><br>
            <li>Uma vez que todas as turmas foram alocadas nas salas e todas as salas validadas, falta a penas enviar as informações para o Sistema de Reserva de Salas de 
                IME(Urano), você pode fazer isso clicando no botão "Reservar Salas no Urano". Antes de realizar as reservas o sistema verifica se não existem conflitos 
                com o que já esta reservado no Urano, caso seja encontrado algum conflito será apresentada uma mensagem informando a primeira ocorrência, entre em contato com
                o administrador do Urano solicitando que a sala em questão seja disponibilizada.</li><br>            
        </div>
    </div>
</div>
@endsection