@extends('main')

@section('title', 'Salas')

@section('content')
  @parent 
<div id="layout_conteudo">
    <div class="justify-content-center">
        <div class="col-md-12">
            <h1 class='text-center mb-5'>Salas</h1>

            <div id="progressbar-div">
            </div>
            <br>
            @if (count($salas) > 0)
                <div class="d-flex justify-content-center">
                    <div class="col-md-6">
                        <div class="float-right" style="margin-bottom: 20px;">
                            <!--
                            <form style="display: inline;"  action="{{ route('rooms.makeReport') }}" method="GET"
                            enctype="multipart/form-data"
                            >
                                @csrf
                                <button  class="btn btn-outline-primary"
                                    id="btn-report"
                                    type="submit"
                                >
                                    Gerar Relatório
                                </button>
                            </form>
                            -->
                            
                            <button class="btn btn-outline-primary"
                                id="btn-distributes"
                                type="button"
                                data-toggle="modal"
                                data-target="#solverConfigModal"
                            >
                                Distribuir Turmas
                            </button>

                            <form id="distributesForm" style="display: none;" action="{{ route('rooms.distributes') }}" method="POST"
                            enctype="multipart/form-data"
                            >
                                @method('patch')
                                @csrf
                            </form>

                            <form id="emptyForm" style="display: inline;" action="{{ route('rooms.empty') }}" method="POST"
                            enctype="multipart/form-data"
                            >
                                @method('patch')
                                @csrf
                                <button  class="btn btn-outline-primary"
                                    id="btn-empty"
                                    type="submit"
                                    onclick="return confirm('Você tem certeza? Esvaziar as salas irá desfazer a distribuição atual!')" 
                                >
                                    Esvaziar Salas
                                </button>
                            </form>

                            <form id="reservationForm" style="display: inline;"  action="{{ route('rooms.reservation') }}" method="GET"
                            enctype="multipart/form-data"
                            >
                                @csrf
                                <button  class="btn btn-outline-primary"
                                    id="btn-reservation"
                                    type="submit"
                                    onclick="return confirm('Você tem certeza? Lembre-se de conferir a distribuição das turmas nas salas antes de enviar as reservas para o Urano!')" 
                                >
                                    Reservar Salas no Urano
                                </button>
                            </form>

                            <form id="stopDistributionForm" style="display: inline;" action="{{ route('rooms.distribution.stop') }}" method="POST"
                            enctype="multipart/form-data"
                            >
                                @csrf
                                <button  class="btn btn-outline-danger"
                                    id="btn-stop-distribution"
                                    type="submit"
                                    style="display: none;"
                                    onclick="return confirm('Você tem certeza? O solver retornará a melhor solução parcial encontrada até o momento.')" 
                                >
                                    Cancelar Distribuição
                                </button>
                            </form>

                            <form id="fallbackDistributionForm" style="display: inline;" action="{{ route('rooms.distribution.fallback') }}" method="POST"
                            enctype="multipart/form-data"
                            >
                                @csrf
                                <button  class="btn btn-outline-warning"
                                    id="btn-fallback-distribution"
                                    type="submit"
                                    style="display: none;"
                                    onclick="return confirm('Tentar resgatar manualmente o resultado do solver? Use apenas se o webhook falhou.')" 
                                >
                                    Verificar Resultado Manualmente
                                </button>
                            </form>

                            <button class="btn btn-outline-info" id="btn-allocation-states" data-toggle="modal" data-target="#allocationStatesModal">
                                Histórico de Estados
                            </button>
                        </div>
                    <br>

                    <table class="table table-bordered table-striped table-hover" style="font-size:15px;">
                        <tr>
                            <th style="vertical-align: middle;">Nome</th>
                            <th style="vertical-align: middle;">Assentos</th>
                            <th>Distribuir<br>nas<br>Salas</th>
                            <th style="vertical-align: middle;">Esvaziar<br>Salas</th>
                            <th>Reservar<br>nas<br>Salas</th>
                            <th></th>
                        </tr>
                        @foreach($salas as $sala)
                            <tr>
                                <td style="white-space: nowrap;">{{ $sala->nome }}</td>
                                <td>{{ $sala->assentos }}</td>
                                @php
                                    $label = "";
                                    $first = true;
                                    $st = App\Models\SchoolTerm::getLatest();
                                    $turmas_nao_alocadas = App\Models\SchoolClass::whereBelongsTo($st)->whereDoesntHave("room")->whereDoesntHave("fusion")->get();
                                    $i = 0;

                                    foreach($turmas_nao_alocadas as $turma){
                                        if($i < 20){
                                            if($sala->isCompatible($turma, $ignore_block=true, $ignore_estmtr=true)){
                                                if($first){
                                                    $label .= "Compativel com:\n";
                                                    $first = false;
                                                }
                                                $label .= $turma->coddis." ".($turma->tiptur=="Graduação" ? "T.".substr($turma->codtur, -2, 2) : "")." ".$turma->nomdis."\n";
                                                $i += 1;
                                            }
                                        }
                                    }

                                    $dobradinhas_nao_alocadas = App\Models\Fusion::whereHas("schoolclasses", function ($query) use ($st){
                                                    $query->whereBelongsTo($st);
                                                })->whereHas("master", function ($query){
                                                    $query->whereDoesntHave("room");
                                                })->get();
                                    
                                    foreach($dobradinhas_nao_alocadas as $fusion){
                                        if($i < 20){
                                            if($sala->isCompatible($fusion->master, $ignore_block=true, $ignore_estmtr=true)){
                                                if($first){
                                                    $label .= "Compativel com:\n";
                                                    $first = false;
                                                }
                                                if($fusion->schoolclasses->pluck("coddis")->unique()->count()==1){
                                                    $label .= $fusion->master->coddis." ";
                                                    foreach(range(0, count($fusion->schoolclasses)-1) as $y){
                                                        $label .= "T.".substr($fusion->schoolclasses[$y]->codtur,-2,2);
                                                        $label .= $y != count($fusion->schoolclasses)-1 ? "/" : "";
                                                    }
                                                    $label .= " ".$fusion->master->nomdis."\n";
                                                }else{
                                                    foreach(range(0, count($fusion->schoolclasses)-1) as $y){
                                                        $label .= $fusion->schoolclasses[$y]->coddis." ";
                                                        $label .= $y != count($fusion->schoolclasses)-1 ? "/" : "\n";
                                                    }
                                                }
                                                $i += 1;
                                            }
                                        }
                                    }
                                    if($first){
                                        if($turmas_nao_alocadas or $dobradinhas_nao_alocadas){ 
                                            $label .= "Nenhuma turma compativel";
                                        }
                                    }
                                    
                                @endphp
                                <td>
                                    <input id="rooms_id" form="distributesForm" class="checkbox" type="checkbox" name="rooms_id[]" value="{{ $sala->id }}" {!! !in_array($sala->nome, ["B05","B04","B07","A249","CEC02","CEC04","CEC05","CEC06","Auditório Jacy Monteiro","Auditório Antonio Gilioli","Auditório Imre Simon","Online","Auditório do CCSL","Auditório do InovaUSP","A251(CEA)"]) ? 'checked' : '' !!}>
                                </td>
                                <td>
                                    <input id="rooms_id" form="emptyForm" class="checkbox" type="checkbox" name="rooms_id[]" value="{{ $sala->id }}" checked>
                                </td>
                                <td>
                                    <input id="rooms_id" form="reservationForm" class="checkbox" type="checkbox" name="rooms_id[]" value="{{ $sala->id }}" {!! in_array($sala->nome, ["CEC02","CEC04","CEC05","CEC06"]) ? 'disabled' : '' !!}>
                                </td>
                                <td class="text-center" style="white-space: nowrap;">
                                    <a  class="btn btn-outline-dark btn-sm"
                                        data-toggle="tooltip" data-placement="top"
                                        title="{{$label}}"
                                        href="{{ route('rooms.show', $sala) }}"
                                        target="_blank"
                                    >Ver Sala
                                    </a>
                                </td>
                            </tr>
                        @endforeach

                        <tr>
                                <td style="white-space: nowrap;" colspan=5>Salas livres por horário</td>
                                <td class="text-center" style="white-space: nowrap;">
                                    <a  class="btn btn-outline-dark btn-sm"
                                        data-toggle="tooltip" data-placement="top"
                                        href="{{ route('rooms.showFreeTime',) }}"
                                        target="_blank"
                                    >Ver Salas
                                    </a>
                                </td>
                        </tr>
                    </table>
                </div>
                </div>
            @else
                <p class="text-center">Não há salas cadastradas</p>
            @endif
        </div>
    </div>
