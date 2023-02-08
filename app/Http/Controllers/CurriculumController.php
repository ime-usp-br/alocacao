<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\SchoolTerm;
use App\Models\Course;
use App\Models\CourseInformation;
use App\Models\SchoolClass;
use App\Models\ClassSchedule;
use Auth;

class CurriculumController extends Controller
{
    public function index()
    {
        if(!Auth::check()){
            return redirect("/login");
        }elseif(!Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        $schoolterm = SchoolTerm::getLatest();

        return view("curriculum.index", compact("schoolterm"));
    }

    public function semesters(Course $course)
    {
        if(!Auth::check()){
            return redirect("/login");
        }elseif(!Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        $schoolterm = SchoolTerm::getLatest();

        $semesters = array_column(CourseInformation::where("nomcur", $course->nomcur)
                                        ->where("perhab", $course->perhab)
                                        ->select("numsemidl")
                                        ->distinct()
                                        ->orderByRaw('CONVERT(numsemidl, SIGNED) asc')
                                        ->get()
                                        ->toarray(), "numsemidl");

        //$semesters = array_intersect($schoolterm->period == "1° Semestre" ? [1,3,5,7,9] : [2,4,6,8,10],$semesters);

        return view("curriculum.semesters", compact(["schoolterm","course","semesters"]));
    }

    public function semestersLicNot()
    {
        if(!Auth::check()){
            return redirect("/login");
        }elseif(!Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        $schoolterm = SchoolTerm::getLatest();

        $course = Course::where("codcur", "45024")->where("perhab", "noturno")->first();

        $semesters = array_column(CourseInformation::where("nomcur", $course->nomcur)
                                        ->where("perhab", $course->perhab)
                                        ->select("numsemidl")
                                        ->distinct()
                                        ->orderByRaw('CONVERT(numsemidl, SIGNED) asc')
                                        ->get()
                                        ->toarray(), "numsemidl");

        //$semesters = array_intersect($schoolterm->period == "1° Semestre" ? [1,3,5,7,9] : [2,4,6,8,10],$semesters);

        return view("curriculum.semesterslicnot", compact(["schoolterm","course","semesters"]));
    }

    public function edit(Course $course, $semester)
    {
        $schoolterm = SchoolTerm::getLatest();

        $habilitations = [];
        $show = [];
        $days = [];
        $schoolclasses = [];

        $turmas = SchoolClass::whereBelongsTo($schoolterm)
                ->whereHas("courseinformations", function($query)use($semester, $course){
                    $query->where("numsemidl",$semester)
                        ->where("nomcur",$course->nomcur)
                        ->where("perhab", $course->perhab)
                        ->where("tipobg", "O");
                    })->get();

        $habs = [];

        foreach($turmas as $turma){
            $habs = array_merge($habs, array_column(
                $turma->courseinformations()
                    ->select(["codhab","nomhab"])
                    ->where("numsemidl",$semester)
                    ->where("nomcur",$course->nomcur)
                    ->where("perhab", $course->perhab)
                    ->where("tipobg", "O")
                    ->get()->sortBy("codhab")->toArray(),"codhab", "nomhab"));
        }
        unset($habs["Habilitação em Saúde Animal"]);
        asort($habs);

        $habilitations = $habs;
        
        foreach($habs as $nomhab=>$codhab){
            $turmas = SchoolClass::whereBelongsTo($schoolterm)
                ->whereHas("courseinformations", function($query)use($semester, $course, $codhab, $habilitations){
                    if(count($habilitations)>1){
                        $query->where("numsemidl",$semester)
                        ->where("nomcur",$course->nomcur)
                        ->where("perhab", $course->perhab)
                        ->where("tipobg", "O")
                        ->whereIn("codhab", [1,4,$codhab]);
                    }else{
                        $query->where("numsemidl",$semester)
                            ->where("nomcur",$course->nomcur)
                            ->where("perhab", $course->perhab)
                            ->where("codhab", $codhab)
                            ->where("tipobg", "O");
                    }
                    })->get();

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

            $show[$nomhab] = ($turmas->isNotEmpty() and ((count($habilitations)>1 and !in_array($codhab, [1,4])) or (count($habilitations)==1)));
            
            if($show[$nomhab]){  

                $days[$nomhab] = ['seg', 'ter', 'qua', 'qui', 'sex']; 

                $temSab = $turmas->filter(function($turma){
                    foreach($turma->classschedules as $schedule){
                        if($schedule->diasmnocp=="sab"){
                            return true;
                        }
                    }
                    return false;
                })->isNotEmpty();

                if($temSab){
                    array_push($days[$nomhab], "sab");
                } 

                $horarios = array_unique(ClassSchedule::whereHas("schoolclasses", function($query)use($turmas){
                    $query->whereIn("id",$turmas->pluck("id")->toArray());
                })->select(["horent","horsai"])->where("diasmnocp", "!=", "dom")->get()->toArray(),SORT_REGULAR);

                array_multisort(array_column($horarios, "horent"), SORT_ASC, $horarios);

                $schedules[$nomhab] = [];
                foreach($horarios as $horario){
                    array_push($schedules[$nomhab], $horario["horent"]." às ".$horario["horsai"]);
                }

                $schoolclasses[$nomhab] = $turmas;
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

        $schedules = $schedules ?? [];

        return view("curriculum.edit", compact([
            "course",
            "schoolterm", 
            "semester",
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

    public function editLicNot(Course $course, $semester)
    {
        $schoolterm = SchoolTerm::getLatest();

        $course = Course::where("codcur",45024)->where("perhab","noturno")->first();

        $schoolclasses = [];
        $A_equals_B = [];
        $days = [];
        $schedules = [];

        $A_equals_B = false;
        foreach(["A","B"] as $grupo){
            if(!$A_equals_B){
                $turmas = SchoolClass::whereBelongsTo($schoolterm)
                    ->whereHas("courseinformations", function($query)use($semester, $course){
                        $query->where("numsemidl",$semester)
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
                    $schoolclasses[$grupo] = $turmas_grupoA;
                    $A_equals_B = true;
                }elseif($grupo=="A"){
                    $schoolclasses[$grupo] = $turmas_grupoA;
                }elseif($grupo=="B"){
                    $schoolclasses[$grupo] = $turmas_grupoB;
                }

                $days[$grupo] = ['seg', 'ter', 'qua', 'qui', 'sex'];  

                $temSab = $schoolclasses[$grupo]->filter(function($turma){
                    foreach($turma->classschedules as $schedule){
                        if($schedule->diasmnocp=="sab"){
                            return true;
                        }
                    }
                    return false;
                })->isNotEmpty();

                if($temSab){
                    array_push($days[$grupo], "sab");
                }

                $ids = $schoolclasses[$grupo]->pluck("id")->toArray();
                $horarios = array_unique(ClassSchedule::whereHas("schoolclasses", function($query)use($ids){
                    $query->whereIn("id",$ids);
                })->select(["horent","horsai"])->where("diasmnocp", "!=", "dom")->get()->toArray(),SORT_REGULAR);

                array_multisort(array_column($horarios, "horent"), SORT_ASC, $horarios);

                $schedules[$grupo] = [];
                foreach($horarios as $horario){
                    array_push($schedules[$grupo], $horario["horent"]." às ".$horario["horsai"]);
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

        return view("curriculum.editlicnot", compact([
            "schoolterm", 
            "course", 
            "semester",
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
}
