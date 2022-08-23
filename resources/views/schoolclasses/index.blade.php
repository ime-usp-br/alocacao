@extends('main')

@section('title', 'Turmas')

@section('content')
  @parent 
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-12">
            <h1 class='text-center mb-5'>Turmas Ministradas no IME</h1>
            @if($schoolterm)
                <h4 class='text-center mb-5'>{{ $schoolterm->period . ' de ' . $schoolterm->year }}</h4>
            @endif

            <div id="progressbar-div">
            </div>
            <br>

            <div class="float-right" style="margin-bottom: 20px;">
                <p class="text-right" style="display: inline;" >
                    <a  class="btn btn-primary"
                        title="Cadastrar" 
                        href="{{ route('schoolclasses.create') }}"
                    >
                        <i class="fas fa-plus-circle"></i>
                        Cadastrar
                    </a>
                    
                    <a  class="btn btn-primary"
                        title="Importar" 
                        href="{{ route('schoolclasses.import') }}"
                    >
                        <i class="fas fa-file-upload"></i>
                        Importar do Jupiter
                    </a>
                        
                </p>
                <form id="makeExternalInBatchForm" style="display: inline;"  action="{{ route('schoolclasses.makeExternalInBatch') }}" method="POST"
                enctype="multipart/form-data"
                >
                    @csrf
                    <button  class="btn btn-primary"
                        type="submit"
                    >
                        Tornar Externo
                    </button>
                </form>
            </div>

            @if (count($turmas) > 0)
                <table class="table table-bordered table-striped table-hover" style="font-size:12px;">
                    <tr>
                        <th>Código da Turma</th>
                        <th>Código da Disciplina</th>
                        <th>Nome da Disciplina</th>
                        <th>Tipo da Turma</th>
                        <th>Horários</th>
                        <th>Professor(es)</th>
                        <th>Início</th>
                        <th>Fim</th>
                        <th>Tornar<br>Externo</th>
                        <th></th>
                    </tr>

                    @foreach($turmas as $turma)
                        <tr style="font-size:12px;">
                            <td>{{ $turma->codtur }}</td>
                            <td>{{ $turma->coddis }}</td>
                            <td>                                
                                <a class="text-dark" target="_blank"
                                    href="{{ $turma->tiptur=='Graduação' ? 'https://uspdigital.usp.br/jupiterweb/obterTurma?nomdis=&sgldis='.$turma->coddis : ''}}"
                                >
                                    {{ $turma->nomdis }}
                                </a>
                            </td>
                            <td>{{ $turma->tiptur }}</td>
                            <td style="white-space: nowrap;">
                                @foreach($turma->classschedules as $horario)
                                    {{ $horario->diasmnocp . ' ' . $horario->horent . ' ' . $horario->horsai }} <br/>
                                @endforeach
                            </td>
                            <td style="white-space: nowrap;">
                                @foreach($turma->instructors as $instructor)
                                    {{ $instructor->getNomAbrev()}} <br/>
                                @endforeach
                            </td>
                            <td>{{ $turma->dtainitur }}</td>
                            <td>{{ $turma->dtafimtur }}</td>
                            <td>
                                <input id="school_classes_id" form="makeExternalInBatchForm" class="checkbox" type="checkbox" name="school_classes_id[]" value="{{ $turma->id }}">
                            </td>
                            <td class="text-center" style="white-space: nowrap;">
                                <a class="text-dark text-decoration-none"
                                    data-toggle="tooltip" data-placement="top"
                                    title="Editar"
                                    href="{{ route('schoolclasses.edit', $turma) }}"
                                >
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a class="text-dark text-decoration-none"
                                    data-toggle="modal"
                                    data-target="#removalModal"
                                    title="Remover"
                                    href="{{ route(
                                        'schoolclasses.destroy',
                                        $turma
                                    ) }}"
                                >
                                    <i class="fas fa-trash-alt"></i>
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </table>
                @include('schoolclasses.modals.removal')
            @else
                <p class="text-center">Não há turmas cadastradas</p>
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
            url: window.location.origin+'/monitor/getImportProcess',
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
                                    location.replace(location);
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
});
</script>
@endsection