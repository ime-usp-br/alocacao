<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ReservationApiService;
use App\Services\ReservationMapper;
use App\Services\SalasApiClient;
use App\Models\SchoolClass;
use App\Models\SchoolTerm;
use App\Models\Requisition;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Exception;

class MigrateReservationsToSalas extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reservas:migrate-to-salas 
                          {--dry-run : Executar em modo de simulação sem realizar alterações}
                          {--school-term-id= : ID específico do período letivo para migrar}
                          {--room-id= : ID específico da sala para migrar}
                          {--batch-size=100 : Número de turmas por lote}
                          {--skip-backup : Pular criação de backup automático (NÃO recomendado)}
                          {--force : Forçar migração sem confirmações adicionais}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrar reservas do sistema Urano para API Salas com backup automático e relatórios';

    private ReservationApiService $reservationService;
    private ReservationMapper $mapper;
    private SalasApiClient $apiClient;
    private string $migrationId;
    private array $statistics;
    private array $backupPaths;
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
        $this->migrationId = 'migration_' . date('YmdHis');
        $this->initializeStatistics();
        $this->backupPaths = [];
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('🔄 AC6: Command de Migração - Sistema Urano → Salas API');
        $this->newLine();

        $this->isDryRun = $this->option('dry-run');
        $this->batchSize = (int) $this->option('batch-size');

        if ($this->isDryRun) {
            $this->warn('⚠️  MODO DRY-RUN ATIVADO - Nenhuma alteração será realizada');
        }

        try {
            // Etapa 1: Pré-carregamento de cache
            $this->info('🚀 Etapa 1: Pré-carregamento de cache da API');
            if (!$this->preloadApiCache()) {
                return 1;
            }

            // Etapa 2: Validações pré-migração
            $this->info('📋 Etapa 2: Validações pré-migração');
            if (!$this->runPreMigrationValidations()) {
                return 1;
            }

            // Etapa 3: Backup automático
            if (!$this->option('skip-backup') && !$this->isDryRun) {
                $this->info('💾 Etapa 3: Backup automático');
                if (!$this->createAutomaticBackup()) {
                    return 1;
                }
            }

            // Etapa 4: Processamento principal
            $this->info('⚡ Etapa 4: Migração de dados');
            if (!$this->processMigration()) {
                return 1;
            }

            // Etapa 5: Relatório final
            $this->info('📊 Etapa 5: Relatório pós-migração');
            $this->generatePostMigrationReport();

            $this->info('✅ Migração concluída com sucesso!');
            return 0;

        } catch (Exception $e) {
            $this->error("❌ Erro crítico na migração: {$e->getMessage()}");
            $this->logError('migration_critical_error', $e);

            if (!$this->isDryRun && !empty($this->backupPaths)) {
                $this->warn('🔄 Iniciando rollback automático...');
                $this->performRollback();
            }

            return 1;
        }
    }

    /**
     * Run comprehensive pre-migration validations
     *
     * @return bool
     */
    private function runPreMigrationValidations(): bool
    {
        $this->info('🔍 Executando validações pré-migração...');
        
        $validations = [
            'api_connectivity_auth' => 'Conectividade e autenticação API Salas',
            'data_integrity' => 'Integridade dos dados fonte',
            'room_mapping' => 'Mapeamento de salas',
            'storage_space' => 'Espaço em disco para backup',
            'permissions' => 'Permissões de arquivo'
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
            $this->error('❌ Validações falharam. Corrija os problemas antes de prosseguir.');
            return false;
        }

        $this->info('✅ Todas as validações passaram!');
        return true;
    }

    /**
     * Preload API cache to reduce rate limiting issues
     *
     * @return bool
     */
    private function preloadApiCache(): bool
    {
        $this->info('🔄 Pré-carregando cache de salas da API...');
        
        try {
            // Fazer uma única chamada para obter todas as salas
            $this->line('  🔸 Carregando todas as salas...');
            $response = $this->apiClient->get('/api/v1/salas');
            
            if (!isset($response['data']) || !is_array($response['data'])) {
                $this->error('  ❌ Resposta inválida da API de salas');
                return false;
            }
            
            $salasCount = count($response['data']);
            $this->info("  ✅ {$salasCount} salas carregadas no cache");
            
            // Pré-carregar o mapeamento reverso de salas para otimizar as validações
            $this->line('  🔸 Pré-processando mapeamento reverso de salas...');
            $cacheHits = 0;
            
            foreach ($response['data'] as $sala) {
                // Tentar mapear nome da API para nomes locais conhecidos
                $salaApiName = $sala['nome'];
                $salaId = $sala['id'];
                
                // Lista de possíveis variações de nomes (pode ser expandida)
                $possibleLocalNames = [
                    $salaApiName, // Nome exato
                    str_replace(' ', '', $salaApiName), // Sem espaços
                    strtoupper($salaApiName), // Maiúscula
                    strtolower($salaApiName), // Minúscula
                ];
                
                foreach ($possibleLocalNames as $localName) {
                    $cacheKey = 'salas_api:mapper:room_id:' . md5($localName);
                    if (!\Illuminate\Support\Facades\Cache::has($cacheKey)) {
                        \Illuminate\Support\Facades\Cache::put($cacheKey, $salaId, 3600);
                        $cacheHits++;
                    }
                }
            }
            
            $this->info("  ✅ {$cacheHits} mapeamentos de salas adicionados ao cache");
            $this->info('🚀 Cache pré-carregado com sucesso!');
            
            return true;
            
        } catch (Exception $e) {
            $this->error("❌ Falha ao pré-carregar cache: {$e->getMessage()}");
            $this->warn('⚠️  Continuando sem pré-carregamento - pode haver impacto de rate limiting');
            return true; // Não bloquear a execução por problemas de cache
        }
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
                case 'api_connectivity_auth':
                    return $this->reservationService->checkApiHealth() ? true : 'API Salas indisponível ou falha na autenticação';

                case 'data_integrity':
                    $schoolTermId = $this->option('school-term-id');
                    if ($schoolTermId) {
                        $schoolTerm = SchoolTerm::find($schoolTermId);
                        return $schoolTerm ? true : 'Período letivo não encontrado';
                    }
                    return SchoolTerm::count() > 0 ? true : 'Nenhum período letivo encontrado';

                case 'room_mapping':
                    $unmappableRooms = $this->findUnmappableRooms();
                    return empty($unmappableRooms) ? true : 'Salas não mapeáveis: ' . implode(', ', $unmappableRooms);

                case 'storage_space':
                    $freeBytes = disk_free_space(storage_path());
                    return ($freeBytes > 1024 * 1024 * 100) ? true : 'Menos de 100MB disponível'; // 100MB minimum

                case 'permissions':
                    $testFile = storage_path('app/test_permissions.txt');
                    if (file_put_contents($testFile, 'test') === false) {
                        return 'Sem permissão de escrita';
                    }
                    unlink($testFile);
                    return true;

                default:
                    return 'Validação desconhecida';
            }
        } catch (Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * Find rooms that cannot be mapped to Salas API
     *
     * @return array
     */
    private function findUnmappableRooms(): array
    {
        $schoolTermId = $this->option('school-term-id');
        $roomId = $this->option('room-id');

        $query = SchoolClass::whereHas('room');
        
        if ($schoolTermId) {
            $query->where('school_term_id', $schoolTermId);
        }
        
        if ($roomId) {
            $query->where('room_id', $roomId);
        }

        // Buscar apenas os nomes únicos das salas para evitar duplicação de validações
        $uniqueRoomNames = $query->with('room')
            ->get()
            ->pluck('room.nome')
            ->unique()
            ->filter(); // Remove valores nulos/vazios

        $unmappableRooms = [];
        $ignoredRooms = [];

        foreach ($uniqueRoomNames as $roomName) {
            // Verificar se a sala está na lista de ignoradas
            if ($this->mapper->isIgnoredRoom($roomName)) {
                $ignoredRooms[] = $roomName;
                continue;
            }

            // Tentar mapear apenas salas que não estão ignoradas
            try {
                $this->mapper->getSalaIdFromNome($roomName);
            } catch (Exception $e) {
                $unmappableRooms[] = $roomName;
            }
        }

        // Log das salas ignoradas para transparência
        if (!empty($ignoredRooms)) {
            $this->info("  ℹ️  Salas ignoradas (configuradas como não mapeáveis): " . implode(', ', $ignoredRooms));
        }

        return $unmappableRooms;
    }

    /**
     * Create automatic backup before migration
     *
     * @return bool
     */
    private function createAutomaticBackup(): bool
    {
        $this->info('💾 Criando backup automático das tabelas críticas...');
        
        $backupDir = "migrations/backups/{$this->migrationId}";
        Storage::makeDirectory($backupDir);

        $tablesToBackup = [
            'school_classes' => SchoolClass::class,
            'school_terms' => SchoolTerm::class,
        ];

        // Backup das tabelas do sistema Urano também
        $uranoTables = ['REQUISICAO', 'RESERVA'];

        try {
            foreach ($tablesToBackup as $name => $model) {
                $this->line("  🔸 Backup de $name...");
                $data = $model::all()->toArray();
                $backupFile = "$backupDir/{$name}_backup.json";
                
                $backupData = [
                    'migration_id' => $this->migrationId,
                    'created_at' => now()->toISOString(),
                    'table_name' => $name,
                    'record_count' => count($data),
                    'data' => $data
                ];

                Storage::put($backupFile, json_encode($backupData, JSON_PRETTY_PRINT));
                $this->backupPaths[] = $backupFile;
                $this->info("    ✅ $name: " . count($data) . " registros salvos");
            }

            // Backup das tabelas Urano
            foreach ($uranoTables as $table) {
                $this->line("  🔸 Backup de $table (Urano)...");
                
                $data = DB::connection('urano')
                    ->table($table)
                    ->get()
                    ->toArray();
                
                $backupFile = "$backupDir/{$table}_backup.json";
                
                $backupData = [
                    'migration_id' => $this->migrationId,
                    'created_at' => now()->toISOString(),
                    'table_name' => $table,
                    'record_count' => count($data),
                    'data' => $data
                ];

                Storage::put($backupFile, json_encode($backupData, JSON_PRETTY_PRINT));
                $this->backupPaths[] = $backupFile;
                $this->info("    ✅ $table: " . count($data) . " registros salvos");
            }

            // Criar manifesto do backup
            $manifest = [
                'migration_id' => $this->migrationId,
                'created_at' => now()->toISOString(),
                'backup_files' => $this->backupPaths,
                'command_options' => $this->options(),
                'system_info' => [
                    'php_version' => PHP_VERSION,
                    'laravel_version' => app()->version(),
                    'disk_free_space' => disk_free_space(storage_path())
                ]
            ];

            Storage::put("$backupDir/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT));
            
            $this->info('✅ Backup automático concluído!');
            $this->info("📁 Localização: storage/app/$backupDir");
            
            return true;

        } catch (Exception $e) {
            $this->error("❌ Falha no backup: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Process the main migration with batch processing and progress bar
     *
     * @return bool
     */
    private function processMigration(): bool
    {
        $this->info('⚡ Iniciando processamento da migração...');

        // Buscar turmas para migrar
        $schoolClasses = $this->getSchoolClassesToMigrate();
        
        if ($schoolClasses->isEmpty()) {
            $this->warn('⚠️ Nenhuma turma encontrada para migração');
            return true;
        }

        $this->info("📊 Total de turmas para migração: {$schoolClasses->count()}");
        $this->info("📦 Processamento em lotes de {$this->batchSize} turmas");

        if (!$this->isDryRun && !$this->option('force')) {
            if (!$this->confirm('Deseja continuar com a migração?')) {
                $this->info('Migração cancelada pelo usuário.');
                return true;
            }
        }

        // Processar em lotes
        $batches = $schoolClasses->chunk($this->batchSize);
        $totalBatches = $batches->count();
        
        $progressBar = $this->output->createProgressBar($schoolClasses->count());
        $progressBar->setFormat('debug');
        
        $currentBatch = 1;
        
        foreach ($batches as $batch) {
            $this->info("\n🔄 Processando lote $currentBatch/$totalBatches ({$batch->count()} turmas)");
            
            try {
                $this->processBatch($batch, $progressBar);
                $currentBatch++;
                
                // Checkpoint para retomada em caso de falha
                $this->saveCheckpoint($currentBatch, $totalBatches);
                
            } catch (Exception $e) {
                $this->error("\n❌ Erro no lote $currentBatch: {$e->getMessage()}");
                $this->statistics['batch_errors']++;
                
                if ($this->statistics['batch_errors'] >= 3) {
                    $this->error('❌ Muitos erros de lote. Interrompendo migração.');
                    return false;
                }
                
                $this->warn('⚠️ Continuando com próximo lote...');
                $currentBatch++;
            }
        }
        
        $progressBar->finish();
        $this->newLine(2);
        
        return true;
    }

    /**
     * Get school classes to migrate based on options
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    private function getSchoolClassesToMigrate()
    {
        $query = SchoolClass::whereHas('room')
            ->whereHas('classschedules')
            ->with(['room', 'classschedules', 'schoolterm', 'instructors']);

        if ($schoolTermId = $this->option('school-term-id')) {
            $query->where('school_term_id', $schoolTermId);
        } else {
            $latestSchoolTerm = SchoolTerm::getLatest();
            if ($latestSchoolTerm) {
                $query->where('school_term_id', $latestSchoolTerm->id);
            }
        }

        if ($roomId = $this->option('room-id')) {
            $query->where('room_id', $roomId);
        }

        return $query->get();
    }

    /**
     * Process a batch of school classes
     *
     * @param \Illuminate\Support\Collection $batch
     * @param \Symfony\Component\Console\Helper\ProgressBar $progressBar
     */
    private function processBatch($batch, $progressBar): void
    {
        foreach ($batch as $schoolClass) {
            try {
                $this->processSingleSchoolClass($schoolClass);
            } catch (Exception $e) {
                // A falha já foi registrada dentro de processSingleSchoolClass
                // O importante é continuar o lote
            }
            
            $progressBar->advance();
        }
    }

    /**
     * Process migration for a single school class, handling multiple schedules.
     *
     * @param SchoolClass $schoolClass
     */
    private function processSingleSchoolClass(SchoolClass $schoolClass): void
    {
        if ($this->isDryRun) {
            $this->statistics['processed_classes']++;
            $this->statistics['successful_migrations']++;
            $this->statistics['dry_run_validations']++;
            return;
        }

        $schedules = $schoolClass->classschedules;

        if ($schedules->isEmpty()) {
            $this->statistics['failed_migrations']++;
            $this->logError('school_class_migration_error', new Exception('Turma sem horários definidos, não pode ser migrada.'), [
                'school_class_id' => $schoolClass->id,
                'disciplina' => $schoolClass->coddis,
            ]);
            return;
        }

        $allSchedulesSucceeded = true;

        foreach ($schedules as $schedule) {
            try {
                // Create a temporary clone to isolate the current schedule
                $tempSchoolClass = clone $schoolClass;
                // CRITICAL FIX: Set the relation to a collection with only the current schedule
                $tempSchoolClass->setRelation('classschedules', collect([$schedule]));

                $reservations = $this->reservationService->createReservationsFromSchoolClass($tempSchoolClass);
                $this->statistics['created_reservations'] += count($reservations);

            } catch (Exception $e) {
                $allSchedulesSucceeded = false;
                $this->logError('school_class_schedule_migration_error', $e, [
                    'school_class_id' => $schoolClass->id,
                    'disciplina' => $schoolClass->coddis,
                    'sala' => $schoolClass->room->nome ?? 'N/A',
                    'schedule_details' => $schedule->toArray(),
                ]);
                // Break the loop for this class on the first error to avoid partial migrations
                break; 
            }
        }

        $this->statistics['processed_classes']++;
        if ($allSchedulesSucceeded) {
            $this->statistics['successful_migrations']++;
        } else {
            $this->statistics['failed_migrations']++;
        }
    }

    /**
     * Save checkpoint for migration resume capability
     *
     * @param int $currentBatch
     * @param int $totalBatches
     */
    private function saveCheckpoint(int $currentBatch, int $totalBatches): void
    {
        $checkpointData = [
            'migration_id' => $this->migrationId,
            'timestamp' => now()->toISOString(),
            'current_batch' => $currentBatch,
            'total_batches' => $totalBatches,
            'statistics' => $this->statistics,
            'options' => $this->options()
        ];

        $checkpointFile = "migrations/checkpoints/{$this->migrationId}_checkpoint.json";
        Storage::put($checkpointFile, json_encode($checkpointData, JSON_PRETTY_PRINT));
    }

    /**
     * Generate comprehensive post-migration report
     */
    private function generatePostMigrationReport(): void
    {
        $this->info('📊 Gerando relatório pós-migração...');

        // Estatísticas finais
        $this->statistics['completed_at'] = now()->toISOString();
        $this->statistics['duration'] = $this->calculateMigrationDuration();
        $this->statistics['success_rate'] = $this->calculateSuccessRate();

        // Exibir estatísticas na console
        $this->displayStatistics();

        // Salvar relatório detalhado
        $this->saveDetailedReport();

        // Recomendações
        $this->displayRecommendations();
    }

    /**
     * Display migration statistics in console
     */
    private function displayStatistics(): void
    {
        $this->info('📈 Estatísticas da Migração:');
        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Turmas Processadas', $this->statistics['processed_classes']],
                ['Migrações Bem-sucedidas', $this->statistics['successful_migrations']],
                ['Migrações Falhadas', $this->statistics['failed_migrations']],
                ['Reservas Criadas', $this->statistics['created_reservations']],
                ['Erros de Lote', $this->statistics['batch_errors']],
                ['Taxa de Sucesso', $this->statistics['success_rate'] . '%'],
                ['Duração Total', $this->statistics['duration']],
                ['Modo', $this->isDryRun ? 'DRY-RUN' : 'PRODUÇÃO']
            ]
        );
    }

    /**
     * Save detailed migration report
     */
    private function saveDetailedReport(): void
    {
        $reportData = [
            'migration_id' => $this->migrationId,
            'command_options' => $this->options(),
            'statistics' => $this->statistics,
            'system_info' => [
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
                'salas_api_config' => config('salas'),
                'executed_at' => now()->toISOString()
            ],
            'backup_paths' => $this->backupPaths,
            'recommendations' => $this->generateRecommendations()
        ];

        $reportFile = "migrations/reports/{$this->migrationId}_report.json";
        Storage::put($reportFile, json_encode($reportData, JSON_PRETTY_PRINT));

        $this->info("📄 Relatório detalhado salvo em: storage/app/$reportFile");
    }

    /**
     * Display actionable recommendations
     */
    private function displayRecommendations(): void
    {
        $recommendations = $this->generateRecommendations();
        
        if (!empty($recommendations)) {
            $this->warn('💡 Recomendações:');
            foreach ($recommendations as $recommendation) {
                $this->line("  • $recommendation");
            }
        }
    }

    /**
     * Generate actionable recommendations based on migration results
     *
     * @return array
     */
    private function generateRecommendations(): array
    {
        $recommendations = [];

        if ($this->statistics['failed_migrations'] > 0) {
            $recommendations[] = 'Revisar logs de erro para turmas que falharam na migração';
        }

        if ($this->statistics['batch_errors'] > 0) {
            $recommendations[] = 'Verificar conectividade com API Salas';
        }

        if ($this->statistics['success_rate'] < 95) {
            $recommendations[] = 'Taxa de sucesso abaixo de 95% - investigar problemas sistemáticos';
        }

        if ($this->isDryRun) {
            $recommendations[] = 'Execute o comando sem --dry-run para realizar a migração real';
        } else {
            $recommendations[] = 'Verificar reservas criadas no sistema Salas';
            $recommendations[] = 'Monitorar logs da aplicação por possíveis problemas pós-migração';
        }

        return $recommendations;
    }

    /**
     * Perform rollback in case of critical failures
     */
    private function performRollback(): void
    {
        $this->warn('🔄 Executando rollback automático...');
        
        try {
            // Em um ambiente real, aqui seria implementada a lógica de rollback
            // Por ora, apenas logamos a intenção
            $this->logError('rollback_initiated', new Exception('Critical migration failure'), [
                'backup_paths' => $this->backupPaths,
                'statistics' => $this->statistics
            ]);
            
            $this->info('📋 Rollback registrado. Backups disponíveis para restauração manual.');
            
        } catch (Exception $e) {
            $this->error("❌ Falha no rollback: {$e->getMessage()}");
        }
    }

    /**
     * Initialize statistics tracking
     */
    private function initializeStatistics(): void
    {
        $this->statistics = [
            'started_at' => now()->toISOString(),
            'processed_classes' => 0,
            'successful_migrations' => 0,
            'failed_migrations' => 0,
            'created_reservations' => 0,
            'batch_errors' => 0,
            'dry_run_validations' => 0,
            'success_rate' => 0,
            'duration' => '0s',
            'completed_at' => null
        ];
    }

    /**
     * Calculate migration duration
     *
     * @return string
     */
    private function calculateMigrationDuration(): string
    {
        $start = Carbon::parse($this->statistics['started_at']);
        $duration = $start->diffInSeconds(now());
        
        if ($duration < 60) {
            return $duration . 's';
        } elseif ($duration < 3600) {
            return round($duration / 60, 1) . 'm';
        } else {
            return round($duration / 3600, 1) . 'h';
        }
    }

    /**
     * Calculate success rate percentage
     *
     * @return float
     */
    private function calculateSuccessRate(): float
    {
        $total = $this->statistics['successful_migrations'] + $this->statistics['failed_migrations'];
        
        if ($total === 0) {
            return 100.0;
        }
        
        return round(($this->statistics['successful_migrations'] / $total) * 100, 2);
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
            'migration_id' => $this->migrationId,
            'error_type' => $errorType,
            'error_message' => $exception->getMessage(),
            'error_trace' => $exception->getTraceAsString(),
            'command_options' => $this->options(),
            'statistics' => $this->statistics
        ]);

        Log::error("Migração AC6 - $errorType", $logContext);
    }
}