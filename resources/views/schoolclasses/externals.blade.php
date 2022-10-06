@extends('main')

@section('title', 'Turmas')

@section('content')
  @parent 
<div id="layout_conteudo">
    <div class="justify-content-center">
        <div class="col-md-12">
            <h1 class='text-center mb-5'>Turmas de outras unidades</h1>
            @if($schoolterm)
                <h4 class='text-center mb-5'>{{ $schoolterm->period . ' de ' . $schoolterm->year }}</h4>
            @endif

            <h4 class='text-center mb-5'>Turmas que não devem constar no relatório precisam ser excluidas</h4>
            <h4 class='text-center mb-5'>Turmas que serão ministradas no IME precisam ser transformadas em internas</h4>

            <div class="float-right" style="margin-bottom: 20px;">
                <form id="makeInternalInBatchForm" style="display: inline;"  action="{{ route('schoolclasses.makeInternalInBatch') }}" method="POST"
                enctype="multipart/form-data"
                >
                    @csrf
                    <button  class="btn btn-outline-primary"
                        type="submit"
                    >
                        Tornar Interno
                    </button>
                </form>
                
                <form id="deleteForm" style="display: inline;"  method="post" action="{{ route('schoolclasses.destroyInBatch') }}" method="POST"
                    enctype="multipart/form-data">
                    @csrf
                    <button class="btn btn-outline-primary"
                        type="submit"                        
                    >
                        Excluir
                    </button>
                </form>
            </div>
            <br>
            

            @if (count($turmas) > 0)
                <table class="table table-bordered table-striped table-hover" style="font-size:12px;">
                    <tr>
                        <th>Código da Disciplina</th>
                        <th>Código da Turma</th>
                        <th>Nome da Disciplina</th>
                        <th>Tipo da Turma</th>
                        <th>Horários</th>
                        <th>Professor(es)</th>
                        <th>Início</th>
                        <th>Fim</th>
                        <th>Tornar Interno</th>
                        <th>Excluir</th>
                        <th></th>
                    </tr>

                    @foreach($turmas as $turma)
                        <tr style="font-size:12px;">
                            <td>{{ $turma->coddis }}</td>
                            <td>{{ $turma->codtur }}</td>
                            <td>        
                                @if($turma->tiptur=='Graduação')                   
                                    <a class="text-dark" target="_blank"
                                        href="{{'https://uspdigital.usp.br/jupiterweb/obterTurma?nomdis=&sgldis='.$turma->coddis}}"
                                    >
                                        {{ $turma->nomdis }}
                                    </a>
                                @else
                                    {{ $turma->nomdis }}
                                @endif
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
                                <input id="school_classes_id" form="makeInternalInBatchForm" class="checkbox" type="checkbox" name="school_classes_id[]" value="{{ $turma->id }}">
                            </td>
                            <td>
                                <input id="school_classes_id" form="deleteForm" class="checkbox" type="checkbox" name="school_classes_id[]" value="{{ $turma->id }}">
                            </td>
                            <td class="text-center" style="white-space: nowrap;">
                                <a class="text-dark text-decoration-none"
                                    data-toggle="tooltip" data-placement="top"
                                    title="Editar"
                                    href="{{ route('schoolclasses.edit', $turma) }}"
                                >
                                    <i class="fas fa-edit"></i>
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