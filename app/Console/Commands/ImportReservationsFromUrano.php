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

            // Step 4: Two-Phase Processing (AC2: Verification → Creation)
            $this->info('🔄 Step 3: Two-Phase Processing (Verification → Creation)');
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
     * Process Urano reservations using two-phase logic (AC2)
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
     * Verification phase: validate all Urano reservations before creation (AC2)
     *
     * @param \Illuminate\Support\Collection $uranoReservations
     * @return array Verification result with validated reservations and statistics
     */
    private function verifyUranoReservations($uranoReservations): array
    {
        $result = [
            'success' => false,
            'verified_reservations' => [],
            'validation_errors' => [],
            'api_ready' => false,
            'room_mappings' => [],
            'statistics' => [
                'total_processed' => $uranoReservations->count(),
                'passed_validation' => 0,
                'failed_validation' => 0,
                'unmappable_rooms' => 0,
                'time_conflicts' => 0,
                'data_validation_errors' => 0,
                'api_validation_errors' => 0
            ]
        ];

        try {
            // Step 1: API Readiness Validation
            $this->info('  🔸 Checking API connectivity and authentication...');
            if (!$this->reservationService->checkApiHealth()) {
                $result['validation_errors'][] = [
                    'type' => 'api_connectivity',
                    'message' => 'Salas API is not accessible or authentication failed',
                    'severity' => 'critical'
                ];
                return $result;
            }
            $result['api_ready'] = true;
            $this->info('    ✅ API connectivity verified');

            // Step 2: Pre-load room mappings for efficiency
            $this->info('  🔸 Pre-loading room mappings...');
            $roomMappings = $this->preloadRoomMappings($uranoReservations);
            $result['room_mappings'] = $roomMappings;

            // Step 3: Validate each reservation
            $this->info('  🔸 Validating individual reservations...');
            $progressBar = $this->output->createProgressBar($uranoReservations->count());
            $progressBar->setFormat('debug');

            foreach ($uranoReservations as $uranoReservation) {
                $validationResult = $this->validateSingleUranoReservation($uranoReservation, $roomMappings);
                
                if ($validationResult['valid']) {
                    $result['verified_reservations'][] = $validationResult['reservation'];
                    $result['statistics']['passed_validation']++;
                } else {
                    $result['validation_errors'] = array_merge($result['validation_errors'], $validationResult['errors']);
                    $result['statistics']['failed_validation']++;
                    
                    // Update specific error counters
                    foreach ($validationResult['errors'] as $error) {
                        switch ($error['type']) {
                            case 'unmappable_room':
                                $result['statistics']['unmappable_rooms']++;
                                break;
                            case 'time_conflict':
                                $result['statistics']['time_conflicts']++;
                                break;
                            case 'data_validation':
                                $result['statistics']['data_validation_errors']++;
                                break;
                            case 'api_validation':
                                $result['statistics']['api_validation_errors']++;
                                break;
                        }
                    }
                }
                $progressBar->advance();
            }

            $progressBar->finish();
            $this->newLine();

            // Step 4: Determine overall success
            $result['success'] = $result['statistics']['failed_validation'] === 0;

            // Update main statistics with verification results
            $this->statistics['verification_phase'] = $result['statistics'];
            $this->statistics['processed_reservations'] = $result['statistics']['total_processed'];

            $this->logError('verification_phase_completed', new Exception('Verification phase completed'), [
                'operation_id' => $this->operationId,
                'total_reservations' => $result['statistics']['total_processed'],
                'passed_validation' => $result['statistics']['passed_validation'],
                'failed_validation' => $result['statistics']['failed_validation'],
                'success' => $result['success']
            ]);

            return $result;

        } catch (Exception $e) {
            $result['validation_errors'][] = [
                'type' => 'system_error',
                'message' => 'Critical error during verification: ' . $e->getMessage(),
                'severity' => 'critical'
            ];

            $this->logError('verification_phase_error', $e);
            return $result;
        }
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
    private function validateSingleUranoReservation($uranoReservation, array $roomMappings): array
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
            $businessValidation = $this->validateBusinessRules($transformedData, $roomMappings[$roomName]['sala_id']);
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
    private function validateBusinessRules(array $transformedData, int $salaId): array
    {
        $errors = [];

        try {
            // Check for time conflicts using existing Salas API integration
            $conflicts = $this->checkReservationConflicts($salaId, $transformedData);
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
            // Log but don't fail - conflict detection is best effort
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
     * Check for reservation conflicts using Salas API
     *
     * @param int $salaId
     * @param array $transformedData
     * @return array Array of conflicting reservations
     */
    private function checkReservationConflicts(int $salaId, array $transformedData): array
    {
        try {
            $params = [
                'sala' => $salaId,
                'data' => $transformedData['data']
            ];

            $response = $this->apiClient->get('/api/v1/reservas', $params);
            $existingReservations = $response['data'] ?? [];

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

        } catch (Exception $e) {
            // Return empty array on error - conflict detection is best effort
            return [];
        }
    }

    /**
     * Display verification errors in a user-friendly format
     *
     * @param array $verificationResult
     */
    private function displayVerificationErrors(array $verificationResult): void
    {
        $this->error('❌ Verification phase failed');
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
     * Creation phase: create verified reservations in Salas system (AC2)
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

        // Initialize creation statistics
        $creationStats = [
            'total_to_create' => $totalReservations,
            'successfully_created' => 0,
            'creation_failures' => 0,
            'api_rate_limit_hits' => 0,
            'batch_errors' => 0
        ];

        try {
            // Process in batches respecting rate limits (30/min for reservations endpoint)
            $batches = array_chunk($verifiedReservations, $this->batchSize);
            $totalBatches = count($batches);
            
            $progressBar = $this->output->createProgressBar($totalReservations);
            $progressBar->setFormat('debug');
            
            $currentBatch = 1;
            
            foreach ($batches as $batch) {
                $this->info("\n🔄 Processing creation batch $currentBatch/$totalBatches (" . count($batch) . " reservations)");
                
                try {
                    $batchResult = $this->createReservationBatch($batch);
                    
                    $creationStats['successfully_created'] += $batchResult['created'];
                    $creationStats['creation_failures'] += $batchResult['failed'];
                    $creationStats['api_rate_limit_hits'] += $batchResult['rate_limited'];
                    
                    // Update overall statistics
                    $this->statistics['successful_imports'] += $batchResult['created'];
                    $this->statistics['failed_imports'] += $batchResult['failed'];
                    $this->statistics['created_reservations'] += $batchResult['created'];
                    
                    // Advance progress bar
                    $progressBar->advance(count($batch));
                    
                    $currentBatch++;
                    
                    // Rate limiting compliance: brief pause between batches
                    if ($currentBatch <= $totalBatches) {
                        sleep(2); // 2 second pause to stay under 30/min limit
                    }
                    
                } catch (Exception $e) {
                    $this->error("\n❌ Error in creation batch $currentBatch: {$e->getMessage()}");
                    $creationStats['batch_errors']++;
                    
                    // Log the batch error with context
                    $this->logError('creation_batch_error', $e, [
                        'batch_number' => $currentBatch,
                        'batch_size' => count($batch),
                        'operation_id' => $this->operationId
                    ]);
                    
                    if ($creationStats['batch_errors'] >= 3) {
                        $this->error('❌ Too many creation batch errors. Aborting remaining import.');
                        break;
                    }
                    
                    $this->warn('⚠️ Continuing with next batch...');
                    $currentBatch++;
                }
            }
            
            $progressBar->finish();
            $this->newLine(2);
            
            // Display creation summary
            $this->displayCreationSummary($creationStats);
            
            // Update main statistics with creation results
            $this->statistics['creation_phase'] = $creationStats;
            
            // Log creation phase completion
            $this->logError('creation_phase_completed', new Exception('Creation phase completed'), [
                'operation_id' => $this->operationId,
                'total_to_create' => $creationStats['total_to_create'],
                'successfully_created' => $creationStats['successfully_created'],
                'creation_failures' => $creationStats['creation_failures'],
                'batch_errors' => $creationStats['batch_errors'],
                'success_rate' => $creationStats['total_to_create'] > 0 ? 
                    ($creationStats['successfully_created'] / $creationStats['total_to_create']) * 100 : 0
            ]);
            
            // Consider operation successful if most reservations were created
            $successThreshold = 0.8; // 80% success rate
            $actualSuccessRate = $creationStats['total_to_create'] > 0 ? 
                ($creationStats['successfully_created'] / $creationStats['total_to_create']) : 0;
                
            return $actualSuccessRate >= $successThreshold;
            
        } catch (Exception $e) {
            $this->error("❌ Critical error during creation phase: {$e->getMessage()}");
            $this->logError('creation_phase_critical_error', $e, [
                'operation_id' => $this->operationId,
                'creation_stats' => $creationStats
            ]);
            return false;
        }
    }

    /**
     * Create a batch of verified reservations
     *
     * @param array $batch Array of verified reservation data
     * @return array Batch creation results
     */
    private function createReservationBatch(array $batch): array
    {
        $result = [
            'created' => 0,
            'failed' => 0,
            'rate_limited' => 0
        ];

        foreach ($batch as $verifiedReservation) {
            try {
                if ($this->isDryRun) {
                    // Simulate creation in dry-run mode
                    $result['created']++;
                    $this->statistics['dry_run_validations']++;
                    continue;
                }

                $uranoData = $verifiedReservation['urano_data'];
                
                // Use existing service with pre-validated data
                $reservation = $this->reservationService->createReservationsFromUranoData($uranoData);
                
                if ($reservation && !empty($reservation)) {
                    $result['created']++;
                    
                    // Log successful creation
                    $this->logError('reservation_created_successfully', new Exception('Reservation created'), [
                        'operation_id' => $this->operationId,
                        'urano_reserva_id' => $uranoData['reserva_id'],
                        'salas_reservation_id' => $reservation[0]['id'] ?? null,
                        'titulo' => $uranoData['titulo'],
                        'data' => $uranoData['data'],
                        'sala_numero' => $uranoData['sala_numero']
                    ]);
                } else {
                    throw new Exception('API returned empty or invalid response');
                }
                
            } catch (Exception $e) {
                $result['failed']++;
                
                // Check if this is a rate limiting error
                if (str_contains($e->getMessage(), 'Too Many Attempts') || 
                    str_contains($e->getMessage(), '429') ||
                    str_contains($e->getMessage(), 'Rate limit')) {
                    $result['rate_limited']++;
                    
                    // Wait longer on rate limit
                    $this->warn('⏳ Rate limit hit, waiting 60 seconds...');
                    sleep(60);
                }
                
                $this->logError('reservation_creation_failed', $e, [
                    'operation_id' => $this->operationId,
                    'urano_reserva_id' => $verifiedReservation['urano_data']['reserva_id'] ?? 'unknown',
                    'urano_requisicao_id' => $verifiedReservation['urano_data']['requisicao_id'] ?? 'unknown',
                    'titulo' => $verifiedReservation['urano_data']['titulo'] ?? 'unknown',
                    'sala_numero' => $verifiedReservation['urano_data']['sala_numero'] ?? 'unknown',
                    'error_message' => $e->getMessage()
                ]);
            }
        }

        return $result;
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
            'requisicao_id' => $uranoReservation->requisicao_id
        ];
    }

    /**
     * Generate final import report with two-phase statistics (AC2)
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
            if ($verificationStats['api_validation_errors'] > 0) {
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
     * Initialize statistics tracking with two-phase support (AC2)
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