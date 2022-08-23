@extends('main')

@section('title', 'Sala')

@section('content')
  @parent 
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-12">
            <h1 class='text-center mb-5'>Salas livres por horário</h1>

            @php
                $st = App\Models\SchoolTerm::getLatest();
                $pallet = ['#F0FFF0','#FFFFF0','#F0E68C','#E6E6FA','#FFF0F5','#7CFC00','#D8BFD8','#ADD8E6','#F08080',
                          '#E0FFFF','#FAFAD2','#D3D3D3','#90EE90','#FFB6C1','#FFA07A','#20B2AA','#87CEFA','#778899',
                          '#B0C4DE','#FFFFE0','#F8F8FF','#F5FFFA','#FFE4E1','#FDF5E6','#FFDEAD','#EEE8AA','#AFEEEE'];
                $i = 0;
                $horarios = [
                    "08:00"=>"09:40",
                    "10:00"=>"11:40",
                    "14:00"=>"15:40",
                    "16:00"=>"17:40",
                    "19:20"=>"21:00",
                    "21:10"=>"22:50",
                ];

                $dias = ['seg', 'ter', 'qua', 'qui', 'sex'];  

            @endphp

            <div class="d-flex justify-content-center">
                <div class="col-md-12">
                    <table class="table table-bordered" style="font-size:15px;">
                        <tr style="background-color:#F5F5F5">
                            <th>Horário</th>
                            @foreach($dias as $dia)
                                <th style="min-width:150px">{{ $dia }}</th>
                            @endforeach
                        </tr>
                        @foreach($horarios as $horent=>$horsai)
                            <tr>
                                <td style="vertical-align: middle;">{{ $horent }}<br>às<br>{{ $horsai }}</td>
                                @foreach($dias as $dia)
                                    @php
                                        $rooms = App\Models\Room::whereDoesntHave("schedules", function($query)use($dia, $horent, $horsai){
                                            $query->where("diasmnocp", $dia)->where("horsai",">",$horent)->where("horent", "<", $horsai);
                                        })->get();
                                    @endphp                                
                                <td style="vertical-align: middle;">
                                    @foreach($rooms ? range(0, count($rooms)-1) : [] as $x)
                                        <a class="text-dark" target="_blank"
                                            href="{{ route('rooms.show', $rooms[$x]) }}"
                                        >
                                            {{ $rooms[$x]->nome }}
                                        </a>
                                        @if(($x+1) % 3 == 0)
                                            <br>
                                        @elseif($x != count($rooms)-1)
                                            -
                                        @endif
                                    @endforeach
                                </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </table>

                    @php
                        $turmas_nao_alocadas = App\Models\SchoolClass::whereBelongsTo($st)->where("externa", false)->whereDoesntHave("room")->whereDoesntHave("fusion")->get();
                        $dobradinhas_nao_alocadas = App\Models\Fusion::whereHas("schoolclasses", function ($query) use ($st){
                                                            $query->whereBelongsTo($st);
                                                        })->whereHas("master", function ($query){
                                                            $query->whereDoesntHave("room");
                                                        })->get();
                    @endphp
                    @if($turmas_nao_alocadas)
                    <br>
                    <h3 class='text-center mb-5'>Turmas não alocadas</h3>

                        <table class="table table-bordered table-striped table-hover" style="font-size:12px;">
                            <tr>
                                <th>Código da Turma</th>
                                <th>Código da Disciplina</th>
                                <th>Nome da Disciplina</th>
                                <th>Tipo da Turma</th>
                                <th>Horários</th>
                                <th>Professor(es)</th>
                                <th>Salas<br>Compatíveis</th>
                            </tr>

                            @foreach($turmas_nao_alocadas as $turma)
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
                                    <td style="white-space: nowrap;">
                                        @php
                                            $rooms = App\Models\Room::all();
                                            $rooms = $rooms->filter(function($room)use($turma){
                                                return $room->isCompatible($turma,$ignore_estmtr=true, $ignore_block=true);
                                            })->values();
                                        @endphp   
                                        @foreach($rooms->isNotEmpty() ? range(0, count($rooms)-1) : [] as $x)
                                            <a class="text-dark" target="_blank"
                                                href="{{ route('rooms.show', $rooms[$x]) }}"
                                            >
                                                {{ $rooms[$x]->nome }}
                                            </a>
                                            @if(($x+1) % 3 == 0)
                                                <br>
                                            @elseif($x != count($rooms)-1)
                                                -
                                            @endif
                                        @endforeach   
                                    </td>
                                </tr>
                            @endforeach
                        </table>
                    @endif

                    @if ($dobradinhas_nao_alocadas->count() > 0)
                        <br>
                        <h3 class='text-center mb-5'>Dobradinhas não alocadas</h3>

                        <table class="table table-bordered" style="font-size:12px;">
                            <tr style="background-color:#F5F5F5">
                                <th>Nome da Dobradinha</th>
                                <th>Código da Turma</th>
                                <th>Código da Disciplina</th>
                                <th>Nome da Disciplina</th>
                                <th>Tipo da Turma</th>
                                <th>Horários</th>
                                <th>Professor(es)</th>
                                <th>Salas<br>Compatíveis</th>
                            </tr>

                            @foreach($dobradinhas_nao_alocadas as $fusion)
                                @foreach(range(0, count($fusion->schoolclasses)-1) as $x)
                                    <tr style="font-size:12px;white-space: nowrap;">
                                        @if($x == 0)
                                            <td rowspan="{{count($fusion->schoolclasses)}}" style="white-space: nowrap;
                                                                                            vertical-align: middle;">
                                                @if($fusion->schoolclasses->pluck("coddis")->unique()->count()==1)
                                                    {{ $fusion->master->coddis }}
                                                    @foreach(range(0, count($fusion->schoolclasses)-1) as $y)
                                                            {{ " T.".substr($fusion->schoolclasses[$y]->codtur,-2,2) }}     
                                                            {{ $y != count($fusion->schoolclasses)-1 ? "/" : "" }}    
                                                    @endforeach
                                                @else
                                                    @foreach(range(0, count($fusion->schoolclasses)-1) as $y)
                                                            {{ $fusion->schoolclasses[$y]->coddis }}     
                                                            {{ $y != count($fusion->schoolclasses)-1 ? "/" : "" }}    
                                                    @endforeach
                                                @endif
                                            </td>
                                        @endif
                                        <td>{{ $fusion->schoolclasses[$x]->codtur }}</td>
                                        <td>{{ $fusion->schoolclasses[$x]->coddis }}</td>
                                        <td>
                                            @if($fusion->schoolclasses[$x]->tiptur == "Graduação")                    
                                                <a class="text-dark" target="_blank"
                                                    href="{{ 'https://uspdigital.usp.br/jupiterweb/obterTurma?nomdis=&sgldis='.$fusion->schoolclasses[$x]->coddis }}"
                                                >
                                                    {{ $fusion->schoolclasses[$x]->nomdis }}
                                                </a>
                                            @else
                                                {{ $fusion->schoolclasses[$x]->nomdis }}
                                            @endif
                                        </td>
                                        <td>{{ $fusion->schoolclasses[$x]->tiptur }}</td>
                                        @if($x == 0)
                                            <td rowspan="{{count($fusion->schoolclasses)}}" 
                                            style="white-space: nowrap;vertical-align: middle;">
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
                                            <td rowspan="{{count($fusion->schoolclasses)}}">  
                                                @php
                                                    $rooms = App\Models\Room::all();
                                                    $rooms = $rooms->filter(function($room)use($fusion){
                                                        return $room->isCompatible($fusion->master,$ignore_estmtr=true, $ignore_block=true);
                                                    })->values();
                                                @endphp   
                                                @foreach($rooms->isNotEmpty() ? range(0, count($rooms)-1) : [] as $x)
                                                    <a class="text-dark" target="_blank"
                                                        href="{{ route('rooms.show', $rooms[$x]) }}"
                                                    >
                                                        {{ $rooms[$x]->nome }}
                                                    </a>
                                                    @if(($x+1) % 3 == 0)
                                                        <br>
                                                    @elseif($x != count($rooms)-1)
                                                        -
                                                    @endif
                                                @endforeach   
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            @endforeach
                        </table>
                    @endif
                </div>
            </div>                
        </div>
    </div>
</div>
@endsection

@section('javascripts_bottom')
@parent
<script>
$( function() {       
    function refresh() {
        document.location.reload();
        setTimeout( refresh, 2000);
    }        
    setTimeout( refresh, 2000 );
});
</script>
@endsection
