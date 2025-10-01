<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\ReservationMapper;
use App\Services\SalasApiClient;
use App\Models\SchoolClass;
use App\Models\SchoolTerm;
use App\Models\Room;
use App\Models\ClassSchedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Exception;
use Carbon\Carbon;

class ReservationMapperTest extends TestCase
{
    private $mapper;
    private $mockApiClient;
    private $mockSchoolClass;
    private $mockRoom;
    private $mockSchoolTerm;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock Cache facade
        Cache::shouldReceive('has')->andReturn(false);
        Cache::shouldReceive('get')->andReturn(collect());
        Cache::shouldReceive('put')->andReturn(true);

        $this->mockApiClient = Mockery::mock(SalasApiClient::class);
        $this->mockApiClient->shouldReceive('getAllSalas')->andReturn(collect([
            ['id' => 123, 'nome' => 'B01'],
            ['id' => 456, 'nome' => 'A101']
        ]));
        $this->mapper = new ReservationMapper($this->mockApiClient);

        // Mock room
        $this->mockRoom = Mockery::mock(Room::class);
        $this->mockRoom->shouldReceive('getAttribute')
            ->with('nome')
            ->andReturn('B01');

        // Mock school term
        $this->mockSchoolTerm = Mockery::mock(SchoolTerm::class);
        $this->mockSchoolTerm->shouldReceive('getAttribute')
            ->with('dtamaxres')
            ->andReturn('31/12/2024');

        // Mock school class base
        $this->mockSchoolClass = Mockery::mock(SchoolClass::class);
        $this->mockSchoolClass->shouldReceive('getAttribute')
            ->with('id')
            ->andReturn(1);
        $this->mockSchoolClass->shouldReceive('getAttribute')
            ->with('coddis')
            ->andReturn('MAC0110');
        $this->mockSchoolClass->shouldReceive('getAttribute')
            ->with('codtur')
            ->andReturn('01');
        $this->mockSchoolClass->shouldReceive('getAttribute')
            ->with('tiptur')
            ->andReturn('Graduação');
        $this->mockSchoolClass->shouldReceive('getAttribute')
            ->with('room')
            ->andReturn($this->mockRoom);
        $this->mockSchoolClass->shouldReceive('getAttribute')
            ->with('schoolterm')
            ->andReturn($this->mockSchoolTerm);

        // Mock fusion relationship
        $this->mockSchoolClass->shouldReceive('getAttribute')
            ->with('fusion')
            ->andReturn(null);

        // Mock offsetExists for tiptur field
        $this->mockSchoolClass->shouldReceive('offsetExists')
            ->with('tiptur')
            ->andReturn(true);

        // Set up cache mock
        Cache::shouldReceive('remember')
            ->andReturn(123); // Mock sala ID
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_generates_traditional_payload_for_single_schedule()
    {
        // Create single schedule
        $schedule = $this->createMockSchedule('seg', '08:00:00', '10:00:00');
        $schedules = collect([$schedule]);

        $this->mockSchoolClass->shouldReceive('getAttribute')
            ->with('classschedules')
            ->andReturn($schedules);

        $payload = $this->mapper->mapSchoolClassToReservationPayload($this->mockSchoolClass);

        $this->assertArrayHasKey('horario_inicio', $payload);
        $this->assertArrayHasKey('horario_fim', $payload);
        $this->assertArrayNotHasKey('day_times', $payload);
        $this->assertEquals('8:00', $payload['horario_inicio']);
        $this->assertEquals('9:59', $payload['horario_fim']); // 10:00 - 1 minute
        $this->assertEquals('Aula - MAC0110 T.01', $payload['nome']);
        $this->assertEquals(123, $payload['sala_id']);
        $this->assertEquals(1, $payload['finalidade_id']); // Graduação
    }

