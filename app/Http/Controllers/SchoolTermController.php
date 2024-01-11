<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSchoolTermRequest;
use App\Http\Requests\UpdateSchoolTermRequest;
use App\Models\SchoolTerm;
use Illuminate\Support\Facades\Auth;

class SchoolTermController extends Controller
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

        $schoolterms = SchoolTerm::orderBy('year')
        ->orderBy('period')->get();

        return view('schoolterms.index', compact('schoolterms'));
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

        $periodo = new SchoolTerm;

        return view('schoolterms.create', compact('periodo'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \App\Http\Requests\StoreSchoolTermRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function store(StoreSchoolTermRequest $request)
    {
        if(!Auth::check() or !Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        $validated = $request->validated();

        SchoolTerm::firstOrCreate($validated);

        return redirect('/schoolterms');
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\SchoolTerm  $schoolTerm
     * @return \Illuminate\Http\Response
     */
    public function show(SchoolTerm $schoolTerm)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\SchoolTerm  $schoolTerm
     * @return \Illuminate\Http\Response
     */
    public function edit(SchoolTerm $schoolterm)
    {
        if(!Auth::check() or !Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        $periodo = $schoolterm;

        return view('schoolterms.edit', compact('periodo'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \App\Http\Requests\UpdateSchoolTermRequest  $request
     * @param  \App\Models\SchoolTerm  $schoolTerm
     * @return \Illuminate\Http\Response
     */
    public function update(UpdateSchoolTermRequest $request, SchoolTerm $schoolterm)
    {
        if(!Auth::check() or !Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        $validated = $request->validated();

        $schoolterm->update($validated);

        return redirect('/schoolterms');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\SchoolTerm  $schoolTerm
     * @return \Illuminate\Http\Response
     */
    public function destroy(SchoolTerm $schoolTerm)
    {
        //
    }
}
