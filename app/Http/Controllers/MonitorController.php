<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\SchoolTerm;
use romanzipp\QueueMonitor\Models\Monitor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class MonitorController extends Controller
{
    public function getImportProcess()
    {
        if(!Auth::check() or !Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        $max_id = Monitor::where(['name'=>'App\Jobs\ProcessImportSchoolClasses'])->max('id');
        $max_progress = Monitor::where(['id'=>$max_id])->max('progress');
        return response()->json(Monitor::where(['id'=>$max_id, 
                                                'progress'=>$max_progress])
                                        ->first());
    }

    public function getReportProcess()
    {
        if(!Auth::check() or !Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        $max_id = Monitor::where(['name'=>'App\Jobs\ProcessReport'])->max('id');
        $max_progress = Monitor::where(['id'=>$max_id])->max('progress');
        return response()->json(Monitor::where(['id'=>$max_id, 
                                                'progress'=>$max_progress])
                                        ->first());
    }

    public function getReservationProcess()
    {
        if(!Auth::check() or !Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        $max_id = Monitor::where(['name'=>'App\Jobs\ProcessReservation'])->max('id');
        $max_progress = Monitor::where(['id'=>$max_id])->max('progress');
        return response()->json(Monitor::where(['id'=>$max_id, 
                                                'progress'=>$max_progress])
                                        ->first());
    }

    public function getDistributionProcess()
    {
        if(!Auth::check() or !Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        $st = SchoolTerm::getLatest();

        if (! $st) {
            return response()->json(null);
        }

        $cached = Cache::get("allocation:{$st->id}");

        if (! $cached) {
            return response()->json(null);
        }

        $terminalStatuses = ['completed', 'error', 'timeout'];
        $isFailed = in_array($cached['status'], $terminalStatuses) && $cached['status'] !== 'completed';

        return response()->json([
            'progress' => $cached['progress'] ?? 0,
            'status' => $cached['status'] ?? 'unknown',
            'message' => $cached['message'] ?? '',
            'failed' => $isFailed,
            'data' => json_encode([
                'status' => $cached['status'],
                'message' => $cached['message'],
                'assignments_count' => $cached['assignments_count'] ?? 0,
                'unassigned_count' => $cached['unassigned_count'] ?? 0,
            ]),
        ]);
    }
}