    /** @test */
    public function it_generates_traditional_payload_for_multiple_uniform_schedules()
    {
        // Create multiple schedules with same times
        $schedule1 = $this->createMockSchedule('ter', '10:00:00', '12:00:00');
        $schedule2 = $this->createMockSchedule('qui', '10:00:00', '12:00:00');
        $schedules = collect([$schedule1, $schedule2]);

        $this->mockSchoolClass->shouldReceive('getAttribute')
            ->with('classschedules')
            ->andReturn($schedules);

        $payload = $this->mapper->mapSchoolClassToReservationPayload($this->mockSchoolClass);

        $this->assertArrayHasKey('horario_inicio', $payload);
        $this->assertArrayHasKey('horario_fim', $payload);
        $this->assertArrayNotHasKey('day_times', $payload);
        $this->assertArrayHasKey('repeat_days', $payload);
        $this->assertArrayHasKey('repeat_until', $payload);
        $this->assertEquals([2, 4], $payload['repeat_days']); // ter=2, qui=4
        $this->assertEquals('10:0', $payload['horario_inicio']);
        $this->assertEquals('12:0', $payload['horario_fim']);
    }

    /** @test */
    public function it_generates_day_times_payload_for_multiple_distinct_schedules()
    {
        // Create multiple schedules with different times
        $schedule1 = $this->createMockSchedule('seg', '08:00:00', '10:00:00');
        $schedule2 = $this->createMockSchedule('qua', '14:00:00', '16:00:00');
        $schedules = collect([$schedule1, $schedule2]);

        $this->mockSchoolClass->shouldReceive('getAttribute')
            ->with('classschedules')
            ->andReturn($schedules);

        $payload = $this->mapper->mapSchoolClassToReservationPayload($this->mockSchoolClass);

        $this->assertArrayNotHasKey('horario_inicio', $payload);
        $this->assertArrayNotHasKey('horario_fim', $payload);
        $this->assertArrayHasKey('day_times', $payload);
        $this->assertArrayHasKey('repeat_days', $payload);
        $this->assertArrayHasKey('repeat_until', $payload);

        // Validate day_times structure
        $expectedDayTimes = [
            '1' => ['start' => '8:0', 'end' => '9:59'], // seg=1
            '3' => ['start' => '14:0', 'end' => '16:0'] // qua=3
        ];

        $this->assertEquals($expectedDayTimes, $payload['day_times']);
        $this->assertEquals([1, 3], $payload['repeat_days']);
    }

    /** @test */
    public function it_handles_complex_distinct_schedules_correctly()
    {
        // Create complex schedule: seg 8-10h, ter 10-12h, qua 14-16h
        $schedule1 = $this->createMockSchedule('seg', '08:00:00', '10:00:00');
        $schedule2 = $this->createMockSchedule('ter', '10:00:00', '12:00:00');
        $schedule3 = $this->createMockSchedule('qua', '14:00:00', '16:00:00');
        $schedules = collect([$schedule1, $schedule2, $schedule3]);

        $this->mockSchoolClass->shouldReceive('getAttribute')
            ->with('classschedules')
            ->andReturn($schedules);

        $payload = $this->mapper->mapSchoolClassToReservationPayload($this->mockSchoolClass);

        $this->assertArrayHasKey('day_times', $payload);
        $this->assertCount(3, $payload['day_times']);

        // Verify each day has correct times
        $this->assertEquals(['start' => '8:0', 'end' => '9:59'], $payload['day_times']['1']); // seg
        $this->assertEquals(['start' => '10:0', 'end' => '12:0'], $payload['day_times']['2']); // ter
        $this->assertEquals(['start' => '14:0', 'end' => '16:0'], $payload['day_times']['3']); // qua

        $this->assertEquals([1, 2, 3], $payload['repeat_days']);
    }

    /** @test */
    public function it_throws_exception_for_schoolclass_without_room()
    {
        $this->mockSchoolClass->shouldReceive('getAttribute')
            ->with('room')
            ->andReturn(null);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('SchoolClass deve ter uma sala alocada para ser mapeada');

        $this->mapper->mapSchoolClassToReservationPayload($this->mockSchoolClass);
    }

    /** @test */
    public function it_throws_exception_for_schoolclass_without_schedules()
    {
        $this->mockSchoolClass->shouldReceive('getAttribute')
            ->with('classschedules')
            ->andReturn(collect([]));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('SchoolClass deve ter pelo menos um horário de aula definido');

        $this->mapper->mapSchoolClassToReservationPayload($this->mockSchoolClass);
    }

