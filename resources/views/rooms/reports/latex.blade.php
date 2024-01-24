\documentclass[12pt, portuguese, a4paper, pdftex, fleqn]{article}
\usepackage{adjustbox}
\usepackage[portuguese]{babel}
\usepackage[scaled=.92]{helvet}
\usepackage{fancyhdr}
\usepackage{float}
\usepackage{array}
\usepackage{indentfirst}
\usepackage{multirow}
\usepackage[hidelinks]{hyperref}
\usepackage[svgnames,table]{xcolor}
\usepackage{booktabs, makecell, longtable}
\usepackage[a4paper,inner=1.5cm,outer=1.5cm,top=1cm,bottom=1cm,bindingoffset=0cm]{geometry}
\usepackage{blindtext}
\geometry{textwidth=\paperwidth, textheight=\paperheight, noheadfoot, nomarginpar}

\renewcommand{\familydefault}{\sfdefault}

\pagestyle{fancy}
\fancyhead{}
\renewcommand{\headrulewidth}{0pt}

\begin{document}
\begin{titlepage}

\begin{center}
  
\begin{minipage}{0.3\textwidth}
\begin{figure}[H]
 \includegraphics[scale=0.2]{{!! base_path() . "/logo_ime.jpg" !!}}
\end{figure}
\end{minipage} \hfill
\begin{minipage}{0.2\textwidth}
\begin{figure}[H]
 \includegraphics[scale=0.55]{{!! base_path() . "/logo_usp.jpg" !!}}
\end{figure}
\end{minipage}\\[8cm]

   {\Large \textbf{INSTITUTO DE MATEMATICA E ESTATÍSTICA}}\\[1cm]
   {\large \textbf{Horário das Disciplinas}}\\
   {\large \textbf{{!! $schoolterm->period !!} de {!! $schoolterm->year !!}}}

   \hspace{.45\textwidth} %posiciona a minipage
  \vfill

\vspace{1cm}


\large \textbf{São Paulo}

  \end{center}