</div>

<!-- Modal Gerenciador de Alocações -->
<div class="modal fade" id="allocationStatesModal" tabindex="-1" role="dialog" aria-labelledby="allocationStatesModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="allocationStatesModalLabel">Gerenciador de Alocações</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Fechar">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="saveAllocationStateForm" action="{{ route('allocation-states.store') }}" method="POST">
                    @csrf
                    <div class="form-group">
                        <label for="allocation_state_name">Nome do estado</label>
                        <input type="text" class="form-control" id="allocation_state_name" name="name"
                               placeholder="Ex: Antes da reunião de departamento">
                    </div>
                    <button type="submit" class="btn btn-primary mb-3">Salvar Estado Atual</button>
                </form>

                <hr>

                <h6>Estados salvos</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered table-hover" id="allocationStatesTable">
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>Data</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td colspan="3" class="text-center">Carregando...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

@php
$tooltips = [
    'strict_capacity' => 'Quando ativada, o solver não pode alocar turmas em salas com capacidade menor que a demanda.',
    'block_b_restriction_for_pos' => 'Quando ativada, pós-graduação não pode ser alocada no Bloco B.',
    'block_a_restriction_for_freshmen' => 'Quando ativada, calouros do IME devem ser alocados no Bloco A.',
    'undergrad_in_block_a_penalty' => 'Penalidade aplicada ao alocar graduação no Bloco A (deveria ser preferencialmente no Bloco B).',
    'pos_in_block_b_penalty' => 'Penalidade aplicada ao alocar pós-graduação no Bloco B (deveria ser preferencialmente no Bloco A).',
    'wasted_seats_weight' => 'Peso dado ao número de assentos ociosos nas salas alocadas.',
    'unassigned_penalty' => 'Penalidade por cada turma que ficar sem sala na solução.',
    'priority_weight' => 'Peso dado às prioridades explícitas de sala/turma.',
    'historical_estimation_method' => 'Método usado para estimar demanda a partir do histórico de matriculados.',
    'historical_threshold_percent' => 'Diferença percentual mínima entre inscritos atuais e média histórica para ativar a correção.',
    'historical_lookback_years' => 'Quantidade de anos anteriores consultados no cálculo da média histórica.',
    'historical_min_years' => 'Número mínimo de anos históricos com dados para considerar a estimativa confiável.',
    'historical_cap' => 'Teto máximo para a demanda estimada.',
    'historical_stddev_multiplier' => 'Multiplicador do desvio padrão somado à média no método average_plus_stddev.',
];
@endphp

