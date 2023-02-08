<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use romanzipp\QueueMonitor\Traits\IsMonitored;
use App\Models\SchoolTerm;
use App\Models\SchoolClass;
use App\Models\Instructor;
use App\Models\ClassSchedule;
use App\Models\Priority;
use App\Models\Room;
use App\Models\CourseInformation;
use App\Models\Fusion;
use App\Models\Course;

class ProcessImportSchoolClasses implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, IsMonitored;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    public function progressCooldown(): int
    {
        return 1; 
    }

    public $timeout = 999;

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $this->queueProgress(1);

        $schoolterm = SchoolTerm::getLatest();

        $turmas = SchoolClass::getFromReplicadoBySchoolTerm($schoolterm);

        $this->queueProgress(10);
        $t = count($turmas);
        $n = 0;

        foreach($turmas as $turma){
            if (($turma['tiptur'] == "Pós Graduação") or 
                ($turma['tiptur'] == "Graduação" and substr($turma["codtur"], -2, 2) >= "40") or
                ($turma['coddis'] == "MAT0112" and substr($turma["codtur"], -2, 2) == "34") or
                ($turma['coddis'] == "MAT0111" and substr($turma["codtur"], -2, 2) == "34") or
                ($turma['coddis'] == "MAT0121" and substr($turma["codtur"], -2, 2) == "34") or
                ($turma['tiptur'] == "Graduação" and $turma["coddis"] == "MAE0116") or
                ($turma["externa"])){
                if($turma['class_schedules']){
                    $schoolclass = SchoolClass::where(array_intersect_key($turma, array_flip(array('codtur', 'coddis'))))->first();
        
                    if(!$schoolclass){
                        $schoolclass = new SchoolClass;
                        $schoolclass->fill($turma);
                        $schoolclass->save();
                
                        foreach($turma['instructors'] as $instructor){
                            $docente = Instructor::getFromReplicadoByCodpes($instructor["codpes"]);
                            $schoolclass->instructors()->attach(Instructor::updateOrCreate(["nompes"=>$docente["nompes"],"codpes"=>$docente["codpes"]],["codema"=>$docente["codema"]]));
                        }
            
                        foreach($turma['class_schedules'] as $classSchedule){
                            $schoolclass->classschedules()->attach(ClassSchedule::firstOrCreate($classSchedule));
                        }

                        $priorities = Priority::$priorities_by_course;

                        if(in_array($schoolclass->coddis,array_keys($priorities))){
                            foreach($priorities[$schoolclass->coddis] as $codtur=>$salas){
                                if($codtur == substr($schoolclass->codtur, -2, 2) and $schoolclass->tiptur == "Graduação"){
                                    foreach($salas as $room_name=>$priority){
                                        if($priority > 20){
                                            $room = Room::where("nome", $room_name)->first();
                                            if($room){
                                                Priority::updateOrCreate(
                                                    ["room_id"=>$room->id,"school_class_id"=>$schoolclass->id],
                                                    ["priority"=>$priority]
                                                );
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }else{
                        foreach($turma['instructors'] as $instructor){
                            if(!in_array($instructor["codpes"], $schoolclass->instructors()->pluck("codpes")->toarray())){
                                $docente = Instructor::getFromReplicadoByCodpes($instructor["codpes"]);
                                $schoolclass->instructors()->attach(Instructor::updateOrCreate(["nompes"=>$docente["nompes"],"codpes"=>$docente["codpes"]],["codema"=>$docente["codema"]]));
                            }
                        }
                    }

                    foreach(CourseInformation::getFromReplicadoBySchoolClass($schoolclass) as $info){
                        if(in_array($info["nomcur"],Course::all()->pluck("nomcur")->toArray())){
                            $ci = CourseInformation::firstOrCreate($info);
                            if(!$ci->schoolclasses->contains($schoolclass->id)){
                                $ci->schoolclasses()->save($schoolclass);
                            }
                        }
                    }

                    $schoolclass->calcEstimadedEnrollment();
                    
                    $schoolclass->save();

                    $schoolclass->searchForFusion();
                }
            }
            $n += 1;
            $this->queueProgress(10 + floor($n*70/$t));
        }

        $schoolclasses = SchoolClass::whereBelongsTo($schoolterm)->get();
        $schoolclasses = $schoolclasses->filter(function($schoolclass){
            if(count($schoolclass->classschedules)>1 and count($schoolclass->classschedules->pluck("diasmnocp")->unique()->toArray())==1){
                return true;
            }
            return false;
        });

        foreach($schoolclasses as $schoolclass){
            $schedules = $schoolclass->classschedules;
            $schoolclass->classschedules()->detach();
            $schoolclass->classschedules()->attach(ClassSchedule::firstOrCreate([
                "diasmnocp"=>$schedules->pluck("diasmnocp")[0],
                "horent"=>$schedules->pluck("horent")->min(),
                "horsai"=>$schedules->pluck("horsai")->max()
            ]));
        }

        $schoolclasses = SchoolClass::whereBelongsTo($schoolterm)->where("tiptur", "Graduação")->get();
        $schoolclasses = $schoolclasses->filter(function($schoolclass){
            if(count(SchoolClass::where("coddis",$schoolclass->coddis)->get())==1){
                return true;
            }
            return false;
        });

        foreach($schoolclasses as $schoolclass){
            $schoolclass->courseinformations()->detach();
            foreach(CourseInformation::getFromReplicadoBySchoolClassAlternative($schoolclass) as $info){
                CourseInformation::firstOrCreate($info)->schoolclasses()->save($schoolclass);
            }
        }

        $cis = [];
        foreach(SchoolClass::whereBelongsTo($schoolterm)->where("tiptur", "Graduação")->whereDoesntHave("courseinformations")->get() as $schoolclass){
            if(!SchoolClass::where("coddis",$schoolclass->coddis)->whereHas("courseinformations")->exists()){
                array_push($cis,["schoolclass"=>$schoolclass,"infos"=>CourseInformation::getFromReplicadoBySchoolClassAlternative($schoolclass)]);
            }
        }

        foreach($cis as $ci){
            foreach($ci["infos"] as $info){
                CourseInformation::firstOrCreate($info)->schoolclasses()->save($ci["schoolclass"]);
            }
        }


        $this->queueProgress(100);
    }
}
