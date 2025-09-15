<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\ReservationApiService;
use App\Services\SalasApiClient;
use App\Services\ReservationMapper;
use App\Models\SchoolClass;
use App\Models\Requisition;
use App\Models\Room;
use App\Models\SchoolTerm;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mockery;
use Exception;

class ReservationApiServiceTest extends TestCase
{
    private $salasApiClient;
    private $reservationMapper;
    private $reservationApiService;
    private $schoolClass;
    private $requisition;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock dependencies
        $this->salasApiClient = Mockery::mock(SalasApiClient::class);
        $this->reservationMapper = Mockery::mock(ReservationMapper::class);

        // Create service instance
        $this->reservationApiService = new ReservationApiService(
            $this->salasApiClient,
            $this->reservationMapper
        );

        // Create test models
        $this->createTestModels();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function createTestModels(): void
    {
        // Create Room
        $room = new Room();
        $room->id = 1;
        $room->nome = 'Sala 01';

        // Create SchoolTerm
        $schoolTerm = new SchoolTerm();
        $schoolTerm->id = 1;
        $schoolTerm->dtamaxres = '31/12/2024';

        // Create SchoolClass
        $this->schoolClass = new SchoolClass();
        $this->schoolClass->id = 1;
        $this->schoolClass->coddis = 'MAC0110';
        $this->schoolClass->codtur = '202410101';
        $this->schoolClass->nomdis = 'Introdução à Computação';

        // Mock relationships
        $this->schoolClass->setRelation('room', $room);
        $this->schoolClass->setRelation('schoolterm', $schoolTerm);

        // Mock class schedules
        $schedule = (object) [
            'diasmnocp' => 'seg',
            'horent' => '08:00:00',
            'horsai' => '10:00:00'
        ];
        $this->schoolClass->setRelation('classschedules', collect([$schedule]));

        // Create Requisition
        $this->requisition = new Requisition();
        $this->requisition->id = 1;
        $this->requisition->titulo = 'MAC0110 T.01';
    }

    /** @test */
    public function test_create_reservations_from_school_class_success()
    {
        // Arrange
        $payload = [
            'nome' => 'Aula - MAC0110 T.01',
            'data' => '2024-01-15',
            'horario_inicio' => '08:00',
            'horario_fim' => '10:00',
            'sala_id' => 1,
            'finalidade_id' => 1,
            'tipo_responsaveis' => 'eu',
            'repeat_days' => [1],
            'repeat_until' => '2024-12-31'
        ];

        $apiResponse = [
            'data' => [
                'id' => 100,
                'nome' => 'Aula - MAC0110 T.01',
                'recurrent' => true,
                'instances_created' => 15,
                'parent_id' => 100
            ]
        ];

        // Mock expectations
        $this->reservationMapper
            ->shouldReceive('mapSchoolClassToReservationPayload')
            ->with($this->schoolClass)
            ->once()
            ->andReturn($payload);

        $this->salasApiClient
            ->shouldReceive('post')
            ->with('/api/v1/reservas', $payload)
            ->once()
            ->andReturn($apiResponse);

        Log::shouldReceive('info')->atLeast(1);
        Log::shouldReceive('debug')->atLeast(0);

        // Act
        $result = $this->reservationApiService->createReservationsFromSchoolClass($this->schoolClass);

        // Assert
        $this->assertIsArray($result);
        $this->assertCount(15, $result); // Main reservation + 14 instances
        $this->assertEquals(100, $result[0]['id']);
        $this->assertEquals('Aula - MAC0110 T.01', $result[0]['nome']);
        $this->assertTrue($result[0]['recurrent']);
    }