    /** @test */
    public function it_correctly_detects_distinct_times()
    {
        $mapper = new ReservationMapper($this->mockApiClient);

        // Test with identical times (should return false)
        $schedule1 = $this->createMockSchedule('ter', '10:00:00', '12:00:00');
        $schedule2 = $this->createMockSchedule('qui', '10:00:00', '12:00:00');
        $uniformSchedules = collect([$schedule1, $schedule2]);

        $this->assertFalse($this->invokePrivateMethod($mapper, 'hasDistinctTimes', [$uniformSchedules]));

        // Test with different times (should return true)
        $schedule3 = $this->createMockSchedule('seg', '08:00:00', '10:00:00');
        $schedule4 = $this->createMockSchedule('qua', '14:00:00', '16:00:00');
        $distinctSchedules = collect([$schedule3, $schedule4]);

        $this->assertTrue($this->invokePrivateMethod($mapper, 'hasDistinctTimes', [$distinctSchedules]));

        // Test with single schedule (should return false)
        $singleSchedule = collect([$schedule1]);
        $this->assertFalse($this->invokePrivateMethod($mapper, 'hasDistinctTimes', [$singleSchedule]));
    }

    /** @test */
    public function it_correctly_builds_day_times_array()
    {
        $mapper = new ReservationMapper($this->mockApiClient);

        $schedule1 = $this->createMockSchedule('seg', '08:00:00', '10:00:00');
        $schedule2 = $this->createMockSchedule('qua', '14:00:00', '16:30:00');
        $schedules = collect([$schedule1, $schedule2]);

        $dayTimes = $this->invokePrivateMethod($mapper, 'buildDayTimesArray', [$schedules]);

        $expected = [
            '1' => ['start' => '8:00', 'end' => '9:59'], // seg
            '3' => ['start' => '14:00', 'end' => '16:30'] // qua
        ];

        $this->assertEquals($expected, $dayTimes);
    }

    /** @test */
    public function it_handles_edge_case_end_times_correctly()
    {
        $mapper = new ReservationMapper($this->mockApiClient);

        // Test with :00 end time (should subtract 1 minute)
        $schedule1 = $this->createMockSchedule('seg', '08:00:00', '10:00:00');
        // Test with :30 end time (should not subtract)
        $schedule2 = $this->createMockSchedule('ter', '14:00:00', '16:30:00');

        $schedules = collect([$schedule1, $schedule2]);
        $dayTimes = $this->invokePrivateMethod($mapper, 'buildDayTimesArray', [$schedules]);

        $this->assertEquals('9:59', $dayTimes['1']['end']); // 10:00 - 1 minute
        $this->assertEquals('16:30', $dayTimes['2']['end']); // unchanged
    }

    /** @test */
    public function it_maps_schoolclass_tiptur_to_correct_finalidade()
    {
        $schedule = $this->createMockSchedule('seg', '08:00:00', '10:00:00');
        $schedules = collect([$schedule]);

        // Test Graduação
        $this->mockSchoolClass->shouldReceive('getAttribute')
            ->with('tiptur')
            ->andReturn('Graduação');
        $this->mockSchoolClass->shouldReceive('getAttribute')
            ->with('classschedules')
            ->andReturn($schedules);

        $payload = $this->mapper->mapSchoolClassToReservationPayload($this->mockSchoolClass);
        $this->assertEquals(1, $payload['finalidade_id']); // Graduação

        // Test Pós Graduação
        $mockPosGrad = clone $this->mockSchoolClass;
        $mockPosGrad->shouldReceive('getAttribute')
            ->with('tiptur')
            ->andReturn('Pós Graduação');
        $mockPosGrad->shouldReceive('getAttribute')
            ->with('classschedules')
            ->andReturn($schedules);
        $mockPosGrad->shouldReceive('getAttribute')
            ->with('room')
            ->andReturn($this->mockRoom);
        $mockPosGrad->shouldReceive('getAttribute')
            ->with('schoolterm')
            ->andReturn($this->mockSchoolTerm);
        $mockPosGrad->shouldReceive('getAttribute')
            ->with('id')
            ->andReturn(2);
        $mockPosGrad->shouldReceive('getAttribute')
            ->with('coddis')
            ->andReturn('MAC0110');
        $mockPosGrad->shouldReceive('getAttribute')
            ->with('codtur')
            ->andReturn('01');
        $mockPosGrad->shouldReceive('getAttribute')
            ->with('fusion')
            ->andReturn(null);

        $payload = $this->mapper->mapSchoolClassToReservationPayload($mockPosGrad);
        $this->assertEquals(2, $payload['finalidade_id']); // Pós-Graduação
    }

