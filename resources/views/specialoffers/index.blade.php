@extends('main')

@section('title', 'Oferecimentos Especiais')

@section('content')
  @parent 
<div id="layout_conteudo">
    <div class="justify-content-center">
        <div class="col-md-12">
            <h1 class='text-center mb-5'>Oferecimentos Especiais</h1>

            @include('specialoffers.modals.addSpecialOffer')

            <div class="float-right" style="margin-bottom: 20px;">
                <p class="text-right" style="display: inline;" >
                    <a  class="btn btn-outline-primary"
                        title="Cadastrar" 
                        data-toggle="modal"
                        data-target="#addSpecialOfferModal"
                    >
                        <i class="fas fa-plus-circle"></i>
                        Cadastrar
                    </a>                        
                </p>
            </div>

            @if (count($especiais) > 0)
                <table class="table table-bordered" style="font-size:12px;">
                    <tr style="background-color:#F5F5F5;vertical-align: middle;">
                        <th>Curso</th>
                        <th>Código da Disciplina</th>
                        <th>Nome da Disciplina</th>
                        <th>Código da Turma</th>
                        <th>Horários</th>
                        <th>Professor(es)</th>
                    </tr>

                    @foreach($especiais as $especial)
                        <tr style="font-size:12px;">
                            <td rowspan="{{ $especial['nrows'] }}" style="vertical-align: middle;">{{ $especial['nomcur'] }}</td>

                            @foreach($especial['disciplinas'] as $disciplina)
                                <td rowspan="{{ max(1,$disciplina['numero de turmas']) }}" style="vertical-align: middle;">     
                                    {{ $disciplina['coddis'] }}                           
                                    <a class="text-dark text-decoration-none"
                                        data-toggle="modal"
                                        data-target="#removalModal"
                                        title="Remover"
                                        href="{{ route(
                                            'specialoffers.destroy',
                                            $disciplina['id']
                                        ) }}"
                                    >
                                        <i class="fas fa-trash-alt"></i>
                                    </a>
                                </td>
                                <td rowspan="{{ max(1,$disciplina['numero de turmas']) }}" style="vertical-align: middle;">             
                                    {{ $disciplina['nomdis'] }}
                                </td>
                                @if($disciplina['numero de turmas'] == 0)
                                    <td>Sem oferecimento</td>
                                    <td>Sem oferecimento</td>
                                    <td>Sem oferecimento</td>
                                    </tr>
                                @else
                                    @foreach($disciplina['turmas'] as $turma)
                                        <td>{{ $turma->codtur }}</td>
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
                                    </tr>
                                    @endforeach
                                @endif
                            @endforeach
                    @endforeach
                </table>
                @include('schoolclasses.modals.removal')
            @else
                <p class="text-center">Não há oferecimentos especiais cadastrados</p>
            @endif
        </div>
    </div>
</div>
@endsection