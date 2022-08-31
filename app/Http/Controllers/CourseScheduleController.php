<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Requests\ShowPosCourseScheduleRequest;
use App\Models\Course;
use App\Models\SchoolTerm;
use App\Models\SchoolClass;
use App\Models\Observation;
use App\Models\ClassSchedule;

class CourseScheduleController extends Controller
{
    public function index()
    {
        $schoolterm = SchoolTerm::getLatest();

        return view("courseschedules.index", compact(["schoolterm"]));
    }

    public function show(Course $course)
    {
        $schoolterm = SchoolTerm::getLatest();

        $observations = Observation::whereBelongsTo($schoolterm)->get();

        $semesters = $schoolterm->period == "1° Semestre" ? [1,3,5,7,9] : [2,4,6,8,10];

        $habilitations = [];
        $show = [];
        $days = [];
        $schoolclasses = [];

        foreach($semesters as $semester){
            $turmas = SchoolClass::whereBelongsTo($schoolterm)
                    ->whereHas("courseinformations", function($query)use($semester, $course){
                        $query->whereIn("numsemidl",[$semester-1,$semester])
                            ->where("nomcur",$course->nomcur)
                            ->where("perhab", $course->perhab)
                            ->where("tipobg", "O");
                        })->get();

            $habs = [];

            foreach($turmas as $turma){
                $habs = array_merge($habs, array_column(
                    $turma->courseinformations()
                        ->select(["codhab","nomhab"])
                        ->whereIn("numsemidl",[$semester-1,$semester])
                        ->where("nomcur",$course->nomcur)
                        ->where("perhab", $course->perhab)
                        ->get()->sortBy("codhab")->toArray(),"codhab", "nomhab"));
            }
            unset($habs["Habilitação em Saúde Animal"]);
            asort($habs);

            $habilitations[$semester] = $habs;

            foreach($habs as $nomhab=>$codhab){
                $turmas = SchoolClass::whereBelongsTo($schoolterm)
                    ->whereHas("courseinformations", function($query)use($semester, $course, $codhab, $habilitations){
                        if(count($habilitations[$semester])>1){
                            $query->whereIn("numsemidl",[$semester-1,$semester])
                            ->where("nomcur",$course->nomcur)
                            ->where("perhab", $course->perhab)
                            ->where("tipobg", "O")
                            ->whereIn("codhab", [1,4,$codhab]);
                        }else{
                            $query->whereIn("numsemidl",[$semester-1,$semester])
                                ->where("nomcur",$course->nomcur)
                                ->where("perhab", $course->perhab)
                                ->where("tipobg", "O");
                        }
                        })->get();
                
                $show[$semester][$nomhab] = $turmas->isNotEmpty() and ((count($habilitations[$semester])>1 and !in_array($codhab, [1,4])) or (count($habilitations[$semester])==1));

                if($show[$semester][$nomhab]){  
                    if($course->nomcur=="Matemática Licenciatura" and $course->perhab=="diurno"){
                        $turmas = $turmas->filter(function($turma){
                            if(substr($turma->codtur,-2,2)=="47" or substr($turma->codtur,-2,2)=="48"){
                                return false;
                            }
                            foreach($turma->classschedules as $schedule){
                                if($schedule->horent >= "18:00"){
                                    return false;
                                }
                            }
                            return true;
                        });
                    }elseif($course->nomcur=="Estatística Bacharelado"){
                        $turmas = $turmas->filter(function($turma){
                            foreach($turma->classschedules as $schedule){
                                if($schedule->horent >= "18:00"){
                                    return false;
                                }
                            }
                            return true;
                        });
                    }elseif($course->nomcur=="Matemática Aplicada - Bacharelado"){
                        $turmas = $turmas->filter(function($turma){
                            foreach($turma->classschedules as $schedule){
                                if($schedule->horent >= "18:00"){
                                    return false;
                                }
                            }
                            return true;
                        });
                    }elseif($course->nomcur=="Bacharelado em Matemática Aplicada e Computacional"){
                        $turmas = $turmas->filter(function($turma)use($turmas){
                            foreach($turmas as $t2){
                                if($turma->coddis == $t2->coddis and $turma->id != $t2->id){
                                    if($t2->classschedules()->where("horent",">=","18:00")->exists() and
                                        $turma->classschedules()->where("horsai","<=", "19:00")->exists()){
                                        $conflict = false;
                                        foreach($turmas as $t3){
                                            if($t2->isInConflict($t3) and $t2->id != $t3->id){
                                                $conflict = true;
                                            }
                                        }
                                        if(!$conflict and $turma->coddis != "HCV0129"){
                                            return false;
                                        }
                                    }
                                }
                            }
                            return true;
                        });
                    }

                    $days[$semester][$nomhab] = ['seg', 'ter', 'qua', 'qui', 'sex']; 

                    $temSab = $turmas->filter(function($turma){
                        foreach($turma->classschedules as $schedule){
                            if($schedule->diasmnocp=="sab"){
                                return true;
                            }
                        }
                        return false;
                    })->isNotEmpty();

                    if($temSab){
                        array_push($days[$semester][$nomhab], "sab");
                    } 

                    $horarios = array_unique(ClassSchedule::whereHas("schoolclasses", function($query)use($turmas){
                        $query->whereIn("id",$turmas->pluck("id")->toArray());
                    })->select(["horent","horsai"])->where("diasmnocp", "!=", "dom")->get()->toArray(),SORT_REGULAR);

                    array_multisort(array_column($horarios, "horent"), SORT_ASC, $horarios);

                    $schedules[$semester][$nomhab] = [];
                    foreach($horarios as $horario){
                        array_push($schedules[$semester][$nomhab], $horario["horent"]." às ".$horario["horsai"]);
                    }

                    $schoolclasses[$semester][$nomhab] = $turmas;
                }
            }
        }

        $turmas = SchoolClass::whereBelongsTo($schoolterm)
                ->whereHas("courseinformations", function($query)use($course){
                    $query->where("nomcur",$course->nomcur)
                        ->where("perhab", $course->perhab)
                        ->whereIn("tipobg", ["C","L"]);
                    })->get();

        $optatives_habilitations = [];

        foreach($turmas as $turma){
            $optatives_habilitations = array_merge($optatives_habilitations, array_column(
                $turma->courseinformations()
                    ->select(["codhab","nomhab"])
                    ->where("nomcur",$course->nomcur)
                    ->where("perhab", $course->perhab)
                    ->get()->toArray(),"codhab", "nomhab"));
        }
        unset($optatives_habilitations["Habilitação em Saúde Animal"]);
        asort($optatives_habilitations);

        $electives = [];
        $free_electives = [];
        $electives_days = [];
        $electives_schedules = [];

        foreach($optatives_habilitations as $nomhab=>$codhab){
            $electives[$nomhab] = SchoolClass::whereBelongsTo($schoolterm)
                ->whereHas("courseinformations", function($query)use($semester, $course, $codhab){
                    $query->where("nomcur",$course->nomcur)
                        ->where("perhab", $course->perhab)
                        ->where("tipobg", "C")
                        ->where("codhab", $codhab);
                    })->get();

            $free_electives[$nomhab] = SchoolClass::whereBelongsTo($schoolterm)->with("courseinformations")
                ->whereHas("courseinformations", function($query)use($semester, $course, $codhab){
                    $query->where("nomcur",$course->nomcur)
                        ->where("perhab", $course->perhab)
                        ->whereIn("tipobg", ["L","C"])
                        ->where("codhab", $codhab);
                    })->orderBy("coddis")->get();
            
            
            if($electives[$nomhab]->isNotEmpty()){
                $electives_days[$nomhab] = ['seg', 'ter', 'qua', 'qui', 'sex']; 
    
                $temSab = $electives[$nomhab]->filter(function($turma){
                    foreach($turma->classschedules as $schedule){
                        if($schedule->diasmnocp=="sab"){
                            return true;
                        }
                    }
                    return false;
                })->isNotEmpty();
    
                if($temSab){
                    array_push($electives_days[$nomhab], "sab");
                } 
                
                $ids = $electives[$nomhab]->pluck("id")->toArray();
                $horarios = array_unique(ClassSchedule::whereHas("schoolclasses", function($query)use($ids){
                    $query->whereIn("id",$ids);
                })->select(["horent","horsai"])->where("diasmnocp", "!=", "dom")->get()->toArray(),SORT_REGULAR);
    
                array_multisort(array_column($horarios, "horent"), SORT_ASC, $horarios);
    
                $electives_schedules[$nomhab] = [];
                foreach($horarios as $horario){
                    array_push($electives_schedules[$nomhab], $horario["horent"]." às ".$horario["horsai"]);
                }
            }
        }

        return view("courseschedules.show", compact([
            "course",
            "schoolterm", 
            "observations",
            "semesters",
            "habilitations",
            "show",
            "days",
            "schoolclasses",
            "schedules",
            "optatives_habilitations",
            "electives",
            "free_electives",
            "electives_days",
            "electives_schedules",
        ]));
    }

