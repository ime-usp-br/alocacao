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
        $this->info('🔄 Import Reservations from Urano to Salas API');
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

            // Step 4: Two-Phase Processing (Verification → Creation)
            $this->info('🔄 Step 3: Two-Phase Processing (Verification → Creation)');
            $this->info('🛡️ All or Nothing Policy - Validation will halt immediately on first error');
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

        // If no start date was specified via other filters, default to future reservations.
        if (!$this->option('date-from') && !$this->option('periodo-id')) {
            $query->where('res.data', '>=', now()->format('Y-m-d'));
        }

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

        // Only approved reservations (assuming status 1 means approved)
        $query->where('req.status', 1);
        $this->info("  🔸 Including only approved reservations (status = 1)");
    }

    /**
     * Process Urano reservations using two-phase logic
     *
     * @param \Illuminate\Support\Collection $uranoReservations
     * @return bool
     */
    private function processUranoReservations($uranoReservations): bool
    {
        // Phase 1: Verification Phase
        $this->info('🔍 Step 4A: Verification Phase');
        $verificationResult = $this->verifyUranoReservations($uranoReservations);
        
        if (!$verificationResult['success']) {
            $this->displayVerificationErrors($verificationResult);
            return false;
        }
        
        $this->info("✅ Verification completed: {$verificationResult['statistics']['passed_validation']} reservations ready for creation");
        
        // Phase 2: Creation Phase (only if verification succeeded)
        $this->info('⚡ Step 4B: Creation Phase');
        $creationResult = $this->createVerifiedReservations($verificationResult['verified_reservations'], $verificationResult['room_mappings']);
        
        if (!$creationResult) {
            return false;
        }
        
        return true;
    }

    /**
     * Fase de Verificação - Connect to Urano database and map individual records
     * This method implements the data mapping logic for Urano imports
     *
     * @return array Verification result with mapped reservations and statistics
     */
    private function faseVerificacao(): array
    {
        $this->info('🔍 Iniciando fase de verificação com mapeamento Urano');
        
        $result = [
            'success' => false,
            'mapped_reservations' => [],
            'validation_errors' => [],
            'statistics' => [
                'total_found' => 0,
                'successfully_mapped' => 0,
                'mapping_failures' => 0,
                'status_approved' => 0,
                'status_pending' => 0
            ]
        ];

        try {
            // Step 1: Connect to Urano database and read all future reservations
            $this->info('  🔸 Conectando ao banco Urano e lendo reservas futuras...');
            $uranoReservations = $this->loadFutureUranoReservations();
            
            if ($uranoReservations->isEmpty()) {
                $this->warn('⚠️ Nenhuma reserva futura encontrada no Urano');
                $result['success'] = true; // Empty is still success
                return $result;
            }

            $result['statistics']['total_found'] = $uranoReservations->count();
            $this->info("  ✅ Encontradas {$uranoReservations->count()} reservas futuras no Urano");

            // Step 2: Map each record individually using existing ReservationMapper
            $this->info('  🔸 Mapeando registros individuais...');
            $progressBar = $this->output->createProgressBar($uranoReservations->count());
            $progressBar->setFormat('debug');

            foreach ($uranoReservations as $uranoRecord) {
                $mappingResult = $this->mapSingleUranoRecord($uranoRecord);
                
                if ($mappingResult['success']) {
                    $result['mapped_reservations'][] = $mappingResult['mapped_data'];
                    $result['statistics']['successfully_mapped']++;
                    
                    // Count status distribution
                    $status = $mappingResult['mapped_data']['status'];
                    if ($status === 'aprovada') {
                        $result['statistics']['status_approved']++;
                    } else {
                        $result['statistics']['status_pending']++;
                    }
                } else {
                    $result['validation_errors'] = array_merge($result['validation_errors'], $mappingResult['errors']);
                    $result['statistics']['mapping_failures']++;
                    
                    // All or Nothing Policy - halt on first error
                    $this->logError('mapping_halt', new Exception('Mapping halted on first error'), [
                        'operation_id' => $this->operationId,
                        'total_found' => $result['statistics']['total_found'],
                        'processed_so_far' => $result['statistics']['successfully_mapped'] + $result['statistics']['mapping_failures'],
                        'first_error_context' => $mappingResult['errors'][0] ?? 'unknown',
                        'policy_triggered' => true
                    ]);
                    break;
                }
                $progressBar->advance();
            }

            $progressBar->finish();
            $this->newLine();

            // Step 3: Determine overall success
            $result['success'] = $result['statistics']['mapping_failures'] === 0;

            $this->logError('fase_verificacao_completed', new Exception('Fase verificação completed'), [
                'operation_id' => $this->operationId,
                'total_found' => $result['statistics']['total_found'],
                'successfully_mapped' => $result['statistics']['successfully_mapped'],
                'mapping_failures' => $result['statistics']['mapping_failures'],
                'status_approved' => $result['statistics']['status_approved'],
                'status_pending' => $result['statistics']['status_pending'],
                'success' => $result['success']
            ]);

            return $result;

        } catch (Exception $e) {
            $result['validation_errors'][] = [
                'type' => 'system_error',
                'message' => 'Critical error during fase verificação: ' . $e->getMessage(),
                'severity' => 'critical'
            ];

            $this->logError('fase_verificacao_error', $e);
            return $result;
        }
    }

    /**
     * Load future reservations from Urano database with proper joins
     * Implements the database connection and reading logic for Urano imports
     *
     * @return \Illuminate\Support\Collection
     */
    private function loadFutureUranoReservations()
    {
        $this->info('🔄 Construindo consulta Urano com junções REQUISICAO + RESERVA + SALA...');
        
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
                'req.status', // Status mapping requirement
                'req.dataCadastro',
                's.numero as sala_numero',
                's.nome as sala_nome',
                's.assentos as sala_assentos'
            ])
            // Only future reservations
            ->whereRaw('res.data >= CURDATE()');

        // Apply existing filters if present
        $this->applyFiltersToQuery($query);

        $reservations = $query->get();
        
        $this->info("  ✅ Carregadas {$reservations->count()} reservas futuras do Urano");
        
        return $reservations;
    }

    /**
     * Map single Urano record with individual mapping logic
     * Implements status mapping and uses default finalidade_id = 1
     *
     * @param \stdClass $uranoRecord
     * @return array
     */
    private function mapSingleUranoRecord($uranoRecord): array
    {
        $result = [
            'success' => false,
            'mapped_data' => null,
            'errors' => []
        ];

        try {
            // Transform Urano record to format expected by ReservationMapper
            $transformedData = [
                'reserva_id' => $uranoRecord->reserva_id,
                'data' => $uranoRecord->data,
                'hora_inicio' => $uranoRecord->hi,
                'hora_fim' => $uranoRecord->hf,
                'titulo' => $uranoRecord->titulo,
                'solicitante' => $uranoRecord->solicitante,
                'email' => $uranoRecord->email,
                'participantes' => $uranoRecord->participantes,
                'sala_numero' => $uranoRecord->sala_numero,
                'sala_nome' => $uranoRecord->sala_nome,
                'atividade_regular' => (bool) $uranoRecord->atividadeRegular,
                'requisicao_id' => $uranoRecord->requisicao_id,
                'tipo_atividade' => $uranoRecord->atividade ?? null,
                // Status mapping from REQUISICAO.status
                'urano_status' => $uranoRecord->status
            ];

            // Use existing ReservationMapper to create API payload
            $apiPayload = $this->mapper->mapUranoDataToReservationPayload($transformedData);

            // Apply status mapping
            $apiPayload['status'] = $this->mapUranoStatusToSalasStatus($uranoRecord->status);
            
            $result['mapped_data'] = array_merge($transformedData, [
                'api_payload' => $apiPayload,
                'status' => $apiPayload['status']
            ]);
            $result['success'] = true;

        } catch (Exception $e) {
            $result['errors'][] = [
                'type' => 'mapping_error',
                'message' => 'Failed to map Urano record: ' . $e->getMessage(),
                'context' => [
                    'reserva_id' => $uranoRecord->reserva_id ?? 'unknown',
                    'requisicao_id' => $uranoRecord->requisicao_id ?? 'unknown',
                    'titulo' => $uranoRecord->titulo ?? 'unknown'
                ]
            ];
        }

        return $result;
    }

    /**
     * Map REQUISICAO.status to Salas API status
     *
     * @param int $uranoStatus Status from REQUISICAO table
     * @return string 'aprovada' or 'pendente'
     */
    private function mapUranoStatusToSalasStatus(int $uranoStatus): string
    {
        // Map REQUISICAO.status to 'aprovada' or 'pendente'
        // Status 1 means approved
        return ($uranoStatus === 1) ? 'aprovada' : 'pendente';
    }

    /**
     * Verification phase: validate all Urano reservations before creation
     *
     * @param \Illuminate\Support\Collection $uranoReservations
     * @return array Verification result with validated reservations and statistics
     */
    private function verifyUranoReservations($uranoReservations): array
    {
        $this->info('🔍 Executando fase de verificação otimizada');
        
        $result = [
            'success' => false,
            'verified_reservations' => [],
            'validation_errors' => [],
            'room_mappings' => [],
            'statistics' => [
                'total_processed' => $uranoReservations->count(),
                'passed_validation' => 0,
                'failed_validation' => 0,
                'unmappable_rooms' => 0,
                'time_conflicts' => 0,
                'data_validation_errors' => 0,
                'api_validation_errors' => 0,
            ]
        ];

        try {
            // Step 1: Pre-load room mappings
            $this->info('  🔸 Pre-loading room mappings...');
            $roomMappings = $this->preloadRoomMappings($uranoReservations);
            $result['room_mappings'] = $roomMappings;

            // Step 2: Pre-fetch existing reservations for conflict checking (Optimization)
            $this->info('  🔸 Pre-fetching existing reservations for conflict checking...');
            $existingReservationsCache = $this->prefetchExistingReservations($uranoReservations, $roomMappings);

            // Step 3: Validate each reservation locally
            $this->info('  🔸 Validating individual reservations locally...');
            $progressBar = $this->output->createProgressBar($uranoReservations->count());
            $progressBar->setFormat('debug');

            foreach ($uranoReservations as $uranoReservation) {
                $roomName = $uranoReservation->sala_nome;
                $salaId = $roomMappings[$roomName]['mappable'] ? $roomMappings[$roomName]['sala_id'] : null;
                $data = $uranoReservation->data;
                $cacheKey = $salaId ? "{$salaId}_{$data}" : null;
                
                $existingReservationsForDay = $cacheKey ? ($existingReservationsCache[$cacheKey] ?? []) : [];

                $validationResult = $this->validateSingleUranoReservation($uranoReservation, $roomMappings, $existingReservationsForDay);
                
                if ($validationResult['valid']) {
                    $result['verified_reservations'][] = $validationResult['reservation'];
                    $result['statistics']['passed_validation']++;
                } else {
                    $result['validation_errors'] = array_merge($result['validation_errors'], $validationResult['errors']);
                    $result['statistics']['failed_validation']++;
                    
                    foreach ($validationResult['errors'] as $error) {
                        if (isset($result['statistics'][$error['type'] . 's'])) {
                            $result['statistics'][$error['type'] . 's']++;
                        }
                    }
                    
                    $this->logError('validation_halt', new Exception('Validation halted on first error'), ['policy_triggered' => true]);
                    break; // Halt on first error
                }
                $progressBar->advance();
            }

            $progressBar->finish();
            $this->newLine();

            $result['success'] = $result['statistics']['failed_validation'] === 0;
            $this->statistics['verification_phase'] = $result['statistics'];

            return $result;

        } catch (Exception $e) {
            $result['validation_errors'][] = ['type' => 'system_error', 'message' => $e->getMessage()];
            $this->logError('verification_phase_error', $e);
            return $result;
        }
    }

    /**
     * Pre-fetches all existing reservations from the API for the given set of Urano reservations.
     *
     * @param \Illuminate\Support\Collection $uranoReservations
     * @param array $roomMappings
     * @return array
     */
    private function prefetchExistingReservations($uranoReservations, array $roomMappings): array
    {
        $requestsToMake = [];
        foreach ($uranoReservations as $reservation) {
            $roomName = $reservation->sala_nome;
            if (isset($roomMappings[$roomName]) && $roomMappings[$roomName]['mappable']) {
                $salaId = $roomMappings[$roomName]['sala_id'];
                $data = $reservation->data;
                $cacheKey = "{$salaId}_{$data}";
                $requestsToMake[$cacheKey] = ['sala' => $salaId, 'data' => $data];
            }
        }

        $this->info("    (Found " . count($requestsToMake) . " unique room/day combinations to check)");
        $progressBar = $this->output->createProgressBar(count($requestsToMake));
        $progressBar->setFormat('debug');

        // Get rate limit configuration
        $rateLimitPerMinute = config('salas.rate_limiting.requests_per_minute', 30);
        $delayBetweenRequests = $this->calculateDelayBetweenRequests($rateLimitPerMinute);

        $cache = [];
        $requestCount = 0;
        foreach ($requestsToMake as $key => $params) {
            try {
                // Apply rate limiting delay before each request (except the first one)
                if ($requestCount > 0) {
                    usleep($delayBetweenRequests * 1000); // Convert milliseconds to microseconds
                }
                
                // Use the new protected API endpoint for better performance and higher rate limits
                $response = $this->apiClient->getReservationsByRoomAndDate(
                    $params['sala'], 
                    $params['data']
                );
                $cache[$key] = $response['data'] ?? [];
                $requestCount++;
            } catch (Exception $e) {
                $this->logError('prefetch_conflict_error', $e, $params);
                $cache[$key] = []; // Assume no reservations on error
                $requestCount++;
            }
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();

        return $cache;
    }

    /**
     * Pre-load room mappings for efficiency during verification
     *
     * @param \Illuminate\Support\Collection $uranoReservations
     * @return array Room mapping cache
     */
    private function preloadRoomMappings($uranoReservations): array
    {
        $uniqueRooms = $uranoReservations->pluck('sala_nome')->unique();
        $mappings = [];

        foreach ($uniqueRooms as $roomName) {
            try {
                $salaId = $this->mapper->getSalaIdFromNome($roomName);
                $mappings[$roomName] = [
                    'sala_id' => $salaId,
                    'mappable' => true,
                    'error' => null
                ];
            } catch (Exception $e) {
                $mappings[$roomName] = [
                    'sala_id' => null,
                    'mappable' => false,
                    'error' => $e->getMessage()
                ];
            }
        }

        $mappableCount = collect($mappings)->where('mappable', true)->count();
        $totalCount = count($mappings);
        $this->info("    ✅ Room mappings loaded: {$mappableCount}/{$totalCount} rooms mappable");

        return $mappings;
    }

    /**
     * Validate a single Urano reservation
     *
     * @param \stdClass $uranoReservation
     * @param array $roomMappings
     * @return array Validation result
     */
    private function validateSingleUranoReservation($uranoReservation, array $roomMappings, array $existingReservationsForDay): array
    {
        $result = [
            'valid' => false,
            'reservation' => null,
            'errors' => []
        ];

        try {
            // Data validation
            $dataValidation = $this->validateUranoReservationData($uranoReservation);
            if (!$dataValidation['valid']) {
                $result['errors'] = array_merge($result['errors'], $dataValidation['errors']);
                return $result;
            }

            // Room mapping validation
            $roomName = $uranoReservation->sala_nome;
            if (!isset($roomMappings[$roomName]) || !$roomMappings[$roomName]['mappable']) {
                $result['errors'][] = [
                    'type' => 'unmappable_room',
                    'message' => "Room '{$roomName}' cannot be mapped to Salas system",
                    'context' => [
                        'urano_room_name' => $roomName,
                        'urano_room_number' => $uranoReservation->sala_numero
                    ]
                ];
                return $result;
            }

            // Transform and prepare data
            $transformedData = $this->transformUranoReservation($uranoReservation);
            
            // Business rule validation (duplicates, conflicts)
            $businessValidation = $this->validateBusinessRules($transformedData, $roomMappings[$roomName]['sala_id'], $existingReservationsForDay);
            if (!$businessValidation['valid']) {
                $result['errors'] = array_merge($result['errors'], $businessValidation['errors']);
                return $result;
            }

            // Prepare API payload
            try {
                $apiPayload = $this->mapper->mapUranoDataToReservationPayload($transformedData);
                $result['reservation'] = [
                    'urano_data' => $transformedData,
                    'api_payload' => $apiPayload,
                    'sala_id' => $roomMappings[$roomName]['sala_id']
                ];
                $result['valid'] = true;

            } catch (Exception $e) {
                $result['errors'][] = [
                    'type' => 'api_validation',
                    'message' => 'Failed to prepare API payload: ' . $e->getMessage(),
                    'context' => [
                        'reserva_id' => $uranoReservation->reserva_id,
                        'titulo' => $uranoReservation->titulo
                    ]
                ];
            }

        } catch (Exception $e) {
            $result['errors'][] = [
                'type' => 'system_error',
                'message' => 'Unexpected error during validation: ' . $e->getMessage(),
                'context' => [
                    'reserva_id' => $uranoReservation->reserva_id ?? 'unknown'
                ]
            ];
        }

        return $result;
    }

    /**
     * Validate Urano reservation data format and required fields
     *
     * @param \stdClass $uranoReservation
     * @return array Validation result
     */
    private function validateUranoReservationData($uranoReservation): array
    {
        $errors = [];
        $requiredFields = [
            'reserva_id' => 'Reservation ID',
            'data' => 'Date',
            'hi' => 'Start time',
            'hf' => 'End time',
            'titulo' => 'Title',
            'solicitante' => 'Requester',
            'sala_numero' => 'Room number',
            'sala_nome' => 'Room name'
        ];

        // Check required fields
        foreach ($requiredFields as $field => $label) {
            if (!isset($uranoReservation->$field) || empty($uranoReservation->$field)) {
                $errors[] = [
                    'type' => 'data_validation',
                    'message' => "Missing or empty required field: {$label}",
                    'context' => ['field' => $field, 'reserva_id' => $uranoReservation->reserva_id ?? 'unknown']
                ];
            }
        }

        // Validate date format
        if (isset($uranoReservation->data)) {
            try {
                Carbon::parse($uranoReservation->data);
            } catch (Exception $e) {
                $errors[] = [
                    'type' => 'data_validation',
                    'message' => 'Invalid date format',
                    'context' => ['date' => $uranoReservation->data, 'reserva_id' => $uranoReservation->reserva_id ?? 'unknown']
                ];
            }
        }

        // Validate time sequence
        if (isset($uranoReservation->hi) && isset($uranoReservation->hf)) {
            try {
                $startTime = Carbon::createFromFormat('H:i:s', $uranoReservation->hi);
                $endTime = Carbon::createFromFormat('H:i:s', $uranoReservation->hf);
                
                if ($endTime <= $startTime) {
                    $errors[] = [
                        'type' => 'data_validation',
                        'message' => 'End time must be after start time',
                        'context' => [
                            'start_time' => $uranoReservation->hi,
                            'end_time' => $uranoReservation->hf,
                            'reserva_id' => $uranoReservation->reserva_id ?? 'unknown'
                        ]
                    ];
                }
            } catch (Exception $e) {
                $errors[] = [
                    'type' => 'data_validation',
                    'message' => 'Invalid time format',
                    'context' => [
                        'start_time' => $uranoReservation->hi,
                        'end_time' => $uranoReservation->hf,
                        'reserva_id' => $uranoReservation->reserva_id ?? 'unknown'
                    ]
                ];
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Validate business rules (duplicates, conflicts)
     *
     * @param array $transformedData
     * @param int $salaId
     * @return array Validation result
     */
    private function validateBusinessRules(array $transformedData, int $salaId, array $existingReservationsForDay): array
    {
        $errors = [];

        try {
            // Check for time conflicts using the pre-fetched data
            $conflicts = $this->checkReservationConflicts($transformedData, $existingReservationsForDay);
            if (!empty($conflicts)) {
                $errors[] = [
                    'type' => 'time_conflict',
                    'message' => 'Time conflict detected with existing reservations',
                    'context' => [
                        'conflicts' => $conflicts,
                        'requested_date' => $transformedData['data'],
                        'requested_time' => $transformedData['hora_inicio'] . '-' . $transformedData['hora_fim']
                    ]
                ];
            }

        } catch (Exception $e) {
            $this->logError('conflict_detection_error', $e, [
                'reserva_id' => $transformedData['reserva_id'],
                'sala_id' => $salaId
            ]);
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Check for reservation conflicts using a pre-fetched list of reservations for that day.
     *
     * @param array $transformedData
     * @param array $existingReservations
     * @return array Array of conflicting reservations
     */
    private function checkReservationConflicts(array $transformedData, array $existingReservations): array
    {
        $conflicts = [];
        $requestStart = $transformedData['hora_inicio'];
        $requestEnd = $transformedData['hora_fim'];

        foreach ($existingReservations as $reservation) {
            $existingStart = $reservation['horario_inicio'];
            $existingEnd = $reservation['horario_fim'];

            // Check for time overlap
            if (!($requestEnd <= $existingStart || $requestStart >= $existingEnd)) {
                $conflicts[] = [
                    'id' => $reservation['id'],
                    'nome' => $reservation['nome'],
                    'horario_inicio' => $existingStart,
                    'horario_fim' => $existingEnd
                ];
            }
        }

        return $conflicts;
    }

    /**
     * Display verification errors in a user-friendly format
     *
     * @param array $verificationResult
     */
    private function displayVerificationErrors(array $verificationResult): void
    {
        $this->error('❌ Verification phase failed');
        $this->warn('🛡️ All or Nothing Policy: Validation halted immediately on first error to prevent creation phase');
        $this->newLine();

        $stats = $verificationResult['statistics'];
        $this->warn("📊 Verification Statistics:");
        $this->line("  • Total reservations: {$stats['total_processed']}");
        $this->line("  • Passed validation: {$stats['passed_validation']}");
        $this->line("  • Failed validation: {$stats['failed_validation']}");
        
        if ($stats['unmappable_rooms'] > 0) {
            $this->line("  • Unmappable rooms: {$stats['unmappable_rooms']}");
        }
        if ($stats['time_conflicts'] > 0) {
            $this->line("  • Time conflicts: {$stats['time_conflicts']}");
        }
        if ($stats['data_validation_errors'] > 0) {
            $this->line("  • Data validation errors: {$stats['data_validation_errors']}");
        }

        $this->newLine();
        
        // Group errors by type for better readability
        $errorsByType = [];
        foreach ($verificationResult['validation_errors'] as $error) {
            $errorsByType[$error['type']][] = $error;
        }

        foreach ($errorsByType as $type => $errors) {
            $this->warn("🔸 " . ucfirst(str_replace('_', ' ', $type)) . " (" . count($errors) . " errors):");
            
            $displayCount = min(5, count($errors)); // Show max 5 examples per type
            for ($i = 0; $i < $displayCount; $i++) {
                $error = $errors[$i];
                $this->line("    • {$error['message']}");
            }
            
            if (count($errors) > 5) {
                $remaining = count($errors) - 5;
                $this->line("    • ... and {$remaining} more similar errors");
            }
            $this->newLine();
        }

        $this->error('💡 Please resolve the validation errors above before proceeding with the import.');
    }

    /**
     * Creation phase: create verified reservations in Salas system
     *
     * @param array $verifiedReservations Array of pre-validated reservations
     * @param array $roomMappings Cached room mappings
     * @return bool Success status
     */
    private function createVerifiedReservations(array $verifiedReservations, array $roomMappings): bool
    {
        if (empty($verifiedReservations)) {
            $this->warn('⚠️ No verified reservations to create');
            return true;
        }

        $totalReservations = count($verifiedReservations);
        $this->info("  🔸 Creating {$totalReservations} verified reservations...");

        $creationStats = [
            'total_to_create' => $totalReservations,
            'successfully_created' => 0,
            'creation_failures' => 0,
            'api_rate_limit_hits' => 0,
            'batch_errors' => 0
        ];
        
        $createdReservationIds = [];

        try {
            $batches = array_chunk($verifiedReservations, $this->batchSize);
            $totalBatches = count($batches);
            
            $progressBar = $this->output->createProgressBar($totalReservations);
            $progressBar->setFormat('debug');
            
            $currentBatch = 1;
            
            foreach ($batches as $batch) {
                //$this->info("\n🔄 Processing creation batch $currentBatch/$totalBatches (" . count($batch) . " reservations)");
                
                $batchResult = $this->createReservationBatch($batch, $createdReservationIds);
                
                $creationStats['successfully_created'] += $batchResult['created'];
                
                $this->statistics['successful_imports'] += $batchResult['created'];
                $this->statistics['created_reservations'] += $batchResult['created'];
                
                $progressBar->advance(count($batch));
                
                $currentBatch++;
                
                // No need for additional sleep here since rate limiting is now handled within createReservationBatch
            }
            
            $progressBar->finish();
            $this->newLine(2);
            
            $this->displayCreationSummary($creationStats);
            $this->statistics['creation_phase'] = $creationStats;
            
            $this->logError('creation_phase_completed', new Exception('Creation phase completed'), [
                'operation_id' => $this->operationId,
                'total_to_create' => $creationStats['total_to_create'],
                'successfully_created' => $creationStats['successfully_created'],
                'creation_failures' => 0, // Failures now trigger rollback
                'success_rate' => 100
            ]);
            
            return true;
            
        } catch (Exception $e) {
            $creationStats['creation_failures'] = $totalReservations - $creationStats['successfully_created'];
            $this->statistics['failed_imports'] += $creationStats['creation_failures'];

            $this->error("❌ Critical error during creation phase: {$e->getMessage()}");
            $this->logError('creation_phase_critical_error', $e, [
                'operation_id' => $this->operationId,
                'creation_stats' => $creationStats
            ]);

            $this->performRollback($createdReservationIds);

            return false;
        }
    }

    /**
     * Create a batch of verified reservations. Throws exception on failure.
     *
     * @param array $batch Array of verified reservation data
     * @param array &$createdReservationIds Array to store IDs of created reservations for rollback
     * @return array Batch creation results
     * @throws Exception
     */
    private function createReservationBatch(array $batch, array &$createdReservationIds): array
    {
        $result = ['created' => 0];
        
        // Get rate limit configuration
        $rateLimitPerMinute = config('salas.rate_limiting.requests_per_minute', 30);
        $delayBetweenRequests = $this->calculateDelayBetweenRequests($rateLimitPerMinute);

        $requestCount = 0;
        foreach ($batch as $verifiedReservation) {
            if ($this->isDryRun) {
                $result['created']++;
                $this->statistics['dry_run_validations']++;
                continue;
            }

            $uranoData = $verifiedReservation['urano_data'];
            
            try {
                // Apply rate limiting delay before each request (except the first one)
                if ($requestCount > 0) {
                    usleep($delayBetweenRequests * 1000); // Convert milliseconds to microseconds
                }
                
                $reservation = $this->reservationService->createReservationsFromUranoData($uranoData);
                
                if ($reservation && !empty($reservation[0]['id'])) {
                    $newId = $reservation[0]['id'];
                    $createdReservationIds[] = $newId;
                    $result['created']++;
                    
                    $this->logError('reservation_created_successfully', new Exception('Reservation created'), [
                        'operation_id' => $this->operationId,
                        'urano_reserva_id' => $uranoData['reserva_id'],
                        'salas_reservation_id' => $newId,
                        'titulo' => $uranoData['titulo'],
                    ]);
                } else {
                    throw new Exception('API returned empty or invalid response (missing ID)');
                }
                
                $requestCount++;
            } catch (Exception $e) {
                $this->logError('reservation_creation_failed', $e, [
                    'operation_id' => $this->operationId,
                    'urano_reserva_id' => $uranoData['reserva_id'] ?? 'unknown',
                    'error_message' => $e->getMessage()
                ]);
                // Re-throw to trigger rollback in the calling method
                throw $e;
            }
        }

        return $result;
    }

    /**
     * Rolls back created reservations.
     *
     * @param array $reservationIds
     */
    private function performRollback(array $reservationIds): void
    {
        if (empty($reservationIds)) {
            $this->info('No reservations to roll back.');
            return;
        }

        $this->warn('🔥 An error occurred. Starting automatic rollback of ' . count($reservationIds) . ' created reservations...');

        $progressBar = $this->output->createProgressBar(count($reservationIds));
        $progressBar->setFormat('debug');

        $successCount = 0;
        $failCount = 0;

        foreach ($reservationIds as $id) {
            try {
                // Using purge=true to delete the whole series if it's a recurrent reservation
                $this->apiClient->delete("/api/v1/reservas/{$id}?purge=true");
                $this->logError('rollback_success', new Exception("Reservation {$id} deleted"), [
                    'operation_id' => $this->operationId,
                    'reservation_id' => $id,
                ]);
                $successCount++;
            } catch (Exception $e) {
                $this->logError('rollback_failed', $e, [
                    'operation_id' => $this->operationId,
                    'reservation_id' => $id,
                ]);
                $failCount++;
            }
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info("✅ Rollback completed.");
        $this->info("  • Successfully deleted: {$successCount}");
        if ($failCount > 0) {
            $this->error("  • Failed to delete: {$failCount}. Please check logs for details.");
        }
    }

    /**
     * Display creation phase summary
     *
     * @param array $creationStats Creation statistics
     */
    private function displayCreationSummary(array $creationStats): void
    {
        $this->info('📊 Creation Phase Summary:');
        $this->line("  • Total to create: {$creationStats['total_to_create']}");
        $this->line("  • Successfully created: {$creationStats['successfully_created']}");
        
        if ($creationStats['creation_failures'] > 0) {
            $this->line("  • Creation failures: {$creationStats['creation_failures']}");
        }
        
        if ($creationStats['api_rate_limit_hits'] > 0) {
            $this->line("  • Rate limit hits: {$creationStats['api_rate_limit_hits']}");
        }
        
        if ($creationStats['batch_errors'] > 0) {
            $this->line("  • Batch errors: {$creationStats['batch_errors']}");
        }

        // Calculate and display success rate
        if ($creationStats['total_to_create'] > 0) {
            $successRate = ($creationStats['successfully_created'] / $creationStats['total_to_create']) * 100;
            $this->line("  • Success rate: " . number_format($successRate, 1) . "%");
            
            if ($successRate >= 80) {
                $this->info("✅ Creation phase completed successfully");
            } else {
                $this->warn("⚠️ Creation phase completed with some failures");
            }
        }
        
        $this->newLine();
    }

    /**
     * Process a batch of Urano reservations (LEGACY - kept for backward compatibility)
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
            'requisicao_id' => $uranoReservation->requisicao_id,
            'tipo_atividade' => $uranoReservation->atividade ?? null
        ];
    }

    /**
     * Generate final import report with two-phase statistics
     */
    private function generateFinalReport(): void
    {
        $this->info('📈 Two-Phase Import Statistics:');
        
        // Overall statistics
        $overallStats = [
            ['Metric', 'Value'],
            ['Processed Reservations', $this->statistics['processed_reservations']],
            ['Successful Imports', $this->statistics['successful_imports']],
            ['Failed Imports', $this->statistics['failed_imports']],
            ['Created Reservations', $this->statistics['created_reservations']],
            ['Overall Success Rate', $this->calculateSuccessRate() . '%'],
            ['Operation Mode', $this->isDryRun ? 'DRY-RUN' : 'PRODUCTION']
        ];
        
        $this->table(['Metric', 'Value'], $overallStats);
        
        // Verification Phase Details
        if (isset($this->statistics['verification_phase'])) {
            $verificationStats = $this->statistics['verification_phase'];
            $this->newLine();
            $this->info('🔍 Verification Phase Details:');
            
            $verificationTable = [
                ['Total Processed', $verificationStats['total_processed']],
                ['Passed Validation', $verificationStats['passed_validation']],
                ['Failed Validation', $verificationStats['failed_validation']]
            ];
            
            if ($verificationStats['unmappable_rooms'] > 0) {
                $verificationTable[] = ['Unmappable Rooms', $verificationStats['unmappable_rooms']];
            }
            if ($verificationStats['time_conflicts'] > 0) {
                $verificationTable[] = ['Time Conflicts', $verificationStats['time_conflicts']];
            }
            if ($verificationStats['data_validation_errors'] > 0) {
                $verificationTable[] = ['Data Validation Errors', $verificationStats['data_validation_errors']];
            }
            if (isset($verificationStats['api_validation_errors']) && $verificationStats['api_validation_errors'] > 0) {
                $verificationTable[] = ['API Validation Errors', $verificationStats['api_validation_errors']];
            }
            
            $this->table(['Verification Metric', 'Count'], $verificationTable);
        }
        
        // Creation Phase Details
        if (isset($this->statistics['creation_phase'])) {
            $creationStats = $this->statistics['creation_phase'];
            if ($creationStats['total_to_create'] > 0) {
                $this->newLine();
                $this->info('⚡ Creation Phase Details:');
                
                $creationTable = [
                    ['Total to Create', $creationStats['total_to_create']],
                    ['Successfully Created', $creationStats['successfully_created']],
                    ['Creation Failures', $creationStats['creation_failures']]
                ];
                
                if ($creationStats['api_rate_limit_hits'] > 0) {
                    $creationTable[] = ['Rate Limit Hits', $creationStats['api_rate_limit_hits']];
                }
                if ($creationStats['batch_errors'] > 0) {
                    $creationTable[] = ['Batch Errors', $creationStats['batch_errors']];
                }
                
                // Calculate creation success rate
                if ($creationStats['total_to_create'] > 0) {
                    $creationSuccessRate = ($creationStats['successfully_created'] / $creationStats['total_to_create']) * 100;
                    $creationTable[] = ['Creation Success Rate', number_format($creationSuccessRate, 1) . '%'];
                }
                
                $this->table(['Creation Metric', 'Count'], $creationTable);
            }
        }

        // Recommendations
        $recommendations = $this->generateRecommendations();
        if (!empty($recommendations)) {
            $this->newLine();
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
     * Initialize statistics tracking with two-phase support
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
            'dry_run_validations' => 0,
            // New two-phase statistics
            'verification_phase' => [
                'total_processed' => 0,
                'passed_validation' => 0,
                'failed_validation' => 0,
                'unmappable_rooms' => 0,
                'time_conflicts' => 0,
                'data_validation_errors' => 0,
                'api_validation_errors' => 0
            ],
            'creation_phase' => [
                'total_to_create' => 0,
                'successfully_created' => 0,
                'creation_failures' => 0,
                'api_rate_limit_hits' => 0,
                'batch_errors' => 0
            ]
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
     * Calculate delay between requests to respect rate limit
     *
     * @param int $rateLimitPerMinute Maximum requests per minute
     * @return int Delay in milliseconds
     */
    private function calculateDelayBetweenRequests(int $rateLimitPerMinute): int
    {
        if ($rateLimitPerMinute <= 0) {
            return 0; // No rate limiting
        }
        
        // Convert to milliseconds and add 10% buffer for safety
        $delayMs = (60 * 1000) / $rateLimitPerMinute;
        $bufferMs = $delayMs * 0.1;
        
        return (int) ceil($delayMs + $bufferMs);
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

        Log::error("Urano Import - $errorType", $logContext);
    }
}