    /** @test */
    public function it_maps_urano_data_to_correct_finalidade()
    {
        $uranoData = [
            'titulo' => 'Defesa de Mestrado',
            'data' => '2024-01-15',
            'hora_inicio' => '14:00',
            'hora_fim' => '16:00',
            'sala_nome' => 'B01',
            'solicitante' => 'João Silva'
        ];

        $payload = $this->mapper->mapUranoDataToReservationPayload($uranoData);
        $this->assertEquals(5, $payload['finalidade_id']); // Defesa

        // Test with tipo_atividade (should override title keywords)
        $uranoDataWithTipo = [
            'titulo' => 'Aula de Pós-Graduação',
            'tipo_atividade' => 'Pós-Graduação',
            'data' => '2024-01-15',
            'hora_inicio' => '14:00',
            'hora_fim' => '16:00',
            'sala_nome' => 'B01',
            'solicitante' => 'João Silva'
        ];
        $payload = $this->mapper->mapUranoDataToReservationPayload($uranoDataWithTipo);
        $this->assertEquals(2, $payload['finalidade_id']); // Pós-Graduação

        // Test fallback to Graduação
        $uranoData = [
            'titulo' => 'Aula Regular',
            'data' => '2024-01-15',
            'hora_inicio' => '14:00',
            'hora_fim' => '16:00',
            'sala_nome' => 'B01',
            'solicitante' => 'João Silva'
        ];
        $payload = $this->mapper->mapUranoDataToReservationPayload($uranoData);
        $this->assertEquals(1, $payload['finalidade_id']); // Graduação (fallback)
    }

    /**
     * Create a mock ClassSchedule
     */
    private function createMockSchedule(string $day, string $start, string $end)
    {
        $schedule = new \stdClass();
        $schedule->diasmnocp = $day;
        $schedule->horent = $start;
        $schedule->horsai = $end;

        return $schedule;
    }

    /**
     * Invoke a private method for testing
     */
    private function invokePrivateMethod($object, $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }

    // ==================== Issue #31: Grouping Tests ====================

    /** @test */
    public function it_maps_single_urano_reservation_without_grouping()
    {
        // Create single reservation (no grouping needed)
        $reservation = (object) [
            'requisicao_id' => 123,
            'reserva_id' => 456,
            'data' => '2024-03-15',
            'hi' => '08:00:00',
            'hf' => '10:00:00',
            'titulo' => 'Test Event',
            'solicitante' => 'John Doe',
            'email' => 'john@example.com',
            'participantes' => 30,
            'sala_nome' => 'B01',
            'atividade' => 'Graduação'
        ];

        $reservations = collect([$reservation]);

        $payload = $this->mapper->mapUranoRequisitionGroupToReservationPayload($reservations);

        // Should use simple time fields (no day_times)
        $this->assertArrayHasKey('data', $payload);
        $this->assertArrayHasKey('horario_inicio', $payload);
        $this->assertArrayHasKey('horario_fim', $payload);
        $this->assertArrayNotHasKey('day_times', $payload);
        $this->assertArrayNotHasKey('repeat_days', $payload);
        $this->assertEquals('2024-03-15', $payload['data']);
        $this->assertEquals('8:00', $payload['horario_inicio']);
        $this->assertEquals('10:00', $payload['horario_fim']);
    }

