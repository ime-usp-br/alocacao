<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Requests\CompatibleRoomRequest;
use App\Http\Requests\AllocateRoomRequest;
use App\Http\Requests\DistributesRoomRequest;
use App\Http\Requests\EmptyRoomRequest;
use App\Http\Requests\ReservationRoomRequest;
use Ismaelw\LaraTeX\LaraTeX;
use App\Jobs\ProcessReport;
use App\Jobs\ProcessReservation;
use romanzipp\QueueMonitor\Models\Monitor;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Session;
use App\Jobs\ProcessRoomDistribution;
use App\Jobs\ProcessLegacyRoomDistribution;
use App\Jobs\ProcessAlgorithmComparison;
use App\Models\Requisition;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\SchoolTerm;
use App\Models\AllocationState;
use App\Models\Priority;
use App\Models\SchoolClass;
use App\Models\CourseInformation;
use App\Models\Fusion;
use App\Services\ReservationApiService;
use App\Services\AllocationStateService;
use Illuminate\Support\Facades\Auth;


class RoomController extends Controller
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

        $salas = Room::all();

        return view('rooms.index', compact(['salas']));
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
     * @param  \App\Models\Room  $room
     * @return \Illuminate\Http\Response
     */
    public function show(Room $room)
    {
        if(!Auth::check() or !Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        return view('rooms.show', compact(['room']));
    }

    public function showFreeTime()
    {
        if(!Auth::check() or !Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        $st = SchoolTerm::getLatest();

        $horarios = [
            "08:00"=>"09:40",
            "10:00"=>"11:40",
            "14:00"=>"15:40",
            "16:00"=>"17:40",
            "19:20"=>"21:00",
            "21:10"=>"22:50",
        ];

        $dias = ['seg', 'ter', 'qua', 'qui', 'sex'];  

        $rooms = [];

        foreach($dias as $dia){
            foreach($horarios as $horent=>$horsai){
                $rooms[$dia][$horent][$horsai] = Room::whereDoesntHave("schoolclasses",function($query)use($st,$dia,$horent,$horsai){
                    $query->whereBelongsTo($st)->whereHas("classschedules",function($query)use($dia,$horent,$horsai){
                        $query->where("diasmnocp",$dia)->where("horsai",">",$horent)->where("horent","<",$horsai);
                    });
                })->get();
            }
        }

        return view('rooms.showFreeTime', compact([
            "st",
            "dias",
            "horarios",
            "rooms",
        ]));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Room  $room
     * @return \Illuminate\Http\Response
     */
    public function edit(Room $room)
    {
        //
    }

    /**CourseInformation
     */
    public function update(Request $request, Room $room)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Room  $room
     * @return \Illuminate\Http\Response
     */
    public function destroy(Room $room)
    {
        //
    }

    public function dissociate(SchoolClass $schoolclass)
    {
        if(!Auth::check() or !Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        $schoolclass->room()->dissociate();
        $schoolclass->save();

        return back();
    }

    public function compatible(CompatibleRoomRequest $request)
    {
        if(!Auth::check() or !Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        $validated = $request->validated();

        $room = Room::find($validated["room_id"]);

        $st = SchoolTerm::getLatest();

        $res = [];

        $turmas = SchoolClass::whereBelongsTo($st)->whereDoesntHave("room")->whereDoesntHave("fusion")
                    ->union(SchoolClass::whereExists(function($query){
                        $query->from("fusions")->whereColumn("fusions.master_id","school_classes.id");
                    })->whereBelongsTo($st)->whereDoesntHave("room"))->get()->sortBy("coddis");

        foreach($turmas as $turma){
            if($room->isCompatible($turma, $ignore_block=true, $ignore_estmtr=true)){
                array_push($res, $turma);
            }
        }

        return response()->json(json_encode($res));
    }

    public function allocate(AllocateRoomRequest $request, Room $room)
    {
        if(!Auth::check() or !Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        $validated = $request->validated();

        $schoolClass = SchoolClass::find($validated["school_class_id"]);
        $room->schoolclasses()->save($schoolClass);

        // If the class is part of a fusion, ensure the master also gets the room_id
        if ($schoolClass->fusion_id) {
            $fusion = $schoolClass->fusion;
            if ($fusion && $fusion->master_id && $fusion->master_id != $schoolClass->id) {
                $master = $fusion->master;
                $master->room_id = $room->id;
                $master->save();
            }
        }

        return back();
    }

    public function makeReport()
    {
        if(!Auth::check() or !Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        ProcessReport::dispatch();

        return back();
    }

    public function downloadReport()
    {
        if(!Auth::check() or !Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        $job = Monitor::where("name","App\Jobs\ProcessReport")->latest("started_at")->first();

        $file = json_decode($job->data)->fileName;

        $job->delete();

        return Storage::download($file);
    }

    public function distributes(DistributesRoomRequest $request)
    {
        if(!Auth::check() or !Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        $validated = $request->validated();
        $schoolterm = SchoolTerm::getLatest();

        // Prevent double-dispatch for the same school term
        $existing = Cache::get("allocation:{$schoolterm->id}");
        if ($existing && !in_array($existing['status'] ?? '', ['completed', 'error', 'timeout'])) {
            Session::put("alert-warning", "Já existe uma distribuição em andamento para este semestre.");
            return back();
        }

        if ((bool) ($validated['compare_algorithms'] ?? false)) {
            $baseStateId = $validated['base_allocation_state_id'] ?? null;

            if (! $baseStateId) {
                Session::put("alert-danger", "Selecione um estado base para a comparação.");
                return back();
            }

            $baseState = AllocationState::whereBelongsTo($schoolterm)->find($baseStateId);
            if (! $baseState) {
                Session::put("alert-danger", "O estado base selecionado não pertence ao semestre atual.");
                return back();
            }

            ProcessAlgorithmComparison::dispatch(
                $schoolterm->id,
                $baseState->id,
                $validated['rooms_id'],
                $validated['solver_config'] ?? []
            );

            Session::put("alert-info", "Comparação de algoritmos iniciada. A distribuição de produção não será alterada. Acompanhe o resultado em /comparison-reports.");
            return back();
        }

        if ((bool) ($validated['use_legacy'] ?? false)) {
            ProcessLegacyRoomDistribution::dispatch(
                $schoolterm->id,
                $validated['rooms_id']
            );

            Session::put("alert-info", "A distribuição legada de salas foi iniciada. Aguarde a conclusão no botão abaixo.");
            return back();
        }

        $solverUrl = rtrim(config('alocacao.solver.url'), '/');
        $apiToken = config('alocacao.solver.api_token');
        $verifySsl = config('alocacao.solver.verify_ssl', true);

        try {
            $healthCheck = Http::withHeaders(['Accept' => 'application/json'])
                ->timeout(5)
                ->when(! $verifySsl, fn ($r) => $r->withoutVerifying())
                ->get("{$solverUrl}/health");

            if (! $healthCheck->ok() || ($healthCheck->json('status') ?? '') !== 'ok') {
                Session::put("alert-danger", "O solver de otimização não está respondendo. Comunique o administrador do sistema. Você ainda pode usar a distribuição legada (opção 'Usar distribuicao legada (sem solver)' no formulário).");
                return back();
            }
        } catch (\Throwable $e) {
            Log::warning('RoomController@distributes: solver health check failed', [
                'solver_url' => $solverUrl,
                'error' => $e->getMessage(),
            ]);

            Session::put("alert-danger", "O solver de otimização não está respondendo. Comunique o administrador do sistema. Você ainda pode usar a distribuição legada (opção 'Usar distribuicao legada (sem solver)' no formulário).");
            return back();
        }

        ProcessRoomDistribution::dispatch(
            $schoolterm->id,
            $validated['rooms_id'],
            $validated['solver_config'] ?? [],
            (bool) ($validated['sync_enrollment'] ?? false)
        );

        Session::put("alert-info", "A distribuição de salas foi iniciada. Aguarde a conclusão no botão abaixo.");
        return back();
    }

    public function stopDistribution(Request $request)
    {
        if(!Auth::check() or !Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        $schoolterm = SchoolTerm::getLatest();

        if (! $schoolterm) {
            Session::put("alert-warning", "Não há semestre letivo ativo.");
            return back();
        }

        $cached = Cache::get("allocation:{$schoolterm->id}");

        if (! $cached || empty($cached['job_id'])) {
            Session::put("alert-warning", "Não há distribuição em andamento para cancelar.");
            return back();
        }

        if (in_array($cached['status'], ['completed', 'error', 'timeout'])) {
            Session::put("alert-info", "A distribuição já foi finalizada.");
            return back();
        }

        if (($cached['mode'] ?? null) === 'legacy') {
            Session::put("alert-warning", "A distribuição legada não pode ser cancelada manualmente. Aguarde a conclusão do job na fila.");
            return back();
        }

        $solverUrl = rtrim(config('alocacao.solver.url'), '/');
        $apiToken = config('alocacao.solver.api_token');
        $jobId = $cached['job_id'];

        try {
            $http = Http::withHeaders([
                    'X-Webhook-Token' => $apiToken,
                    'Accept' => 'application/json',
                ])
                ->timeout(30);

            if (! config('alocacao.solver.verify_ssl', true)) {
                $http = $http->withoutVerifying();
            }

            $response = $http->post("{$solverUrl}/api/v1/jobs/{$jobId}/stop");

            if ($response->successful()) {
                $cached['status'] = 'stopping';
                $cached['message'] = 'Solicitação de cancelamento enviada ao solver';
                Cache::put("allocation:{$schoolterm->id}", $cached, now()->addHours(4));

                Session::put("alert-info", "Solicitação de cancelamento enviada. Aguardando resultados parciais.");
            } else {
                Session::put("alert-danger", "Não foi possível cancelar a distribuição. Tente novamente.");
            }
        } catch (\Exception $e) {
            Log::error('RoomController@stopDistribution: failed to contact solver', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
            Session::put("alert-danger", "Erro de comunicação com o solver. Tente novamente.");
        }

        return back();
    }

    public function fallbackDistribution(Request $request)
    {
        if(!Auth::check() or !Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        $schoolterm = SchoolTerm::getLatest();

        if (! $schoolterm) {
            Session::put("alert-warning", "Não há semestre letivo ativo.");
            return back();
        }

        $cached = Cache::get("allocation:{$schoolterm->id}");

        if (! $cached || empty($cached['job_id'])) {
            Session::put("alert-warning", "Não há distribuição para resgatar.");
            return back();
        }

        if (in_array($cached['status'], ['completed'])) {
            Session::put("alert-info", "A distribuição já foi concluída.");
            return back();
        }

        if (($cached['mode'] ?? null) === 'legacy') {
            Session::put("alert-warning", "O resgate manual é exclusivo do solver. Para a distribuição legada, aguarde o job na fila.");
            return back();
        }

        $solverUrl = rtrim(config('alocacao.solver.url'), '/');
        $apiToken = config('alocacao.solver.api_token');
        $jobId = $cached['job_id'];

        try {
            $http = Http::withHeaders([
                    'X-Webhook-Token' => $apiToken,
                    'Accept' => 'application/json',
                ])
                ->timeout(config('alocacao.solver.timeout', 60));

            if (! config('alocacao.solver.verify_ssl', true)) {
                $http = $http->withoutVerifying();
            }

            $response = $http->get("{$solverUrl}/api/v1/jobs/{$jobId}/result");

            if (! $response->successful()) {
                Session::put("alert-danger", "O solver não possui resultado disponível para este job.");
                return back();
            }

            $data = $response->json();
            $assignments = $data['allocations'] ?? [];
            $unassignedGroups = $data['unassigned_groups'] ?? [];

            $manualCount = 0;
            $autoCount = 0;

            DB::transaction(function () use ($assignments, $unassignedGroups, &$manualCount, &$autoCount) {
                foreach ($assignments as $assignment) {
                    SchoolClass::where('id', $assignment['group_id'])->update(['room_id' => $assignment['room_id']]);
                    $autoCount++;
                }

                if (! empty($unassignedGroups)) {
                    $alreadyAllocated = SchoolClass::whereIn('id', $unassignedGroups)
                        ->whereNotNull('room_id')
                        ->pluck('id')
                        ->toArray();

                    $toClear = array_diff($unassignedGroups, $alreadyAllocated);
                    if (! empty($toClear)) {
                        SchoolClass::whereIn('id', $toClear)->update(['room_id' => null]);
                    }

                    $manualCount = count($alreadyAllocated);
                }
            });

            $suggestions = $data['suggestions'] ?? [];
            if (! empty($suggestions)) {
                Cache::put("allocation_suggestions:{$schoolterm->id}", $suggestions, now()->addDays(7));
            }

            Cache::put("allocation:{$schoolterm->id}", [
                'job_id' => $jobId,
                'status' => 'completed',
                'progress' => 100,
                'message' => 'Distribuição concluída (via resgate)',
                'finished_at' => now()->toIso8601String(),
                'assignments_count' => $autoCount,
                'unassigned_count' => count($unassignedGroups) - $manualCount,
                'manual_count' => $manualCount,
            ], now()->addHours(4));

            Session::put("alert-info", "Resultado resgatado e aplicado com sucesso.");
        } catch (\Exception $e) {
            Log::error('RoomController@fallbackDistribution: failed to contact solver', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
            Session::put("alert-danger", "Erro de comunicação com o solver ao tentar resgatar o resultado.");
        }

        return back();
    }

    public function reservation(ReservationRoomRequest $request)
    {
        if(!Auth::check() or !Auth::user()->hasRole(["Administrador"])){
            abort(403);
        }

        $validated = $request->validated();

        // AC5: API connectivity validation with explicit error handling
        $useApiService = config('salas.use_api', false);

        if ($useApiService) {
            try {
                $reservationApiService = app(ReservationApiService::class);
                $apiHealthy = $reservationApiService->checkApiHealth();
                
                if (!$apiHealthy) {
                    // AC5 Architectural Decision: No fallback - explicit error to user
                    \Log::error('Salas API indisponível - operação de reserva bloqueada', [
                        'operation' => 'reservation',
                        'rooms_count' => count($validated["rooms_id"]),
                        'user_id' => Auth::id(),
                        'timestamp' => now()->toISOString(),
                        'action_required' => 'Verificar status da API Salas'
                    ]);

                    Session::put("alert-danger", "Sistema de reservas temporariamente indisponível. Tente novamente em alguns minutos ou entre em contato com o suporte.");
                    return back();
                }
            } catch (\Exception $e) {
                // AC5 Architectural Decision: Explicit error handling without fallback
                \Log::error('Falha crítica na conectividade com API Salas - operação de reserva bloqueada', [
                    'operation' => 'reservation',
                    'error' => $e->getMessage(),
                    'error_class' => get_class($e),
                    'rooms_count' => count($validated["rooms_id"]),
                    'user_id' => Auth::id(),
                    'timestamp' => now()->toISOString(),
                    'action_required' => 'Verificar conectividade e status da API Salas'
                ]);

                Session::put("alert-danger", "Erro na conexão com o sistema de reservas. Por favor, tente novamente em alguns minutos.");
                return back();
            }
        }

        // Only dispatch job if API is healthy (when API is enabled) or API is disabled (legacy mode)
        ProcessReservation::dispatch($validated["rooms_id"]);

        // AC5: Preserve exact feedback message - maintains existing user experience
        Session::put("alert-info", "As reservas no Urano estão sendo processadas.");
        return back();
    }

    public function empty(EmptyRoomRequest $request)
    {
        if(!Auth::check() or !Auth::user()->hasRole(["Administrador", "Operador"])){
            abort(403);
        }

        $validated = $request->validated();

        $rooms_ids = $validated["rooms_id"];

        $schoolterm = SchoolTerm::getLatest();

        AllocationStateService::capture(
            $schoolterm,
            'Pré-Esvaziamento - ' . now()->format('d/m/Y H:i:s')
        );

        $schoolclasses = SchoolClass::whereBelongsTo($schoolterm)->whereHas("room", function($query)use($rooms_ids){
            $query->whereIn("id", $rooms_ids);
        })->get();

        foreach($schoolclasses as $schoolclass){
            $schoolclass->room()->dissociate();
            $schoolclass->save();
        }

        Session::put("alert-info", "As salas foram esvaziadas com sucesso.");
        return back();
    }
}