<!-- Modal de Configuração do Solver -->
<div class="modal fade" id="solverConfigModal" tabindex="-1" role="dialog" aria-labelledby="solverConfigModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="solverConfigModalLabel">Configuração do Solver</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Fechar">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs" id="solverConfigTabs" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active" id="tab-hard-link" data-toggle="tab" href="#tab-hard" role="tab">Hard Constraints</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="tab-soft-link" data-toggle="tab" href="#tab-soft" role="tab">Soft Constraints</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="tab-estimativa-link" data-toggle="tab" href="#tab-estimativa" role="tab">Estimativa 1º Sem</a>
                    </li>
                </ul>

                <div class="tab-content mt-3">
                    <!-- Aba 1 - Hard Constraints -->
                    <div class="tab-pane fade show active" id="tab-hard" role="tabpanel">
                        @foreach ([
                            'strict_capacity' => 'Capacidade Estrita',
                            'block_b_restriction_for_pos' => 'Restrição Bloco B p/ Pós',
                            'block_a_restriction_for_freshmen' => 'Restrição Bloco A p/ Calouros',
                        ] as $key => $label)
                            <div class="form-group">
                                <label data-toggle="tooltip" data-placement="top" title="{{ $tooltips[$key] }}">{{ $label }}</label>
                                <select name="solver_config[{{ $key }}]" class="form-control" form="distributesForm">
                                    <option value="1" {{ config('alocacao.room_allocation.' . $key) ? 'selected' : '' }}>Sim</option>
                                    <option value="0" {{ ! config('alocacao.room_allocation.' . $key) ? 'selected' : '' }}>Não</option>
                                </select>
                            </div>
                        @endforeach
                    </div>

                    <!-- Aba 2 - Soft Constraints -->
                    <div class="tab-pane fade" id="tab-soft" role="tabpanel">
                        @foreach ([
                            'undergrad_in_block_a_penalty' => 'Penalidade Graduação Bloco A',
                            'pos_in_block_b_penalty' => 'Penalidade Pós Bloco B',
                            'wasted_seats_weight' => 'Peso Assentos Ociosos',
                            'unassigned_penalty' => 'Penalidade Turma sem Sala',
                            'priority_weight' => 'Peso de Prioridade',
                        ] as $key => $label)
                            <div class="form-group">
                                <label data-toggle="tooltip" data-placement="top" title="{{ $tooltips[$key] }}">{{ $label }}</label>
                                <input type="number" step="any" class="form-control" name="solver_config[{{ $key }}]" value="{{ config('alocacao.room_allocation.' . $key) }}" form="distributesForm">
                            </div>
                        @endforeach
                    </div>

                    <!-- Aba 3 - Estimativa 1º Sem -->
                    <div class="tab-pane fade" id="tab-estimativa" role="tabpanel">
                        <div class="form-group">
                            <label data-toggle="tooltip" data-placement="top" title="{{ $tooltips['historical_estimation_method'] }}">Método</label>
                            <select name="solver_config[historical_estimation_method]" class="form-control" form="distributesForm">
                                <option value="average_plus_stddev" {{ config('alocacao.historical_estimation_method') === 'average_plus_stddev' ? 'selected' : '' }}>average_plus_stddev</option>
                                <option value="none" {{ config('alocacao.historical_estimation_method') === 'none' ? 'selected' : '' }}>none</option>
                            </select>
                        </div>
                        @foreach ([
                            'historical_threshold_percent' => 'Threshold %',
                            'historical_lookback_years' => 'Anos de Lookback',
                            'historical_min_years' => 'Mínimo de Anos',
                            'historical_cap' => 'Limite/Cap',
                            'historical_stddev_multiplier' => 'Multiplicador DP',
                        ] as $key => $label)
                            <div class="form-group">
                                <label data-toggle="tooltip" data-placement="top" title="{{ $tooltips[$key] }}">{{ $label }}</label>
                                <input type="number" step="any" class="form-control" name="solver_config[{{ $key }}]" value="{{ config('alocacao.' . $key) }}" form="distributesForm">
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                <button type="submit" form="distributesForm" class="btn btn-primary"
                    onclick="return confirm('Você tem certeza? Redistribuir as turmas irá desfazer a distribuição atual!')">
                    Executar Solver
                </button>
            </div>
        </div>
    </div>