\thispagestyle{empty}
\pagebreak
\end{titlepage}
@foreach(App\Models\CourseInformation::$codtur_by_course as $sufixo_codtur=>$course)
  @foreach([0,1,2] as $x)
    @php
      $semestres = $schoolterm->period == "1° Semestre" ? [1+$x*4,3+$x*4] : [2+$x*4,4+$x*4];
      $tem_turma = App\Models\SchoolClass::whereBelongsTo($schoolterm)
                  ->whereHas("courseinformations", function($query)use($semestres, $course){$query->whereIn("numsemidl",$semestres)->where("nomcur",$course["nomcur"])->where("perhab", $course["perhab"]);})->get()->isNotEmpty();
      $linhas = 0;

      $turmas = App\Models\SchoolClass::whereBelongsTo($schoolterm)
              ->whereHas("courseinformations", function($query)use($semestres, $course){$query->whereIn("numsemidl",$semestres)->where("nomcur",$course["nomcur"])->where("perhab", $course["perhab"]);})->get();

      $habs = [];

      foreach($turmas as $turma){
        $habs = array_merge($habs, array_column($turma->courseinformations()->select(["codhab"])->whereIn("numsemidl",$semestres)->where("nomcur",$course["nomcur"])->where("perhab", $course["perhab"])->get()->toArray(),"codhab"));
      }
      $mais_de_uma_hab = count(array_unique($habs)) > 1 ? true : false;

      $max_linhas = 48;

    @endphp
    @if($tem_turma)
      \section*{{!! $course["nomcur"] !!}}

      \begin{tabular}{ l l }
        Código: & \textbf{{!! $course["codcur"] !!} } \\
        Período: & \textbf{{!! ucfirst($course["perhab"]) !!}}
        @if(in_array("grupo",array_keys($course)))
          \\\textbf{Grupo {!! $course["grupo"] !!}} &
        @endif
      \end{tabular}
      \\

      @php
        $max_linhas -= 5;

        $habs = [];

        foreach($turmas as $turma){
          $habs = array_merge($habs, array_column($turma->courseinformations()->select(["codhab","nomhab"])->whereIn("numsemidl",$semestres)->where("nomcur",$course["nomcur"])->where("perhab", $course["perhab"])->get()->sortBy("codhab")->toArray(),"codhab", "nomhab"));
        }

        $x = 0;
      @endphp
      @if($semestres == [1,3] or $semestres == [2,4])
        @if($mais_de_uma_hab)
          \begin{tabular}{ l l l l}
            @foreach($habs as $nomhab=>$codhab)
              @php $x += 1; @endphp 
              @if($x % 2 == 0)
                &
              @endif
              @if(in_array($codhab, [0,1,2,4]))
                Habilitação: & \textbf{{!! "00".$codhab." - "."Núcleo Básico" !!}}
              @else
                Habilitação: & \textbf{{!! $codhab." - ".explode("Habilitação em ", $nomhab)[1] !!}}
              @endif
              @if($x % 2 == 0)
                @php $max_linhas -= 1; @endphp
                \\
              @endif
            @endforeach
          \end{tabular}
        @endif
      @endif

      \begin{longtable}{ |>{\centering\arraybackslash}m{1.8cm}|>{\centering\arraybackslash}m{1.5cm}|>{\centering\arraybackslash}m{2.3cm}|>{\centering\arraybackslash}m{2.3cm}|>{\centering\arraybackslash}m{2.3cm}|>{\centering\arraybackslash}m{2.3cm}|>{\centering\arraybackslash}m{2.3cm}| }
      \toprule
      \makecell{Semestre\\Ideal} & Horário & Seg & Ter & Qua & Qui & Sex\\
      \midrule
      \endfirsthead
      \toprule
      \makecell{Semestre\\Ideal} & Horário & Seg & Ter & Qua & Qui & Sex\\
      \midrule
      \endhead
        @foreach($semestres as $semestre)
          @foreach($habs as $nomhab=>$codhab)
            @php
              $turmas = App\Models\SchoolClass::whereBelongsTo($schoolterm)
                ->whereHas("courseinformations", function($query)use($semestre, $course, $codhab){$query->where("numsemidl",$semestre)->where("codhab", $codhab)->where("nomcur",$course["nomcur"])->where("perhab", $course["perhab"]);})->get();
              
              $turmas = $turmas->filter(function($turma)use($turmas){
                if($turma->externa){
                  $conflict = false;
                  foreach($turmas->filter(function($turma){return !$turma->externa;}) as $turma_interna){
                    if($turma->isInConflict($turma_interna)){
                      $conflict = true;
                    }
                  }
                  return !$conflict;
                }else{
                  return true;
                }
              });


              if(in_array("grupo",array_keys($course))){
                if($course["grupo"]=="A"){
                  $turmas = $turmas->filter(function($turma)use($turmas, $schoolterm){
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
                }elseif($course["grupo"]=="B"){
                  $turmas = $turmas->filter(function($turma)use($turmas, $schoolterm){
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
                }
              }

              $dias = ['seg', 'ter', 'qua', 'qui', 'sex'];  

              $schedules = array_unique(App\Models\ClassSchedule::whereHas("schoolclasses", function($query)use($turmas){$query->whereIn("id",$turmas->pluck("id")->toArray());})->select(["horent","horsai"])->whereNotIn("diasmnocp", ["sab","dom"])->get()->toArray(),SORT_REGULAR);

              array_multisort(array_column($schedules, "horent"), SORT_ASC, $schedules);

              $horarios = [];
              foreach($schedules as $schedule){
                array_push($horarios, $schedule["horent"]." às ".$schedule["horsai"])
                ;
              }
              
              $linhas_s = 0;
              foreach($horarios as $h){
                $linhas_h = 0;
                foreach($dias as $dia){
                  if($turmas->filter(function($turma)use($dia, $h){return $turma->classschedules()->where("horent",explode(" ",$h)[0])->where("horsai",explode(" ",$h)[2])->where("diasmnocp",$dia)->exists();})->count()*2 > $linhas_h){
                    $linhas_h = $turmas->filter(function($turma)use($dia, $h){return $turma->classschedules()->where("horent",explode(" ",$h)[0])->where("horsai",explode(" ",$h)[2])->where("diasmnocp",$dia)->exists();})->count()*2;
                  }
                }
                if($linhas_h){
                  $linhas_s += max(3,$linhas_h);
                }
              }
              $linhas += $linhas_s;
            @endphp
            @if($turmas->isNotEmpty())
              @if($linhas > $max_linhas)
                @php 
                  $linhas = $linhas_s; 
                  $max_linhas = 48;
                @endphp
                \pagebreak
              @endif
              \hline
              @if(count($horarios)>1)
                \multirow{{!! $linhas_s !!}}{*}{
                  \makecell{
                    {!! $semestre !!}°\\Semestre
                    @if(count($habs)>1)
                      \\{\footnotesize Habilitação:}\\{\footnotesize {!! in_array($codhab, [0,1,2,4]) ? "00".$codhab : $codhab !!}}
                    @endif
                  }
                }
              @else
                \makecell{
                  {!! $semestre !!}°\\Semestre
                  @if(count($habs)>1)
                    \\{\footnotesize Habilitação:}\\{\footnotesize {!! in_array($codhab, [0,1,2,4]) ? "00".$codhab : $codhab !!}}
                  @endif
                }
              @endif
              @foreach($horarios as $h)
                &\makecell{{!! explode(" ",$h)[0] !!}\\{!! explode(" ",$h)[1] !!}\\{!! explode(" ",$h)[2] !!}} 
                @foreach($dias as $dia)
                  &
                  @php $done = []; @endphp
                  \makecell{
                    @foreach($turmas as $turma)
                        @if($turma->classschedules()->where("diasmnocp",$dia)->where("horent",explode(" ",$h)[0])->where("horsai",explode(" ",$h)[2])->get()->isNotEmpty())
                          @if(!$turma->externa)
                            @if($turma->fusion()->exists())
                            \href{run:https://uspdigital.usp.br/jupiterweb/obterTurma?nomdis=\&sgldis={!! $turma->coddis !!}}{{!! $turma->coddis !!}}\\{!! "T.".substr($turma->codtur,-2,2) ." ". ($turma->fusion->master->room()->exists() ? "S. ".$turma->fusion->master->room->nome : "Sem Sala") !!}\\
                            @else
                            \href{run:https://uspdigital.usp.br/jupiterweb/obterTurma?nomdis=\&sgldis={!! $turma->coddis !!}}{{!! $turma->coddis !!}}\\{!! "T.".substr($turma->codtur,-2,2) ." ". ($turma->room()->exists() ? "S. ".$turma->room->nome : "Sem Sala") !!}\\
                            @endif
                          @elseif(!in_array($turma->id, $done))
                          \href{run:https://uspdigital.usp.br/jupiterweb/obterTurma?nomdis=\&sgldis={!! $turma->coddis !!}}{{!! $turma->coddis !!}}\\
                            @php $coddis = $turma->coddis; @endphp
                            @foreach($turmas->filter(function($t)use($coddis){return $t->coddis == $coddis;}) as $turma2)
                              @if($turma2->classschedules()->where("diasmnocp",$dia)->where("horent",explode(" ",$h)[0])->where("horsai",explode(" ",$h)[2])->get()->isNotEmpty())
                                {!! "T.".substr($turma2->codtur,-2,2)." " !!}
                                @php array_push($done, $turma2->id); @endphp
                              @endif
                            @endforeach
                            \\
                          @endif
                        @endif
                    @endforeach
                  }
                @endforeach
                \\ 
                \cline{2-7}
              @endforeach
              \hline
            @endif
          @endforeach
        @endforeach
      \end{longtable}

      @php
        $turmas = App\Models\SchoolClass::whereBelongsTo($schoolterm)
          ->whereHas("courseinformations", function($query)use($semestres, $course){$query->whereIn("numsemidl",$semestres)->where("nomcur",$course["nomcur"])->where("perhab", $course["perhab"]);})->get()->sortBy("nomdis");

        if(in_array("grupo",array_keys($course))){
            if($course["grupo"]=="A"){
              $turmas = $turmas->filter(function($turma)use($turmas, $schoolterm){
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
            }elseif($course["grupo"]=="B"){
              $turmas = $turmas->filter(function($turma)use($turmas, $schoolterm){
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
            }
          }

        $habs = [];
        foreach($turmas as $turma){
          $habs = array_merge($habs, array_column($turma->courseinformations()->select(["codhab"])->whereIn("numsemidl",$semestres)->where("nomcur",$course["nomcur"])->where("perhab", $course["perhab"])->get()->toArray(),"codhab"));
        }
        $mais_de_uma_hab = count(array_unique($habs)) > 1 ? true : false;
      @endphp

      \begin{footnotesize}
      \begin{longtable}{ >{\centering\arraybackslash}m{1.5cm} | >{\raggedright}m{8.5cm} | >{\raggedright}m{3.2cm} | >{\centering\arraybackslash}m{1.2cm} | >{\centering\arraybackslash}m{1.2cm} }
      \multicolumn{1}{>{\centering\arraybackslash}m{1.5cm}|}{\textbf{Código}} & 
      \multicolumn{1}{>{\centering\arraybackslash}m{8.5cm}|}{\textbf{Nome da Disciplina}} & 
      \multicolumn{1}{>{\centering\arraybackslash}m{3.2cm}|}{\textbf{Tipo}} & 
      \multicolumn{1}{>{\centering\arraybackslash}m{1.2cm}|}{\textbf{Sala}} & 
      \multicolumn{1}{>{\centering\arraybackslash}m{1.2cm}}{\textbf{Turma}} \\
      \midrule
      \endfirsthead
      \multicolumn{1}{>{\centering\arraybackslash}m{1.5cm}|}{\textbf{Código}} & 
      \multicolumn{1}{>{\centering\arraybackslash}m{8.5cm}|}{\textbf{Nome da Disciplina}} & 
      \multicolumn{1}{>{\centering\arraybackslash}m{3.2cm}|}{\textbf{Tipo}} & 
      \multicolumn{1}{>{\centering\arraybackslash}m{1.2cm}|}{\textbf{Sala}} & 
      \multicolumn{1}{>{\centering\arraybackslash}m{1.2cm}}{\textbf{Turma}} \\
      \midrule
      \endhead
        \hline
        @php $done = []; @endphp
        @foreach($turmas as $turma)
          @if(!in_array($turma->id, $done))
            {!! $turma->coddis !!}& 
            \href{run:https://uspdigital.usp.br/jupiterweb/obterTurma?nomdis=\&sgldis={!! $turma->coddis !!}}{{!! $turma->nomdis !!}} 
            & 
            @php  
              $tipobg = $turma->courseinformations()->select(["codcur","codhab","tipobg"])->whereIn("numsemidl",$semestres)->where("nomcur",$course["nomcur"])->where("perhab", $course["perhab"])->get()->sortBy("codhab")->toArray();

              foreach($tipobg as $key=>$value){
                unset($tipobg[$key]["pivot"]);
              }

              $tipobg = array_unique($tipobg, SORT_REGULAR);

              $tipos = ["L"=>"Livre","O"=>"Obrigatória","C"=>"Eletiva"];
            @endphp
            \makecell[lc]{
              @foreach($tipobg as $t)
                @if($t["codcur"] != $course["codcur"])
                  @php
                    $mostrar_cur_ant = true;
                    foreach($tipobg as $t2){
                      if($t["codcur"] != $t2["codcur"] and $t["codhab"] == $t2["codhab"] and $t["tipobg"] == $t2["tipobg"]){
                        $mostrar_cur_ant = false;
                      }
                    }
                  @endphp
                  @if($mostrar_cur_ant)
                    Curr. Ant. {!! $tipos[$t["tipobg"]] !!}\\
                  @endif
                @elseif($mais_de_uma_hab)
                  hab {!! $t["codhab"] !!} {!! $tipos[$t["tipobg"]] !!}\\
                @else
                  @php
                    $mostrar_cur_nov = false;
                    foreach($tipobg as $t2){
                      if($t["codcur"] != $t2["codcur"] and $t["codhab"] == $t2["codhab"] and $t["tipobg"] != $t2["tipobg"]){
                        $mostrar_cur_nov = true;
                      }
                    }
                  @endphp
                  @if($mostrar_cur_nov)
                    Curr. Nov. {!! $tipos[$t["tipobg"]] !!}\\
                  @else
                    {!! $tipos[$t["tipobg"]] !!}\\
                    @endif
                @endif
              @endforeach
            }
            &
            @if(!$turma->externa)
              @if($turma->fusion()->exists()) 
                {!! $turma->fusion->master->room()->exists() ? $turma->fusion->master->room->nome : "Sem Sala" !!}
              @else
                {!! $turma->room()->exists() ? $turma->room->nome : "Sem Sala" !!}
              @endif
            @else
              Externa
            @endif
            & 
            @if($turma->externa)
              @php 
                $coddis = $turma->coddis; 
                $codturs = [];
              @endphp
              @foreach($turmas->filter(function($t)use($coddis){return $t->coddis == $coddis and $t->externa;}) as $turma2)
                @php 
                  array_push($done, $turma2->id); 
                  array_push($codturs, substr($turma2->codtur,-2,2)); 
                @endphp
              @endforeach
              @php sort($codturs); @endphp
              \makecell{
                @foreach($codturs as $codtur)
                  {!! "T.".$codtur !!}\\
                @endforeach
              }
            @else
              {!! "T.".substr($turma->codtur,-2,2) !!}
            @endif
            \\
            \hline
          @endif
        @endforeach
      \end{longtable}
      \end{footnotesize}
    \pagebreak
    @endif
  @endforeach
@endforeach

\end{document}