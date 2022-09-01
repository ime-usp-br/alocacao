@extends('main')

@section('title', 'Dobradinhas')

@section('content')
  @parent 
<div id="layout_conteudo">
    <div class="justify-content-center">
        <div class="col-md-12">
            <h1 class='text-center mb-5'>Dobradinhas</h1>
            @if($schoolterm)
                <h4 class='text-center mb-5'>{{ $schoolterm->period . ' de ' . $schoolterm->year }}</h4>
            @endif

            <div id="progressbar-div">
            </div>
            <br>

            @if ($fusions->count() > 0)
                <table class="table table-bordered" style="font-size:12px;">
                    <tr style="background-color:#F5F5F5;vertical-align: middle;">
                        <th>Nome da Dobradinha</th>
                        <th>Horários</th>
                        <th>Professor(es)</th>
                        <th>Código da Turma</th>
                        <th>Código da Disciplina</th>
                        <th>Nome da Disciplina</th>
                        <th>Tipo da Turma</th>
                        <th>Desmembrar<br></th>
                    </tr>

                    @foreach($fusions as $fusion)
                        @foreach(range(0, count($fusion->schoolclasses)-1) as $x)
                            <tr style="font-size:12px;white-space: nowrap;">
                                @if($x == 0)
                                    <td rowspan="{{count($fusion->schoolclasses)}}" style="white-space: nowrap;
                                                                                    vertical-align: middle;">
                                        @foreach(range(0, count($fusion->schoolclasses)-1) as $y)
                                                {{$fusion->schoolclasses[$y]->coddis}}     
                                                {{$y != count($fusion->schoolclasses)-1 ? "/" : ""}}    
                                        @endforeach
                                    </td>
                                    <td rowspan="{{count($fusion->schoolclasses)}}" style="white-space: nowrap;
                                                                                    vertical-align: middle;">
                                        @foreach($fusion->master->classschedules as $horario)
                                            {{ $horario->diasmnocp . ' ' . $horario->horent . ' ' . $horario->horsai }} <br/>
                                        @endforeach
                                    </td>
                                    <td rowspan="{{count($fusion->schoolclasses)}}" style="white-space: nowrap;
                                                                                    vertical-align: middle;">
                                        @foreach($fusion->master->instructors as $instructor)
                                            {{ $instructor->getNomAbrev()}} <br/>
                                        @endforeach
                                    </td>
                                @endif
                                <td>{{ $fusion->schoolclasses[$x]->codtur }}</td>
                                <td>{{ $fusion->schoolclasses[$x]->coddis }}</td>
                                <td>{{ $fusion->schoolclasses[$x]->nomdis }}</td>
                                <td>{{ $fusion->schoolclasses[$x]->tiptur }}</td>
                                <td>
                                    <form method="get"  action="{{ route('fusions.disjoint', $fusion->schoolclasses[$x]) }}" style="display: inline;">
                                        @csrf
                                        <button class="btn px-0">
                                            <i class="fas fa-minus-circle"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    @endforeach
                </table>
            @else
                <p class="text-center">Não há dobradinhas cadastradas</p>
            @endif
        </div>
    </div>
</div>
@endsection