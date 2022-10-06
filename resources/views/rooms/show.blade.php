@extends('main')

@section('title', 'Sala')

@section('content')
  @parent 
<div id="layout_conteudo">
    <div class="justify-content-center">
        <div class="col-md-12">
            <h1 class='text-center mb-5'>Sala {{$room->nome}}</h1>

            <h3 class='text-center mb-5'>Assentos {{$room->assentos}}</h3>

            @php
                $st = App\Models\SchoolTerm::getLatest();
                $pallet = ['#F0FFF0','#FFFFF0','#F0E68C','#E6E6FA','#FFF0F5','#7CFC00','#D8BFD8','#ADD8E6','#F08080',
                          '#E0FFFF','#FAFAD2','#D3D3D3','#90EE90','#FFB6C1','#FFA07A','#20B2AA','#87CEFA','#778899',
                          '#B0C4DE','#FFFFE0','#F8F8FF','#F5FFFA','#FFE4E1','#FDF5E6','#FFDEAD','#EEE8AA','#AFEEEE'];
                $i = 0;
                $horarios = [];
                $dias_efetivos = [];
                foreach($room->schoolclasses()->whereBelongsTo($st)->get() as $sc){
                    $cores[$sc->id] = $pallet[$i];
                    $i+=1;
                    foreach($sc->classschedules as $cs){
                        array_push($dias_efetivos, $cs->diasmnocp);
                        array_push($horarios, $cs->horent);
                        array_push($horarios, $cs->horsai);
                    }
                }
                $horarios = array_unique($horarios);
                $dias_efetivos = array_unique($dias_efetivos);

                sort($horarios, SORT_REGULAR);      

                $dias = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sab'];  

                if(!in_array("dom",$dias_efetivos)){
                    unset($dias[array_search("dom", $dias)]);
                }
                if(!in_array("sab",$dias_efetivos)){
                    unset($dias[array_search("sab", $dias)]);
                }

            @endphp


            <p class="text-right">
                <a  class="btn btn-outline-primary"
                    id="btn-allocateSchoolClass"
                    data-toggle="modal"
                    data-target="#allocateModal"
                >
                    <i class="fas fa-plus-circle"></i>
                    Alocar Turma
                </a>
                <a  class="btn btn-outline-primary"
                    href="{{ route('rooms.index') }}"
                >
                    <i class="fas fa-arrow-alt-circle-left"></i>
                    Voltar
                </a>
                    
            </p>
            
            @include('rooms.modals.allocate')

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
                                                            $label = "";
                                                            $nomdis = $turma->fusion->schoolclasses->pluck("nomdis")->unique()->toArray();
                                                            if($turma->fusion->schoolclasses->pluck("coddis")->unique()->count() == 1){
                                                                $dobradinha .= $turma->fusion->schoolclasses[0]->coddis." ";
                                                                $label .= $turma->fusion->schoolclasses[0]->nomdis;
                                                                foreach(range(0, count($turma->fusion->schoolclasses)-1) as $y){
                                                                    $dobradinha .= "T.".substr($turma->fusion->schoolclasses[$y]->codtur, -2, 2);
                                                                    $dobradinha .= $y != count($turma->fusion->schoolclasses)-1 ? "/" : "";
                                                                }
                                                            }elseif(count($nomdis)==1 and in_array("Trabalho de Formatura", $nomdis)){
                                                                $dobradinha .= "MAP20XX";
                                                                $label .= "Trabalho de Formatura\n";
                                                                foreach(range(0, count($turma->fusion->schoolclasses)-1) as $y){
                                                                    $label .= $turma->fusion->schoolclasses[$y]->coddis." T. ".$turma->fusion->schoolclasses[$y]->codtur;
                                                                    $label .= $y != count($turma->fusion->schoolclasses)-1 ? "\n" : "";
                                                                }
                                                            }elseif($turma->fusion->schoolclasses()->where("tiptur","Graduação")->get()->count() == $turma->fusion->schoolclasses->count()){
                                                                foreach(range(0, count($turma->fusion->schoolclasses)-1) as $y){
                                                                    $dobradinha .= $turma->fusion->schoolclasses[$y]->coddis." T.".substr($turma->fusion->schoolclasses[$y]->codtur, -2, 2);
                                                                    $dobradinha .= $y != count($turma->fusion->schoolclasses)-1 ? "/" : "";
                                                                }

                                                            }else{
                                                                foreach(range(0, count($turma->fusion->schoolclasses)-1) as $y){
                                                                    $dobradinha .= $turma->fusion->schoolclasses[$y]->coddis;
                                                                    $dobradinha .= $y != count($turma->fusion->schoolclasses)-1 ? "/" : "";
                                                                    $label .= $turma->fusion->schoolclasses[$y]->nomdis;
                                                                    $label .= $y != count($turma->fusion->schoolclasses)-1 ? "\n" : "";
                                                                }
                                                                $dobradinha .= " T.".substr($turma->codtur, -2, 2);
                                                            }
                                                    
                                                        @endphp
                                                        <a class="text-dark" target="_blank"
                                                            title="{{ $label }}"
                                                            href="{{'https://uspdigital.usp.br/jupiterweb/obterTurma?nomdis=&sgldis='.$turma->coddis}}"
                                                        >
                                                            {{$dobradinha}}
                                                        </a>
                                                                                                                  
                                                    @else
                                                        @php
                                                            $label = $turma->nomdis;
                                                            $label .= "\n". ($turma->estmtr ? "Número estimado de matriculados ".$turma->estmtr : "Não foram encontrados registros anteriores para calcular uma estimativa de matriculados");
                                                        @endphp
                                                        <a class="text-dark" target="_blank"
                                                            title="{{ $label }}"
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
                        <th>Código da Disciplina</th>
                        <th>Código da Turma</th>
                        <th>Nome da Disciplina</th>
                        <th>Tipo da Turma</th>
                        <th>Horários</th>
                        <th>Professor(es)</th>
                    </tr>

                    @foreach($turmas_nao_alocadas as $turma)
                        <tr style="font-size:12px;">
                            <td>{{ $turma->coddis }}</td>
                            <td>{{ $turma->codtur }}</td>
                            <td>                                
                                <a class="text-dark" target="_blank"
                                    href="{{ $turma->tiptur=='Graduação' ? 'https://uspdigital.usp.br/jupiterweb/obterTurma?nomdis=&sgldis='.$turma->coddis : ''}}"
                                >
                                    {{ $turma->nomdis }}
                                </a>
                            </td>
                            <td>{{ $turma->tiptur }}</td>
                            <td style="{{ $room->isCompatible($turma, $ignore_block=true, $ignore_estmtr=true) ? 'white-space: nowrap;color:green;' : 'white-space: nowrap;color:red' }}">
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
                </table>
            @endif

            @if ($dobradinhas_nao_alocadas->count() > 0)
                <br>
                <h3 class='text-center mb-5'>Dobradinhas não alocadas</h3>

                <table class="table table-bordered" style="font-size:12px;">
                    <tr style="background-color:#F5F5F5">
                        <th>Nome da Dobradinha</th>
                        <th>Código da Disciplina</th>
                        <th>Código da Turma</th>
                        <th>Nome da Disciplina</th>
                        <th>Tipo da Turma</th>
                        <th>Horários</th>
                        <th>Professor(es)</th>
                    </tr>

                    @foreach($dobradinhas_nao_alocadas as $fusion)
                        @foreach(range(0, count($fusion->schoolclasses)-1) as $x)
                            <tr style="font-size:12px;white-space: nowrap;">
                                @if($x == 0)
                                    <td rowspan="{{count($fusion->schoolclasses)}}" style="white-space: nowrap;
                                                                                    vertical-align: middle;">
                                        @php
                                            $nomdis = $fusion->schoolclasses->pluck("nomdis")->unique()->toArray();
                                        @endphp
                                        @if($fusion->schoolclasses->pluck("coddis")->unique()->count()==1)
                                            {{ $fusion->master->coddis }}
                                            @foreach(range(0, count($fusion->schoolclasses)-1) as $y)
                                                    {{ " T.".substr($fusion->schoolclasses[$y]->codtur,-2,2) }}     
                                                    {{ $y != count($fusion->schoolclasses)-1 ? "/" : "" }}    
                                            @endforeach
                                        @elseif(count($nomdis)==1 and in_array("Trabalho de Formatura", $nomdis))
                                            MAP20XX
                                        @else
                                            @foreach(range(0, count($fusion->schoolclasses)-1) as $y)
                                                    {{ $fusion->schoolclasses[$y]->coddis }}     
                                                    {{ $y != count($fusion->schoolclasses)-1 ? "/" : "" }}    
                                            @endforeach
                                        @endif
                                    </td>
                                @endif
                                <td>{{ $fusion->schoolclasses[$x]->coddis }}</td>
                                <td>{{ $fusion->schoolclasses[$x]->codtur }}</td>
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
                                    style="{{ $room->isCompatible($fusion->master, $ignore_block=true, $ignore_estmtr=true) ? 'white-space: nowrap;vertical-align: middle;color:green;' : 'white-space: nowrap;vertical-align: middle;color:red' }}">
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
                            </tr>
                        @endforeach
                    @endforeach
                </table>
            @endif
        </div>
    </div>
</div>
@endsection