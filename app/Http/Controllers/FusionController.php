<?php

namespace App\Http\Controllers;

use App\Models\Fusion;
use App\Models\SchoolTerm;
use App\Models\SchoolClass;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FusionController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        if(!Auth::check() or !Auth::user()->canView()){
            abort(403);
        }

        $schoolterm = SchoolTerm::getLatest();

        $fusions = Fusion::whereHas("schoolclasses", function ($query) use ($schoolterm) {
            $query->whereBelongsTo($schoolterm);
        })->get();
        
        return view("fusions.index", compact(["fusions", "schoolterm"]));
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
     * @param  \App\Models\Fusion  $fusion
     * @return \Illuminate\Http\Response
     */
    public function show(Fusion $fusion)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Fusion  $fusion
     * @return \Illuminate\Http\Response
     */
    public function edit(Fusion $fusion)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Fusion  $fusion
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Fusion $fusion)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Fusion  $fusion
     * @return \Illuminate\Http\Response
     */
    public function destroy(Fusion $fusion)
    {
        //
    }

    public function disjoint(SchoolClass $schoolclass)
    {
        if(!Auth::check() or !Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }
        
        if(count($schoolclass->fusion->schoolclasses)==2){
            $sc2 = $schoolclass->fusion->schoolclasses()->where("id","!=",$schoolclass->id)->first();
            $sc2->fusion_id = null;
            $sc2->save();
            $schoolclass->fusion->delete();
        }elseif($schoolclass->fusion->master->id == $schoolclass->id){
            $fusion = $schoolclass->fusion;
            if($schoolclass->fusion->schoolclasses()->where("id","!=",$schoolclass->id)->where("tiptur","Graduação")->exists()){
                $schoolclass->fusion->master()->associate($schoolclass->fusion->schoolclasses()->where("id","!=",$schoolclass->id)->where("tiptur", "Graduação")->first());
            }else{
                $fusion->master()->associate($schoolclass->fusion->schoolclasses()->where("id","!=",$schoolclass->id)->first());
            }
            $fusion->save();
        }
        $schoolclass->fusion_id = null;
        $schoolclass->save();
        
        return back();
    }
}