    /** @test */
    public function test_create_reservations_throws_exception_when_no_room()
    {
        // Arrange
        $this->schoolClass->setRelation('room', null);

        Log::shouldReceive('info')->atLeast(1);
        Log::shouldReceive('debug')->atLeast(0);
        Log::shouldReceive('error')->atLeast(0);

        // Act & Assert
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('SchoolClass deve ter uma sala alocada para operações via API Salas');

        $this->reservationApiService->createReservationsFromSchoolClass($this->schoolClass);
    }

    /** @test */
    public function test_create_reservations_throws_exception_when_no_schedules()
    {
        // Arrange
        $this->schoolClass->setRelation('classschedules', collect([]));

        Log::shouldReceive('info')->atLeast(1);
        Log::shouldReceive('debug')->atLeast(0);
        Log::shouldReceive('error')->atLeast(0);

        // Act & Assert
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('SchoolClass deve ter pelo menos um horário de aula definido');

        $this->reservationApiService->createReservationsFromSchoolClass($this->schoolClass);
    }

    /** @test */
    public function test_check_availability_for_school_class_available()
    {
        // Arrange
        $this->reservationMapper
            ->shouldReceive('getSalaIdFromNome')
            ->with('Sala 01')
            ->once()
            ->andReturn(1);

        Cache::shouldReceive('remember')
            ->once()
            ->andReturnUsing(function ($key, $ttl, $callback) {
                // Mock the actual implementation here to avoid calling real API
                return true; // Available
            });

        Log::shouldReceive('info')->atLeast(1);
        Log::shouldReceive('error')->atLeast(0); // Allow error logs from enhanced context

        // Act
        $result = $this->reservationApiService->checkAvailabilityForSchoolClass($this->schoolClass);

        // Assert
        $this->assertTrue($result);
    }

    /** @test */
    public function test_check_availability_for_school_class_not_available()
    {
        // Arrange
        $this->reservationMapper
            ->shouldReceive('getSalaIdFromNome')
            ->with('Sala 01')
            ->once()
            ->andReturn(1);

        Cache::shouldReceive('remember')
            ->once()
            ->andReturnUsing(function ($key, $ttl, $callback) {
                // Mock the actual implementation here to simulate conflicts
                return false; // Not available
            });

        Log::shouldReceive('info')->atLeast(1);
        Log::shouldReceive('error')->atLeast(0); // Allow error logs from enhanced context

        // Act
        $result = $this->reservationApiService->checkAvailabilityForSchoolClass($this->schoolClass);

        // Assert
        $this->assertFalse($result);
    }

    /** @test */
    public function test_cancel_reservations_for_requisition_success()
    {
        // Arrange
        $existingReservations = [
            [
                'id' => 100,
                'nome' => 'MAC0110 T.01',
                'recurrent' => true
            ],
            [
                'id' => 101,
                'nome' => 'MAC0110 T.01',
                'recurrent' => false
            ]
        ];

        $this->salasApiClient
            ->shouldReceive('get')
            ->with('/api/v1/reservas', ['nome' => 'MAC0110 T.01'])
            ->once()
            ->andReturn(['data' => $existingReservations]);

        $this->salasApiClient
            ->shouldReceive('delete')
            ->with('/api/v1/reservas/100?purge=true')
            ->once();

        $this->salasApiClient
            ->shouldReceive('delete')
            ->with('/api/v1/reservas/101')
            ->once();

        Log::shouldReceive('info')->atLeast(1);
        Log::shouldReceive('debug')->atLeast(0);

        // Act
        $result = $this->reservationApiService->cancelReservationsForRequisition($this->requisition);

        // Assert
        $this->assertTrue($result);
    }

    /** @test */
    public function test_cancel_reservations_no_reservations_found()
    {
        // Arrange
        $this->salasApiClient
            ->shouldReceive('get')
            ->with('/api/v1/reservas', ['nome' => 'MAC0110 T.01'])
            ->once()
            ->andReturn(['data' => []]);

        Log::shouldReceive('info')->atLeast(1);
        Log::shouldReceive('debug')->atLeast(0);
        Log::shouldReceive('warning')->atLeast(0);

        // Act
        $result = $this->reservationApiService->cancelReservationsForRequisition($this->requisition);

        // Assert
        $this->assertTrue($result); // No reservations to cancel is considered success
    }

