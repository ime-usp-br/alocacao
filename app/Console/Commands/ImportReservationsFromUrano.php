<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ReservationApiService;
use App\Services\ReservationMapper;
use App\Services\SalasApiClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Exception;

class ImportReservationsFromUrano extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reservas:urano-to-salas 
                          {--dry-run : Execute in simulation mode}
                          {--periodo-id= : Specific Urano period ID}
                          {--date-from= : Start date filter (YYYY-MM-DD)}
                          {--date-to= : End date filter (YYYY-MM-DD)}
                          {--sala-numero= : Specific room number from Urano}
                          {--requisicao-id= : Specific requisition ID}
                          {--batch-size=100 : Reservations per batch}
                          {--force : Skip confirmation prompts}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import reservations directly from Urano database to Salas API';

    private ReservationApiService $reservationService;
    private ReservationMapper $mapper;
    private SalasApiClient $apiClient;
    private string $operationId;
    private array $statistics;
    private int $batchSize;
    private bool $isDryRun;

    /**
     * Create a new command instance.
     */
    public function __construct(
        ReservationApiService $reservationService,
        ReservationMapper $mapper,
        SalasApiClient $apiClient
    ) {
        parent::__construct();
        $this->reservationService = $reservationService;
        $this->mapper = $mapper;
        $this->apiClient = $apiClient;
        $this->operationId = 'urano_import_' . date('YmdHis');
        $this->initializeStatistics();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('🔄 AC1: Import Reservations from Urano to Salas API');
        $this->newLine();

        $this->isDryRun = $this->option('dry-run');
        $this->batchSize = (int) $this->option('batch-size');

        if ($this->isDryRun) {
            $this->warn('⚠️  DRY-RUN MODE ACTIVATED - No actual changes will be made');
        }

        try {
            // Step 1: Pre-flight validations
            $this->info('📋 Step 1: Pre-flight validations');
            if (!$this->runPreFlightValidations()) {
                return 1;
            }

            // Step 2: Load Urano data
            $this->info('🔍 Step 2: Loading Urano reservation data');
            $uranoReservations = $this->loadUranoReservations();
            
            if ($uranoReservations->isEmpty()) {
                $this->warn('⚠️ No Urano reservations found matching the criteria');
                return 0;
            }

            $this->info("📊 Found {$uranoReservations->count()} reservations to process");

            // Step 3: Confirmation
            if (!$this->isDryRun && !$this->option('force')) {
                if (!$this->confirm('Proceed with importing reservations to Salas API?')) {
                    $this->info('Operation cancelled by user.');
                    return 0;
                }
            }

            // Step 4: Process reservations
            $this->info('⚡ Step 3: Processing reservations');
            if (!$this->processUranoReservations($uranoReservations)) {
                return 1;
            }

            // Step 5: Final report
            $this->info('📊 Step 4: Final report');
            $this->generateFinalReport();

            $this->info('✅ Urano import completed successfully!');
            return 0;

        } catch (Exception $e) {
            $this->error("❌ Critical error during import: {$e->getMessage()}");
            $this->logError('urano_import_critical_error', $e);
            return 1;
        }
    }

    /**
     * Run pre-flight validations
     *
     * @return bool
     */
    private function runPreFlightValidations(): bool
    {
        $this->info('🔍 Running pre-flight validations...');
        
        $validations = [
            'urano_connectivity' => 'Urano database connectivity',
            'salas_api_connectivity' => 'Salas API connectivity and authentication',
            'filters_validation' => 'Command filters validation'
        ];

        $failures = [];

        foreach ($validations as $check => $description) {
            $this->line("  🔸 $description...");
            
            $result = $this->performValidation($check);
            if ($result === true) {
                $this->info("    ✅ $description: OK");
            } else {
                $this->error("    ❌ $description: $result");
                $failures[] = $check;
            }
        }

        if (!empty($failures)) {
            $this->error('❌ Validations failed. Please fix issues before proceeding.');
            return false;
        }

        $this->info('✅ All pre-flight validations passed!');
        return true;
    }

    /**
     * Perform individual validation checks
     *
     * @param string $check
     * @return bool|string
     */
    private function performValidation(string $check)
    {
        try {
            switch ($check) {
                case 'urano_connectivity':
                    // Test Urano database connection
                    DB::connection('urano')->getPdo();
                    
                    // Verify required tables exist
                    $requiredTables = ['REQUISICAO', 'RESERVA', 'SALA', 'PERIODO'];
                    foreach ($requiredTables as $table) {
                        $exists = DB::connection('urano')
                            ->select("SHOW TABLES LIKE '$table'");
                        if (empty($exists)) {
                            return "Table $table not found in Urano database";
                        }
                    }
                    return true;

                case 'salas_api_connectivity':
                    return $this->reservationService->checkApiHealth() ? true : 'Salas API unavailable or authentication failed';

                case 'filters_validation':
                    // Validate date formats if provided
                    if ($this->option('date-from')) {
                        if (!$this->isValidDate($this->option('date-from'))) {
                            return 'Invalid date-from format. Use YYYY-MM-DD';
                        }
                    }
                    if ($this->option('date-to')) {
                        if (!$this->isValidDate($this->option('date-to'))) {
                            return 'Invalid date-to format. Use YYYY-MM-DD';
                        }
                    }
                    
                    // Validate periodo-id if provided
                    if ($this->option('periodo-id')) {
                        $periodoExists = DB::connection('urano')
                            ->table('PERIODO')
                            ->where('id', $this->option('periodo-id'))
                            ->exists();
                        if (!$periodoExists) {
                            return 'Periodo ID not found in Urano database';
                        }
                    }
                    
                    return true;

                default:
                    return 'Unknown validation check';
            }
        } catch (Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * Load reservations from Urano database
     *
     * @return \Illuminate\Support\Collection
     */
    private function loadUranoReservations()
    {
        $this->info('🔄 Building Urano query with filters...');
        
        $query = DB::connection('urano')
            ->table('RESERVA as res')
            ->join('REQUISICAO as req', 'res.requisicao_id', '=', 'req.id')
            ->join('SALA as s', 'res.sala_numero', '=', 's.numero')
            ->select([
                'res.id as reserva_id',
                'res.data',
                'res.hi',
                'res.hf',
                'res.atividadeRegular',
                'req.id as requisicao_id',
                'req.titulo',
                'req.email',
                'req.solicitante',
                'req.participantes',
                'req.atividade',
                'req.status',
                'req.dataCadastro',
                's.numero as sala_numero',
                's.nome as sala_nome',
                's.assentos as sala_assentos'
            ]);

        // Apply filters
        $this->applyFiltersToQuery($query);

        $reservations = $query->get();
        
        $this->info("  ✅ Loaded {$reservations->count()} reservations from Urano");
        
        return $reservations;
    }

    /**
     * Apply command-line filters to the Urano query
     *
     * @param \Illuminate\Database\Query\Builder $query
     */
    private function applyFiltersToQuery($query): void
    {
        // Period filter
        if ($periodoId = $this->option('periodo-id')) {
            $periodo = DB::connection('urano')
                ->table('PERIODO')
                ->where('id', $periodoId)
                ->first();
            
            if ($periodo) {
                $query->whereBetween('res.data', [
                    Carbon::parse($periodo->dataInicio)->format('Y-m-d'),
                    Carbon::parse($periodo->dataFim)->format('Y-m-d')
                ]);
                $this->info("  🔸 Filtered by Urano period {$periodoId} ({$periodo->dataInicio} to {$periodo->dataFim})");
            }
        }

        // Date range filters
        if ($dateFrom = $this->option('date-from')) {
            $query->where('res.data', '>=', $dateFrom);
            $this->info("  🔸 Filtered by date from: {$dateFrom}");
        }
        
        if ($dateTo = $this->option('date-to')) {
            $query->where('res.data', '<=', $dateTo);
            $this->info("  🔸 Filtered by date to: {$dateTo}");
        }

        // Room filter
        if ($salaNumero = $this->option('sala-numero')) {
            $query->where('res.sala_numero', $salaNumero);
            $this->info("  🔸 Filtered by room number: {$salaNumero}");
        }

        // Specific requisition filter
        if ($requisicaoId = $this->option('requisicao-id')) {
            $query->where('req.id', $requisicaoId);
            $this->info("  🔸 Filtered by requisition ID: {$requisicaoId}");
        }

        // Only approved reservations (assuming status 3 means approved)
        $query->where('req.status', 3);
        $this->info("  🔸 Including only approved reservations (status = 3)");
    }

    /**
     * Process Urano reservations in batches
     *
     * @param \Illuminate\Support\Collection $uranoReservations
     * @return bool
     */
    private function processUranoReservations($uranoReservations): bool
    {
        $batches = $uranoReservations->chunk($this->batchSize);
        $totalBatches = $batches->count();
        
        $progressBar = $this->output->createProgressBar($uranoReservations->count());
        $progressBar->setFormat('debug');
        
        $currentBatch = 1;
        
        foreach ($batches as $batch) {
            $this->info("\n🔄 Processing batch $currentBatch/$totalBatches ({$batch->count()} reservations)");
            
            try {
                $this->processBatch($batch, $progressBar);
                $currentBatch++;
                
            } catch (Exception $e) {
                $this->error("\n❌ Error in batch $currentBatch: {$e->getMessage()}");
                $this->statistics['batch_errors']++;
                
                if ($this->statistics['batch_errors'] >= 3) {
                    $this->error('❌ Too many batch errors. Aborting import.');
                    return false;
                }
                
                $this->warn('⚠️ Continuing with next batch...');
                $currentBatch++;
            }
        }
        
        $progressBar->finish();
        $this->newLine(2);
        
        return true;
    }

    /**
     * Process a batch of Urano reservations
     *
     * @param \Illuminate\Support\Collection $batch
     * @param \Symfony\Component\Console\Helper\ProgressBar $progressBar
     */
    private function processBatch($batch, $progressBar): void
    {
        foreach ($batch as $uranoReservation) {
            try {
                $this->processSingleUranoReservation($uranoReservation);
            } catch (Exception $e) {
                $this->logError('single_reservation_error', $e, [
                    'reserva_id' => $uranoReservation->reserva_id,
                    'requisicao_id' => $uranoReservation->requisicao_id,
                    'sala_numero' => $uranoReservation->sala_numero
                ]);
            }
            
            $progressBar->advance();
        }
    }

    /**
     * Process a single Urano reservation
     *
     * @param \stdClass $uranoReservation
     */
    private function processSingleUranoReservation($uranoReservation): void
    {
        if ($this->isDryRun) {
            $this->statistics['processed_reservations']++;
            $this->statistics['successful_imports']++;
            $this->statistics['dry_run_validations']++;
            return;
        }

        try {
            // Transform Urano data to format expected by ReservationMapper
            $transformedData = $this->transformUranoReservation($uranoReservation);
            
            // Use existing services to create the reservation
            $reservation = $this->reservationService->createReservationsFromUranoData($transformedData);
            
            $this->statistics['processed_reservations']++;
            $this->statistics['successful_imports']++;
            $this->statistics['created_reservations']++;
            
        } catch (Exception $e) {
            $this->statistics['failed_imports']++;
            $this->logError('reservation_creation_error', $e, [
                'reserva_id' => $uranoReservation->reserva_id,
                'requisicao_id' => $uranoReservation->requisicao_id,
                'sala_numero' => $uranoReservation->sala_numero,
                'data' => $uranoReservation->data
            ]);
        }
    }

    /**
     * Transform Urano reservation data to expected format
     *
     * @param \stdClass $uranoReservation
     * @return array
     */
    private function transformUranoReservation($uranoReservation): array
    {
        return [
            'reserva_id' => $uranoReservation->reserva_id,
            'data' => $uranoReservation->data,
            'hora_inicio' => $uranoReservation->hi,
            'hora_fim' => $uranoReservation->hf,
            'titulo' => $uranoReservation->titulo,
            'solicitante' => $uranoReservation->solicitante,
            'email' => $uranoReservation->email,
            'participantes' => $uranoReservation->participantes,
            'sala_numero' => $uranoReservation->sala_numero,
            'sala_nome' => $uranoReservation->sala_nome,
            'atividade_regular' => (bool) $uranoReservation->atividadeRegular,
            'requisicao_id' => $uranoReservation->requisicao_id
        ];
    }

    /**
     * Generate final import report
     */
    private function generateFinalReport(): void
    {
        $this->info('📈 Import Statistics:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Processed Reservations', $this->statistics['processed_reservations']],
                ['Successful Imports', $this->statistics['successful_imports']],
                ['Failed Imports', $this->statistics['failed_imports']],
                ['Created Reservations', $this->statistics['created_reservations']],
                ['Batch Errors', $this->statistics['batch_errors']],
                ['Success Rate', $this->calculateSuccessRate() . '%'],
                ['Operation Mode', $this->isDryRun ? 'DRY-RUN' : 'PRODUCTION']
            ]
        );

        // Recommendations
        $recommendations = $this->generateRecommendations();
        if (!empty($recommendations)) {
            $this->warn('💡 Recommendations:');
            foreach ($recommendations as $recommendation) {
                $this->line("  • $recommendation");
            }
        }
    }

    /**
     * Generate actionable recommendations
     *
     * @return array
     */
    private function generateRecommendations(): array
    {
        $recommendations = [];

        if ($this->statistics['failed_imports'] > 0) {
            $recommendations[] = 'Review error logs for failed reservation imports';
        }

        if ($this->statistics['batch_errors'] > 0) {
            $recommendations[] = 'Check Salas API connectivity and rate limits';
        }

        if ($this->calculateSuccessRate() < 95) {
            $recommendations[] = 'Success rate below 95% - investigate systematic issues';
        }

        if ($this->isDryRun) {
            $recommendations[] = 'Run command without --dry-run to perform actual import';
        } else {
            $recommendations[] = 'Verify imported reservations in Salas system';
        }

        return $recommendations;
    }

    /**
     * Calculate success rate percentage
     *
     * @return float
     */
    private function calculateSuccessRate(): float
    {
        $total = $this->statistics['successful_imports'] + $this->statistics['failed_imports'];
        
        if ($total === 0) {
            return 100.0;
        }
        
        return round(($this->statistics['successful_imports'] / $total) * 100, 2);
    }

    /**
     * Initialize statistics tracking
     */
    private function initializeStatistics(): void
    {
        $this->statistics = [
            'started_at' => now()->toISOString(),
            'processed_reservations' => 0,
            'successful_imports' => 0,
            'failed_imports' => 0,
            'created_reservations' => 0,
            'batch_errors' => 0,
            'dry_run_validations' => 0
        ];
    }

    /**
     * Validate date format
     *
     * @param string $date
     * @return bool
     */
    private function isValidDate(string $date): bool
    {
        try {
            Carbon::createFromFormat('Y-m-d', $date);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Log structured error messages
     *
     * @param string $errorType
     * @param Exception $exception
     * @param array $context
     */
    private function logError(string $errorType, Exception $exception, array $context = []): void
    {
        $logContext = array_merge($context, [
            'operation_id' => $this->operationId,
            'error_type' => $errorType,
            'error_message' => $exception->getMessage(),
            'error_trace' => $exception->getTraceAsString(),
            'command_options' => $this->options(),
            'statistics' => $this->statistics
        ]);

        Log::error("Urano Import AC1 - $errorType", $logContext);
    }
}