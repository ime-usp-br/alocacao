<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ReservationMapper;
use App\Services\SalasApiClient;
use App\Models\SchoolClass;
use App\Models\SchoolTerm;
use Exception;

class TestReservationMapping extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'salas:test-mapping 
                          {--room-test : Test specific room mappings}
                          {--api-test : Test API connectivity}
                          {--full-test : Test full SchoolClass mapping}
                          {--clear-cache : Clear mapping cache}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test ReservationMapper functionality for AC2 validation';

    private ReservationMapper $mapper;
    private SalasApiClient $apiClient;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(ReservationMapper $mapper, SalasApiClient $apiClient)
    {
        parent::__construct();
        $this->mapper = $mapper;
        $this->apiClient = $apiClient;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('🔍 Testando AC2: Mapeamento de Dados');
        $this->newLine();

        if ($this->option('clear-cache')) {
            $this->testClearCache();
        }

        if ($this->option('api-test')) {
            $this->testApiConnectivity();
        }

        if ($this->option('room-test')) {
            $this->testRoomMapping();
        }

        if ($this->option('full-test')) {
            $this->testFullMapping();
        }

        if (!$this->hasOption('room-test') && !$this->hasOption('api-test') && 
            !$this->hasOption('full-test') && !$this->hasOption('clear-cache')) {
            $this->info('Use uma das opções: --room-test, --api-test, --full-test, --clear-cache');
            $this->info('Ou combine múltiplas opções para testes mais completos.');
        }

        return 0;
    }

    /**
     * Test API connectivity
     */
    private function testApiConnectivity(): void
    {
        $this->info('🌐 Testando conectividade com API Salas...');

        try {
            $connected = $this->apiClient->testConnection();
            
            if ($connected) {
                $this->info('✅ Conectividade com API Salas: OK');
                
                // Test getting salas
                $salas = $this->mapper->getAllSalas();
                $this->info("✅ Salas encontradas: " . count($salas));
                
                $this->table(
                    ['ID', 'Nome', 'Categoria', 'Capacidade'],
                    collect($salas)->take(5)->map(function ($sala) {
                        return [
                            $sala['id'],
                            $sala['nome'],
                            $sala['categoria'] ?? 'N/A',
                            $sala['capacidade'] ?? 'N/A'
                        ];
                    })->toArray()
                );
                
            } else {
                $this->error('❌ Falha na conectividade com API Salas');
            }
        } catch (Exception $e) {
            $this->error('❌ Erro de conectividade: ' . $e->getMessage());
        }

        $this->newLine();
    }

    /**
     * Test room name mapping
     */
    private function testRoomMapping(): void
    {
        $this->info('🏢 Testando mapeamento de salas...');

        // Test cases baseados na análise do código atual
        $testCases = [
            'Auditório Jacy Monteiro',
            'Auditório Antonio Gilioli', 
            'B01',
            'B123', // Caso especial de formato
            'A132',
            'B144'
        ];

        $results = [];
        foreach ($testCases as $roomName) {
            try {
                $canMap = $this->mapper->canMapRoom($roomName);
                $status = $canMap ? '✅' : '❌';
                $results[] = [$roomName, $status, $canMap ? 'Mapeável' : 'Não encontrada'];
            } catch (Exception $e) {
                $results[] = [$roomName, '❌', 'Erro: ' . $e->getMessage()];
            }
        }

        $this->table(['Sala Original', 'Status', 'Resultado'], $results);
        $this->newLine();
    }

    /**
     * Test full SchoolClass mapping including day_times validation
     */
    private function testFullMapping(): void
    {
        $this->info('🎓 Testando mapeamento completo de SchoolClass...');

        try {
            $schoolTerm = SchoolTerm::getLatest();
            if (!$schoolTerm) {
                $this->error('❌ Nenhum período letivo encontrado');
                return;
            }

            // Get a few school classes with rooms allocated
            $schoolClasses = SchoolClass::whereBelongsTo($schoolTerm)
                ->whereHas('room')
                ->with(['room', 'classschedules', 'schoolterm'])
                ->take(5) // Increase to get more variety
                ->get();

            if ($schoolClasses->isEmpty()) {
                $this->warn('⚠️ Nenhuma turma com sala alocada encontrada');
                return;
            }

            $singleScheduleCount = 0;
            $multipleUniformCount = 0;
            $multipleDistinctCount = 0;

            foreach ($schoolClasses as $schoolClass) {
                $scheduleCount = $schoolClass->classschedules->count();
                $this->info("📝 Testando turma: {$schoolClass->coddis} - {$schoolClass->codtur}");
                $this->info("   Sala: {$schoolClass->room->nome}");
                $this->info("   Horários: {$scheduleCount} horário(s)");

                // Show schedule details for analysis
                foreach ($schoolClass->classschedules as $schedule) {
                    $this->line("     • {$schedule->diasmnocp}: {$schedule->horent} - {$schedule->horsai}");
                }

                try {
                    $payload = $this->mapper->mapSchoolClassToReservationPayload($schoolClass);

                    $this->info('✅ Mapeamento bem-sucedido:');
                    $this->line("   - Nome: {$payload['nome']}");
                    $this->line("   - Sala ID: {$payload['sala_id']}");
                    $this->line("   - Data início: {$payload['data']}");

                    // Analyze time structure
                    if (isset($payload['day_times'])) {
                        $hasDistinctTimes = $this->hasDistinctTimes($schoolClass->classschedules);

                        if ($hasDistinctTimes) {
                            $this->line("   ⭐ Estrutura: day_times (horários distintos)");
                            $multipleDistinctCount++;
                        } else {
                            $this->line("   ⭐ Estrutura: day_times (horários uniformes - exigência API)");
                            $multipleUniformCount++;
                        }

                        foreach ($payload['day_times'] as $dayNum => $times) {
                            $dayNames = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
                            $dayName = $dayNames[$dayNum] ?? "Dia $dayNum";
                            $this->line("     • {$dayName}: {$times['start']} - {$times['end']}");
                        }
                    } else {
                        $this->line("   📅 Estrutura: tradicional (não recorrente)");
                        $this->line("   - Horário: {$payload['horario_inicio']} - {$payload['horario_fim']}");
                        $singleScheduleCount++;
                    }

                    if (isset($payload['repeat_days'])) {
                        $days = implode(', ', $payload['repeat_days']);
                        $this->line("   - Dias recorrência: [{$days}]");
                        $this->line("   - Até: {$payload['repeat_until']}");
                    }

                    // Validate payload structure
                    $this->validatePayloadStructure($payload);

                } catch (Exception $e) {
                    $this->error("❌ Erro no mapeamento: {$e->getMessage()}");
                }

                $this->newLine();
            }

            // Summary of test results
            $this->info('📊 Resumo dos testes:');
            $this->table(
                ['Tipo de Horário', 'Quantidade', 'Estrutura Usada'],
                [
                    ['Horário único/não recorrente', $singleScheduleCount, 'tradicional'],
                    ['Múltiplos uniformes', $multipleUniformCount, 'day_times'],
                    ['Múltiplos distintos', $multipleDistinctCount, 'day_times']
                ]
            );

        } catch (Exception $e) {
            $this->error('❌ Erro geral: ' . $e->getMessage());
        }
    }

    /**
     * Validate payload structure for API compliance
     *
     * @param array $payload
     */
    private function validatePayloadStructure(array $payload): void
    {
        $hasTraditionalTimes = isset($payload['horario_inicio']) && isset($payload['horario_fim']);
        $hasDayTimes = isset($payload['day_times']);
        $hasRecurrence = isset($payload['repeat_days']) && isset($payload['repeat_until']);

        if ($hasDayTimes && $hasTraditionalTimes) {
            $this->error('   ❌ Validação falhou: payload não deve ter ambos day_times e horario_inicio/horario_fim');
        } elseif ($hasRecurrence && !$hasDayTimes) {
            $this->error('   ❌ Validação falhou: API Salas exige day_times para todas as reservas recorrentes');
        } elseif ($hasDayTimes && !$hasRecurrence) {
            $this->error('   ❌ Validação falhou: day_times deve ser usado apenas com recorrência');
        } elseif (!$hasRecurrence && !$hasTraditionalTimes) {
            $this->error('   ❌ Validação falhou: reserva não recorrente deve ter horario_inicio/horario_fim');
        } else {
            $this->line('   ✅ Estrutura do payload válida');
        }

        // Validate day_times structure if present
        if ($hasDayTimes) {
            foreach ($payload['day_times'] as $dayNum => $times) {
                if (!is_numeric($dayNum) || $dayNum < 0 || $dayNum > 6) {
                    $this->error("   ❌ Dia da semana inválido: {$dayNum}");
                }
                if (!isset($times['start']) || !isset($times['end'])) {
                    $this->error("   ❌ Horário incompleto para dia {$dayNum}");
                }
            }
        }
    }

    /**
     * Test cache clearing
     */
    private function testClearCache(): void
    {
        $this->info('🗑️ Limpando cache de mapeamento...');
        
        try {
            $this->mapper->clearSalasCache();
            $this->info('✅ Cache limpo (implementação manual necessária)');
        } catch (Exception $e) {
            $this->error('❌ Erro ao limpar cache: ' . $e->getMessage());
        }

        $this->newLine();
    }

    /**
     * Helper method to check if schedules have distinct times
     *
     * @param mixed $schedules
     * @return bool
     */
    private function hasDistinctTimes($schedules): bool
    {
        if (count($schedules) <= 1) {
            return false;
        }

        $uniqueTimes = collect($schedules)->map(function ($schedule) {
            return $schedule->horent . '-' . $schedule->horsai;
        })->unique();

        return $uniqueTimes->count() > 1;
    }
}