    /** @test */
    public function test_cancel_reservations_partial_failure()
    {
        // Arrange
        $existingReservations = [
            [
                'id' => 100,
                'nome' => 'MAC0110 T.01',
                'recurrent' => true
            ],
            [
                'id' => 101,
                'nome' => 'MAC0110 T.01',
                'recurrent' => false
            ]
        ];

        $this->salasApiClient
            ->shouldReceive('get')
            ->with('/api/v1/reservas', ['nome' => 'MAC0110 T.01'])
            ->once()
            ->andReturn(['data' => $existingReservations]);

        $this->salasApiClient
            ->shouldReceive('delete')
            ->with('/api/v1/reservas/100?purge=true')
            ->once();

        $this->salasApiClient
            ->shouldReceive('delete')
            ->with('/api/v1/reservas/101')
            ->once()
            ->andThrow(new Exception('API Error'));

        Log::shouldReceive('info')->atLeast(1);
        Log::shouldReceive('debug')->atLeast(0);
        Log::shouldReceive('warning')->atLeast(0);
        Log::shouldReceive('debug')->atLeast(0);

        // Act
        $result = $this->reservationApiService->cancelReservationsForRequisition($this->requisition);

        // Assert
        $this->assertFalse($result); // Partial failure should return false
    }

    /** @test */
    public function test_handles_api_authentication_error()
    {
        // Arrange
        $this->reservationMapper
            ->shouldReceive('mapSchoolClassToReservationPayload')
            ->with($this->schoolClass)
            ->once()
            ->andReturn([]);

        $this->salasApiClient
            ->shouldReceive('post')
            ->once()
            ->andThrow(new Exception('Authentication failed - token may have expired'));

        Log::shouldReceive('info')->atLeast(1);
        Log::shouldReceive('debug')->atLeast(0);
        Log::shouldReceive('error')->atLeast(0);

        // Act & Assert
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Authentication failed');

        $this->reservationApiService->createReservationsFromSchoolClass($this->schoolClass);
    }

    /** @test */
    public function test_handles_api_rate_limit_error()
    {
        // Arrange
        $this->reservationMapper
            ->shouldReceive('mapSchoolClassToReservationPayload')
            ->with($this->schoolClass)
            ->once()
            ->andReturn([]);

        $this->salasApiClient
            ->shouldReceive('post')
            ->once()
            ->andThrow(new Exception('Rate limit exceeded. Retry after 60 seconds'));

        Log::shouldReceive('info')->atLeast(1);
        Log::shouldReceive('debug')->atLeast(0);
        Log::shouldReceive('warning')->atLeast(0);

        // Act & Assert
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Rate limit exceeded');

        $this->reservationApiService->createReservationsFromSchoolClass($this->schoolClass);
    }

    /** @test */
    public function test_handles_api_validation_error()
    {
        // Arrange
        $this->reservationMapper
            ->shouldReceive('mapSchoolClassToReservationPayload')
            ->with($this->schoolClass)
            ->once()
            ->andReturn([]);

        $this->salasApiClient
            ->shouldReceive('post')
            ->once()
            ->andThrow(new Exception('Validation failed: Data inválida'));

        Log::shouldReceive('info')->atLeast(1);
        Log::shouldReceive('debug')->atLeast(0);
        Log::shouldReceive('warning')->atLeast(0);

        // Act & Assert
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Validation failed');

        $this->reservationApiService->createReservationsFromSchoolClass($this->schoolClass);
    }