    public function showLicNot()
    {
        $schoolterm = SchoolTerm::getLatest();

        $course = Course::where("codcur",45024)->where("perhab","noturno")->first();

        $observations = Observation::whereBelongsTo($schoolterm)->get();

        $semesters = $schoolterm->period == "1° Semestre" ? [1,3,5,7,9] : [2,4,6,8,10];

        $schoolclasses = [];
        $A_equals_B = [];
        $days = [];
        $schedules = [];

        foreach($semesters as $semester){
            $A_equals_B[$semester] = false;
            foreach(["A","B"] as $grupo){
                if(!$A_equals_B[$semester]){
                    $turmas = SchoolClass::whereBelongsTo($schoolterm)
                        ->whereHas("courseinformations", function($query)use($semester, $course){
                            $query->whereIn("numsemidl",[$semester-1,$semester])
                                ->where("nomcur",$course->nomcur)
                                ->where("perhab", $course->perhab)
                                ->where("tipobg", "O");
                            })->get();   
    
                    $turmas = $turmas->filter(function($turma){
                        foreach($turma->classschedules as $schedule){
                            if($schedule->horent < "18:00"){
                                return false;
                            }
                        }
                        return true;
                    });        
    
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
                        $schoolclasses[$semester][$grupo] = $turmas_grupoA;
                        $A_equals_B[$semester] = true;
                    }elseif($grupo=="A"){
                        $schoolclasses[$semester][$grupo] = $turmas_grupoA;
                    }elseif($grupo=="B"){
                        $schoolclasses[$semester][$grupo] = $turmas_grupoB;
                    }
    
                    $days[$semester][$grupo] = ['seg', 'ter', 'qua', 'qui', 'sex'];  
    
                    $temSab = $schoolclasses[$semester][$grupo]->filter(function($turma){
                        foreach($turma->classschedules as $schedule){
                            if($schedule->diasmnocp=="sab"){
                                return true;
                            }
                        }
                        return false;
                    })->isNotEmpty();
    
                    if($temSab){
                        array_push($days[$semester][$grupo], "sab");
                    }

                    $ids = $schoolclasses[$semester][$grupo]->pluck("id")->toArray();
                    $horarios = array_unique(ClassSchedule::whereHas("schoolclasses", function($query)use($ids){
                        $query->whereIn("id",$ids);
                    })->select(["horent","horsai"])->where("diasmnocp", "!=", "dom")->get()->toArray(),SORT_REGULAR);

                    array_multisort(array_column($horarios, "horent"), SORT_ASC, $horarios);

                    $schedules[$semester][$grupo] = [];
                    foreach($horarios as $horario){
                        array_push($schedules[$semester][$grupo], $horario["horent"]." às ".$horario["horsai"]);
                    }
                }
            }
        }

