@extends('main')

@section('title', $title )

@section('content')
  @parent 
<div id="layout_conteudo">
    <div class="justify-content-center">
        <div class="col-md-12">
            <h1 class='text-center mb-5'><b>{!! $titulo !!}</b></h1>
            <h2 class='text-center mb-5'>Horário das Disciplinas - {!! $schoolterm->period . ' de ' . $schoolterm->year !!}</h2>
            
            @foreach($observations as $observation)
                <div class="card my-3">
                    <div class="card-body">
                        <h3 class='card-title' style="color:blue">{!! $observation->title !!}</h3>
                        <br>
                        {!! $observation->body !!}
                    </div>
                </div>
            @endforeach

            @if($schoolclasses->isNotEmpty())
                <table class="table table-bordered" style="font-size:15px;">
                    <tr style="background-color:#F5F5F5">
                        <th>Horários</th>
                        <th>Segunda</th>
                        <th>Terça</th>
                        <th>Quarta</th>
                        <th>Quinta</th>
                        <th>Sexta</th>
                        @if(in_array("sab",$days))
                            <th>Sábado</th>
                        @endif
                    </tr>
                    @foreach($schedules as $h)
                        <tr>
                            <td style="vertical-align: middle;" width="170px">{{ explode(" ",$h)[0] }}<br>{{ explode(" ",$h)[1] }}<br>{{ explode(" ",$h)[2] }}</td>
                            @foreach($days as $dia)
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