    /** @test */
    public function test_handles_connection_error()
    {
        // Arrange
        $this->reservationMapper
            ->shouldReceive('mapSchoolClassToReservationPayload')
            ->with($this->schoolClass)
            ->once()
            ->andReturn([]);

        $this->salasApiClient
            ->shouldReceive('post')
            ->once()
            ->andThrow(new Exception('Connection failed: timeout'));

        Log::shouldReceive('info')->atLeast(1);
        Log::shouldReceive('debug')->atLeast(0);
        Log::shouldReceive('error')->atLeast(0);

        // Act & Assert
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Connection failed');

        $this->reservationApiService->createReservationsFromSchoolClass($this->schoolClass);
    }

    /** @test */
    public function test_handles_api_unavailability_scenario()
    {
        // Arrange - Simulate API completely unavailable (AC5 explicit error behavior)
        $this->reservationMapper
            ->shouldReceive('mapSchoolClassToReservationPayload')
            ->with($this->schoolClass)
            ->once()
            ->andReturn([]);

        $this->salasApiClient
            ->shouldReceive('post')
            ->once()
            ->andThrow(new Exception('Service unavailable: API Salas is temporarily down'));

        Log::shouldReceive('info')->atLeast(1);
        Log::shouldReceive('debug')->atLeast(0);
        Log::shouldReceive('error')->atLeast(1); // Error should be logged

        // Act & Assert - Should throw exception without fallback (AC5 behavior)
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Service unavailable');

        $this->reservationApiService->createReservationsFromSchoolClass($this->schoolClass);
    }

    /** @test */
    public function test_handles_http_401_unauthorized_error()
    {
        // Arrange
        $this->reservationMapper
            ->shouldReceive('mapSchoolClassToReservationPayload')
            ->with($this->schoolClass)
            ->once()
            ->andReturn([]);

        $this->salasApiClient
            ->shouldReceive('post')
            ->once()
            ->andThrow(new Exception('HTTP 401: Unauthorized - Invalid token'));

        Log::shouldReceive('info')->atLeast(1);
        Log::shouldReceive('debug')->atLeast(0);
        Log::shouldReceive('error')->atLeast(1);

        // Act & Assert
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('HTTP 401');

        $this->reservationApiService->createReservationsFromSchoolClass($this->schoolClass);
    }

    /** @test */
    public function test_handles_http_403_forbidden_error()
    {
        // Arrange
        $this->reservationMapper
            ->shouldReceive('mapSchoolClassToReservationPayload')
            ->with($this->schoolClass)
            ->once()
            ->andReturn([]);

        $this->salasApiClient
            ->shouldReceive('post')
            ->once()
            ->andThrow(new Exception('HTTP 403: Forbidden - Insufficient permissions'));

        Log::shouldReceive('info')->atLeast(1);
        Log::shouldReceive('debug')->atLeast(0);
        Log::shouldReceive('error')->atLeast(1);

        // Act & Assert
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('HTTP 403');

        $this->reservationApiService->createReservationsFromSchoolClass($this->schoolClass);
    }

    /** @test */
    public function test_handles_http_429_rate_limit_error()
    {
        // Arrange
        $this->reservationMapper
            ->shouldReceive('mapSchoolClassToReservationPayload')
            ->with($this->schoolClass)
            ->once()
            ->andReturn([]);

        $this->salasApiClient
            ->shouldReceive('post')
            ->once()
            ->andThrow(new Exception('HTTP 429: Too Many Requests - Rate limit exceeded'));

        Log::shouldReceive('info')->atLeast(1);
        Log::shouldReceive('debug')->atLeast(0);
        Log::shouldReceive('warning')->atLeast(0);

        // Act & Assert
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('HTTP 429');

        $this->reservationApiService->createReservationsFromSchoolClass($this->schoolClass);
    }

