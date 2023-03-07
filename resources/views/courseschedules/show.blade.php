@extends('main')

@section('title', $course->nomcur )

@section('content')
  @parent 
<div id="layout_conteudo">
    <div class="justify-content-center">
        <div class="col-md-12">
            
            <h1 class='text-center mb-5'><b>{!! $course->nomcur !!}</b></h1>
            <h2 class='text-center mb-5'>Horário das Disciplinas - {!! $schoolterm->period . ' de ' . $schoolterm->year !!}</h2>

            <div class="d-flex justify-content-center">
                <div class="col-md-6">
                    <table class="table table-bordered table-striped table-hover" style="font-size:15px;">
                        <tr>
                            <th>Código do Curso</th>
                            <th>Período</th>
                        </tr>

                        <tr style="font-size:12px;">
                                <td>{{ $course->codcur }}</td>
                                <td>{{ ucfirst($course->perhab) }}</td>
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
                @foreach($habilitations[$semester] as $nomhab=>$codhab)
                    @if($show[$semester][$nomhab])
                        <h2 class="text-left"><b>{!! $semester."° Semestre".(count($habilitations[$semester]) > 1 ? ( in_array($codhab, [1,4]) ? " 00".$codhab." - "."Núcleo Básico" : " ".$codhab." - ".explode("Habilitação em ", $nomhab)[1]) : "") !!}</b></h2>
                        <br>
                        <table class="table table-bordered" style="font-size:15px;">
                            <tr style="background-color:#F5F5F5">
                                <th>Horários</th>
                                <th>Segunda</th>
                                <th>Terça</th>
                                <th>Quarta</th>
                                <th>Quinta</th>
                                <th>Sexta</th>
                                @if(in_array("sab",$days[$semester][$nomhab]))
                                    <th>Sábado</th>
                                @endif
                            </tr>
                            @foreach($schedules[$semester][$nomhab] as $h)
                                <tr>
                                    <td style="vertical-align: middle;" width="170px">{{ explode(" ",$h)[0] }}<br>{{ explode(" ",$h)[1] }}<br>{{ explode(" ",$h)[2] }}</td>
                                    @foreach($days[$semester][$nomhab] as $dia)
                                        @php $done = []; @endphp
                                        <td style="vertical-align: middle;" width="180px">                                                
                                            @foreach($schoolclasses[$semester][$nomhab] as $turma)
                                                @if($turma->courseinformations()->where("nomcur", $course->nomcur)->where("codhab",$codhab)->where("numsemidl", $semester)->exists())
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
                                                                @foreach($schoolclasses[$semester][$nomhab]->filter(function($t)use($coddis){return $t->coddis == $coddis;}) as $turma2)
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
                                @foreach($schoolclasses[$semester][$nomhab] as $turma)
                                    @if(!in_array($turma->id, $done))
                                        <tr>
                                            <td style="vertical-align: middle;">{!! $turma->coddis !!}</td>
                                            <td style="vertical-align: middle;">
                                                @php
                                                    $foraSemIdl = $turma->courseinformations()
                                                        ->where("numsemidl",$semester-1)
                                                        ->where("nomcur",$course->nomcur)
                                                        ->where("perhab", $course->perhab)
                                                        ->where("tipobg", "O")
                                                        ->where("codhab", $codhab)->exists();
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
                                                    ->where("codhab", $codhab)
                                                    ->where("tipobg", "O")
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
                                                @foreach($schoolclasses[$semester][$nomhab] as $turma2)
                                                    @if(($turma->coddis == $turma2->coddis) and ($turma->getRoomName() == $turma2->getRoomName()) and ($turma->instructors->diff($turma2->instructors)->isEmpty()) and ($turma2->instructors->diff($turma->instructors)->isEmpty()))
                                                        @php 
                                                            array_push($done, $turma2->id); 
                                                            array_push($codturs, $turma2->codtur); 
                                                        @endphp
                                                    @endif
                                                @endforeach
                                                @php sort($codturs); @endphp
                                                @foreach($codturs as $codtur)
                                                    {!! "T.".substr($codtur,-2,2) !!}
                                                    <br>
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

            @foreach($optatives_habilitations as $nomhab=>$codhab)
                @if($electives[$nomhab]->isNotEmpty())
                    <h2 class="text-left"><b>{!! "Horários das Optativas Eletivas ".( $temMaisDeUmaHab ? ( in_array($codhab, [1,4]) ? "- Núcleo Básico" : " ".$codhab." - ".explode("Habilitação em ", $nomhab)[1]) : "") !!}</b></h2>
                    <br>

                    <table class="table table-bordered" style="font-size:15px;">
                        <tr style="background-color:#F5F5F5">
                            <th>Horários</th>
                            <th>Segunda</th>
                            <th>Terça</th>
                            <th>Quarta</th>
                            <th>Quinta</th>
                            <th>Sexta</th>
                            @if(in_array("sab",$electives_days[$nomhab]))
                                <th>Sábado</th>
                            @endif
                        </tr>
                        @foreach($electives_schedules[$nomhab] as $h)
                            <tr>
                                <td style="vertical-align: middle;" width="170px">{{ explode(" ",$h)[0] }}<br>{{ explode(" ",$h)[1] }}<br>{{ explode(" ",$h)[2] }}</td>
                                @foreach($electives_days[$nomhab] as $dia)
                                    @php $done = []; @endphp
                                    <td style="vertical-align: middle;" width="180px">                                                
                                        @foreach($electives[$nomhab] as $turma)
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
                                                        @foreach($electives[$nomhab]->filter(function($t)use($coddis){return $t->coddis == $coddis;}) as $turma2)
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
                            @foreach($electives[$nomhab] as $turma)
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
                                                ->where("codhab", $codhab)
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
                                            @foreach($electives[$nomhab] as $turma2)
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
            @endforeach

            @foreach($specialoffers_habilitations as $nomhab=>$codhab)
                @if($specialoffers[$nomhab]->isNotEmpty())
                    <h2 class="text-left"><b>{!! "Optativas Livres: oferecimentos especiais ".( $temMaisDeUmaHab ? ( in_array($codhab, [1,4]) ? "- Núcleo Básico" : " ".$codhab." - ".explode("Habilitação em ", $nomhab)[1]) : "") !!}</b></h2>
                    <br>

                    <table class="table table-bordered" style="font-size:15px;">
                        <tr style="background-color:#F5F5F5">
                            <th>Horários</th>
                            <th>Segunda</th>
                            <th>Terça</th>
                            <th>Quarta</th>
                            <th>Quinta</th>
                            <th>Sexta</th>
                            @if(in_array("sab",$specialoffers_days[$nomhab]))
                                <th>Sábado</th>
                            @endif
                        </tr>
                        @foreach($specialoffers_schedules[$nomhab] as $h)
                            <tr>
                                <td style="vertical-align: middle;" width="170px">{{ explode(" ",$h)[0] }}<br>{{ explode(" ",$h)[1] }}<br>{{ explode(" ",$h)[2] }}</td>
                                @foreach($specialoffers_days[$nomhab] as $dia)
                                    @php $done = []; @endphp
                                    <td style="vertical-align: middle;" width="180px">                                                
                                        @foreach($specialoffers[$nomhab] as $turma)
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
                                                        @foreach($specialoffers[$nomhab]->filter(function($t)use($coddis){return $t->coddis == $coddis;}) as $turma2)
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
                            @foreach($specialoffers[$nomhab] as $turma)
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
                                                ->where("codhab", $codhab)
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
                                            @foreach($specialoffers[$nomhab] as $turma2)
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
            @endforeach
        </div>
    </div>
</div>
@endsection