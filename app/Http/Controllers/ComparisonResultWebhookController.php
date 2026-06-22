<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ComparisonResultWebhookController extends Controller
{
    /**
     * Recebe o resultado assincrono do Solver CP-SAT para fins de
     * Benchmarking (modulo de comparacao).
     *
     * A persistencia das solver_metrics e a transicao de status do
     * ComparisonReport para 'completed' sao tratadas nesta issue dedicada
     * (modulo de Benchmarking). Este controlador existe primariamente para
     * que a rota `webhooks.comparison.result` seja resolvivel pelo helper
     * route() no momento do disparo do Job `ProcessAlgorithmComparison`.
     */
    public function __invoke(Request $request)
    {
        Log::info('ComparisonResultWebhook: received solver result for benchmarking', [
            'job_id' => $request->input('job_id'),
            'status' => $request->input('status'),
        ]);

        return response()->json(['message' => 'accepted'], 202);
    }
}
