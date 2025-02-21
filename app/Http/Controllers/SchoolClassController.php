<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSchoolClassRequest;
use App\Http\Requests\UpdateSchoolClassRequest;
use App\Http\Requests\IndexSchoolClassRequest;
use App\Http\Requests\CreateSchoolClassRequest;
use App\Http\Requests\DestroyInBatchSchoolClassRequest;
use App\Http\Requests\MakeInternalInBatchSchoolClassRequest;
use App\Http\Requests\MakeExternalInBatchSchoolClassRequest;
use App\Models\SchoolClass;
use App\Models\SchoolTerm;
use App\Models\Instructor;
use App\Models\ClassSchedule;
use App\Models\Priority;
use App\Models\Fusion;
use App\Models\Room;
use App\Models\CourseInformation;
use App\Models\Course;
use App\Jobs\ProcessImportSchoolClasses;
use Illuminate\Support\Facades\Auth;
use Session;

class SchoolClassController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(IndexSchoolClassRequest $request)
    {
        if(!Auth::check() or !Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        $schoolterm = SchoolTerm::getLatest();

        $validated = $request->validated();
        
        if($request->expectsJson()){
            if(array_key_exists('coddis', $validated)){
                $coddis = $validated['coddis'];

                $turmas = $schoolterm ? SchoolClass::whereBelongsTo($schoolterm)->where("coddis", $coddis)->get() : [];
                if($turmas){
                    return response()->json($turmas);
                }
                return response()->json("");
            }
        }

        $turmas = $schoolterm ? SchoolClass::whereBelongsTo($schoolterm)->where("externa", "Não")->get() : [];

        return view('schoolclasses.index', compact(['turmas', 'schoolterm']));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        if(!Auth::check() or !Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        $turma = new SchoolClass;
        $schoolTerm = SchoolTerm::getLatest();
        $turma->schoolterm()->associate($schoolTerm);

        return view('schoolclasses.create', compact('turma'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \App\Http\Requests\StoreSchoolClassRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function store(StoreSchoolClassRequest $request)
    {
        if(!Auth::check() or !Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        $validated = $request->validated();
        $schoolTerm = SchoolTerm::find($validated["periodoId"]);
        $schoolclass = SchoolClass::where(array_intersect_key($validated, array_flip(array('codtur', 'coddis'))))->first();

        if(!$schoolclass){
            $schoolclass = new SchoolClass;

            $schoolclass->fill($validated);

            $schoolclass->schoolterm()->associate($schoolTerm);
            $schoolclass->save();

            if(array_key_exists('instrutores', $validated)){
                foreach($validated['instrutores'] as $instructor){
                    $docente = Instructor::getFromReplicadoByCodpes($instructor['codpes']);
                    $schoolclass->instructors()->attach(Instructor::updateOrCreate(["codpes" => $docente["codpes"]],["nompes" => $docente["nompes"],"codema" => $docente["codema"]]));
                }
            }   

            if(array_key_exists('horarios', $validated)){
                foreach($validated['horarios'] as $classSchedule){
                    $schoolclass->classschedules()->attach(ClassSchedule::firstOrCreate($classSchedule));
                }
            }
            $schoolclass->save();

            $schoolclass->searchForFusion();

            if(count(SchoolClass::where("coddis",$schoolclass->coddis)->get())==1){
                foreach(CourseInformation::getFromReplicadoBySchoolClassAlternative($schoolclass) as $info){
                    if(in_array($info["nomcur"],Course::all()->pluck("nomcur")->toArray())){
                        CourseInformation::firstOrCreate($info)->schoolclasses()->save($schoolclass);
                    }
                }
            }else{
                foreach(CourseInformation::getFromReplicadoBySchoolClass($schoolclass) as $info){
                    if(in_array($info["nomcur"],Course::all()->pluck("nomcur")->toArray())){
                        CourseInformation::firstOrCreate($info)->schoolclasses()->save($schoolclass);
                    }
                }
            }
        }else{
            Session::flash("alert-warning", "Já existe uma turma cadastrada com esse código de turma e código de disciplina");
            return back();
        }

        return redirect('/schoolclasses');
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\SchoolClass  $schoolClass
     * @return \Illuminate\Http\Response
     */
    public function show(SchoolClass $schoolClass)
    {
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\SchoolClass  $schoolClass
     * @return \Illuminate\Http\Response
     */
    public function edit(SchoolClass $schoolclass)
    {
        if(!Auth::check() or !Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        $turma = $schoolclass;

        return view('schoolclasses.edit', compact('turma'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \App\Http\Requests\UpdateSchoolClassRequest  $request
     * @param  \App\Models\SchoolClass  $schoolClass
     * @return \Illuminate\Http\Response
     */
    public function update(UpdateSchoolClassRequest $request, SchoolClass $schoolclass)
    {
        if(!Auth::check() or !Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        $validated = $request->validated();

        $schoolclass->instructors()->detach();
        if(array_key_exists('instrutores', $validated)){
            foreach($validated['instrutores'] as $instructor){
                $docente = Instructor::getFromReplicadoByCodpes($instructor['codpes']);
                $schoolclass->instructors()->attach(Instructor::updateOrCreate(["codpes" => $docente["codpes"]],["nompes" => $docente["nompes"],"codema" => $docente["codema"]]));
            }
        }

        $schoolclass->classschedules()->detach();
        if(array_key_exists('horarios', $validated)){
            foreach($validated['horarios'] as $classSchedule){
                $schoolclass->classschedules()->attach(ClassSchedule::firstOrCreate($classSchedule));
            }
        }

        $schoolclass->update($validated);

        $schoolclass->searchForFusion();

        return redirect('/schoolclasses');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\SchoolClass  $schoolClass
     * @return \Illuminate\Http\Response
     */
    public function destroy(SchoolClass $schoolclass)
    {
        if(!Auth::check() or !Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        $schoolclass->instructors()->detach();
        $schoolclass->classschedules()->detach();
        $schoolclass->courseinformations()->detach();

        if($schoolclass->fusion()->exists()){
            if(count($schoolclass->fusion->schoolclasses)==2){
                $cs2 = $schoolclass->fusion->schoolclasses()->where("id","!=",$schoolclass->id)->first();
                $cs2->fusion_id = null;
                $cs2->save();
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
        }

        $schoolclass->delete();

        return back();
    }

    public function makeInternalInBatch(MakeInternalInBatchSchoolClassRequest $request)
    {
        if(!Auth::check() or !Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        $validated = $request->validated();

        foreach($validated["school_classes_id"] as $id){
            $schoolclass = SchoolClass::find($id);
            $schoolclass->externa = false;
            $schoolclass->save();
        }

        return back();
    }

    public function makeExternalInBatch(MakeExternalInBatchSchoolClassRequest $request)
    {
        if(!Auth::check() or !Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        $validated = $request->validated();

        foreach($validated["school_classes_id"] as $id){
            $schoolclass = SchoolClass::find($id);
            $schoolclass->externa = true;
            $schoolclass->save();
        }

        return back();
    }

    public function destroyInBatch(DestroyInBatchSchoolClassRequest $request)
    {
        if(!Auth::check() or !Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        $validated = $request->validated();

        foreach($validated["school_classes_id"] as $id){
            $schoolclass = SchoolClass::find($id);
            $schoolclass->instructors()->detach();
            $schoolclass->classschedules()->detach();
            $schoolclass->courseinformations()->detach();
            $schoolclass->delete();
        }

        return back();
    }

    public function externals()
    {
        if(!Auth::check() or !Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        $schoolterm = SchoolTerm::getLatest();

        $turmas = $schoolterm ? SchoolClass::whereBelongsTo($schoolterm)->where("externa", true)->get() : [];

        return view('schoolclasses.externals', compact(['turmas', 'schoolterm']));
    }

    public function import()
    {        
        if(!Auth::check() or !Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        ProcessImportSchoolClasses::dispatch();

        return redirect('/schoolclasses');
    }
}