</div>
@endsection

@section('javascripts_bottom')
@parent
<script>
$( function() {       
    function progress() {
        $.ajax({
            url: window.location.origin+'/monitor/getReportProcess',
            dataType: 'json',
            success: function success(json){
                if('progress' in json){
                    if(!json["failed"]){
                        if(document.getElementById('progressbar')){
                            $( "#progressbar" ).progressbar( "value", json['progress'] );
                        }else if(json['progress'] != 100){
                            $('#progressbar-div').append("<div id='progressbar'><div class='progress-label'></div></div>");
                            var progressbar = $( "#progressbar" ),
                            progressLabel = $( ".progress-label" );
                            progressbar.progressbar({
                                value: false,
                                change: function() {
                                    progressLabel.text( progressbar.progressbar( "value" ) + "%" );
                                },
                                complete: function() {
                                    $( "#progressbar" ).remove();
                                    window.clearTimeout(timeouthandle);
                                    location.replace(window.location.origin+'/rooms/downloadReport');
                                }
                            });
                        }
                    }
                }
                var timeouthandle = setTimeout( progress, 1000);
            }
        });
    }        
    setTimeout( progress, 50 ); 

    function progress2() {
        $.ajax({
            url: window.location.origin+'/monitor/getReservationProcess',
            dataType: 'json',
            success: function success(json){
                if('progress' in json){
                    if(!json["data"] && !json['failed']){
                        if(document.getElementById('progressbar')){
                            $( "#progressbar" ).progressbar( "value", json['progress'] );
                        }else if(json['progress'] != 100){
                            document.getElementById("btn-reservation").disabled = true;
                            //document.getElementById("btn-report").disabled = true;
                            document.getElementById("btn-distributes").disabled = true;
                            document.getElementById("btn-empty").disabled = true;
                            $('#progressbar-div').append("<div id='progressbar'><div class='progress-label'></div></div>");
                            var progressbar = $( "#progressbar" ),
                            progressLabel = $( ".progress-label" );
                            progressbar.progressbar({
                                value: false,
                                change: function() {
                                    progressLabel.text( progressbar.progressbar( "value" ) + "%" );
                                },
                                complete: function() {
                                    document.getElementById("btn-reservation").disabled = false;
                                    //document.getElementById("btn-report").disabled = false;
                                    document.getElementById("btn-distributes").disabled = false;
                                    document.getElementById("btn-empty").disabled = false;
                                    $( "#progressbar" ).remove();
                                    $('#flash-message').empty();
                                    $('#flash-message').append("<p id='success-message' class='alert alert-success'>As reservas foram feitas com sucesso.</p>");
                                }
                            });
                        }
                    }else if((JSON.parse(json["data"])["status"] == "failed") && !(document.getElementById('error-message'))){
                        document.getElementById("btn-reservation").disabled = false;
                        //document.getElementById("btn-report").disabled = false;
                        document.getElementById("btn-distributes").disabled = false;
                        document.getElementById("btn-empty").disabled = false;
                        $( "#progressbar" ).remove();

                        var schoolclass = JSON.parse(json["data"])["schoolclass"];
                        var sala = JSON.parse(json["data"])["room"];

                        $('#flash-message').empty();
                        $('#flash-message').append("<p id='error-message' class='alert alert-danger'>Não foi possivel realizar as reservas. A disciplina "+
                            schoolclass.coddis+" turma "+schoolclass.codtur+" não conseguiu reserva na sala "+sala+". Entre em contato com o administrador. </p>");
                    }else if(json['failed']){
                        document.getElementById("btn-reservation").disabled = false;
                        //document.getElementById("btn-report").disabled = false;
                        document.getElementById("btn-distributes").disabled = false;
                        document.getElementById("btn-empty").disabled = false;
                        $( "#progressbar" ).remove();
                        $('#flash-message').empty();
                        $('#flash-message').append("<p id='error-message' class='alert alert-danger'>Não foi possivel realizar as reservas. Falha critica. Entre em contato com o administrador. </p>");
                    }
                }
                var timeouthandle = setTimeout( progress2, 1000);
            }
        });
    }        
    setTimeout( progress2, 50 );

    var trackingJob = false;
    function progressDistribution() {
        $.ajax({
            url: window.location.origin+'/monitor/getDistributionProcess',
            dataType: 'json',
            success: function success(json){
                if(json && 'progress' in json){
                    var isFailed = json['failed'] || (json['data'] && JSON.parse(json['data'])['status'] === 'error');
                    var isCompleted = json['status'] === 'completed';

                    if(!isFailed && !isCompleted){
                        trackingJob = true;

                        document.getElementById("btn-stop-distribution").style.display = 'inline-block';
                        document.getElementById("btn-fallback-distribution").style.display = 'inline-block';
                        document.getElementById("btn-reservation").disabled = true;
                        document.getElementById("btn-distributes").disabled = true;
                        document.getElementById("btn-empty").disabled = true;

                        if(document.getElementById('progressbar')){
                            $( "#progressbar" ).progressbar( "value", json['progress'] );
                        }else{
                            $('#progressbar-div').append("<div id='progressbar'><div class='progress-label'></div></div>");
                            var progressbar = $( "#progressbar" ),
                            progressLabel = $( ".progress-label" );
                            progressbar.progressbar({
                                value: false,
                                change: function() {
                                    progressLabel.text( progressbar.progressbar( "value" ) + "%" );
                                }
                            });
                        }
                    }else if(isFailed){
                        if (trackingJob) {
                            document.getElementById("btn-reservation").disabled = false;
                            document.getElementById("btn-distributes").disabled = false;
                            document.getElementById("btn-empty").disabled = false;
                            document.getElementById("btn-stop-distribution").style.display = 'none';
                            document.getElementById("btn-fallback-distribution").style.display = 'inline-block';
                            $( "#progressbar" ).remove();
                            $('#flash-message').empty();
                            $('#flash-message').append("<p id='error-message' class='alert alert-danger'>"+
                                (json['message'] || 'Não foi possível realizar a distribuição. Falha crítica.') + "</p>");

                            setTimeout(function() { window.location.reload(); }, 2500);
                        }

                        trackingJob = false;
                        return;
                    }else if(isCompleted){
                        if (trackingJob) {
                            document.getElementById("btn-reservation").disabled = false;
                            document.getElementById("btn-distributes").disabled = false;
                            document.getElementById("btn-empty").disabled = false;
                            document.getElementById("btn-stop-distribution").style.display = 'none';
                            document.getElementById("btn-fallback-distribution").style.display = 'none';
                            $( "#progressbar" ).remove();
                            $('#flash-message').empty();

                            var autoCount = json['assignments_count'] || 0;
                            var manualCount = json['manual_count'] || 0;
                            var unassignedCount = json['unassigned_count'] || 0;
                            var successMsg = 'Distribuição concluída. ' +
                                autoCount + ' turma(s) alocada(s) automaticamente';
                            if (manualCount > 0) {
                                successMsg += ', ' + manualCount + ' turma(s) manual(is) preservada(s)';
                            }
                            if (unassignedCount > 0) {
                                successMsg += ', ' + unassignedCount + ' turma(s) não alocada(s)';
                            }
                            successMsg += '.';
                            $('#flash-message').append("<p id='success-message' class='alert alert-success'>" + successMsg + "</p>");

                            setTimeout(function() { window.location.reload(); }, 2500);
                        }

                        trackingJob = false;
                        return;
                    }
                }else{
                    document.getElementById("btn-stop-distribution").style.display = 'none';
                    document.getElementById("btn-fallback-distribution").style.display = 'none';
                    trackingJob = false;
                }

                setTimeout( progressDistribution, 1000);
            },
            error: function(xhr, status, err){
                setTimeout( progressDistribution, 3000);
            }
        });
    }
    setTimeout( progressDistribution, 50 );

    function loadAllocationStates() {
        $.ajax({
            url: "{{ route('allocation-states.index') }}",
            dataType: 'json',
            success: function(data) {
                var tbody = $('#allocationStatesTable tbody');
                tbody.empty();

                if (data.states.length === 0) {
                    tbody.append('<tr><td colspan="3" class="text-center">Nenhum estado salvo.</td></tr>');
                    return;
                }

                data.states.forEach(function(state) {
                    var disabled = data.is_solving ? 'disabled' : '';
                    var row = '<tr>' +
                        '<td>' + escapeHtml(state.name) + '</td>' +
                        '<td>' + escapeHtml(state.created_at) + '</td>' +
                        '<td>' +
                            '<form style="display: inline;" action="/allocation-states/' + state.id + '/restore" method="POST">' +
                                '@csrf' +
                                '<button type="submit" class="btn btn-sm btn-outline-success" ' + disabled + '>Carregar</button>' +
                            '</form> ' +
                            '<form style="display: inline;" action="/allocation-states/' + state.id + '" method="POST">' +
                                '@csrf' +
                                '@method('DELETE')' +
                                '<button type="submit" class="btn btn-sm btn-outline-danger">Excluir</button>' +
                            '</form>' +
                        '</td>' +
                    '</tr>';
                    tbody.append(row);
                });
            },
            error: function() {
                $('#allocationStatesTable tbody').html('<tr><td colspan="3" class="text-center text-danger">Erro ao carregar estados.</td></tr>');
            }
        });
    }

    function escapeHtml(text) {
        if (!text) return '';
        return text
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    $('#allocationStatesModal').on('shown.bs.modal', function () {
        loadAllocationStates();
    });

    $('#solverConfigModal').on('shown.bs.modal', function () {
        $(this).find('[data-toggle="tooltip"]').tooltip();
    }).on('hidden.bs.modal', function () {
        $(this).find('[data-toggle="tooltip"]').tooltip('dispose');
    });
});
</script>
@endsection