    /** @test */
    public function test_handles_http_500_server_error()
    {
        // Arrange
        $this->reservationMapper
            ->shouldReceive('mapSchoolClassToReservationPayload')
            ->with($this->schoolClass)
            ->once()
            ->andReturn([]);

        $this->salasApiClient
            ->shouldReceive('post')
            ->once()
            ->andThrow(new Exception('HTTP 500: Internal server error'));

        Log::shouldReceive('info')->atLeast(1);
        Log::shouldReceive('debug')->atLeast(0);
        Log::shouldReceive('error')->atLeast(1);

        // Act & Assert
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('HTTP 500');

        $this->reservationApiService->createReservationsFromSchoolClass($this->schoolClass);
    }

    /** @test */
    public function test_handles_http_422_validation_error_detailed()
    {
        // Arrange
        $this->reservationMapper
            ->shouldReceive('mapSchoolClassToReservationPayload')
            ->with($this->schoolClass)
            ->once()
            ->andReturn([]);

        $validationErrorResponse = [
            'message' => 'The given data was invalid.',
            'errors' => [
                'sala_id' => ['The selected sala id is invalid.'],
                'horario_inicio' => ['The horario inicio field is required.']
            ]
        ];

        $this->salasApiClient
            ->shouldReceive('post')
            ->once()
            ->andThrow(new Exception('HTTP 422: Validation failed - ' . json_encode($validationErrorResponse)));

        Log::shouldReceive('info')->atLeast(1);
        Log::shouldReceive('debug')->atLeast(0);
        Log::shouldReceive('warning')->atLeast(0);

        // Act & Assert
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('HTTP 422');

        $this->reservationApiService->createReservationsFromSchoolClass($this->schoolClass);
    }

    /** @test */
    public function test_handles_malformed_api_response()
    {
        // Arrange
        $payload = [
            'nome' => 'Aula - MAC0110 T.01',
            'data' => '2024-01-15',
            'horario_inicio' => '08:00',
            'horario_fim' => '10:00',
            'sala_id' => 1,
            'finalidade_id' => 1,
            'tipo_responsaveis' => 'eu',
            'repeat_days' => [1],
            'repeat_until' => '2024-12-31'
        ];

        // Malformed response without expected 'data' key
        $malformedResponse = [
            'id' => 100,
            'nome' => 'Aula - MAC0110 T.01'
            // Missing 'data' wrapper and other expected fields
        ];

        $this->reservationMapper
            ->shouldReceive('mapSchoolClassToReservationPayload')
            ->with($this->schoolClass)
            ->once()
            ->andReturn($payload);

        $this->salasApiClient
            ->shouldReceive('post')
            ->with('/api/v1/reservas', $payload)
            ->once()
            ->andReturn($malformedResponse);

        Log::shouldReceive('info')->atLeast(1);
        Log::shouldReceive('debug')->atLeast(0);
        Log::shouldReceive('warning')->atLeast(0);
        Log::shouldReceive('error')->atLeast(0);

        // Act & Assert - Should handle gracefully or throw appropriate exception
        $result = $this->reservationApiService->createReservationsFromSchoolClass($this->schoolClass);
        
        // Should still return an array, even if malformed
        $this->assertIsArray($result);
    }

    /** @test */
    public function test_handles_concurrent_reservation_conflicts()
    {
        // Arrange - Simulate concurrent reservation conflict scenario
        $this->reservationMapper
            ->shouldReceive('mapSchoolClassToReservationPayload')
            ->with($this->schoolClass)
            ->once()
            ->andReturn([]);

        $this->salasApiClient
            ->shouldReceive('post')
            ->once()
            ->andThrow(new Exception('HTTP 409: Conflict - Room already reserved for this time slot'));

        Log::shouldReceive('info')->atLeast(1);
        Log::shouldReceive('debug')->atLeast(0);
        Log::shouldReceive('error')->atLeast(1);

        // Act & Assert
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('HTTP 409');

        $this->reservationApiService->createReservationsFromSchoolClass($this->schoolClass);
    }

    /** @test */
    public function test_check_api_health_method_exists()
    {
        // Test that the service has health check capability for AC6 validation
        $this->assertTrue(method_exists($this->reservationApiService, 'checkApiHealth'));
    }

