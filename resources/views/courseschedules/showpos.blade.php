@extends('main')

@section('title', $title )

@section('content')
  @parent 
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-12">
            @php
                $schoolterm = App\Models\SchoolTerm::getLatest();
            @endphp
            <h1 class='text-center mb-5'><b>{!! $titulo !!}</b></h1>
            <h2 class='text-center mb-5'>Horário das Disciplinas - {!! $schoolterm->period . ' de ' . $schoolterm->year !!}</h2>
            
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

            @if($schoolclasses->isNotEmpty())
                @php          
                    $dias = ['seg', 'ter', 'qua', 'qui', 'sex'];  

                    $temSab = $schoolclasses->filter(function($turma){
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

                    $schedules = array_unique(App\Models\ClassSchedule::whereHas("schoolclasses", function($query)use($schoolclasses){$query->whereIn("id",$schoolclasses->pluck("id")->toArray());})->select(["horent","horsai"])->where("diasmnocp", "!=", "dom")->get()->toArray(),SORT_REGULAR);

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
                                <td style="vertical-align: middle;" width="180px">                                                
                                    @foreach($schoolclasses as $turma)
                                        @if($turma->classschedules()->where("diasmnocp",$dia)->where("horent",explode(" ",$h)[0])->where("horsai",explode(" ",$h)[2])->get()->isNotEmpty())
                                            {!! $turma->coddis !!}<br>
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
                        <th>Professor(es)</th>
                        <th>Sala</th>
                    </tr>
                    @foreach($schoolclasses as $turma)
                        <tr>
                            <td style="vertical-align: middle;">{!! $turma->coddis !!}</td>
                            <td style="vertical-align: middle;">
                                <a class="text-dark" target="_blank"
                                    href="{{'https://uspdigital.usp.br/jupiterweb/obterTurma?nomdis=&sgldis='.$turma->coddis}}"
                                >
                                    {!! $turma->nomdis !!}
                                </a>
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
                        </tr>
                    @endforeach
                </table>
            @endif
        </div>
    </div>
</div>
@endsection
