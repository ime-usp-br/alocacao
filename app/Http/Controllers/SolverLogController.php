<?php

namespace App\Http\Controllers;

use App\Models\SolverLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

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
    public function show($id)
    {
        if (! Auth::check() || ! Auth::user()->hasRole('Administrador')) {
            abort(403);
        }

        $solverLog = SolverLog::with('schoolTerm')->findOrFail($id);

        Log::info('SolverLogController@show: loading log', [
            'id' => $solverLog->id,
            'job_id' => $solverLog->job_id,
            'has_payload' => ! is_null($solverLog->payload),
            'has_response' => ! is_null($solverLog->response),
            'payload_type' => gettype($solverLog->payload),
        ]);

        return view('solverlogs.show', compact('solverLog'));
    }
}