    /** @test */
    public function test_handles_network_timeout()
    {
        // Arrange
        $this->reservationMapper
            ->shouldReceive('mapSchoolClassToReservationPayload')
            ->with($this->schoolClass)
            ->once()
            ->andReturn([]);

        $this->salasApiClient
            ->shouldReceive('post')
            ->once()
            ->andThrow(new Exception('Network timeout: Request timed out after 30 seconds'));

        Log::shouldReceive('info')->atLeast(1);
        Log::shouldReceive('debug')->atLeast(0);
        Log::shouldReceive('error')->atLeast(1);

        // Act & Assert
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Network timeout');

        $this->reservationApiService->createReservationsFromSchoolClass($this->schoolClass);
    }

    /** @test */
    public function test_check_availability_for_multiple_distinct_schedules()
    {
        // Arrange - Create school class with multiple distinct schedules
        $schoolClass = Mockery::mock(SchoolClass::class);
        $room = Mockery::mock(Room::class);
        $room->shouldReceive('getAttribute')->with('nome')->andReturn('B01');

        $schoolClass->shouldReceive('getAttribute')->with('id')->andReturn(1);
        $schoolClass->shouldReceive('getAttribute')->with('room')->andReturn($room);

        // Mock multiple schedules
        $schedule1 = (object)['diasmnocp' => 'seg', 'horent' => '08:00:00', 'horsai' => '10:00:00'];
        $schedule2 = (object)['diasmnocp' => 'qua', 'horent' => '14:00:00', 'horsai' => '16:00:00'];
        $schedules = collect([$schedule1, $schedule2]);

        $schoolClass->shouldReceive('getAttribute')->with('classschedules')->andReturn($schedules);

        $this->reservationMapper
            ->shouldReceive('getSalaIdFromNome')
            ->with('B01')
            ->once()
            ->andReturn(1);

        // Mock availability check that will be called for each schedule
        Cache::shouldReceive('remember')
            ->once()
            ->andReturnUsing(function ($key, $ttl, $callback) use ($schoolClass) {
                // Simulate checking each schedule individually
                return $callback();
            });

        // Mock API calls for conflict checking (one per schedule)
        $this->salasApiClient
            ->shouldReceive('get')
            ->with('/api/v1/reservas', Mockery::any())
            ->andReturn(['data' => []]); // No conflicts

        Log::shouldReceive('info')->atLeast(1);
        Log::shouldReceive('warning')->atLeast(0);

        // Act
        $result = $this->reservationApiService->checkAvailabilityForSchoolClass($schoolClass);

        // Assert
        $this->assertTrue($result);
    }

    /** @test */
    public function test_check_availability_logs_multiple_schedule_information()
    {
        // Arrange - Create school class with multiple schedules
        $schoolClass = Mockery::mock(SchoolClass::class);
        $room = Mockery::mock(Room::class);
        $room->shouldReceive('getAttribute')->with('nome')->andReturn('A132');

        $schoolClass->shouldReceive('getAttribute')->with('id')->andReturn(2);
        $schoolClass->shouldReceive('getAttribute')->with('room')->andReturn($room);

        // Mock three different schedules
        $schedule1 = (object)['diasmnocp' => 'seg', 'horent' => '08:00:00', 'horsai' => '10:00:00'];
        $schedule2 = (object)['diasmnocp' => 'ter', 'horent' => '10:00:00', 'horsai' => '12:00:00'];
        $schedule3 = (object)['diasmnocp' => 'qua', 'horent' => '14:00:00', 'horsai' => '16:00:00'];
        $schedules = collect([$schedule1, $schedule2, $schedule3]);

        $schoolClass->shouldReceive('getAttribute')->with('classschedules')->andReturn($schedules);

        $this->reservationMapper
            ->shouldReceive('getSalaIdFromNome')
            ->with('A132')
            ->once()
            ->andReturn(132);

        Cache::shouldReceive('remember')
            ->once()
            ->andReturnUsing(function ($key, $ttl, $callback) {
                return $callback();
            });

        // Mock API calls for each schedule
        $this->salasApiClient
            ->shouldReceive('get')
            ->times(3) // Should check each schedule
            ->with('/api/v1/reservas', Mockery::any())
            ->andReturn(['data' => []]);

        // Expect detailed logging about multiple schedules
        Log::shouldReceive('info')
            ->with('Verificação de disponibilidade: sem conflitos', Mockery::on(function ($context) {
                return isset($context['total_schedules_checked']) &&
                       $context['total_schedules_checked'] === 3 &&
                       isset($context['has_multiple_schedules']) &&
                       $context['has_multiple_schedules'] === true;
            }));

        Log::shouldReceive('info')->atLeast(1);
        Log::shouldReceive('warning')->atLeast(0);

        // Act
        $result = $this->reservationApiService->checkAvailabilityForSchoolClass($schoolClass);

        // Assert
        $this->assertTrue($result);
    }