    /** @test */
    public function it_maps_multiple_urano_reservations_with_day_times()
    {
        // Create multiple reservations (grouping needed)
        $reservations = collect([
            (object) [
                'requisicao_id' => 123,
                'reserva_id' => 456,
                'data' => '2024-03-18', // Monday
                'hi' => '08:00:00',
                'hf' => '10:00:00',
                'titulo' => 'Test Event',
                'solicitante' => 'John Doe',
                'email' => 'john@example.com',
                'participantes' => 30,
                'sala_nome' => 'B01',
                'atividade' => 'Graduação'
            ],
            (object) [
                'requisicao_id' => 123,
                'reserva_id' => 457,
                'data' => '2024-03-20', // Wednesday
                'hi' => '08:00:00',
                'hf' => '10:00:00',
                'titulo' => 'Test Event',
                'solicitante' => 'John Doe',
                'email' => 'john@example.com',
                'participantes' => 30,
                'sala_nome' => 'B01',
                'atividade' => 'Graduação'
            ],
            (object) [
                'requisicao_id' => 123,
                'reserva_id' => 458,
                'data' => '2024-03-22', // Friday
                'hi' => '08:00:00',
                'hf' => '10:00:00',
                'titulo' => 'Test Event',
                'solicitante' => 'John Doe',
                'email' => 'john@example.com',
                'participantes' => 30,
                'sala_nome' => 'B01',
                'atividade' => 'Graduação'
            ]
        ]);

        $payload = $this->mapper->mapUranoRequisitionGroupToReservationPayload($reservations);

        // Should use day_times structure
        $this->assertArrayHasKey('day_times', $payload);
        $this->assertArrayHasKey('repeat_days', $payload);
        $this->assertArrayHasKey('repeat_until', $payload);
        $this->assertArrayNotHasKey('horario_inicio', $payload);
        $this->assertArrayNotHasKey('horario_fim', $payload);

        // Check day_times structure
        $this->assertIsArray($payload['day_times']);
        $this->assertCount(3, $payload['day_times']); // Mon, Wed, Fri
        $this->assertArrayHasKey('1', $payload['day_times']); // Monday
        $this->assertArrayHasKey('3', $payload['day_times']); // Wednesday
        $this->assertArrayHasKey('5', $payload['day_times']); // Friday

        // Check times
        $this->assertEquals('8:00', $payload['day_times']['1']['start']);
        $this->assertEquals('10:00', $payload['day_times']['1']['end']);

        // Check repeat_days
        $this->assertEquals([1, 3, 5], $payload['repeat_days']);

        // Check dates
        $this->assertEquals('2024-03-18', $payload['data']);
        $this->assertEquals('2024-03-22', $payload['repeat_until']);
    }

    /** @test */
    public function it_throws_exception_for_empty_reservation_group()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Cannot map empty reservation group');

