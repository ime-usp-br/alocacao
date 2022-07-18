@extends('main')

@section('title', 'Sala')

@section('content')
  @parent 
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-12">
            <h1 class='text-center mb-5'>Sala {{$room->nome}}</h1>

            <h3 class='text-center mb-5'>Assentos {{$room->assentos}}</h3>

            @php
                $st = App\Models\SchoolTerm::getLatest();
                $horarios = [];
                $pallet = ['#F0FFF0','#FFFFF0','#F0E68C','#E6E6FA','#FFF0F5','#7CFC00','#D8BFD8','#ADD8E6','#F08080',
                          '#E0FFFF','#FAFAD2','#D3D3D3','#90EE90','#FFB6C1','#FFA07A','#20B2AA','#87CEFA','#778899',
                          '#B0C4DE','#FFFFE0','#F8F8FF','#F5FFFA','#FFE4E1','#FDF5E6','#FFDEAD','#EEE8AA','#AFEEEE'];
                $i = 0;
                $cores;
                foreach($room->schoolclasses()->whereBelongsTo($st)->get() as $sc){
                    $cores[$sc->id] = $pallet[$i];
                    $i+=1;
                    foreach($sc->classschedules as $cs){
                        array_push($horarios, $cs->horent);
                        array_push($horarios, $cs->horsai);
                    }
                }
                $horarios = array_unique($horarios);
                sort($horarios, SORT_REGULAR);      
                $dias = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sab'];  

                $compativeis = 0;
                foreach(App\Models\SchoolClass::whereBelongsTo($st)->whereDoesntHave("room")->whereDoesntHave("fusion")->get() as $t){
                    if($room->isCompatible($t)){
                        $compativeis += 1;
                    }
                }

                $nao_alocadas = App\Models\SchoolClass::whereBelongsTo($st)->whereDoesntHave("room")->whereDoesntHave("fusion")->get()->count();
                $nao_alocadas += App\Models\SchoolClass::whereExists(function($query){$query->from("fusions")->whereColumn("fusions.master_id","school_classes.id");})->whereDoesntHave("room")->get()->count();
            @endphp

            <h3 class='text-center mb-5'>Total de turmas não alocadas {{$nao_alocadas}}</h3>
            <h3 class='text-center mb-5'>Turmas não alocadas compativeis {{$compativeis}}</h3>

            @if (count($room->schoolclasses()->whereBelongsTo($st)->get()) > 0)
                <div class="d-flex justify-content-center">
                    <div class="col-md-12">
                    <table class="table table-bordered" style="font-size:15px;">
                        <tr style="background-color:#F5F5F5">
                            <th>Horário</th>
                            @foreach($dias as $dia)
                                <th style="min-width:150px">{{ $dia }}</th>
                            @endforeach
                        </tr>

                        @foreach($horarios ? range(0, count($horarios)-2) : [] as $x)
                            <tr>
                                    <td style="white-space: nowrap;">{{ $horarios[$x]."-".$horarios[$x+1] }}</td>
                                    @foreach($dias as $dia)
                                        @php
                                            $turma = $room->schoolclasses()->whereBelongsTo(App\Models\SchoolTerm::getLatest())
                                                        ->whereHas('classschedules', function($query) use($dia, $horarios, $x) {
                                                            $query->where('diasmnocp', $dia)
                                                                    ->where('horent', '<=', $horarios[$x])
                                                                    ->where('horsai', '>=', $horarios[$x+1]);
                                                                })->first();
                                        @endphp

                                        @if($turma)
                                            @php
                                                $classschedule = $turma->classschedules()->where('diasmnocp', $dia)->where('horent', $horarios[$x])->first();
                                                $excesao = $turma->classschedules()->where('diasmnocp', $dia)->where('horsai', $horarios[$x+1])->first();
                                                $excesao2 = $turma->classschedules()->where('diasmnocp', $dia)->where('horent',"<", $horarios[$x])->where('horsai',">", $horarios[$x+1])->first();
                                            @endphp
                                            @if($classschedule)
                                                @php $i+=1; @endphp
                                                <td style="white-space: nowrap;
                                                        vertical-align: middle;
                                                        background-color:{{$cores[$turma->id]}};" 
                                                    rowspan={{array_search($classschedule->horsai, $horarios) - array_search($classschedule->horent, $horarios)}}>
                                                    @if($turma->fusion()->exists())
                                                        @php
                                                            $dobradinha = "";
                                                            foreach(range(0, count($turma->fusion->schoolclasses)-1) as $y){
                                                                $dobradinha .= $turma->fusion->schoolclasses[$y]->coddis;
                                                                $dobradinha .= $y != count($turma->fusion->schoolclasses)-1 ? "/" : "";
                                                            }
                                                        @endphp
                                                        <a class="text-dark" target="_blank"
                                                            href="{{'https://uspdigital.usp.br/jupiterweb/obterTurma?nomdis=&sgldis='.$turma->coddis}}"
                                                        >
                                                            {{$dobradinha}}
                                                        </a>
                                                                                                                  
                                                    @else
                                                        @php
                                                            $title = $turma->estmtr ? "Número estimado de matriculados ".$turma->estmtr : "Não foram encontrados registros anteriores para calcular uma estimativa de matriculados";
                                                        @endphp
                                                        <a class="text-dark" target="_blank"
                                                            title="{{ $title }}"
                                                            href="{{'https://uspdigital.usp.br/jupiterweb/obterTurma?nomdis=&sgldis='.$turma->coddis}}"
                                                        >
                                                            {{ $turma->coddis.($turma->tiptur=="Graduação" ? " T.".substr($turma->codtur, -2, 2) : "") }}
                                                        </a>
                                                        
                                                    @endif
                                                    <a class="text-dark text-decoration-none"
                                                        title="Remover"
                                                        data-method="delete"
                                                        href="{{ route(
                                                            'rooms.dissociate',
                                                            $turma
                                                        ) }}"
                                                    >
                                                        <i class="fas fa-trash-alt"></i>
                                                    </a>
                                                </td>
                                            @elseif(!$excesao and !$excesao2)
                                                <td></td>    
                                            @endif
                                        @else
                                            <td></td>                                                    
                                        @endif
                                    @endforeach
                                </tr>
                        @endforeach
                    </table>
                </div>
                </div>
            @else
                <p class="text-center">Não há turmas nessa sala no {{$st->period}}-{{$st->year}}</p>
            @endif
        </div>
    </div>
</div>
@endsection