    /** @test */
    public function test_check_availability_handles_conflicts_in_multiple_schedules()
    {
        // Arrange - Create school class with conflicting schedules
        $schoolClass = Mockery::mock(SchoolClass::class);
        $room = Mockery::mock(Room::class);
        $room->shouldReceive('getAttribute')->with('nome')->andReturn('B16');

        $schoolClass->shouldReceive('getAttribute')->with('id')->andReturn(3);
        $schoolClass->shouldReceive('getAttribute')->with('room')->andReturn($room);

        $schedule1 = (object)['diasmnocp' => 'seg', 'horent' => '08:00:00', 'horsai' => '10:00:00'];
        $schedule2 = (object)['diasmnocp' => 'qua', 'horent' => '14:00:00', 'horsai' => '16:00:00'];
        $schedules = collect([$schedule1, $schedule2]);

        $schoolClass->shouldReceive('getAttribute')->with('classschedules')->andReturn($schedules);

        $this->reservationMapper
            ->shouldReceive('getSalaIdFromNome')
            ->with('B16')
            ->once()
            ->andReturn(16);

        Cache::shouldReceive('remember')
            ->once()
            ->andReturnUsing(function ($key, $ttl, $callback) {
                return $callback();
            });

        // Mock API response with conflict on second schedule
        $this->salasApiClient
            ->shouldReceive('get')
            ->with('/api/v1/reservas', Mockery::any())
            ->andReturn(['data' => []], // First schedule - no conflict
                       ['data' => [     // Second schedule - has conflict
                           [
                               'id' => 999,
                               'nome' => 'Existing Reservation',
                               'data' => '2024-01-17',
                               'horario_inicio' => '14:30',
                               'horario_fim' => '15:30'
                           ]
                       ]]);

        // Expect warning logs for conflicts
        Log::shouldReceive('warning')
            ->with('Conflito encontrado em horário específico', Mockery::on(function ($context) {
                return isset($context['dia_semana']) &&
                       $context['dia_semana'] === 'qua' &&
                       isset($context['conflitos_encontrados']) &&
                       $context['conflitos_encontrados'] > 0;
            }));

        Log::shouldReceive('warning')
            ->with('Verificação de disponibilidade: conflitos encontrados', Mockery::on(function ($context) {
                return isset($context['total_conflicts_found']) &&
                       $context['total_conflicts_found'] > 0 &&
                       isset($context['availability_result']) &&
                       $context['availability_result'] === false;
            }));

        Log::shouldReceive('info')->atLeast(0);
        Log::shouldReceive('warning')->atLeast(0);

        // Act
        $result = $this->reservationApiService->checkAvailabilityForSchoolClass($schoolClass);

        // Assert
        $this->assertFalse($result);
    }
}