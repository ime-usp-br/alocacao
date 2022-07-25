<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Room;
use App\Models\SchoolTerm;
use App\Models\Priority;
use App\Models\SchoolClass;
use App\Models\CourseInformation;
use App\Http\Requests\CompatibleRoomRequest;
use App\Http\Requests\AllocateRoomRequest;
use Ismaelw\LaraTeX\LaraTeX;

class RoomController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
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
        return view('rooms.show', compact(['room']));
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
        $schoolclass->room()->dissociate();
        $schoolclass->save();

        return back();
    }

    public function compatible(CompatibleRoomRequest $request)
    {
        $validated = $request->validated();

        $room = Room::find($validated["room_id"]);

        $st = SchoolTerm::getLatest();

        $res = [];

        $turmas = SchoolClass::whereBelongsTo($st)->whereDoesntHave("room")->whereDoesntHave("fusion")
                    ->union(SchoolClass::whereExists(function($query){
                        $query->from("fusions")->whereColumn("fusions.master_id","school_classes.id");
                    })->whereBelongsTo($st)->whereDoesntHave("room"))->get();

        foreach($turmas as $turma){
            if($room->isCompatible($turma, $ignore_block=true, $ignore_estmtr=true)){
                array_push($res, $turma);
            }
        }

        return response()->json(json_encode($res));
    }

    public function allocate(AllocateRoomRequest $request, Room $room)
    {
        $validated = $request->validated();

        $room->schoolclasses()->save(SchoolClass::find($validated["school_class_id"]));

        return back();
    }

    public function report()
    {

        return (new LaraTeX('rooms.reports.latex'))->with([
            'schoolterm' => SchoolTerm::getLatest(),
        ])->download('relatorio.pdf');
    }

    public function distributes()
    {
        //Implementar um formulario para saber as salas indisponiveis
        $salas_indiposniveis = ["B05"];

        $schoolterm = SchoolTerm::getLatest();

        foreach(SchoolClass::whereBelongsTo($schoolterm)->get() as $schoolclass){
            $schoolclass->room()->dissociate();
            $schoolclass->save();
        }

        foreach(CourseInformation::$codtur_by_course as $sufixo_codtur=>$course){
            $turmas = SchoolClass::whereBelongsTo($schoolterm)->whereHas("courseinformations", function($query){
                                        $query->whereIn("numsemidl",[1,2])->where("tipobg","O");
                                    })->where("codtur","like","%".$sufixo_codtur)->get();
                                    
            $ps = Priority::whereHas("schoolclass",function($query)use($turmas){
                                $query->whereIn("id",$turmas->pluck("id")->toArray());
                            })->with("room")->get();

            $salas = [];
            foreach($ps->groupBy("room.id") as $sala_id=>$p){
                $salas[$p->sum("priority")] = Room::find($sala_id);
            }
            krsort($salas);
            
            $salas = $salas ? $salas : Room::all()->sortby("assentos");

            $alocado = false;
            foreach($salas as $room){
                if(!$alocado){
                    if(!in_array($room->nome, $salas_indiposniveis)){
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

        $prioridades = Priority::whereHas("schoolclass", function($query) use($schoolterm) {$query->whereBelongsTo($schoolterm);})
                                ->get()->sortByDesc("priority");

        foreach($prioridades as $prioridade){
            $t1 = $prioridade->schoolclass;
            $room = $prioridade->room;
            if(!in_array($room->nome, $salas_indiposniveis)){
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
            foreach(Room::all()->shuffle() as $sala){
                if(!in_array($sala->nome, $salas_indiposniveis)){
                    if(!$t1->room()->exists() and !$t1->fusion()->exists()){
                        if($sala->isCompatible($t1)){
                            $sala->schoolclasses()->save($t1);
                        }
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
            foreach(Room::all()->sortby("assentos") as $sala){
                if(!in_array($sala->nome, $salas_indiposniveis)){
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
        }

        return redirect("/rooms");
    }
}