        $electives = SchoolClass::whereBelongsTo($schoolterm)
            ->whereHas("courseinformations", function($query)use($course){
                $query->where("nomcur",$course->nomcur)
                    ->where("perhab", $course->perhab)
                    ->where("tipobg", "C");
                })->get();
        $free_electives = SchoolClass::whereBelongsTo($schoolterm)->with("courseinformations")
            ->whereHas("courseinformations", function($query)use($course){
                $query->where("nomcur",$course->nomcur)
                    ->where("perhab", $course->perhab)
                    ->whereIn("tipobg", ["L","C"]);
                })->orderBy("coddis")->get();


        if($electives->isNotEmpty()){
            $electives_days = ['seg', 'ter', 'qua', 'qui', 'sex']; 

            $temSab = $electives->filter(function($turma){
                foreach($turma->classschedules as $schedule){
                    if($schedule->diasmnocp=="sab"){
                        return true;
                    }
                }
                return false;
            })->isNotEmpty();

            if($temSab){
                array_push($electives_days, "sab");
            } 
            
            $ids = $electives->pluck("id")->toArray();
            $horarios = array_unique(ClassSchedule::whereHas("schoolclasses", function($query)use($ids){
                $query->whereIn("id",$ids);
            })->select(["horent","horsai"])->where("diasmnocp", "!=", "dom")->get()->toArray(),SORT_REGULAR);

            array_multisort(array_column($horarios, "horent"), SORT_ASC, $horarios);

            $electives_schedules = [];
            foreach($horarios as $horario){
                array_push($electives_schedules, $horario["horent"]." às ".$horario["horsai"]);
            }
        }

