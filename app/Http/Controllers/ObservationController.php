<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreObservationRequest;
use App\Http\Requests\UpdateObservationRequest;
use App\Models\Observation;
use App\Models\SchoolTerm;

class ObservationController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $schoolterm = SchoolTerm::getLatest();

        $observations = Observation::whereBelongsTo($schoolterm)->get();

        return view("observations.index", compact(["schoolterm", "observations"]));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $observation = new Observation;

        return view("observations.create", compact("observation"));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \App\Http\Requests\StoreObservationRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function store(StoreObservationRequest $request)
    {        
        $validated = $request->validated();

        $schoolterm = SchoolTerm::getLatest();

        $observation = new Observation;

        $observation->fill($validated);

        $observation->schoolterm()->associate($schoolterm);

        $observation->save();

        return redirect("/observations");
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Observation  $observation
     * @return \Illuminate\Http\Response
     */
    public function show(Observation $observation)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Observation  $observation
     * @return \Illuminate\Http\Response
     */
    public function edit(Observation $observation)
    {
        return view("observations.edit", compact("observation"));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \App\Http\Requests\UpdateObservationRequest  $request
     * @param  \App\Models\Observation  $observation
     * @return \Illuminate\Http\Response
     */
    public function update(UpdateObservationRequest $request, Observation $observation)
    {
        $validated = $request->validated();

        $observation->update($validated);

        return redirect("/observations");
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Observation  $observation
     * @return \Illuminate\Http\Response
     */
    public function destroy(Observation $observation)
    {
        $observation->delete();

        return back();
    }
}
