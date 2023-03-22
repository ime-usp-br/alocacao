<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Requests\CompatibleRoomRequest;
use App\Http\Requests\AllocateRoomRequest;
use App\Http\Requests\DistributesRoomRequest;
use App\Http\Requests\EmptyRoomRequest;
use App\Http\Requests\ReservationRoomRequest;
use Ismaelw\LaraTeX\LaraTeX;
use App\Jobs\ProcessReport;
use App\Jobs\ProcessReservation;
use romanzipp\QueueMonitor\Models\Monitor;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Gate;
use Session;
use App\Models\Requisition;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\SchoolTerm;
use App\Models\Priority;
use App\Models\SchoolClass;
use App\Models\CourseInformation;
use App\Models\Fusion;
use Illuminate\Support\Facades\Auth;


class RoomController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        if(!Auth::check() or !Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        $salas = Room::all();

        return view('rooms.index', compact(['salas']));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Room  $room
     * @return \Illuminate\Http\Response
     */
    public function show(Room $room)
    {
        if(!Auth::check() or !Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        return view('rooms.show', compact(['room']));
    }

    public function showFreeTime()
    {
        if(!Auth::check() or !Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        $st = SchoolTerm::getLatest();

        $horarios = [
            "08:00"=>"09:40",
            "10:00"=>"11:40",
            "14:00"=>"15:40",
            "16:00"=>"17:40",
            "19:20"=>"21:00",
            "21:10"=>"22:50",
        ];

        $dias = ['seg', 'ter', 'qua', 'qui', 'sex'];  

        $rooms = [];

        foreach($dias as $dia){
            foreach($horarios as $horent=>$horsai){
                $rooms[$dia][$horent][$horsai] = Room::whereDoesntHave("schoolclasses",function($query)use($st,$dia,$horent,$horsai){
                    $query->whereBelongsTo($st)->whereHas("classschedules",function($query)use($dia,$horent,$horsai){
                        $query->where("diasmnocp",$dia)->where("horsai",">",$horent)->where("horent","<",$horsai);
                    });
                })->get();
            }
        }

        return view('rooms.showFreeTime', compact([
            "st",
            "dias",
            "horarios",
            "rooms",
        ]));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Room  $room
     * @return \Illuminate\Http\Response
     */
    public function edit(Room $room)
    {
        //
    }

    /**CourseInformation
     */
    public function update(Request $request, Room $room)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Room  $room
     * @return \Illuminate\Http\Response
     */
    public function destroy(Room $room)
    {
        //
    }

    public function dissociate(SchoolClass $schoolclass)
    {
        if(!Auth::check() or !Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        $schoolclass->room()->dissociate();
        $schoolclass->save();

        return back();
    }

    public function compatible(CompatibleRoomRequest $request)
    {
        if(!Auth::check() or !Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        $validated = $request->validated();

        $room = Room::find($validated["room_id"]);

        $st = SchoolTerm::getLatest();

        $res = [];

        $turmas = SchoolClass::whereBelongsTo($st)->whereDoesntHave("room")->whereDoesntHave("fusion")
                    ->union(SchoolClass::whereExists(function($query){
                        $query->from("fusions")->whereColumn("fusions.master_id","school_classes.id");
                    })->whereBelongsTo($st)->whereDoesntHave("room"))->get()->sortBy("coddis");

        foreach($turmas as $turma){
            if($room->isCompatible($turma, $ignore_block=true, $ignore_estmtr=true)){
                array_push($res, $turma);
            }
        }

        return response()->json(json_encode($res));
    }

    public function allocate(AllocateRoomRequest $request, Room $room)
    {
        if(!Auth::check() or !Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        $validated = $request->validated();

        $room->schoolclasses()->save(SchoolClass::find($validated["school_class_id"]));

        return back();
    }

    public function makeReport()
    {
        if(!Auth::check() or !Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        ProcessReport::dispatch();

        return back();
    }

    public function downloadReport()
    {
        if(!Auth::check() or !Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        $job = Monitor::where("name","App\Jobs\ProcessReport")->latest("started_at")->first();

        $file = json_decode($job->data)->fileName;

        $job->delete();

        return Storage::download($file);
    }

    public function distributes(DistributesRoomRequest $request)
    {
        if(!Auth::check() or !Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        $validated = $request->validated();
        
        $salas_disponiveis = $validated["rooms_id"];

        $schoolterm = SchoolTerm::getLatest();

        $semestres = $schoolterm->period == "1° Semestre" ? [1] : [2];// Se quiser que em outros semestres os cursos fiquem na mesma sala add aqui 

        foreach($semestres as $semestre){
            foreach(CourseInformation::$codtur_by_course as $sufixo_codtur=>$course){
                $turmas = SchoolClass::whereBelongsTo($schoolterm)->whereHas("courseinformations", function($query)use($course, $semestre){
                                            $query->where("numsemidl",$semestre)->where("tipobg","O")->where("nomcur", $course["nomcur"])->where("perhab", $course["perhab"]);
                                        })->where("codtur","like","%".$sufixo_codtur)->where("externa", false)->get();
                if($turmas->count() > 1){
                    $ps = Priority::whereHas("schoolclass",function($query)use($turmas){
                                        $query->whereIn("id",$turmas->pluck("id")->toArray());
                                    })->with("room")->get();
        
                    $salas = [];
                    foreach($ps->groupBy("room.id") as $sala_id=>$p){
                        $salas[$p->sum("priority")] = Room::find($sala_id);
                    }
                    krsort($salas);
                    foreach(Room::whereNotIn("id", array_column($salas,"id"))->get()->sortby("assentos") as $room){
                        array_push($salas, $room);
                    }

                    $alocado = false;
                    foreach($salas as $room){
                        if(!$alocado){
                            if(in_array($room->id, $salas_disponiveis)){
                                $conflito = false;
                                foreach($turmas as $turma){
                                    if(!$room->isCompatible($turma)){
                                        $conflito = true;
                                    }
                                }
                                if(!$conflito){
                                    foreach($turmas as $turma){
                                        $room->schoolclasses()->save($turma);
                                    }
                                    $alocado = true;
                                }
                            }
                        }
                    }
                }
            }
        }

        $prioridades = Priority::whereHas("schoolclass", function($query) use($schoolterm) {$query->whereBelongsTo($schoolterm);})
                                ->get()->sortByDesc("priority");

        foreach($prioridades as $prioridade){
            $t1 = $prioridade->schoolclass;
            $room = $prioridade->room;
            if(in_array($room->id, $salas_disponiveis)){
                if(!$t1->room()->exists() and $t1->coddis!="MAE0116"){
                    if($t1->fusion()->exists()){
                        if($t1->fusion->master->id == $t1->id){
                            if($room->isCompatible($t1)){
                                $room->schoolclasses()->save($t1);
                            }                        
                        }
                    }elseif($room->isCompatible($t1)){
                        $room->schoolclasses()->save($t1);
                    }
                }
            }
        }

        $turmas = SchoolClass::whereBelongsTo($schoolterm)
                                ->where("tiptur","Pós Graduação")
                                ->whereDoesntHave("room")->get();
        foreach($turmas as $t1){
            foreach(Room::whereIn("id", $salas_disponiveis)->get()->shuffle() as $sala){
                if(!$t1->room()->exists() and !$t1->fusion()->exists()){
                    if($sala->isCompatible($t1)){
                        $sala->schoolclasses()->save($t1);
                    }
                }
            }
        }

        $turmas = SchoolClass::whereBelongsTo($schoolterm)
                                ->where("tiptur","Graduação")
                                ->whereNotNull("estmtr")
                                ->whereDoesntHave("room")
                                ->get()->sortBy("estmtr");
        foreach($turmas as $t1){
            foreach(Room::whereIn("id", $salas_disponiveis)->get()->sortby("assentos") as $sala){
                if(!$t1->room()->exists() and $t1->coddis!="MAE0116"){
                    if($t1->fusion()->exists()){
                        if($t1->fusion->master->id == $t1->id){
                            if($sala->isCompatible($t1)){
                                $sala->schoolclasses()->save($t1);
                            }                        
                        }
                    }elseif($sala->isCompatible($t1)){
                        $sala->schoolclasses()->save($t1);
                    }
                }
            }
        }

        Session::put("alert-info", "As turmas foram distribuidas nas salas com sucesso.");
        return back();
    }

    public function reservation(ReservationRoomRequest $request)
    {
        if(!Auth::check() or !Auth::user()->hasRole(["Administrador"])){
            abort(403);
        }

        $validated = $request->validated();

        ProcessReservation::dispatch($validated["rooms_id"]);

        Session::put("alert-info", "As reservas no Urano estão sendo processadas.");
        return back();
    }

    public function empty(EmptyRoomRequest $request)
    {
        if(!Auth::check() or !Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        $validated = $request->validated();

        $rooms_ids = $validated["rooms_id"];

        $schoolterm = SchoolTerm::getLatest();

        $schoolclasses = SchoolClass::whereBelongsTo($schoolterm)->whereHas("room", function($query)use($rooms_ids){
            $query->whereIn("id", $rooms_ids);
        })->get();

        foreach($schoolclasses as $schoolclass){
            $schoolclass->room()->dissociate();
            $schoolclass->save();
        }

        Session::put("alert-info", "As salas foram esvaziadas com sucesso.");
        return back();
    }
}
