<?php

namespace App\Http\Controllers;

use App\Models\ComparisonReport;
use App\Services\AllocationEvaluatorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ComparisonResultWebhookController extends Controller
{
    /**
     * Recebe o resultado assincrono do Solver CP-SAT para fins de
     * Benchmarking (modulo de comparacao).
     *
     * Diferente do webhook de alocacao de producao, este controlador NUNCA
     * modifica as turmas em `school_classes`. Ele apenas extrai as alocacoes
     * retornadas, submete-as ao motor de avaliacao isomorfo
     * `AllocationEvaluatorService` e atualiza o `ComparisonReport` ativo,
     * transicionando seu status para `completed` (ou `failed`).
     */
    public function __invoke(Request $request)
    {
        if (! $this->tokenIsValid($request)) {
            abort(401, 'Unauthorized');
        }

        $validated = $request->validate([
            'job_id' => 'required|string',
            'status' => 'required|string|in:optimal,feasible,stopped,infeasible,error',
            'allocations' => 'nullable|array',
            'allocations.*.group_id' => 'required_with:allocations|integer',
            'allocations.*.room_id' => 'required_with:allocations|integer',
            'solve_time_seconds' => 'nullable|numeric|min:0',
        ]);

        $jobId = $validated['job_id'];
        $status = $validated['status'];
        $assignments = $validated['allocations'] ?? [];
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
        // mapa [school_class_id => room_id] esperado pelo avaliador. O
        // group_id do solver corresponde ao id da SchoolClass (mesma
        // convensao adotada pelo AllocationResultWebhookController).
        $rawAllocations = [];
        foreach ($assignments as $assignment) {
            $rawAllocations[(int) $assignment['group_id']] = (int) $assignment['room_id'];
        }

        try {
            $evaluator = new AllocationEvaluatorService();
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
