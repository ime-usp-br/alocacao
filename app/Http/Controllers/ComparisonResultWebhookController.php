<?php

namespace App\Http\Controllers;

use App\Models\ComparisonReport;
use App\Services\AllocationEvaluatorService;
use App\Services\AllocationStateService;
use App\Services\ComparisonAllocationCollector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ComparisonResultWebhookController extends Controller
{
    /**
     * Recebe o resultado assincrono do Solver CP-SAT para fins de
     * Benchmarking (modulo de comparacao).
     *
     * Diferente do webhook de alocacao de producao, este controlador NUNCA
     * modifica permanentemente as turmas em `school_classes`. Ele adota a
     * mesma politica de coleta do motor legado: restaura o estado base,
     * escreve as alocacoes do solver em `school_classes` dentro de uma
     * transacao reversivel, coleta o mapa [class_id => room_id] do banco
     * (com precedencia de mestre de fusao) e entao REVERTE a transacao.
     * O mapa coletado em memoria e submetido ao motor de avaliacao isomorfo
     * `AllocationEvaluatorService` e o `ComparisonReport` e finalizado.
     *
     * Esta simetria garante que ambos os motores sejam avaliados a partir de
     * uma unica fonte de verdade (o banco de dados), eliminando a assimetria
     * que ocorria quando o solver era avaliado diretamente do payload — onde
     * turmas filhas de fusao apareciam como nao-alocadas por nao constarem
     * individualmente no array de alocacoes do solver.
     */
    public function __invoke(Request $request)
    {
        // if (! $this->tokenIsValid($request)) {
        //     abort(401, 'Unauthorized');
        // }

        $validated = $request->validate([
            'job_id' => 'required|string',
            'status' => 'required|string|in:optimal,feasible,stopped,infeasible,error',
            'allocations' => 'nullable|array',
            'allocations.*.group_id' => 'required_with:allocations|integer',
            'allocations.*.room_id' => 'required_with:allocations|integer',
            'unassigned_groups' => 'nullable|array',
            'unassigned_groups.*' => 'integer',
            'solve_time_seconds' => 'nullable|numeric|min:0',
        ]);

        $jobId = $validated['job_id'];
        $status = $validated['status'];
        $assignments = $validated['allocations'] ?? [];
        $unassignedGroups = $validated['unassigned_groups'] ?? [];
        $solveTimeSeconds = $validated['solve_time_seconds'] ?? null;

        $reportId = $this->findComparisonReportIdByJobId($jobId);

        if ($reportId === null) {
            Log::warning('ComparisonResultWebhook: received result for unknown job_id', [
                'job_id' => $jobId,
            ]);

            return response()->json(['message' => 'Job not found'], 404);
        }

        /** @var ComparisonReport|null $report */
        $report = ComparisonReport::find($reportId);

        if ($report === null) {
            Log::warning('ComparisonResultWebhook: comparison report not found for cached job_id', [
                'job_id' => $jobId,
                'report_id' => $reportId,
            ]);

            return response()->json(['message' => 'Job not found'], 404);
        }

        // Idempotency / zombie guard: apenas relatorios em 'processing'
        // aguardam o callback do solver. Resultados tardios ou duplicados
        // para relatorios ja finalizados sao ignorados.
        if ($report->status !== 'processing') {
            Log::info('ComparisonResultWebhook: ignoring result for already finished report', [
                'job_id' => $jobId,
                'report_id' => $report->id,
                'status' => $report->status,
            ]);

            return response()->json(['message' => 'Ignored. Report already finished.'], 200);
        }

        // Falhas reportadas pelo solver nao possuem alocacoes uteis.
        if (in_array($status, ['infeasible', 'error'], true)) {
            $report->update(['status' => 'failed']);

            Log::error('ComparisonResultWebhook: solver reported failure for comparison', [
                'job_id' => $jobId,
                'report_id' => $report->id,
                'solver_status' => $status,
            ]);

            return response()->json(['message' => 'Comparison marked as failed'], 200);
        }

        // Converte o array de alocacoes do solver [{group_id, room_id}] no
        // formato esperado pela politica de escrita. O group_id do solver
        // corresponde ao id da SchoolClass mestre da fusao (mesma convensao
        // do AllocationResultWebhookController).
        $solverAssignments = [];
        foreach ($assignments as $assignment) {
            $solverAssignments[] = [
                'group_id' => (int) $assignment['group_id'],
                'room_id' => (int) $assignment['room_id'],
            ];
        }

        $baseState = $report->baseAllocationState;

        if ($baseState === null) {
            $report->update(['status' => 'failed']);

            Log::error('ComparisonResultWebhook: comparison report missing base allocation state', [
                'job_id' => $jobId,
                'report_id' => $report->id,
            ]);

            return response()->json(['message' => 'Failed to evaluate solver result'], 500);
        }

        try {
            // Politica simetrica ao motor legado: restaura o estado base,
            // escreve as alocacoes do solver no banco, coleta o mapa pela
            // mesma regua (DB com precedencia de mestre de fusao) e reverte.
            DB::beginTransaction();

            AllocationStateService::restore($baseState);

            $collector = new ComparisonAllocationCollector();
            $collector->applySolverAssignmentsToDatabase($solverAssignments, $unassignedGroups);

            $rawAllocations = $collector->collectFromDatabase($report->schoolTerm);

            DB::rollBack();
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            $report->update(['status' => 'failed']);

            Log::error('ComparisonResultWebhook: failed to collect solver allocations from database', [
                'job_id' => $jobId,
                'report_id' => $report->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to evaluate solver result'], 500);
        }

        try {
            $evaluator = new AllocationEvaluatorService($report->solver_config ?? []);
            $metrics = $evaluator->evaluate(
                $report->schoolTerm,
                $rawAllocations,
                $solveTimeSeconds !== null ? (float) $solveTimeSeconds : null
            );

            $report->update([
                'solver_metrics' => $metrics,
                'solver_raw_allocations' => $rawAllocations,
                'status' => 'completed',
            ]);

            Log::info('ComparisonResultWebhook: comparison report completed', [
                'job_id' => $jobId,
                'report_id' => $report->id,
                'allocations_count' => count($rawAllocations),
                'allocation_rate' => $metrics['allocation_rate'],
            ]);

            return response()->json(['message' => 'Comparison report completed'], 200);
        } catch (\Throwable $e) {
            $report->update(['status' => 'failed']);

            Log::error('ComparisonResultWebhook: failed to evaluate solver result', [
                'job_id' => $jobId,
                'report_id' => $report->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to evaluate solver result'], 500);
        }
    }

    /**
     * Validate the webhook token.
     */
    private function tokenIsValid(Request $request): bool
    {
        $expectedToken = config('alocacao.solver.api_token');

        if (empty($expectedToken)) {
            return false;
        }

        return $request->header('X-Webhook-Token') === $expectedToken;
    }

    /**
     * Resolve o job_id do solver para o id do ComparisonReport ativo via
     * indice secundario em Cache, mantido pelo Job
     * ProcessAlgorithmComparison no momento do disparo.
     */
    private function findComparisonReportIdByJobId(string $jobId): ?int
    {
        $reportId = Cache::get("comparison:job:{$jobId}");

        if ($reportId !== null) {
            return (int) $reportId;
        }

        return null;
    }
}
