<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Requests\AttachCourseInformationRequest;
use App\Models\CourseInformation;
use App\Models\SchoolClass;
use Auth;

class CourseInformationController extends Controller
{
    public function detach(CourseInformation $ci, SchoolClass $sc)
    {
        if(!Auth::check()){
            return redirect("/login");
        }elseif(!Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        $ci->schoolclasses()->detach($sc->id);

        return back();
    }

    public function attach(AttachCourseInformationRequest $request)
    {
        if(!Auth::check()){
            return redirect("/login");
        }elseif(!Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        $validated = $request->validated();

        $ci = CourseInformation::where("nomcur", $validated["nomcur"])
                                ->where("numsemidl", $validated["numsemidl"])
                                ->where("perhab", $validated["perhab"])
                                ->where("tipobg", $validated["tipobg"])
                                ->where("codhab", $validated["codhab"])->first();

                                
        $ci->schoolclasses()->attach($validated["schoolclasses"]);

        return back();
    }
}
