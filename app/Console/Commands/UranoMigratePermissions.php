<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\UranoPermissionService;
use App\Services\PermissionMapper;
use App\Services\SalasApiClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Exception;

class UranoMigratePermissions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'urano:migrate-permissions
                          {--dry-run : Preview changes without applying them}
                          {--apply : Execute the migration (mutually exclusive with --dry-run)}
                          {--report : Generate detailed report in tabular format}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate user permissions from Urano (PAPEL/GRUPO) to Salas categories via API';

    private UranoPermissionService $uranoService;
    private PermissionMapper $mapper;
    private SalasApiClient $apiClient;
    private array $statistics;
    private string $migrationId;
    private bool $isDryRun;

    /**
     * Create a new command instance.
     */
    public function __construct(
        UranoPermissionService $uranoService,
        PermissionMapper $mapper,
        SalasApiClient $apiClient
    ) {
        parent::__construct();
        $this->uranoService = $uranoService;
        $this->mapper = $mapper;
        $this->apiClient = $apiClient;
        $this->migrationId = 'permission_migration_' . date('YmdHis');
        $this->initializeStatistics();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Inject API client into mapper for category loading
        $this->mapper->setApiClient($this->apiClient);

        $this->info('🔐 Urano Permissions Migration to Salas Categories');
        $this->info('Migration ID: ' . $this->migrationId);
        $this->newLine();

        // Parse flags
        $this->isDryRun = $this->option('dry-run');
        $shouldApply = $this->option('apply');
        $shouldReport = $this->option('report');

        // Validate flags
        if ($this->isDryRun && $shouldApply) {
            $this->error('❌ Cannot use --dry-run and --apply together. Choose one.');
            return 1;
        }

        if (!$this->isDryRun && !$shouldApply && !$shouldReport) {
            $this->warn('⚠️  No action flag specified. Please use:');
            $this->line('  --dry-run    Preview changes without applying');
            $this->line('  --apply      Execute the migration');
            $this->line('  --report     Generate detailed report');
            return 1;
        }

        if ($this->isDryRun) {
            $this->warn('⚠️  DRY-RUN MODE ACTIVE - No changes will be applied');
        }

        try {
            // Step 1: Pre-migration validations
            $this->info('📋 Step 1: Pre-migration validations');
            if (!$this->runPreMigrationValidations()) {
                return 1;
            }

            // Step 2: Load users from Urano
            $this->info('📥 Step 2: Loading users from Urano');
            $uranoUsers = $this->uranoService->getAllUsersWithPermissions();
            $this->statistics['total_users'] = $uranoUsers->count();
            $activeCount = $uranoUsers->where('ativado', 1)->count();
            $inactiveCount = $uranoUsers->where('ativado', '!=', 1)->count();
            $this->info("  ✅ Loaded {$uranoUsers->count()} users from Urano ({$activeCount} active, {$inactiveCount} inactive)");

            // Step 3: Generate mapping preview
            $this->info('🗺️  Step 3: Generating permission mappings');
            $mappings = $this->generateMappings($uranoUsers);
            $this->info("  ✅ Generated {$mappings->count()} user mappings");

            // Step 4: Display report if requested
            if ($shouldReport || $this->isDryRun) {
                $this->info('📊 Step 4: Generating report');
                $this->generateReport($mappings);
            }

            // Step 5: Execute migration if --apply
            if ($shouldApply && !$this->isDryRun) {
                $this->info('⚡ Step 5: Executing migration');

                if (!$this->confirm('⚠️  This will apply changes to Salas database. Continue?')) {
                    $this->warn('Migration cancelled by user.');
                    return 0;
                }

                if (!$this->executeMigration($mappings)) {
                    return 1;
                }

                $this->info('✅ Migration completed successfully!');
            }

            // Step 6: Final statistics
            $this->displayFinalStatistics();

            return 0;

        } catch (Exception $e) {
            $this->error("❌ Critical error: {$e->getMessage()}");
            Log::error('Permission migration error', [
                'migration_id' => $this->migrationId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
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
        $this->line('  🔸 Checking Urano database connection...');
        if (!$this->uranoService->testConnection()) {
            $this->error('    ❌ Cannot connect to Urano database');
            return false;
        }
        $this->info('    ✅ Urano database: Connected');

        $this->line('  🔸 Checking Salas API connectivity...');
        try {
            $response = $this->apiClient->get('/api/v1/categorias');
            if (!isset($response['data']) || empty($response['data'])) {
                $this->error('    ❌ Salas API returned empty categories');
                return false;
            }
            $this->info('    ✅ Salas API: Connected (' . count($response['data']) . ' categories found)');
        } catch (Exception $e) {
            $this->error('    ❌ Salas API error: ' . $e->getMessage());
            return false;
        }

        $this->line('  🔸 Checking Urano data integrity...');
        $stats = $this->uranoService->getPermissionStatistics();
        if ($stats['total_users'] === 0) {
            $this->error('    ❌ No users found in Urano');
            return false;
        }
        $this->info("    ✅ Data integrity: {$stats['total_users']} users ({$stats['active_users']} active), {$stats['total_roles']} roles, {$stats['total_groups']} groups");

        $this->info('✅ All validations passed!');
        return true;
    }

    /**
     * Generate permission mappings for all users
     *
     * @param \Illuminate\Support\Collection $uranoUsers
     * @return \Illuminate\Support\Collection
     */
    private function generateMappings($uranoUsers)
    {
        return $uranoUsers->map(function ($user) {
            $categoryIds = $this->mapper->mapUserToCategories($user);

            return (object) [
                'codpes' => $user->codpes,
                'name' => $user->nompes,
                'email' => $user->email,
                'active' => $user->ativado == 1,
                'roles' => $user->papeis,
                'groups' => $user->grupos,
                'category_ids' => $categoryIds,
                'category_names' => array_map(
                    fn($id) => $this->mapper->getCategoryNameById($id),
                    $categoryIds
                ),
                'urano_user_id' => $user->id,
            ];
        });
    }

    /**
     * Generate detailed report
     *
     * @param \Illuminate\Support\Collection $mappings
     */
    private function generateReport($mappings): void
    {
        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════════════════');
        $this->info('                    PERMISSION MIGRATION REPORT');
        $this->info('═══════════════════════════════════════════════════════════════════');
        $this->newLine();

        // Summary statistics
        $mappingStats = $this->mapper->getMappingStatistics($mappings);

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Users', $mappingStats['total_users']],
                ['Admin Users (ADM/OPR)', $mappingStats['admin_users']],
                ['Regular Users', $mappingStats['regular_users']],
                ['Users Without Permissions', $mappingStats['users_without_permissions']],
            ]
        );

        $this->newLine();
        $this->info('Category Distribution:');
        foreach ($mappingStats['category_distribution'] as $categoryName => $count) {
            $this->line("  • {$categoryName}: {$count} users");
        }

        $this->newLine();
        $this->info('User-Level Mappings:');
        $this->newLine();

        // Prepare table data
        $tableData = $mappings->map(function ($mapping) {
            $maskedCodpes = substr($mapping->codpes, 0, 3) . '****';
            $status = $mapping->active ? 'Ativo' : 'Inativo';
            $roles = implode(' + ', $mapping->roles) ?: '-';
            $groups = implode(', ', array_slice($mapping->groups, 0, 2)) . (count($mapping->groups) > 2 ? '...' : '');
            if (empty($groups)) $groups = '-';
            $categories = implode(', ', $mapping->category_names) ?: 'None';

            return [
                $maskedCodpes,
                $status,
                $roles,
                $groups,
                $categories,
            ];
        })->toArray();

        $this->table(
            ['Codpes', 'Status', 'Urano Roles', 'Urano Groups', 'Salas Categories'],
            $tableData
        );

        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════════════════');
    }

    /**
     * Execute the migration
     *
     * @param \Illuminate\Support\Collection $mappings
     * @return bool
     */
    private function executeMigration($mappings): bool
    {
        $this->newLine();
        $progressBar = $this->output->createProgressBar($mappings->count());
        $progressBar->setFormat('  %current%/%max% [%bar%] %percent:3s%% - %message%');
        $progressBar->setMessage('Starting migration...');
        $progressBar->start();

        DB::beginTransaction();

        try {
            foreach ($mappings as $mapping) {
                $progressBar->setMessage("Processing {$mapping->codpes}...");

                // Step 1: Check if user exists in Salas
                $salasUser = $this->findOrCreateUser($mapping);

                if (!$salasUser) {
                    $this->statistics['users_failed']++;
                    $this->logError('user_creation_failed', $mapping->codpes);
                    $progressBar->advance();
                    continue;
                }

                // Step 2: Sync categories for user
                if (!empty($mapping->category_ids)) {
                    $synced = $this->syncUserCategories($salasUser['id'], $mapping->category_ids);

                    if ($synced) {
                        $this->statistics['users_migrated']++;
                        $this->statistics['category_assignments'] += count($mapping->category_ids);
                    } else {
                        $this->statistics['users_failed']++;
                        $this->logError('category_sync_failed', $mapping->codpes);
                    }
                } else {
                    $this->statistics['users_without_categories']++;
                }

                $progressBar->advance();
            }

            $progressBar->setMessage('Migration completed!');
            $progressBar->finish();
            $this->newLine(2);

            DB::commit();
            return true;

        } catch (Exception $e) {
            DB::rollBack();
            $this->newLine(2);
            $this->error("  ❌ Migration failed: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Find user in Salas or create if doesn't exist
     *
     * @param object $mapping
     * @return array|null User data from Salas API
     */
    private function findOrCreateUser($mapping): ?array
    {
        try {
            // First, try to find user via API using codpes
            try {
                $response = $this->apiClient->get('/api/v1/users', ['codpes' => $mapping->codpes]);

                if (isset($response['data']['id'])) {
                    // User exists, return their data
                    return $response['data'];
                }
            } catch (Exception $e) {
                // If 404, user doesn't exist - we'll create below
                // If other error, log but continue to try creating
                if (strpos($e->getMessage(), '404') === false) {
                    Log::warning('Error checking user existence', [
                        'migration_id' => $this->migrationId,
                        'codpes' => $mapping->codpes,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // User doesn't exist, create via API
            $payload = [
                'codpes' => $mapping->codpes,
                'name' => $mapping->name,
                'email' => $mapping->email ?: "user{$mapping->codpes}@ime.usp.br",
                'password' => bin2hex(random_bytes(16)), // Random secure password
            ];

            $response = $this->apiClient->post('/api/v1/users', $payload);
            $this->statistics['users_created']++;
            return $response['data'] ?? null;

        } catch (Exception $e) {
            // Handle race condition - user might have been created between check and create
            if (strpos($e->getMessage(), '422') !== false || strpos($e->getMessage(), 'codpes') !== false) {
                // Try one more time to get the user via API
                try {
                    $response = $this->apiClient->get('/api/v1/users', ['codpes' => $mapping->codpes]);
                    if (isset($response['data']['id'])) {
                        return $response['data'];
                    }
                } catch (Exception $retryError) {
                    // Ignore retry error
                }
            }

            Log::error('User creation/lookup failed', [
                'migration_id' => $this->migrationId,
                'codpes' => $mapping->codpes,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Sync categories for a user via Salas API
     *
     * @param int $userId Salas user ID
     * @param array $categoryIds Category IDs to sync
     * @return bool
     */
    private function syncUserCategories(int $userId, array $categoryIds): bool
    {
        try {
            $payload = [
                'categoria_ids' => $categoryIds,
            ];

            $response = $this->apiClient->put("/api/v1/users/{$userId}/categorias", $payload);

            // Log successful sync for debugging
            Log::info('Category sync successful', [
                'migration_id' => $this->migrationId,
                'user_id' => $userId,
                'category_ids' => $categoryIds,
                'response' => $response,
            ]);

            return true;
        } catch (Exception $e) {
            Log::error('Category sync failed', [
                'migration_id' => $this->migrationId,
                'user_id' => $userId,
                'category_ids' => $categoryIds,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * Display final statistics
     */
    private function displayFinalStatistics(): void
    {
        $this->newLine();
        $this->info('📊 Final Statistics:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Users Processed', $this->statistics['total_users']],
                ['Users Migrated Successfully', $this->statistics['users_migrated']],
                ['Users Created in Salas', $this->statistics['users_created']],
                ['Users Failed', $this->statistics['users_failed']],
                ['Users Without Categories', $this->statistics['users_without_categories']],
                ['Category Assignments Created', $this->statistics['category_assignments']],
                ['Mode', $this->isDryRun ? 'DRY-RUN' : 'APPLIED'],
            ]
        );
    }

    /**
     * Log error for audit trail
     *
     * @param string $errorType
     * @param string $codpes
     */
    private function logError(string $errorType, string $codpes): void
    {
        Log::warning('Permission migration error', [
            'migration_id' => $this->migrationId,
            'error_type' => $errorType,
            'codpes_masked' => substr($codpes, 0, 3) . '****',
        ]);
    }

    /**
     * Initialize statistics tracking
     */
    private function initializeStatistics(): void
    {
        $this->statistics = [
            'total_users' => 0,
            'users_migrated' => 0,
            'users_created' => 0,
            'users_failed' => 0,
            'users_without_categories' => 0,
            'category_assignments' => 0,
        ];
    }
}
