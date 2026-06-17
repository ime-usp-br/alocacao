<?php

namespace App\Http\Controllers;

use App\Models\SolverLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SolverLogController extends Controller
{
    /**
     * Display a listing of solver logs.
     */
    public function index(Request $request)
    {
        if (! Auth::check() || ! Auth::user()->hasRole('Administrador')) {
            abort(403);
        }

        $logs = SolverLog::with('schoolTerm')
            ->orderByDesc('dispatched_at')
            ->orderByDesc('id')
            ->paginate(20);

        return view('solverlogs.index', compact('logs'));
    }

    /**
     * Display a single solver log with payload and response.
     */
    public function show(SolverLog $solverLog)
    {
        if (! Auth::check() || ! Auth::user()->hasRole('Administrador')) {
            abort(403);
        }

        $solverLog->load('schoolTerm');

        return view('solverlogs.show', compact('solverLog'));
    }
}
