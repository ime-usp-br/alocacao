<?php

namespace App\Jobs;

use App\Models\SchoolClass;
use App\Models\SchoolTerm;
use App\Models\SolverLog;
use App\Services\RoomAllocationPayloadBuilder;
use App\Services\AllocationStateService;
use App\Services\SkuldPredictionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessRoomDistribution implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $schoolTermId;
    public array $roomIds;
    public array $solverConfig;
    public bool $syncEnrollment;
    public bool $useSkuldPrediction;

    public int $timeout = 300;
    public int $tries = 3;
    public int $uniqueFor = 3600;

    /**
     * Create a new job instance.
     */
    public function __construct(int $schoolTermId, array $roomIds, array $solverConfig = [], bool $syncEnrollment = false, bool $useSkuldPrediction = false)
    {
        $this->schoolTermId = $schoolTermId;
        $this->roomIds = $roomIds;
        $this->solverConfig = $solverConfig;
        $this->syncEnrollment = $syncEnrollment;
        $this->useSkuldPrediction = $useSkuldPrediction;
    }

    /**
     * The unique ID of the job.
     */
    public function uniqueId(): string
    {
        return 'room-distribution-' . $this->schoolTermId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $term = SchoolTerm::findOrFail($this->schoolTermId);

        if ($this->syncEnrollment) {
            Log::info('ProcessRoomDistribution: syncing enrollment from Replicado', [
                'school_term_id' => $this->schoolTermId,
            ]);

            SchoolClass::whereBelongsTo($term)
                ->chunkById(100, function ($classes) {
                    foreach ($classes as $class) {
                        $class->calcEstimadedEnrollment();
                        $class->save();
                    }
                });
        }

        $payload = (new RoomAllocationPayloadBuilder())
            ->build($term, $this->roomIds, $this->solverConfig, $this->resolveSkuldPredicoes($term));

        $solverUrl = rtrim(config('alocacao.solver.url'), '/');
        $apiToken = config('alocacao.solver.api_token');
        $timeout = config('alocacao.solver.timeout', 60);

        $webhookUrl = route('webhooks.allocation.result');
        $progressWebhookUrl = route('webhooks.allocation.progress');

        Log::info('ProcessRoomDistribution: dispatching to solver', [
            'school_term_id' => $this->schoolTermId,
            'solver_url' => $solverUrl,
            'webhook_url' => $webhookUrl,
            'progress_webhook_url' => $progressWebhookUrl,
        ]);

        $http = Http::withHeaders([
                'X-Webhook-Token' => $apiToken,
                'Accept' => 'application/json',
            ])
            ->timeout($timeout);

        if (! config('alocacao.solver.verify_ssl', true)) {
            $http = $http->withoutVerifying();
        }

        // Injeta as URLs de webhook para dentro do bloco meta do JSON
        $payload['meta']['webhook_url'] = $webhookUrl;
        $payload['meta']['progress_webhook_url'] = $progressWebhookUrl;

        // Envia o payload raiz para o Python
        $response = $http->post("{$solverUrl}/api/v1/solve", $payload);

        if (! $response->successful()) {
            Log::error('ProcessRoomDistribution: solver returned error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Solver returned HTTP ' . $response->status() . ': ' . $response->body());
        }

        $jobId = $response->json('job_id');

        if (empty($jobId)) {
            Log::error('ProcessRoomDistribution: solver response missing job_id', [
                'body' => $response->json(),
            ]);
            throw new \RuntimeException('Solver response missing job_id');
        }

        Cache::put(
            "allocation:{$this->schoolTermId}",
            [
                'job_id' => $jobId,
                'status' => 'solving',
                'progress' => 0,
                'message' => 'Job enviado ao solver',
                'started_at' => now()->toIso8601String(),
            ],
            now()->addHours(4)
        );

        // Secondary index to map job_id back to school_term_id
        Cache::put(
            "allocation:job:{$jobId}",
            $this->schoolTermId,
            now()->addHours(4)
        );

        // Conta as alocações manuais existentes antes do envio: turmas do
        // semestre (não externas) que já têm sala fixada manualmente. Lemos do
        // banco e não do payload porque turmas excluídas do solver (ex. MAE0116)
        // também podem ter alocação manual e devem ser contabilizadas.
        $manualCount = SchoolClass::whereBelongsTo($term)
            ->where('externa', false)
            ->whereNotNull('room_id')
            ->count();

        // Persist the payload for later debugging via admin view
        $solverLog = SolverLog::create([
            'school_term_id' => $this->schoolTermId,
            'job_id' => $jobId,
            'payload' => $payload,
            'status' => 'solving',
            'manual_count' => $manualCount,
            'dispatched_at' => now(),
        ]);

        // Auto-save the current allocation state before the solver runs
        AllocationStateService::capture(
            $term,
            'Pré-Solver - ' . now()->format('d/m/Y H:i:s'),
            $solverLog->id
        );

        Log::info('ProcessRoomDistribution: job dispatched successfully', [
            'school_term_id' => $this->schoolTermId,
            'job_id' => $jobId,
        ]);
    }

    /**
     * Resolve as predições do Skuld para o semestre, forçando a estimativa
     * histórica de calouros para 'none' (o Skuld passa a ser a fonte de
     * inferência do estmtr). O estmtr do banco local NÃO é sobrescrito.
     *
     * @return array<string, int> Mapa coddis|codtur => estmtr previsto.
     */
    private function resolveSkuldPredicoes(SchoolTerm $term): array
    {
        if (! $this->useSkuldPrediction) {
            return [];
        }

        // Trava a estimativa histórica de calouros como desligada, mesmo que o
        // Skuld esteja indisponível ou retorne vazio.
        $this->solverConfig['historical_estimation_method'] = 'none';

        $predicoes = (new SkuldPredictionService())->predict($term);

        Log::info('ProcessRoomDistribution: skuld predictions resolved', [
            'school_term_id' => $this->schoolTermId,
            'skuld_predictions' => count($predicoes),
        ]);

        return $predicoes;
    }
}