        $emptyGroup = collect([]);
        $this->mapper->mapUranoRequisitionGroupToReservationPayload($emptyGroup);
    }

    /** @test */
    public function it_throws_exception_for_mixed_rooms_in_group()
    {
        $reservations = collect([
            (object) [
                'requisicao_id' => 123,
                'reserva_id' => 456,
                'data' => '2024-03-18',
                'hi' => '08:00:00',
                'hf' => '10:00:00',
                'titulo' => 'Test Event',
                'solicitante' => 'John Doe',
                'email' => 'john@example.com',
                'participantes' => 30,
                'sala_nome' => 'B01',
                'atividade' => 'Graduação'
            ],
            (object) [
                'requisicao_id' => 123,
                'reserva_id' => 457,
                'data' => '2024-03-20',
                'hi' => '08:00:00',
                'hf' => '10:00:00',
                'titulo' => 'Test Event',
                'solicitante' => 'John Doe',
                'email' => 'john@example.com',
                'participantes' => 30,
                'sala_nome' => 'A101', // Different room!
                'atividade' => 'Graduação'
            ]
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Reservations in same requisition must have same room');

        $this->mapper->mapUranoRequisitionGroupToReservationPayload($reservations);
    }

    /** @test */
    public function it_extracts_repeat_days_from_reservations()
    {
        $reservations = collect([
            (object) ['data' => '2024-03-18'], // Monday (1)
            (object) ['data' => '2024-03-20'], // Wednesday (3)
            (object) ['data' => '2024-03-22'], // Friday (5)
        ]);

        $repeatDays = $this->invokePrivateMethod($this->mapper, 'extractRepeatDaysFromReservations', [$reservations]);

        $this->assertEquals([1, 3, 5], $repeatDays);
    }

    /** @test */
    public function it_builds_day_times_from_urano_reservations()
    {
        $reservations = collect([
            (object) [
                'data' => '2024-03-18', // Monday (1)
                'hi' => '08:00:00',
                'hf' => '10:00:00'
            ],
            (object) [
                'data' => '2024-03-20', // Wednesday (3)
                'hi' => '14:00:00',
                'hf' => '16:00:00'
            ]
        ]);

        $dayTimes = $this->invokePrivateMethod($this->mapper, 'buildDayTimesFromUranoReservations', [$reservations]);

        $this->assertIsArray($dayTimes);
        $this->assertArrayHasKey('1', $dayTimes); // Monday
        $this->assertArrayHasKey('3', $dayTimes); // Wednesday
        $this->assertEquals('8:00', $dayTimes['1']['start']);
        $this->assertEquals('10:00', $dayTimes['1']['end']);
        $this->assertEquals('14:00', $dayTimes['3']['start']);
        $this->assertEquals('16:00', $dayTimes['3']['end']);
    }

    /** @test */
    public function it_generates_observations_for_grouped_reservations()
    {
        $reservations = collect([
            (object) [
                'requisicao_id' => 123,
                'reserva_id' => 456,
                'data' => '2024-03-18',
                'participantes' => 30,
                'email' => 'john@example.com'
            ],
            (object) [
                'requisicao_id' => 123,
                'reserva_id' => 457,
                'data' => '2024-03-20',
                'participantes' => 30,
                'email' => 'john@example.com'
            ]
        ]);

        $observations = $this->invokePrivateMethod($this->mapper, 'generateUranoObservationsForGroup', [$reservations]);

        $this->assertStringContainsString('Importado do sistema Urano', $observations);
        $this->assertStringContainsString('ID Requisição Urano: 123', $observations);
        $this->assertStringContainsString('IDs Reservas Urano: 456, 457', $observations);
        $this->assertStringContainsString('Participantes: 30', $observations);
        $this->assertStringContainsString('Contato: john@example.com', $observations);
        $this->assertStringContainsString('Datas: 18/03/2024, 20/03/2024', $observations);
    }

    /** @test */
    public function it_handles_different_times_per_day_in_group()
    {
        $reservations = collect([
            (object) [
                'requisicao_id' => 123,
                'reserva_id' => 456,
                'data' => '2024-03-18', // Monday
                'hi' => '08:00:00',
                'hf' => '10:00:00',
                'titulo' => 'Test Event',
                'solicitante' => 'John Doe',
                'email' => 'john@example.com',
                'participantes' => 30,
                'sala_nome' => 'B01',
                'atividade' => 'Graduação'
            ],
            (object) [
                'requisicao_id' => 123,
                'reserva_id' => 457,
                'data' => '2024-03-20', // Wednesday - different time
                'hi' => '14:00:00',
                'hf' => '16:00:00',
                'titulo' => 'Test Event',
                'solicitante' => 'John Doe',
                'email' => 'john@example.com',
                'participantes' => 30,
                'sala_nome' => 'B01',
                'atividade' => 'Graduação'
            ]
        ]);

        $payload = $this->mapper->mapUranoRequisitionGroupToReservationPayload($reservations);

        // Should use day_times with different times per day
        $this->assertArrayHasKey('day_times', $payload);
        $this->assertEquals('8:00', $payload['day_times']['1']['start']); // Monday 8-10
        $this->assertEquals('10:00', $payload['day_times']['1']['end']);
        $this->assertEquals('14:00', $payload['day_times']['3']['start']); // Wednesday 14-16
        $this->assertEquals('16:00', $payload['day_times']['3']['end']);
    }
}