<?php

namespace App\Jobs;

use App\Models\CourseInformation;
use App\Models\Priority;
use App\Models\Room;
use App\Models\SchoolClass;
use App\Models\SchoolTerm;
use App\Services\AllocationStateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use romanzipp\QueueMonitor\Traits\IsMonitored;

class ProcessLegacyRoomDistribution implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, IsMonitored;

    public int $schoolTermId;
    public array $roomIds;

    public int $timeout = 1800;
    public int $tries = 1;
    public int $uniqueFor = 3600;

    public function progressCooldown(): int
    {
        return 1;
    }

    public function __construct(int $schoolTermId, array $roomIds)
    {
        $this->schoolTermId = $schoolTermId;
        $this->roomIds = $roomIds;
    }

    public function uniqueId(): string
    {
        return 'legacy-room-distribution-' . $this->schoolTermId;
    }

    protected function updateProgress(string $cacheKey, int $progress, string $message): void
    {
        $cached = Cache::get($cacheKey);
        if ($cached && ($cached['mode'] ?? null) === 'legacy') {
            $cached['progress'] = $progress;
            $cached['message'] = $message;
            Cache::put($cacheKey, $cached, now()->addHours(4));
        }
    }

    /**
     * Total de unidades de alocacao elegiveis: turmas standalone (sem
     * fusao) + uma unidade por fusao. Exclui externas e MAE0116.
     */
    protected function countTotalAllocationUnits(SchoolTerm $term): int
    {
        $standalone = SchoolClass::whereBelongsTo($term)
            ->where('externa', false)
            ->where('coddis', '!=', 'MAE0116')
            ->whereNull('fusion_id')
            ->count();

        $fusions = SchoolClass::whereBelongsTo($term)
            ->where('externa', false)
            ->where('coddis', '!=', 'MAE0116')
            ->whereNotNull('fusion_id')
            ->distinct('fusion_id')
            ->count('fusion_id');

        return $standalone + $fusions;
    }

    /**
     * Unidades de alocacao com sala: standalone com room_id + uma por
     * fusao cujo mestre tem room_id. Exclui externas e MAE0116.
     */
    protected function countAllocationUnitsWithRoom(SchoolTerm $term): int
    {
        $standalone = SchoolClass::whereBelongsTo($term)
            ->where('externa', false)
            ->where('coddis', '!=', 'MAE0116')
            ->whereNull('fusion_id')
            ->whereNotNull('room_id')
            ->count();

        // Fusoes alocadas: distinct fusion_id em que o mestre tem room_id.
        $fusions = SchoolClass::whereBelongsTo($term)
            ->where('externa', false)
            ->where('coddis', '!=', 'MAE0116')
            ->whereNotNull('fusion_id')
            ->whereHas('fusion.master', function ($q) {
                $q->whereNotNull('room_id');
            })
            ->distinct('fusion_id')
            ->count('fusion_id');

        return $standalone + $fusions;
    }

    public function handle(): void
    {
        $term = SchoolTerm::findOrFail($this->schoolTermId);
        $salas_disponiveis = $this->roomIds;

        $cacheKey = "allocation:{$term->id}";
        Cache::put($cacheKey, [
            'job_id' => 'legacy',
            'status' => 'solving',
            'progress' => 0,
            'message' => 'Distribuição legada em execução',
            'started_at' => now()->toIso8601String(),
            'mode' => 'legacy',
        ], now()->addHours(4));

        AllocationStateService::capture(
            $term,
            'Pré-Legado - ' . now()->format('d/m/Y H:i:s'),
            null
        );

        // Conta unidades de alocacao ANTES das fases (alocacao manual
        // preservada). Unidade = turma standalone OU fusao inteira (conta
        // como 1, via o mestre). Exclui MAE0116 (hardcoded-skip) e externas.
        $manualCount = $this->countAllocationUnitsWithRoom($term);

        DB::transaction(function () use ($term, $salas_disponiveis, $cacheKey) {
            $this->phaseByCourse($term, $salas_disponiveis);
            $this->updateProgress($cacheKey, 25, 'Fase 1/4 concluida (cursos)');
            $this->phaseByPriority($term, $salas_disponiveis);
            $this->updateProgress($cacheKey, 50, 'Fase 2/4 concluida (prioridades)');
            $this->phasePosGraduation($term, $salas_disponiveis);
            $this->updateProgress($cacheKey, 75, 'Fase 3/4 concluida (pos-graduacao)');
            $this->phaseGraduacaoRestante($term, $salas_disponiveis);
            $this->updateProgress($cacheKey, 90, 'Fase 4/4 concluida (graduacao restante)');
        });

        $withRoomAfter = $this->countAllocationUnitsWithRoom($term);
        $autoCount = max(0, $withRoomAfter - $manualCount);

        $totalEligible = $this->countTotalAllocationUnits($term);
        $unassignedCount = max(0, $totalEligible - $withRoomAfter);

        Cache::put($cacheKey, [
            'job_id' => 'legacy',
            'status' => 'completed',
            'progress' => 100,
            'message' => 'Distribuição legada concluída',
            'finished_at' => now()->toIso8601String(),
            'assignments_count' => $autoCount,
            'unassigned_count' => $unassignedCount,
            'manual_count' => $manualCount,
            'mode' => 'legacy',
        ], now()->addHours(4));

        Log::info('ProcessLegacyRoomDistribution: concluded', [
            'school_term_id' => $term->id,
            'auto_count' => $autoCount,
            'manual_count' => $manualCount,
            'unassigned_count' => $unassignedCount,
        ]);
    }

    protected function phaseByCourse(SchoolTerm $schoolterm, array $salas_disponiveis): void
    {
        $semestres = $schoolterm->period == "1° Semestre" ? [1] : [2];

        foreach ($semestres as $semestre) {
            foreach (CourseInformation::$codtur_by_course as $sufixo_codtur => $course) {
                $turmas = SchoolClass::whereBelongsTo($schoolterm)
                    ->whereHas("courseinformations", function ($query) use ($course, $semestre) {
                        $query->where("numsemidl", $semestre)
                              ->where("tipobg", "O")
                              ->where("nomcur", $course["nomcur"])
                              ->where("perhab", $course["perhab"]);
                    })
                    ->where("codtur", "like", "%" . $sufixo_codtur)
                    ->where("externa", false)
                    ->get();

                if ($turmas->count() > 1) {
                    $ps = Priority::whereHas("schoolclass", function ($query) use ($turmas) {
                        $query->whereIn("id", $turmas->pluck("id")->toArray());
                    })->with("room")->get();

                    $salas = [];
                    foreach ($ps->groupBy("room.id") as $sala_id => $p) {
                        $salas[$p->sum("priority")] = Room::find($sala_id);
                    }
                    krsort($salas);
                    foreach (Room::whereNotIn("id", array_column($salas, "id"))->get()->sortby("assentos") as $room) {
                        array_push($salas, $room);
                    }

                    $alocado = false;
                    foreach ($salas as $room) {
                        if (!$alocado) {
                            if (in_array($room->id, $salas_disponiveis)) {
                                $conflito = false;
                                foreach ($turmas as $turma) {
                                    if (!$room->isCompatible($turma)) {
                                        $conflito = true;
                                    }
                                }
                                if (!$conflito) {
                                    foreach ($turmas as $turma) {
                                        $room->schoolclasses()->save($turma);
                                    }
                                    $alocado = true;
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    protected function phaseByPriority(SchoolTerm $schoolterm, array $salas_disponiveis): void
    {
        $prioridades = Priority::whereHas("schoolclass", function ($query) use ($schoolterm) {
            $query->whereBelongsTo($schoolterm);
        })
            ->get()
            ->sortByDesc("priority");

        foreach ($prioridades as $prioridade) {
            $t1 = $prioridade->schoolclass;
            $room = $prioridade->room;
            if (in_array($room->id, $salas_disponiveis)) {
                if (!$t1->room()->exists() and $t1->coddis != "MAE0116") {
                    if ($t1->fusion()->exists()) {
                        if ($t1->fusion->master->id == $t1->id) {
                            if ($room->isCompatible($t1)) {
                                $room->schoolclasses()->save($t1);
                            }
                        }
                    } elseif ($room->isCompatible($t1)) {
                        $room->schoolclasses()->save($t1);
                    }
                }
            }
        }
    }

    protected function phasePosGraduation(SchoolTerm $schoolterm, array $salas_disponiveis): void
    {
        $turmas = SchoolClass::whereBelongsTo($schoolterm)
            ->where("tiptur", "Pós Graduação")
            ->whereDoesntHave("room")
            ->get();

        foreach ($turmas as $t1) {
            foreach (Room::whereIn("id", $salas_disponiveis)->get()->shuffle() as $sala) {
                if (!$t1->room()->exists() and !$t1->fusion()->exists()) {
                    if ($sala->isCompatible($t1)) {
                        $sala->schoolclasses()->save($t1);
                    }
                }
            }
        }
    }

    protected function phaseGraduacaoRestante(SchoolTerm $schoolterm, array $salas_disponiveis): void
    {
        $turmas = SchoolClass::whereBelongsTo($schoolterm)
            ->where("tiptur", "Graduação")
            ->whereNotNull("estmtr")
            ->whereDoesntHave("room")
            ->get()
            ->sortBy("estmtr");

        foreach ($turmas as $t1) {
            foreach (Room::whereIn("id", $salas_disponiveis)->get()->sortby("assentos") as $sala) {
                if (!$t1->room()->exists() and $t1->coddis != "MAE0116") {
                    if ($t1->fusion()->exists()) {
                        if ($t1->fusion->master->id == $t1->id) {
                            if ($sala->isCompatible($t1)) {
                                $sala->schoolclasses()->save($t1);
                            }
                        }
                    } elseif ($sala->isCompatible($t1)) {
                        $sala->schoolclasses()->save($t1);
                    }
                }
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        $cacheKey = "allocation:{$this->schoolTermId}";
        $cached = Cache::get($cacheKey);
        if ($cached && ($cached['mode'] ?? null) === 'legacy') {
            $cached['status'] = 'error';
            $cached['message'] = 'Distribuição legada falhou: ' . $exception->getMessage();
            Cache::put($cacheKey, $cached, now()->addHours(4));
        }

        Log::error('ProcessLegacyRoomDistribution: failed', [
            'school_term_id' => $this->schoolTermId,
            'error' => $exception->getMessage(),
        ]);
    }
}