        return view("courseschedules.showLicNot", compact([
            "schoolterm", 
            "course", 
            "observations",
            "semesters",
            "schoolclasses",
            "days",
            "schedules",
            "A_equals_B",
            "electives",
            "free_electives",
            "electives_days",
            "electives_schedules",
        ]));
    }

    public function showAll()
    {
        $schoolterm = SchoolTerm::getLatest();
        $schoolclasses = SchoolClass::whereBelongsTo($schoolterm)->where("externa", false)->orderBy("coddis")->get();
        $days = ["seg"=>1,"ter"=>2,"qua"=>3,"qui"=>4,"sex"=>5,"sab"=>6];
        $observations = Observation::whereBelongsTo($schoolterm)->get();
        
        return view("courseschedules.showAll", compact([
            "schoolterm", 
            "schoolclasses",
            "observations",
            "days",
        ]));
    }

    public function showPos(ShowPosCourseScheduleRequest $request)
    {
        $validated = $request->validated();

        $schoolterm = SchoolTerm::getLatest();

        $observations = Observation::whereBelongsTo($schoolterm)->get();
        
        if($validated["prefixo"] != "MPM"){
            $schoolclasses = SchoolClass::whereBelongsTo($schoolterm)
                ->where("tiptur","Pós Graduação")
                ->where("coddis","LIKE",$validated["prefixo"]."%")->orderBy("coddis")->get();
            $programas = [
                "MAC"=>"Programa de Pós-graduação em Ciência da Computação",
                "MAE"=>"Programa de Pós-graduação em Estatística",
                "MAT"=>"Programa de Pós-graduação em Matemática",
                "MAP"=>"Programa de Pós-graduação em Matemática Aplicada"];
            $titulo = $programas[$validated["prefixo"]];
        }else{
            $schoolclasses = SchoolClass::whereBelongsTo($schoolterm)
                ->where("tiptur","Pós Graduação")
                ->where(function($query){
                    $query->where("coddis","LIKE","MPM%")
                        ->orWhere("coddis","LIKE","GEN%");
                })->orderBy("coddis")->get();
            $titulo = "Mestrado Profissional em Ensino de Matemática";
        }
              
        $days = ['seg', 'ter', 'qua', 'qui', 'sex'];  

        $temSab = $schoolclasses->filter(function($turma){
            foreach($turma->classschedules as $schedule){
                if($schedule->diasmnocp=="sab"){
                    return true;
                }
            }
            return false;
        })->isNotEmpty();

        if($temSab){
            array_push($days, "sab");
        }

        $horarios = array_unique(ClassSchedule::whereHas("schoolclasses", function($query)use($schoolclasses){
            $query->whereIn("id",$schoolclasses->pluck("id")->toArray());
        })->select(["horent","horsai"])->where("diasmnocp", "!=", "dom")->get()->toArray(),SORT_REGULAR);

        array_multisort(array_column($horarios, "horent"), SORT_ASC, $horarios);

        $schedules = [];
        foreach($horarios as $horario){
            array_push($schedules, $horario["horent"]." às ".$horario["horsai"]);
        }
        return view("courseschedules.showpos", compact([
            "schoolterm",
            "schoolclasses",
            "observations",
            "titulo",
            "days",
            "schedules",
        ]));
    }
}
