@extends('main')

@section('title', 'Horário das Disciplinas')

@section('content')
  @parent 
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-12">
            @php
                $schoolterm = App\Models\SchoolTerm::getLatest();
            @endphp
            <h1 class='text-center mb-5'><b>Matemática Licenciatura</b></h1>
            <h2 class='text-center mb-5'>Horário das Disciplinas - {!! $schoolterm->period . ' de ' . $schoolterm->year !!}</h2>

            <div class="d-flex justify-content-center">
                <div class="col-md-6">
                    <table class="table table-bordered table-striped table-hover" style="font-size:15px;">
                        <tr>
                            <th>Código do Curso</th>
                            <th>Período</th>
                        </tr>

                        <tr style="font-size:12px;">
                                <td>45024</td>
                                <td>Noturno </td>
                        </tr>
                    </table>
                </div>
            </div>

            @foreach(App\Models\Observation::whereBelongsTo($schoolterm)->get() as $observation)
                <div class="card my-3">
                    <div class="card-body">
                        <h3 class='card-title' style="color:blue">{!! $observation->title !!}</h3>
                        @foreach(explode("\r\n", $observation->body) as $line)
                            <p class="card-text" style="color:blue">{!! $line !!} </p>
                        @endforeach
                    </div>
                </div>
            @endforeach

            @php
                $semestres = $schoolterm->period == "1° Semestre" ? [1,3,5,7,9] : [2,4,6,8,10];
            @endphp
            @foreach($semestres as $semestre)
                @php $iguais = false; @endphp
                @foreach(["A","B"] as $grupo)
                    @if(!$iguais)
                        @php    
                            $course = App\Models\Course::where("codcur",45024)->where("grupo",$grupo)->first();
                            $turmas = App\Models\SchoolClass::whereBelongsTo($schoolterm)
                                ->whereHas("courseinformations", function($query)use($semestre, $course){
                                    $query->whereIn("numsemidl",[$semestre-1,$semestre])
                                        ->where("nomcur",$course->nomcur)
                                        ->where("perhab", $course->perhab)
                                        ->where("tipobg", "O");
                                    })->get();           

                            $turmas_grupoA = $turmas->filter(function($turma)use($turmas, $schoolterm){
                                    $codturs = $turmas->where("coddis",$turma->coddis)->pluck("codtur")->toArray();
                                    $prefixo_codtur = $schoolterm->year.($schoolterm->period == "1° Semestre" ? "1" : "2");
                                    if(in_array($prefixo_codtur."47", $codturs) and in_array($prefixo_codtur."48", $codturs)){
                                        if(substr($turma->codtur,-2,2)!="48"){
                                            return true;
                                        }else{
                                            return false;
                                        }
                                    }else{
                                        return true;
                                    }
                                });

                            $turmas_grupoB = $turmas->filter(function($turma)use($turmas, $schoolterm){
                                    $codturs = $turmas->where("coddis",$turma->coddis)->pluck("codtur")->toArray();
                                    $prefixo_codtur = $schoolterm->year.($schoolterm->period == "1° Semestre" ? "1" : "2");
                                    if(in_array($prefixo_codtur."47", $codturs) and in_array($prefixo_codtur."48", $codturs)){
                                        if(substr($turma->codtur,-2,2)!="47"){
                                            return true;
                                        }else{
                                            return false;
                                        }
                                    }else{
                                        return true;
                                    }
                                });
                            
                            if($turmas_grupoA->diff($turmas_grupoB)->isEmpty() and $turmas_grupoB->diff($turmas_grupoA)->isEmpty()){
                                $turmas = $turmas_grupoA;
                                $iguais = true;
                            }elseif($course->grupo=="A"){
                                $turmas = $turmas_grupoA;
                            }elseif($course->grupo=="B"){
                                $turmas = $turmas_grupoB;
                            }

                            $turmas = $turmas->filter(function($turma){
                                foreach($turma->classschedules as $schedule){
                                    if($schedule->horent < "18:00"){
                                        return false;
                                    }
                                }
                                return true;
                            });

                            $dias = ['seg', 'ter', 'qua', 'qui', 'sex'];  

                            $temSab = $turmas->filter(function($turma){
                                foreach($turma->classschedules as $schedule){
                                    if($schedule->diasmnocp=="sab"){
                                        return true;
                                    }
                                }
                                return false;
                            })->isNotEmpty();

                            if($temSab){
                                array_push($dias, "sab");
                            }

                            $schedules = array_unique(App\Models\ClassSchedule::whereHas("schoolclasses", function($query)use($turmas){$query->whereIn("id",$turmas->pluck("id")->toArray());})->select(["horent","horsai"])->where("diasmnocp", "!=", "dom")->get()->toArray(),SORT_REGULAR);

                            array_multisort(array_column($schedules, "horent"), SORT_ASC, $schedules);

                            $horarios = [];
                            foreach($schedules as $schedule){
                                array_push($horarios, $schedule["horent"]." às ".$schedule["horsai"]);
                            }
                        @endphp
                        @if($turmas->isNotEmpty())
                            <h2 class="text-left"><b>{!! $semestre."° Semestre ".($iguais ? "Grupos A e B" : "Grupo ".$course->grupo) !!}</b></h2>
                            <br>
                            <table class="table table-bordered" style="font-size:15px;">
                                <tr style="background-color:#F5F5F5">
                                    <th>Horários</th>
                                    <th>Segunda</th>
                                    <th>Terça</th>
                                    <th>Quarta</th>
                                    <th>Quinta</th>
                                    <th>Sexta</th>
                                    @if($temSab)
                                        <th>Sábado</th>
                                    @endif
                                </tr>
                                @foreach($horarios as $h)
                                    <tr>
                                        <td style="vertical-align: middle;" width="170px">{{ explode(" ",$h)[0] }}<br>{{ explode(" ",$h)[1] }}<br>{{ explode(" ",$h)[2] }}</td>
                                        @foreach($dias as $dia)
                                            @php $done = []; @endphp
                                            <td style="vertical-align: middle;" width="180px">                                                
                                                @foreach($turmas as $turma)
                                                    @if($turma->classschedules()->where("diasmnocp",$dia)->where("horent",explode(" ",$h)[0])->where("horsai",explode(" ",$h)[2])->get()->isNotEmpty())
                                                        @if(!$turma->externa)
                                                            <a class="text-dark" target="_blank"
                                                                href="{{'https://uspdigital.usp.br/jupiterweb/obterTurma?nomdis=&sgldis='.$turma->coddis}}"
                                                            >
                                                                {!! $turma->coddis." T.".substr($turma->codtur,-2,2) !!}
                                                            </a>
                                                            <br>
                                                        @elseif(!in_array($turma->id, $done))
                                                            <a class="text-dark" target="_blank"
                                                                href="{{'https://uspdigital.usp.br/jupiterweb/obterTurma?nomdis=&sgldis='.$turma->coddis}}"
                                                            >
                                                                {!! $turma->coddis." " !!}
                                                                @php $coddis = $turma->coddis; @endphp
                                                                @foreach($turmas->filter(function($t)use($coddis){return $t->coddis == $coddis;}) as $turma2)
                                                                    @if($turma2->classschedules()->where("diasmnocp",$dia)->where("horent",explode(" ",$h)[0])->where("horsai",explode(" ",$h)[2])->get()->isNotEmpty())
                                                                        {!! "T.".substr($turma2->codtur,-2,2)." " !!}
                                                                        @php array_push($done, $turma2->id); @endphp
                                                                    @endif
                                                                @endforeach
                                                            </a>
                                                            <br>
                                                        @endif
                                                    @endif
                                                @endforeach
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </table>
                            <br>          
                            <table class="table table-bordered table-striped table-hover" style="font-size:12px;">

                                <tr>
                                    <th>Código da Disciplina</th>
                                    <th>Nome da Disciplina</th>
                                    <th>Tipo</th>
                                    <th>Professor(es)</th>
                                    <th>Sala</th>
                                    <th>Turma</th>
                                </tr>

                                    @php $done = []; @endphp
                                    @foreach($turmas as $turma)
                                        @if(!in_array($turma->id, $done))
                                            <tr>
                                                <td style="vertical-align: middle;">{!! $turma->coddis !!}</td>
                                                <td style="vertical-align: middle;">
                                                    @php
                                                        $foraSemIdl = $turma->courseinformations()
                                                            ->where("numsemidl",$semestre-1)
                                                            ->where("nomcur",$course->nomcur)
                                                            ->where("perhab", $course->perhab)->exists();
                                                    @endphp
                                                    <a class="text-dark" target="_blank"
                                                        href="{{'https://uspdigital.usp.br/jupiterweb/obterTurma?nomdis=&sgldis='.$turma->coddis}}"
                                                    >
                                                        {!! $turma->nomdis !!}<b style="white-space: nowrap;">{!! $foraSemIdl ? " (".($semestre - 1)."° Semestre)" : "" !!}</b>
                                                    </a>
                                                </td>
                                                @php  
                                                    $tipobg = $turma->courseinformations()->select(["codcur","tipobg"])
                                                        ->whereIn("numsemidl",[$semestre-1,$semestre])
                                                        ->where("nomcur",$course->nomcur)
                                                        ->where("perhab", $course->perhab)
                                                        ->get()->toArray();

                                                    foreach($tipobg as $key=>$value){
                                                        unset($tipobg[$key]["pivot"]);
                                                    }

                                                    $tipobg = array_unique($tipobg, SORT_REGULAR);

                                                    $tipos = ["L"=>"Livre","O"=>"Obrigatória","C"=>"Eletiva"];
                                                @endphp
                                                <td style="vertical-align: middle;">
                                                    @foreach($tipobg as $t)
                                                        @if($t["codcur"] != $course->codcur)
                                                            @php
                                                                $mostrar_cur_ant = true;
                                                                foreach($tipobg as $t2){
                                                                    if($t["codcur"] != $t2["codcur"] and $t["tipobg"] == $t2["tipobg"]){
                                                                        $mostrar_cur_ant = false;
                                                                    }
                                                                }
                                                            @endphp
                                                            @if($mostrar_cur_ant)
                                                                {!! "Curr. Ant. ".$tipos[$t["tipobg"]] !!}<br>
                                                            @endif
                                                        @else
                                                            @php
                                                                $mostrar_cur_nov = false;
                                                                foreach($tipobg as $t2){
                                                                    if($t["codcur"] != $t2["codcur"] and $t["tipobg"] != $t2["tipobg"]){
                                                                        $mostrar_cur_nov = true;
                                                                    }
                                                                }
                                                            @endphp
                                                            @if($mostrar_cur_nov)
                                                                {!! "Curr. Novo ".$tipos[$t["tipobg"]] !!}<br>
                                                            @else
                                                                {!! $tipos[$t["tipobg"]] !!}<br>
                                                            @endif
                                                        @endif
                                                    @endforeach
                                                </td>
                                                <td style="white-space: nowrap;vertical-align: middle;">
                                                    @foreach($turma->instructors as $instructor)
                                                        {{ $instructor->getNomAbrev()}} <br/>
                                                    @endforeach
                                                </td>
                                                <td style="vertical-align: middle;">
                                                    @if(!$turma->externa)
                                                        @if($turma->fusion()->exists()) 
                                                            {!! $turma->fusion->master->room()->exists() ? $turma->fusion->master->room->nome : "Sem Sala" !!}
                                                        @else
                                                            {!! $turma->room()->exists() ? $turma->room->nome : "Sem Sala" !!}
                                                        @endif
                                                    @else
                                                        Externa
                                                    @endif
                                                </td>
                                                <td style="vertical-align: middle;">
                                                    @php 
                                                        $coddis = $turma->coddis; 
                                                        $codturs = [];
                                                    @endphp
                                                    @foreach($turmas as $turma2)
                                                        @if(($turma->coddis == $turma2->coddis) and ($turma->instructors->diff($turma2->instructors)->isEmpty()) and ($turma2->instructors->diff($turma->instructors)->isEmpty()))
                                                            @php 
                                                                array_push($done, $turma2->id); 
                                                                array_push($codturs, substr($turma2->codtur,-2,2)); 
                                                            @endphp
                                                        @endif
                                                    @endforeach
                                                    @php sort($codturs); @endphp
                                                    @foreach($codturs as $codtur)
                                                        {!! "T.".$codtur !!}<br>
                                                    @endforeach
                                                </td>
                                            </tr>
                                        @endif
                                    @endforeach
                                </tr>
                            </table>
                            <br>                     
                            <br>                     
                        @endif
                    @endif
                @endforeach
            @endforeach

            @php
                $turmas_eletivas = App\Models\SchoolClass::whereBelongsTo($schoolterm)
                    ->whereHas("courseinformations", function($query)use($semestre, $course){
                        $query->where("nomcur",$course->nomcur)
                            ->where("perhab", $course->perhab)
                            ->where("tipobg", "C");
                        })->get();
                $turmas_eletivas_livres = App\Models\SchoolClass::whereBelongsTo($schoolterm)->with("courseinformations")
                    ->whereHas("courseinformations", function($query)use($semestre, $course){
                        $query->where("nomcur",$course->nomcur)
                            ->where("perhab", $course->perhab)
                            ->whereIn("tipobg", ["L","C"]);
                        })->orderBy("coddis")->get();
            @endphp
            @if($turmas_eletivas_livres->isNotEmpty())
                <h2 class="text-left"><b>Disciplinas Optativas</b></h2>
                <br>
            @endif
            @if($turmas_eletivas->isNotEmpty())
                @php          
                    $dias = ['seg', 'ter', 'qua', 'qui', 'sex'];  

                    $temSab = $turmas_eletivas->filter(function($turma){
                        foreach($turma->classschedules as $schedule){
                            if($schedule->diasmnocp=="sab"){
                                return true;
                            }
                        }
                        return false;
                    })->isNotEmpty();

                    if($temSab){
                        array_push($dias, "sab");
                    }

                    $schedules = array_unique(App\Models\ClassSchedule::whereHas("schoolclasses", function($query)use($turmas_eletivas){$query->whereIn("id",$turmas_eletivas->pluck("id")->toArray());})->select(["horent","horsai"])->where("diasmnocp", "!=", "dom")->get()->toArray(),SORT_REGULAR);

                    array_multisort(array_column($schedules, "horent"), SORT_ASC, $schedules);

                    $horarios = [];
                    foreach($schedules as $schedule){
                        array_push($horarios, $schedule["horent"]." às ".$schedule["horsai"]);
                    }
                @endphp
                <table class="table table-bordered" style="font-size:15px;">
                    <tr style="background-color:#F5F5F5">
                        <th>Horários</th>
                        <th>Segunda</th>
                        <th>Terça</th>
                        <th>Quarta</th>
                        <th>Quinta</th>
                        <th>Sexta</th>
                        @if($temSab)
                            <th>Sábado</th>
                        @endif
                    </tr>
                    @foreach($horarios as $h)
                        <tr>
                            <td style="vertical-align: middle;" width="170px">{{ explode(" ",$h)[0] }}<br>{{ explode(" ",$h)[1] }}<br>{{ explode(" ",$h)[2] }}</td>
                            @foreach($dias as $dia)
                                @php $done = []; @endphp
                                <td style="vertical-align: middle;" width="180px">                                                
                                    @foreach($turmas_eletivas as $turma)
                                        @if($turma->classschedules()->where("diasmnocp",$dia)->where("horent",explode(" ",$h)[0])->where("horsai",explode(" ",$h)[2])->get()->isNotEmpty())
                                            @if(!$turma->externa)
                                                <a class="text-dark" target="_blank"
                                                    href="{{'https://uspdigital.usp.br/jupiterweb/obterTurma?nomdis=&sgldis='.$turma->coddis}}"
                                                >
                                                    {!! $turma->coddis." T.".substr($turma->codtur,-2,2) !!}
                                                </a>
                                                <br>
                                            @elseif(!in_array($turma->id, $done))
                                                <a class="text-dark" target="_blank"
                                                    href="{{'https://uspdigital.usp.br/jupiterweb/obterTurma?nomdis=&sgldis='.$turma->coddis}}"
                                                >
                                                    {!! $turma->coddis." " !!}
                                                    @php $coddis = $turma->coddis; @endphp
                                                    @foreach($turmas->filter(function($t)use($coddis){return $t->coddis == $coddis;}) as $turma2)
                                                        @if($turma2->classschedules()->where("diasmnocp",$dia)->where("horent",explode(" ",$h)[0])->where("horsai",explode(" ",$h)[2])->get()->isNotEmpty())
                                                            {!! "T.".substr($turma2->codtur,-2,2)." " !!}
                                                            @php array_push($done, $turma2->id); @endphp
                                                        @endif
                                                    @endforeach
                                                </a>
                                                <br>
                                            @endif
                                        @endif
                                    @endforeach
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </table>
                <br>          
            @endif
            @if($turmas_eletivas_livres->isNotEmpty())
                <table class="table table-bordered table-striped table-hover" style="font-size:12px;">

                    <tr>
                        <th>Código da Disciplina</th>
                        <th>Nome da Disciplina</th>
                        <th>Tipo</th>
                        <th>Professor(es)</th>
                        <th>Sala</th>
                        <th>Turma</th>
                    </tr>

                        @php $done = []; @endphp
                        @foreach($turmas_eletivas_livres as $turma)
                            @if(!in_array($turma->id, $done))
                                <tr>
                                    <td style="vertical-align: middle;">{!! $turma->coddis !!}</td>
                                    <td style="vertical-align: middle;">
                                        <a class="text-dark" target="_blank"
                                            href="{{'https://uspdigital.usp.br/jupiterweb/obterTurma?nomdis=&sgldis='.$turma->coddis}}"
                                        >
                                            {!! $turma->nomdis !!}
                                        </a>
                                    </td>
                                    @php  
                                        $tipobg = $turma->courseinformations()->select(["codcur","tipobg"])
                                            ->where("nomcur",$course->nomcur)
                                            ->where("perhab", $course->perhab)
                                            ->get()->toArray();

                                        foreach($tipobg as $key=>$value){
                                            unset($tipobg[$key]["pivot"]);
                                        }

                                        $tipobg = array_unique($tipobg, SORT_REGULAR);

                                        $tipos = ["L"=>"Livre","O"=>"Obrigatória","C"=>"Eletiva"];
                                    @endphp
                                    <td style="vertical-align: middle;">
                                        @foreach($tipobg as $t)
                                            @if($t["codcur"] != $course->codcur)
                                                @php
                                                    $mostrar_cur_ant = true;
                                                    foreach($tipobg as $t2){
                                                        if($t["codcur"] != $t2["codcur"] and $t["tipobg"] == $t2["tipobg"]){
                                                            $mostrar_cur_ant = false;
                                                        }
                                                    }
                                                @endphp
                                                @if($mostrar_cur_ant)
                                                    {!! "Curr. Ant. ".$tipos[$t["tipobg"]] !!}<br>
                                                @endif
                                            @else
                                                @php
                                                    $mostrar_cur_nov = false;
                                                    foreach($tipobg as $t2){
                                                        if($t["codcur"] != $t2["codcur"] and $t["tipobg"] != $t2["tipobg"]){
                                                            $mostrar_cur_nov = true;
                                                        }
                                                    }
                                                @endphp
                                                @if($mostrar_cur_nov)
                                                    {!! "Curr. Novo ".$tipos[$t["tipobg"]] !!}<br>
                                                @else
                                                    {!! $tipos[$t["tipobg"]] !!}<br>
                                                @endif
                                            @endif
                                        @endforeach
                                    </td>
                                    <td style="white-space: nowrap;vertical-align: middle;">
                                        @foreach($turma->instructors as $instructor)
                                            {{ $instructor->getNomAbrev()}} <br/>
                                        @endforeach
                                    </td>
                                    <td style="vertical-align: middle;">
                                        @if(!$turma->externa)
                                            @if($turma->fusion()->exists()) 
                                                {!! $turma->fusion->master->room()->exists() ? $turma->fusion->master->room->nome : "Sem Sala" !!}
                                            @else
                                                {!! $turma->room()->exists() ? $turma->room->nome : "Sem Sala" !!}
                                            @endif
                                        @else
                                            Externa
                                        @endif
                                    </td>
                                    <td style="vertical-align: middle;">
                                        @php 
                                            $coddis = $turma->coddis; 
                                            $codturs = [];
                                        @endphp
                                        @foreach($turmas_eletivas_livres as $turma2)
                                            @if(($turma->coddis == $turma2->coddis) and ($turma->instructors->diff($turma2->instructors)->isEmpty()) and ($turma2->instructors->diff($turma->instructors)->isEmpty()))
                                                @php 
                                                    array_push($done, $turma2->id); 
                                                    array_push($codturs, substr($turma2->codtur,-2,2)); 
                                                @endphp
                                            @endif
                                        @endforeach
                                        @php sort($codturs); @endphp
                                        @foreach($codturs as $codtur)
                                            {!! "T.".$codtur !!}<br>
                                        @endforeach
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tr>
                </table>
                <br>                     
                <br>                     
            @endif
        </div>
    </div>
</div>
@endsection