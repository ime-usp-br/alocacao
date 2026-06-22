<?php

namespace App\Jobs;

use App\Models\AllocationState;
use App\Models\ComparisonReport;
use App\Models\SchoolClass;
use App\Models\SchoolTerm;
use App\Services\AllocationEvaluatorService;
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

class ProcessAlgorithmComparison implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, IsMonitored;

    public int $schoolTermId;
    public int $baseAllocationStateId;
    public array $roomIds;

    public ?int $comparisonReportId = null;

    public int $timeout = 1800;
    public int $tries = 1;
    public int $uniqueFor = 3600;

    public function progressCooldown(): int
    {
        return 1;
    }

    public function __construct(int $schoolTermId, int $baseAllocationStateId, array $roomIds)
    {
        $this->schoolTermId = $schoolTermId;
        $this->baseAllocationStateId = $baseAllocationStateId;
        $this->roomIds = $roomIds;
    }

    public function uniqueId(): string
    {
        return 'algorithm-comparison-' . $this->schoolTermId;
    }

    public function handle(): void
    {
        $term = SchoolTerm::findOrFail($this->schoolTermId);
        $baseState = AllocationState::findOrFail($this->baseAllocationStateId);

        $report = ComparisonReport::create([
            'school_term_id' => $term->id,
            'base_allocation_state_id' => $baseState->id,
            'status' => 'processing',
        ]);
        $this->comparisonReportId = $report->id;

        // Snapshot do cache de progresso de alocacao para garantir que a
        // execucao da heuristica legada (que escreve no cache de producao)
        // nao perturbe o estado visto por usuarios reais. Ao final, o cache
        // e restaurado ao seu valor original.
        $cacheKey = "allocation:{$term->id}";
        $previousCache = Cache::get($cacheKey);

        $legacyAllocations = [];
        $solveStart = microtime(true);

        try {
            DB::beginTransaction();

            // 1. Restaura o estado base (travas manuais) como ponto de
            //    partida identico para a heuristica legada.
            AllocationStateService::restore($baseState);

            // 2. Executa a heuristica legada sincronamente dentro do
            //    contexto transacional. Suas escritas em school_classes
            //    ficam confinadas a esta transacao reversivel.
            $legacyJob = new ProcessLegacyRoomDistribution($term->id, $this->roomIds);
            $legacyJob->handle();

            // 3. Coleta imediatamente o mapa resultante [class_id => room_id]
            //    antes de qualquer rollback.
            $legacyAllocations = $this->collectRawAllocations($term);

            // 4. Aborta a transacao IMEDIATAMENTE para garantir que o banco
            //    de producao permaneca intacto (side-effect free).
            DB::rollBack();
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            $this->restoreCache($cacheKey, $previousCache);

            $report->update(['status' => 'failed']);

            throw $e;
        }

        $solveTime = microtime(true) - $solveStart;
        $this->restoreCache($cacheKey, $previousCache);

        // 5. Avaliacao isomorfica do resultado legado via Evaluator (puro,
        //    sem escrita em DB). O mapa coletado ja esta em memoria, logo o
        //    rollback do banco nao o afeta.
        $evaluator = new AllocationEvaluatorService();
        $legacyMetrics = $evaluator->evaluate($term, $legacyAllocations, $solveTime);

        // 6. Persiste as metricas e o mapa bruto do legado. O relatorio
        //    permanece em 'processing' ate que o solver seja avaliado
        //    (fluxo do webhook, tratado em issue dedicada).
        $report->update([
            'legacy_metrics' => $legacyMetrics,
            'legacy_raw_allocations' => $legacyAllocations,
        ]);

        Log::info('ProcessAlgorithmComparison: legacy phase concluded', [
            'comparison_report_id' => $report->id,
            'school_term_id' => $term->id,
            'base_allocation_state_id' => $baseState->id,
            'allocated_count' => count(array_filter($legacyAllocations, fn ($id) => $id !== null)),
        ]);
    }

    /**
     * Constroi o mapa bruto [class_id => room_id] a partir do estado atual
     * das turmas, resolvido da mesma forma que AllocationStateService::capture
     * (mestre de fusao tem precedencia).
     *
     * @return array<int, int|null>
     */
    protected function collectRawAllocations(SchoolTerm $term): array
    {
        $allocations = [];

        $classes = SchoolClass::whereBelongsTo($term)
            ->with(['fusion.master', 'fusion.schoolclasses'])
            ->get();

        foreach ($classes as $class) {
            $allocations[$class->id] = $this->resolveRoomId($class);
        }

        return $allocations;
    }

    protected function resolveRoomId(SchoolClass $class): ?int
    {
        if ($class->fusion_id && $class->fusion) {
            $master = $class->fusion->master;

            if ($master && $master->room_id) {
                return $master->room_id;
            }

            foreach ($class->fusion->schoolclasses as $child) {
                if ($child->room_id) {
                    return $child->room_id;
                }
            }

            return null;
        }

        return $class->room_id;
    }

    /**
     * Restaura o cache de progresso de alocacao ao seu valor anterior a
     * execucao da heuristica legada, garantindo que usuarios de producao
     * nao observem estado espurio de "concluido" pelo benchmarking.
     */
    /**
     * @param  string  $cacheKey
     * @param  array|null  $previousCache
     * @return void
     */
    protected function restoreCache(string $cacheKey, $previousCache): void
    {
        if ($previousCache === null) {
            Cache::forget($cacheKey);

            return;
        }

        Cache::put($cacheKey, $previousCache, now()->addHours(4));
    }

    public function failed(\Throwable $exception): void
    {
        if ($this->comparisonReportId !== null) {
            ComparisonReport::where('id', $this->comparisonReportId)->update([
                'status' => 'failed',
            ]);
        }

        Log::error('ProcessAlgorithmComparison: failed', [
            'school_term_id' => $this->schoolTermId,
            'base_allocation_state_id' => $this->baseAllocationStateId,
            'error' => $exception->getMessage(),
        ]);
    }
}
