<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
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

        $this->mockApiClient = Mockery::mock(SalasApiClient::class);
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
        $this->assertEquals('8:0', $payload['horario_inicio']);
        $this->assertEquals('9:59', $payload['horario_fim']); // 10:00 - 1 minute
        $this->assertEquals('Aula - MAC0110 T.01', $payload['nome']);
        $this->assertEquals(123, $payload['sala_id']);
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
            '1' => ['start' => '8:0', 'end' => '9:59'], // seg
            '3' => ['start' => '14:0', 'end' => '16:30'] // qua
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
}