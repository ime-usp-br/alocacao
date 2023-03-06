<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSpecialOfferRequest;
use App\Http\Requests\UpdateSpecialOfferRequest;
use App\Models\SpecialOffer;
use App\Models\SchoolTerm;
use App\Models\SchoolClass;
use Auth;
use Session;

class SpecialOfferController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        if(!Auth::check()){
            return redirect("/login");
        }elseif(!Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        $schoolterm = SchoolTerm::getLatest();

        $especiais = [];
        foreach(SpecialOffer::all()->pluck("nomcur")->unique()->sort()->values()->toArray() as $nomcur){
            $buff = [];
            $buff["nomcur"] = $nomcur;

            $coddis = SpecialOffer::where("nomcur", $nomcur)->pluck("coddis")->unique()->sort()->values()->toArray();

            $buff["nrows"] = max(count($coddis),SchoolClass::whereBelongsTo($schoolterm)->whereIn("coddis", $coddis)->get()->count());

            $buff["disciplinas"] = [];
            foreach(SpecialOffer::where("nomcur", $nomcur)->get() as $specialoffer){
                $buff2 = [];
                $buff2["coddis"] = $specialoffer["coddis"];
                $buff2["nomdis"] = SchoolClass::where("coddis", $specialoffer["coddis"])->first()->nomdis ?? "Não encontrada";
                $buff2["id"] = $specialoffer->id;
                $buff2["turmas"] = SchoolClass::whereBelongsTo($schoolterm)->where("coddis", $specialoffer["coddis"])->get();
                $buff2["numero de turmas"] = $buff2["turmas"]->count();
                array_push($buff["disciplinas"], $buff2);
            }

            array_push($especiais, $buff);
        }

        return view("specialoffers.index", compact(["schoolterm", "especiais"]));
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
     * @param  \App\Http\Requests\StoreSpecialOfferRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function store(StoreSpecialOfferRequest $request)
    {
        if(!Auth::check()){
            return redirect("/login");
        }elseif(!Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        $validated = $request->validated();

        SpecialOffer::firstOrCreate($validated);

        Session::flash("alert-success", "Oferecimento especial cadastrado com sucesso!");

        return back();
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\SpecialOffer  $specialOffer
     * @return \Illuminate\Http\Response
     */
    public function show(SpecialOffer $specialOffer)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\SpecialOffer  $specialOffer
     * @return \Illuminate\Http\Response
     */
    public function edit(SpecialOffer $specialOffer)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \App\Http\Requests\UpdateSpecialOfferRequest  $request
     * @param  \App\Models\SpecialOffer  $specialOffer
     * @return \Illuminate\Http\Response
     */
    public function update(UpdateSpecialOfferRequest $request, SpecialOffer $specialOffer)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\SpecialOffer  $specialOffer
     * @return \Illuminate\Http\Response
     */
    public function destroy(SpecialOffer $specialoffer)
    {
        if(!Auth::check()){
            return redirect("/login");
        }elseif(!Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        $specialoffer->delete();

        Session::flash("alert-success", "Oferecimento especial removido com sucesso!");

        return back();
    }
}
