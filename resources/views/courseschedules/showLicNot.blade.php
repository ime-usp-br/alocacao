@extends('main')

@section('title', $course->nomcur)

@section('content')
  @parent 
<div id="layout_conteudo">
    <div class="justify-content-center">
        <div class="col-md-12">
            <h1 class='text-center mb-5'><b>Matemática - Licenciatura</b></h1>
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

            @foreach($observations as $observation)
                <div class="card my-3">
                    <div class="card-body">
                        <h3 class='card-title' style="color:blue">{!! $observation->title !!}</h3>
                        <br>
                        {!! $observation->body !!}
                    </div>
                </div>
            @endforeach

            @foreach($semesters as $semester)
                @foreach(["A","B"] as $grupo)
                    @if(!($grupo == "B" and $A_equals_B[$semester]))
                        @if($schoolclasses[$semester][$grupo]->isNotEmpty())
                            <h2 class="text-left"><b>{!! $semester."° Semestre ".($A_equals_B[$semester] ? "Grupos A e B" : "Grupo ".$grupo) !!}</b></h2>
                            <br>
                            <table class="table table-bordered" style="font-size:15px;">
                                <tr style="background-color:#F5F5F5">
                                    <th>Horários</th>
                                    <th>Segunda</th>
                                    <th>Terça</th>
                                    <th>Quarta</th>
                                    <th>Quinta</th>
                                    <th>Sexta</th>
                                    @if(in_array("sab",$days[$semester][$grupo]))
                                        <th>Sábado</th>
                                    @endif
                                </tr>
                                @foreach($schedules[$semester][$grupo] as $h)
                                    <tr>
                                        <td style="vertical-align: middle;" width="170px">{{ explode(" ",$h)[0] }}<br>{{ explode(" ",$h)[1] }}<br>{{ explode(" ",$h)[2] }}</td>
                                        @foreach($days[$semester][$grupo] as $dia)
                                            @php $done = []; @endphp
                                            <td style="vertical-align: middle;" width="180px">                                                
                                                @foreach($schoolclasses[$semester][$grupo] as $turma)
                                                    @if($turma->courseinformations()->where("codcur", "45024")->where("codhab", "4")->where("numsemidl", $semester)->exists())
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
                                                                    @foreach($schoolclasses[$semester][$grupo]->filter(function($t)use($coddis){return $t->coddis == $coddis;}) as $turma2)
                                                                        @if($turma2->classschedules()->where("diasmnocp",$dia)->where("horent",explode(" ",$h)[0])->where("horsai",explode(" ",$h)[2])->get()->isNotEmpty())
                                                                            {!! "T.".substr($turma2->codtur,-2,2)." " !!}
                                                                            @php array_push($done, $turma2->id); @endphp
                                                                        @endif
                                                                    @endforeach
                                                                </a>
                                                                <br>
                                                            @endif
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
                                    @foreach($schoolclasses[$semester][$grupo] as $turma)
                                        @if(!in_array($turma->id, $done))
                                            <tr>
                                                <td style="vertical-align: middle;">{!! $turma->coddis !!}</td>
                                                <td style="vertical-align: middle;">
                                                    @php
                                                        $foraSemIdl = $turma->courseinformations()
                                                            ->where("numsemidl",$semester-1)
                                                            ->where("nomcur",$course->nomcur)
                                                            ->where("perhab", $course->perhab)->exists();
                                                    @endphp
                                                    <a class="text-dark" target="_blank"
                                                        href="{{'https://uspdigital.usp.br/jupiterweb/obterTurma?nomdis=&sgldis='.$turma->coddis}}"
                                                    >
                                                        {!! $turma->nomdis !!}<b style="white-space: nowrap;">{!! $foraSemIdl ? " (".($semester - 1)."° semester)" : "" !!}</b>
                                                    </a>
                                                </td>
                                                @php  
                                                    $tipobg = $turma->courseinformations()->select(["codcur","tipobg"])
                                                        ->whereIn("numsemidl",[$semester-1,$semester])
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
                                                    @foreach($schoolclasses[$semester][$grupo] as $turma2)
                                                        @if(($turma->coddis == $turma2->coddis) and ($turma->getRoomName() == $turma2->getRoomName()) and ($turma->instructors->diff($turma2->instructors)->isEmpty()) and ($turma2->instructors->diff($turma->instructors)->isEmpty()))
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

            @if(count($electives_specialoffers)>0)
                <h2 class="text-left"><b>Disciplinas Optativas Eletivas com horário especial</b></h2>
                <br>

                <table class="table table-bordered" style="font-size:15px;">
                    <tr style="background-color:#F5F5F5">
                        <th>Horários</th>
                        <th>Segunda</th>
                        <th>Terça</th>
                        <th>Quarta</th>
                        <th>Quinta</th>
                        <th>Sexta</th>
                        @if(in_array("sab",$electives_specialoffers_days))
                            <th>Sábado</th>
                        @endif
                    </tr>
                    @foreach($electives_specialoffers_schedules as $h)
                        <tr>
                            <td style="vertical-align: middle;" width="170px">{{ explode(" ",$h)[0] }}<br>{{ explode(" ",$h)[1] }}<br>{{ explode(" ",$h)[2] }}</td>
                            @foreach($electives_specialoffers_days as $dia)
                                @php $done = []; @endphp
                                <td style="vertical-align: middle;" width="180px">                                                
                                    @foreach($electives_specialoffers as $turma)
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
                                                    @foreach($electives_specialoffers->filter(function($t)use($coddis){return $t->coddis == $coddis;}) as $turma2)
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
                        @foreach($electives_specialoffers as $turma)
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
                                        @foreach($electives_specialoffers as $turma2)
                                            @if(($turma->coddis == $turma2->coddis) and ($turma->getRoomName() == $turma2->getRoomName()) and ($turma->instructors->diff($turma2->instructors)->isEmpty()) and ($turma2->instructors->diff($turma->instructors)->isEmpty()))
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

            @if(count($electives_specialoffers_annual)>0)
                <h2 class="text-left"><b>Disciplinas Anuais - Optativas Eletivas</b></h2>
                <br>

                <table class="table table-bordered" style="font-size:15px;">
                    <tr style="background-color:#F5F5F5">
                        <th>Código</th>
                        <th>Disciplina</th>
                        <th>Professor</th>
                        <th>Sala</th>
                        <th>Turma</th>
                    </tr>
                    @php $done = []; @endphp
                    @foreach($electives_specialoffers_annual as $turma)
                        @if(!in_array($turma->id, $done))
                            <tr>
                                <td style="vertical-align: middle;">
                                    @if(!$turma->externa)
                                        <a class="text-dark" target="_blank"
                                            href="{{'https://uspdigital.usp.br/jupiterweb/obterTurma?nomdis=&sgldis='.$turma->coddis}}"
                                        >
                                            {!! $turma->coddis !!}
                                        </a>
                                    @else
                                        {!! $turma->coddis !!}
                                    @endif
                                </td>
                                <td style="vertical-align: middle;">
                                    @php
                                        // Buscar semestre ideal para disciplinas anuais
                                        $semestreIdeal = $turma->courseinformations()
                                            ->where("nomcur",$course->nomcur)
                                            ->where("perhab", $course->perhab)
                                            ->first()?->numsemidl;
                                    @endphp
                                    @if(!$turma->externa)
                                        <a class="text-dark" target="_blank"
                                            href="{{'https://uspdigital.usp.br/jupiterweb/obterTurma?nomdis=&sgldis='.$turma->coddis}}"
                                        >
                                            {!! $turma->nomdis !!}<b style="white-space: nowrap;">{!! $semestreIdeal ? " (".$semestreIdeal."° semestre)" : "" !!}</b>
                                        </a>
                                    @else
                                        {!! $turma->nomdis !!}<b style="white-space: nowrap;">{!! $semestreIdeal ? " (".$semestreIdeal."° semestre)" : "" !!}</b>
                                    @endif
                                </td>
                                <td style="vertical-align: middle;">
                                    @if(!$turma->externa)
                                        @foreach($turma->instructors as $instructor)
                                            {!! $instructor->nome !!}<br>
                                        @endforeach
                                    @else
                                        Externa
                                    @endif
                                </td>
                                <td style="vertical-align: middle;">
                                    @if(!$turma->externa)
                                        @if($turma->fusion()->exists())
                                            @if($turma->fusion->room()->exists())
                                                {!! $turma->fusion->room->nome !!}
                                            @else
                                                {!! $turma->room()->exists() ? $turma->room->nome : "Sem Sala" !!}
                                            @endif
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
                                    @foreach($electives_specialoffers_annual as $turma2)
                                        @if(($turma->coddis == $turma2->coddis) and ($turma->getRoomName() == $turma2->getRoomName()) and ($turma->instructors->diff($turma2->instructors)->isEmpty()) and ($turma2->instructors->diff($turma->instructors)->isEmpty()))
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
                </table>
                <br>
                <br>
            @endif
            
            @if($electives->isNotEmpty())
                <h2 class="text-left"><b>Horários das Optativas Eletivas</b></h2>
                <br>

                <table class="table table-bordered" style="font-size:15px;">
                    <tr style="background-color:#F5F5F5">
                        <th>Horários</th>
                        <th>Segunda</th>
                        <th>Terça</th>
                        <th>Quarta</th>
                        <th>Quinta</th>
                        <th>Sexta</th>
                        @if(in_array("sab",$electives_days))
                            <th>Sábado</th>
                        @endif
                    </tr>
                    @foreach($electives_schedules as $h)
                        <tr>
                            <td style="vertical-align: middle;" width="170px">{{ explode(" ",$h)[0] }}<br>{{ explode(" ",$h)[1] }}<br>{{ explode(" ",$h)[2] }}</td>
                            @foreach($electives_days as $dia)
                                @php $done = []; @endphp
                                <td style="vertical-align: middle;" width="180px">                                                
                                    @foreach($electives as $turma)
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
                                                    @foreach($electives->filter(function($t)use($coddis){return $t->coddis == $coddis;}) as $turma2)
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
                        @foreach($electives as $turma)
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
                                        @foreach($electives as $turma2)
                                            @if(($turma->coddis == $turma2->coddis) and ($turma->getRoomName() == $turma2->getRoomName()) and ($turma->instructors->diff($turma2->instructors)->isEmpty()) and ($turma2->instructors->diff($turma->instructors)->isEmpty()))
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
            
            @if($specialoffers->isNotEmpty())
                <h2 class="text-left"><b>Optativas Livres: oferecimentos especiais</b></h2>
                <br>

                <table class="table table-bordered" style="font-size:15px;">
                    <tr style="background-color:#F5F5F5">
                        <th>Horários</th>
                        <th>Segunda</th>
                        <th>Terça</th>
                        <th>Quarta</th>
                        <th>Quinta</th>
                        <th>Sexta</th>
                        @if(in_array("sab",$specialoffers_days))
                            <th>Sábado</th>
                        @endif
                    </tr>
                    @foreach($specialoffers_schedules as $h)
                        <tr>
                            <td style="vertical-align: middle;" width="170px">{{ explode(" ",$h)[0] }}<br>{{ explode(" ",$h)[1] }}<br>{{ explode(" ",$h)[2] }}</td>
                            @foreach($specialoffers_days as $dia)
                                @php $done = []; @endphp
                                <td style="vertical-align: middle;" width="180px">                                                
                                    @foreach($specialoffers as $turma)
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
                                                    @foreach($specialoffers->filter(function($t)use($coddis){return $t->coddis == $coddis;}) as $turma2)
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
                        @foreach($specialoffers as $turma)
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
                                        @foreach($specialoffers as $turma2)
                                            @if(($turma->coddis == $turma2->coddis) and ($turma->getRoomName() == $turma2->getRoomName()) and ($turma->instructors->diff($turma2->instructors)->isEmpty()) and ($turma2->instructors->diff($turma->instructors)->isEmpty()))
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