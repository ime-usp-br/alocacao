<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSchoolClassRequest;
use App\Http\Requests\UpdateSchoolClassRequest;
use App\Http\Requests\IndexSchoolClassRequest;
use App\Http\Requests\CreateSchoolClassRequest;
use App\Http\Requests\DestroyInBatchSchoolClassRequest;
use App\Http\Requests\MakeInternalInBatchSchoolClassRequest;
use App\Models\SchoolClass;
use App\Models\SchoolTerm;
use App\Models\Instructor;
use App\Models\ClassSchedule;
use App\Models\Priority;
use App\Models\Fusion;
use App\Models\Room;
use App\Models\CourseInformation;
use App\Jobs\ProcessImportSchoolClasses;

class SchoolClassController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(IndexSchoolClassRequest $request)
    {
        $schoolterm = SchoolTerm::getLatest();

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
                    $schoolclass->instructors()->attach(Instructor::firstOrCreate(Instructor::getFromReplicadoByCodpes($instructor['codpes'])));
                }
            }   

            if(array_key_exists('horarios', $validated)){
                foreach($validated['horarios'] as $classSchedule){
                    $schoolclass->classschedules()->attach(ClassSchedule::firstOrCreate($classSchedule));
                }
            }
            $schoolclass->save();
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
        $validated = $request->validated();

        $schoolclass->instructors()->detach();
        if(array_key_exists('instrutores', $validated)){
            foreach($validated['instrutores'] as $instructor){
                $schoolclass->instructors()->attach(Instructor::firstOrCreate(Instructor::getFromReplicadoByCodpes($instructor['codpes'])));
            }
        }

        $schoolclass->classschedules()->detach();
        if(array_key_exists('horarios', $validated)){
            foreach($validated['horarios'] as $classSchedule){
                $schoolclass->classschedules()->attach(ClassSchedule::firstOrCreate($classSchedule));
            }
        }

        $schoolclass->update($validated);

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
        $schoolclass->instructors()->detach();
        $schoolclass->classschedules()->detach();
        $schoolclass->courseinformations()->detach();
        $schoolclass->delete();

        return back();
    }

    public function makeInternalInBatch(MakeInternalInBatchSchoolClassRequest $request)
    {
        $validated = $request->validated();

        foreach($validated["school_classes_id"] as $id){
            $schoolclass = SchoolClass::find($id);
            $schoolclass->externa = false;
            $schoolclass->save();
        }

        return back();
    }

    public function destroyInBatch(DestroyInBatchSchoolClassRequest $request)
    {
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
        $schoolterm = SchoolTerm::getLatest();

        $turmas = $schoolterm ? SchoolClass::whereBelongsTo($schoolterm)->where("externa", true)->get() : [];

        return view('schoolclasses.externals', compact(['turmas', 'schoolterm']));
    }

    public function import()
    {        
        ProcessImportSchoolClasses::dispatch();

        return redirect('/schoolclasses');
    }
}
