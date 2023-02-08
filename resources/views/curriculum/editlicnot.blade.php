@extends('main')

@section('title', 'Horário das Disciplinas')

@section('content')
  @parent 
<div id="layout_conteudo">
    <div class="justify-content-center">
        <div class="col-md-12">
            <h1 class='text-center'>Grade Curricular</h1>
            
            <h4 class='text-center mb-4'>{{ $schoolterm->period . ' de ' . $schoolterm->year }}</h4>
            <h2 class='text-center'>{!! $course->nomcur." - ".ucfirst($course->perhab) !!}</h2>
            <h4 class='text-center mb-5'>{{ $semester . '° Semestre Ideal ' }}</h4>

            @include('curriculum.modals.attachscModal')

            @foreach(["A","B"] as $grupo)
                @if(!($grupo == "B" and $A_equals_B))
                    @if($schoolclasses[$grupo]->isNotEmpty())
                        <h2 class="text-left"><b>{!! $semester."° Semestre ".($A_equals_B ? "Grupos A e B" : "Grupo ".$grupo) !!}</b></h2>

                        <p class="text-right">
                            <a  id="btn-openAttachscModal"
                                class="btn btn-outline-primary"
                                data-toggle="modal"
                                data-target="#attachscModal"
                                title="Adicionar Turma a Grade Curricular" 
                            >
                                <i class="fas fa-plus-circle"></i>
                                Turma
                            </a>
                        </p>
                        <br>
                        <table class="table table-bordered" style="font-size:15px;">
                            <tr style="background-color:#F5F5F5">
                                <th>Horários</th>
                                <th>Segunda</th>
                                <th>Terça</th>
                                <th>Quarta</th>
                                <th>Quinta</th>
                                <th>Sexta</th>
                                @if(in_array("sab",$days[$grupo]))
                                    <th>Sábado</th>
                                @endif
                            </tr>
                            @foreach($schedules[$grupo] as $h)
                                <tr>
                                    <td style="vertical-align: middle;" width="170px">{{ explode(" ",$h)[0] }}<br>{{ explode(" ",$h)[1] }}<br>{{ explode(" ",$h)[2] }}</td>
                                    @foreach($days[$grupo] as $dia)
                                        @php $done = []; @endphp
                                        <td style="vertical-align: middle;" width="180px">                                                
                                            @foreach($schoolclasses[$grupo] as $turma)
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
                                                            @foreach($schoolclasses[$grupo]->filter(function($t)use($coddis){return $t->coddis == $coddis;}) as $turma2)
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
                                @foreach($schoolclasses[$grupo] as $turma)
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
                                                    ->where("numsemidl",$semester)
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
                                                @foreach($schoolclasses[$grupo] as $turma2)
                                                    @if(($turma->coddis == $turma2->coddis) and ($turma->instructors->diff($turma2->instructors)->isEmpty()) and ($turma2->instructors->diff($turma->instructors)->isEmpty()))
                                                        @php 
                                                            array_push($done, $turma2->id); 
                                                            array_push($codturs, $turma2->codtur); 
                                                        @endphp
                                                    @endif
                                                @endforeach
                                                @php sort($codturs); @endphp
                                                @foreach($codturs as $codtur)
                                                    {!! "T.".substr($codtur,-2,2) !!}
                                                <a class="text-dark text-decoration-none"
                                                    onClick="saveScrollPos(this)"
                                                    title="Remover"
                                                    data-method="delete"
                                                    href="{{ route(
                                                        'courseinformations.detach',
                                                        [\App\Models\SchoolClass::where('coddis', $turma->coddis)->where('codtur', $codtur)->first()
                                                            ->courseinformations()
                                                            ->where('numsemidl',$semester)
                                                            ->where('nomcur',$course->nomcur)
                                                            ->where('perhab', $course->perhab)
                                                            ->where('tipobg', 'O')
                                                            ->first(), 
                                                        \App\Models\SchoolClass::where('coddis', $turma->coddis)->where('codtur', $codtur)->first()]
                                                    ) }}"
                                                >
                                                    <i class="fas fa-trash-alt"></i>
                                                </a>
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
                @else
                    <p class="text-right">
                        <a  id="btn-openAttachscModal"
                            class="btn btn-outline-primary"
                            data-toggle="modal"
                            data-target="#attachscModal"
                            title="Adicionar Turma a Grade Curricular" 
                        >
                            <i class="fas fa-plus-circle"></i>
                            Turma
                        </a>
                    </p>
                @endif
            @endforeach
        </div>
    </div>
</div>
@endsection


@section('javascripts_bottom')
  @parent
    <script type="text/javascript" src="{{ asset('js/jquery.cookie.js') }}"></script>
    <script>
        $(document).ready(function() {

            // If cookie is set, scroll to the position saved in the cookie.
            if ( $.cookie("scroll") !== null ) {
                $(document).scrollTop( $.cookie("scroll") );
            }

        });
        function saveScrollPos(button){
            // Set a cookie that holds the scroll position.
            $.cookie("scroll", $(document).scrollTop() );
        }
        $("#btn-searchscModal").on("click", function(e){
            e.preventDefault();
            e.stopPropagation();
            var coddis = $('#coddis').val();
            $('#msn-div').empty();
            $('#schoolclasses-div').empty();
            if(coddis != ""){
                $.ajax({
                url: baseURL + '/schoolclasses?coddis=' + coddis,
                dataType: 'json',
                success: function success(schoolclasses){
                if(schoolclasses != ""){
                    var label_titulo = "<h4 class='modal-title text-center'>Escolha a(s) turma(s)</h4><div class='col-12'>";
                    $('#schoolclasses-div').append(label_titulo);
                    schoolclasses.forEach(function (schoolclass, i){
                    if(i<10){
                        var html = [
                                    "<div class='form-check'>",
                                    "<input class='checkbox' type='checkbox' id='schoolclasses' name='schoolclasses[]' value='"+schoolclass['id']+"'/></input>",
                                    "<label class='font-weight-normal ml-2 mb-0'> T. "+schoolclass['codtur'].substring(5)+" "+schoolclass['nomdis']+"</label><br>",
                                    "</div>"
                                ].join("\n");
                        $('#schoolclasses-div').append(html);
                    }
                    })
                    $('#schoolclasses-div').append("</div>");
                } else{
                    var error = "<p class='alert alert-warning align-items-center'>Nenhuma turma encontrada.</p>";
                    $('#msn-div').append(error);
                }
                }
            });
            }else{
                var error = "<p class='alert alert-warning align-items-center'>Informe um código de turma</p>";
                $('#msn-div').append(error);
            }
        });
    </script>   
@endsection