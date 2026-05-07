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
                            
                            <form id="distributesForm" style="display: inline;"action="{{ route('rooms.distributes') }}" method="POST"
                            enctype="multipart/form-data"
                            >
                                @method('patch')
                                @csrf
                                <button  class="btn btn-outline-primary"
                                    id="btn-distributes"
                                    type="submit"
                                    onclick="return confirm('Você tem certeza? Redistribuir as turmas irá desfazer a distribuição atual!')" 
                                >
                                    Distribuir Turmas
                                </button>
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
                        trackingJob = true; // Avisa o JS que estamos assistindo a um job ativo
                        
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
                        return; // O RETURN É CRÍTICO! Interrompe o polling e o loop.
                    }else if(isCompleted){
                        if (trackingJob) {
                            document.getElementById("btn-reservation").disabled = false;
                            document.getElementById("btn-distributes").disabled = false;
                            document.getElementById("btn-empty").disabled = false;
                            document.getElementById("btn-stop-distribution").style.display = 'none';
                            document.getElementById("btn-fallback-distribution").style.display = 'none';
                            $( "#progressbar" ).remove();
                            $('#flash-message').empty();
                            $('#flash-message').append("<p id='success-message' class='alert alert-success'>As turmas foram distribuídas nas salas com sucesso.</p>");
                            
                            setTimeout(function() { window.location.reload(); }, 2500);
                        }
                        return; // O RETURN É CRÍTICO! Interrompe o polling e o loop.
                    }
                }else{
                    document.getElementById("btn-stop-distribution").style.display = 'none';
                    document.getElementById("btn-fallback-distribution").style.display = 'none';
                    trackingJob = false;
                }
                
                setTimeout( progressDistribution, 1000);
            },
            error: function() {
                setTimeout( progressDistribution, 3000);
            }
        });
    }
    setTimeout( progressDistribution, 50 );
});
</script>
@endsection