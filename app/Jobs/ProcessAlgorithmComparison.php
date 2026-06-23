<?php

namespace App\Jobs;

use App\Models\AllocationState;
use App\Models\ComparisonReport;
use App\Models\SchoolTerm;
use App\Services\AllocationEvaluatorService;
use App\Services\AllocationStateService;
use App\Services\ComparisonAllocationCollector;
use App\Services\RoomAllocationPayloadBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use romanzipp\QueueMonitor\Traits\IsMonitored;

class ProcessAlgorithmComparison implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, IsMonitored;

    public int $schoolTermId;
    public int $baseAllocationStateId;
    public array $roomIds;
    public array $solverConfig;

    public ?int $comparisonReportId = null;

    public int $timeout = 1800;
    public int $tries = 1;
    public int $uniqueFor = 3600;

    public function progressCooldown(): int
    {
        return 1;
    }

    public function __construct(int $schoolTermId, int $baseAllocationStateId, array $roomIds, array $solverConfig = [])
    {
        $this->schoolTermId = $schoolTermId;
        $this->baseAllocationStateId = $baseAllocationStateId;
        $this->roomIds = $roomIds;
        $this->solverConfig = $solverConfig;
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
            'solver_config' => $this->solverConfig,
            'status' => 'processing',
        ]);
        $this->comparisonReportId = $report->id;

        // Snapshot do cache de progresso de alocacao para garantir que a
        // execucao da heuristica legada (que escreve no cache de producao)
        // nao perturbe o estado visto por usuarios reais. Ao final, o cache
        // e restaurado ao seu valor original.
        $cacheKey = "allocation:{$term->id}";
        $previousCache = Cache::get($cacheKey);

        // Flag para que o MonitorController::getDistributionProcess()
        // saiba que o cache esta sendo escrito pelo benchmarking (legado
        // rodando dentro da comparacao) e nao por uma distribuicao real,
        // evitando que o frontend exiba "Distribuicao concluida".
        Cache::put("comparison:running:{$term->id}", $report->id, now()->addHours(4));

        $legacyAllocations = [];
        $solverPayload = null;
        $solveStart = microtime(true);

        try {
            DB::beginTransaction();

            // 1. Restaura o estado base (travas manuais) como ponto de
            //    partida identico para a heuristica legada.
            AllocationStateService::restore($baseState);

            // 2. Construi o payload do Solver CP-SAT A PARTIR do estado base
            //    restaurado. As pre-alocacoes manuais (room_id) presentes no
            //    estado base sao capturadas como `preassigned_room_id` no
            //    payload, garantindo que o Solver inicie do mesmo ponto que a
            //    heuristica legada. A construcao e pura (leituras), logo o
            //    rollback posterior nao afeta o array em memoria.
            $solverPayload = (new RoomAllocationPayloadBuilder())
                ->build($term, $this->roomIds, $this->solverConfig);

            // 3. Executa a heuristica legada sincronamente dentro do
            //    contexto transacional. Suas escritas em school_classes
            //    ficam confinadas a esta transacao reversivel.
            $legacyJob = new ProcessLegacyRoomDistribution($term->id, $this->roomIds);
            $legacyJob->handle();

            // 4. Coleta imediatamente o mapa resultante [class_id => room_id]
            //    antes de qualquer rollback, usando a mesma politica de coleta
            //    do solver (DB com precedencia de mestre de fusao).
            $legacyAllocations = (new ComparisonAllocationCollector())->collectFromDatabase($term);

            // 5. Aborta a transacao IMEDIATAMENTE para garantir que o banco
            //    de producao permaneca intacto (side-effect free).
            DB::rollBack();
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            $this->restoreCache($cacheKey, $previousCache);
            Cache::forget("comparison:running:{$term->id}");

            $report->update(['status' => 'failed']);

            throw $e;
        }

        $solveTime = microtime(true) - $solveStart;
        $this->restoreCache($cacheKey, $previousCache);
        Cache::forget("comparison:running:{$term->id}");

        // 6. Avaliacao isomorfica do resultado legado via Evaluator (puro,
        //    sem escrita em DB). O mapa coletado ja esta em memoria, logo o
        //    rollback do banco nao o afeta. As alocacoes manuais preservadas
        //    do estado base sao excluidas da avaliacao, pois nao sao decisoes
        //    da heuristica legada.
        $evaluator = new AllocationEvaluatorService($this->solverConfig);
        $manualUnitIds = ComparisonAllocationCollector::manualUnitIds($term, $baseState->allocations ?? []);
        $legacyMetrics = $evaluator->evaluate($term, $legacyAllocations, $solveTime, $manualUnitIds);

        // 7. Persiste as metricas e o mapa bruto do legado. O relatorio
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

        // 8. Disparo do Solver CP-SAT para Benchmarking. O payload foi
        //    construido a partir do estado base (passo 2) e agora e enviado
        //    ao microsservico Python, apontando o webhook de retorno para a
        //    rota exclusiva de comparacao. Falhas de conexao/disparo
        //    finalizam o relatorio como 'failed' (evitando Jobs zumbis);
        //    sucesso mantem 'processing' para aguardar o retorno assincrono.
        $this->dispatchSolver($term, $report, $solverPayload);
    }

    /**
     * Dispara o payload do Solver CP-SAT (microsservico Python) para fins de
     * Benchmarking, injetando no bloco `meta` a URL do webhook de comparacao.
     *
     * O envio e best-effort em relacao ao status do relatorio: qualquer falha
     * de conexao, HTTP de erro ou resposta invalida marca o relatorio como
     * 'failed' e encerra o Job de forma limpa (sem relancar), preservando as
     * legacy_metrics ja persistidas. Em sucesso, o relatorio permanece
     * 'processing' aguardando o callback assincrono do Solver.
     */
    protected function dispatchSolver(SchoolTerm $term, ComparisonReport $report, ?array $payload): void
    {
        if ($payload === null) {
            $report->update(['status' => 'failed']);
            Log::error('ProcessAlgorithmComparison: solver payload missing', [
                'comparison_report_id' => $report->id,
                'school_term_id' => $term->id,
            ]);

            return;
        }

        $solverUrl = rtrim((string) config('alocacao.solver.url'), '/');
        $apiToken = config('alocacao.solver.api_token');
        $timeout = config('alocacao.solver.timeout', 60);

        $webhookUrl = route('webhooks.comparison.result');
        $progressWebhookUrl = route('webhooks.allocation.progress');

        // Injeta no bloco meta do payload as URLs de webhook especificas
        // do escopo de Benchmarking (em vez da rota padrao de salvamento).
        // O progress_webhook_url e obrigatorio pela API do solver mesmo
        // no fluxo de comparacao.
        $payload['meta']['webhook_url'] = $webhookUrl;
        $payload['meta']['progress_webhook_url'] = $progressWebhookUrl;

        Log::info('ProcessAlgorithmComparison: dispatching to solver', [
            'comparison_report_id' => $report->id,
            'school_term_id' => $term->id,
            'solver_url' => $solverUrl,
            'webhook_url' => $webhookUrl,
        ]);

        try {
            $http = Http::withHeaders([
                    'X-Webhook-Token' => $apiToken,
                    'Accept' => 'application/json',
                ])
                ->timeout($timeout);

            if (! config('alocacao.solver.verify_ssl', true)) {
                $http = $http->withoutVerifying();
            }

            $response = $http->post("{$solverUrl}/api/v1/solve", $payload);
        } catch (\Throwable $e) {
            $report->update(['status' => 'failed']);
            Log::error('ProcessAlgorithmComparison: solver connection failed', [
                'comparison_report_id' => $report->id,
                'school_term_id' => $term->id,
                'solver_url' => $solverUrl,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if (! $response->successful()) {
            $report->update(['status' => 'failed']);
            Log::error('ProcessAlgorithmComparison: solver returned error', [
                'comparison_report_id' => $report->id,
                'school_term_id' => $term->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return;
        }

        $jobId = $response->json('job_id');

        if (empty($jobId)) {
            $report->update(['status' => 'failed']);
            Log::error('ProcessAlgorithmComparison: solver response missing job_id', [
                'comparison_report_id' => $report->id,
                'school_term_id' => $term->id,
                'body' => $response->json(),
            ]);

            return;
        }

        // Indice secundario para que o webhook de comparacao consiga
        // resolver job_id -> comparison_report_id no retorno assincrono.
        Cache::put(
            "comparison:job:{$jobId}",
            $report->id,
            now()->addHours(4)
        );

        Log::info('ProcessAlgorithmComparison: solver dispatched successfully', [
            'comparison_report_id' => $report->id,
            'school_term_id' => $term->id,
            'job_id' => $jobId,
        ]);
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

        Cache::forget("comparison:running:{$this->schoolTermId}");

        Log::error('ProcessAlgorithmComparison: failed', [
            'school_term_id' => $this->schoolTermId,
            'base_allocation_state_id' => $this->baseAllocationStateId,
            'error' => $exception->getMessage(),
        ]);
    }
}
