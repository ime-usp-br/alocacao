<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ReservationMapper;
use App\Services\SalasApiClient;
use App\Models\SchoolClass;
use App\Models\SchoolTerm;
use Exception;

class TestReservationMappingOffline extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'salas:test-mapping-offline';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test ReservationMapper logic offline (without API calls)';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('🔍 Testando AC2: Mapeamento de Dados (Offline)');
        $this->newLine();

        $this->testRoomNameMapping();
        $this->testDayMapping();
        $this->testReservationNameGeneration();

        $this->info('✅ Testes offline concluídos!');
        return 0;
    }

    /**
     * Test room name mapping logic
     */
    private function testRoomNameMapping(): void
    {
        $this->info('🏢 Testando lógica de mapeamento de nomes de salas...');

        // Create a mock mapper to test the private methods via reflection
        $salasApiClient = app(SalasApiClient::class);
        $mapper = new ReservationMapper($salasApiClient);

        // Test using reflection to access private method
        $reflection = new \ReflectionClass($mapper);
        $mapRoomNameMethod = $reflection->getMethod('mapRoomName');
        $mapRoomNameMethod->setAccessible(true);

        $testCases = [
            // Casos especiais (configuração override)
            'Auditório Jacy Monteiro' => 'Auditório Jacy Monteiro',
            'Auditório Antonio Gilioli' => 'Auditório Antonio Gilioli',
            // Lógica original do Reservation.php:
            'B01' => 'B001',   // 3 chars -> adiciona 0: B + 0 + 01
            'B123' => 'B123',  // 4 chars -> mantém como está
            'A132' => 'A132',  // 4 chars -> mantém como está
        ];

        $results = [];
        foreach ($testCases as $input => $expected) {
            try {
                $result = $mapRoomNameMethod->invoke($mapper, $input);
                $status = $result === $expected ? '✅' : '❌';
                $results[] = [$input, $expected, $result, $status];
            } catch (Exception $e) {
                $results[] = [$input, $expected, 'ERRO: ' . $e->getMessage(), '❌'];
            }
        }

        $this->table(['Input', 'Esperado', 'Resultado', 'Status'], $results);
        $this->newLine();
    }

    /**
     * Test day mapping
     */
    private function testDayMapping(): void
    {
        $this->info('📅 Testando mapeamento de dias da semana...');

        $salasApiClient = app(SalasApiClient::class);
        $mapper = new ReservationMapper($salasApiClient);

        // Create mock class schedules
        $mockSchedules = collect([
            (object)['diasmnocp' => 'seg'],
            (object)['diasmnocp' => 'ter'],
            (object)['diasmnocp' => 'qua'],
            (object)['diasmnocp' => 'qui'],
            (object)['diasmnocp' => 'sex'],
        ]);

        $reflection = new \ReflectionClass($mapper);
        $mapDaysMethod = $reflection->getMethod('mapDaysToRepeatDays');
        $mapDaysMethod->setAccessible(true);

        try {
            $result = $mapDaysMethod->invoke($mapper, $mockSchedules);
            $expected = [1, 2, 3, 4, 5]; // seg=1, ter=2, etc.
            
            $status = $result == $expected ? '✅' : '❌';
            $resultStr = '[' . implode(', ', $result) . ']';
            $expectedStr = '[' . implode(', ', $expected) . ']';
            
            $this->table(
                ['Entrada', 'Esperado', 'Resultado', 'Status'], 
                [['seg-sex', $expectedStr, $resultStr, $status]]
            );

        } catch (Exception $e) {
            $this->error('❌ Erro no teste de dias: ' . $e->getMessage());
        }

        $this->newLine();
    }

    /**
     * Test reservation name generation logic
     */
    private function testReservationNameGeneration(): void
    {
        $this->info('📝 Testando geração de nomes de reserva...');

        try {
            // Get a real SchoolClass to test with
            $schoolTerm = SchoolTerm::getLatest();
            if (!$schoolTerm) {
                $this->warn('⚠️ Nenhum período letivo encontrado para teste');
                return;
            }

            $schoolClass = SchoolClass::whereBelongsTo($schoolTerm)
                ->with(['fusion'])
                ->first();

            if (!$schoolClass) {
                $this->warn('⚠️ Nenhuma turma encontrada para teste');
                return;
            }

            $salasApiClient = app(SalasApiClient::class);
            $mapper = new ReservationMapper($salasApiClient);

            $reflection = new \ReflectionClass($mapper);
            $generateNameMethod = $reflection->getMethod('generateReservationName');
            $generateNameMethod->setAccessible(true);

            $result = $generateNameMethod->invoke($mapper, $schoolClass);
            
            $expected = "Aula - {$schoolClass->coddis}";
            $containsExpected = strpos($result, $expected) !== false;
            
            $status = $containsExpected ? '✅' : '❌';
            
            $this->table(
                ['Disciplina', 'Turma', 'Nome Gerado', 'Status'], 
                [[$schoolClass->coddis, $schoolClass->codtur, $result, $status]]
            );

        } catch (Exception $e) {
            $this->error('❌ Erro no teste de geração de nome: ' . $e->getMessage());
        }

        $this->newLine();
    }
}