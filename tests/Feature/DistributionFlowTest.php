<?php

namespace Tests\Feature;

use App\Models\ClassSchedule;
use App\Models\Room;
use App\Models\SchoolClass;
use App\Models\SchoolTerm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DistributionFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['alocacao.solver.url' => 'http://solver.test']);
        config(['alocacao.solver.api_token' => 'test-token']);
    }

    private function actingAsOperator(): self
    {
        Role::firstOrCreate(['name' => 'Operador', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole('Operador');
        return $this->actingAs($user);
    }

    /** @test */
    public function operator_can_dispatch_distribution()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();
        $class = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => null,
            'externa' => false,
        ]);
        $schedule = ClassSchedule::factory()->seg()->morning()->create();
        $class->classschedules()->attach($schedule);

        Http::fake([
            'http://solver.test/api/v1/solve' => Http::response([
                'job_id' => 'flow-123',
            ], 202),
        ]);

        $response = $this->actingAsOperator()
            ->patch('/rooms/distributes', ['rooms_id' => [$room->id]]);

        $response->assertRedirect();
        $response->assertSessionHas('alert-info');

        $cached = Cache::get("allocation:{$term->id}");
        $this->assertNotNull($cached);
        $this->assertEquals('flow-123', $cached['job_id']);
    }

    /** @test */
    public function operator_can_dispatch_distribution_with_solver_config_overrides()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();
        $class = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => null,
            'externa' => false,
        ]);
        $schedule = ClassSchedule::factory()->seg()->morning()->create();
        $class->classschedules()->attach($schedule);

        Http::fake([
            'http://solver.test/api/v1/solve' => Http::response([
                'job_id' => 'flow-config-123',
            ], 202),
        ]);

        $response = $this->actingAsOperator()
            ->patch('/rooms/distributes', [
                'rooms_id' => [$room->id],
                'solver_config' => [
                    'strict_capacity' => false,
                    'unassigned_penalty' => 2000.0,
                    'historical_estimation_method' => 'none',
                ],
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('alert-info');

        $cached = Cache::get("allocation:{$term->id}");
        $this->assertNotNull($cached);
        $this->assertEquals('flow-config-123', $cached['job_id']);

        $solverLog = \App\Models\SolverLog::where('job_id', 'flow-config-123')->first();
        $this->assertNotNull($solverLog);
        $this->assertFalse($solverLog->payload['config']['strict_capacity']);
        $this->assertEquals(2000.0, $solverLog->payload['config']['unassigned_penalty']);
        $this->assertEquals('none', $solverLog->payload['config']['historical_estimation_method']);
    }

    /** @test */
    public function dispatch_records_manual_count_based_on_preassigned_groups()
    {
        $term = SchoolTerm::factory()->create();
        $roomA = Room::factory()->create();
        $roomB = Room::factory()->create();

        $manualClass = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => $roomA->id,
            'externa' => false,
        ]);
        $autoClass = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => null,
            'externa' => false,
        ]);
        $schedule = ClassSchedule::factory()->seg()->morning()->create();
        $manualClass->classschedules()->attach($schedule);
        $autoClass->classschedules()->attach($schedule);

        Http::fake([
            'http://solver.test/api/v1/solve' => Http::response([
                'job_id' => 'flow-manual-count',
            ], 202),
        ]);

        $this->actingAsOperator()
            ->patch('/rooms/distributes', ['rooms_id' => [$roomB->id]]);

        $solverLog = \App\Models\SolverLog::where('job_id', 'flow-manual-count')->first();
        $this->assertNotNull($solverLog);
        $this->assertEquals(1, $solverLog->manual_count);
    }

    /** @test */
    public function distributes_request_rejects_invalid_solver_config()
    {
        $room = Room::factory()->create();

        $response = $this->actingAsOperator()
            ->patch('/rooms/distributes', [
                'rooms_id' => [$room->id],
                'solver_config' => [
                    'historical_estimation_method' => 'invalid_method',
                    'historical_lookback_years' => -1,
                ],
            ]);

        $response->assertSessionHasErrors([
            'solver_config.historical_estimation_method',
            'solver_config.historical_lookback_years',
        ]);
    }

    /** @test */
    public function double_dispatch_is_prevented()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();

        Cache::put("allocation:{$term->id}", [
            'job_id' => 'existing',
            'status' => 'solving',
            'progress' => 50,
        ], now()->addHour());

        $response = $this->actingAsOperator()
            ->patch('/rooms/distributes', ['rooms_id' => [$room->id]]);

        $response->assertRedirect();
        $response->assertSessionHas('alert-warning');
    }

    /** @test */
    public function monitor_returns_distribution_progress()
    {
        $term = SchoolTerm::factory()->create();

        Cache::put("allocation:{$term->id}", [
            'job_id' => 'mon-123',
            'status' => 'solving',
            'progress' => 75,
            'message' => 'Quase lá...',
        ], now()->addHour());

        $response = $this->actingAsOperator()
            ->get('/monitor/getDistributionProcess');

        $response->assertOk();
        $response->assertJson([
            'progress' => 75,
            'status' => 'solving',
            'message' => 'Quase lá...',
            'failed' => false,
        ]);
    }

    /** @test */
    public function monitor_returns_null_when_no_active_distribution()
    {
        $response = $this->actingAsOperator()
            ->get('/monitor/getDistributionProcess');

        $response->assertOk();
        $response->assertExactJson([]);
    }

    /** @test */
    public function stop_distribution_sends_request_to_solver()
    {
        $term = SchoolTerm::factory()->create();

        Cache::put("allocation:{$term->id}", [
            'job_id' => 'stop-123',
            'status' => 'solving',
        ], now()->addHour());

        Http::fake([
            'http://solver.test/api/v1/jobs/stop-123/stop' => Http::response(['status' => 'ok'], 200),
        ]);

        $response = $this->actingAsOperator()
            ->post('/rooms/distribution/stop');

        $response->assertRedirect();
        $response->assertSessionHas('alert-info');

        Http::assertSent(function ($request) {
            return $request->url() === 'http://solver.test/api/v1/jobs/stop-123/stop';
        });

        $cached = Cache::get("allocation:{$term->id}");
        $this->assertEquals('cancelled', $cached['status']);
    }

    /** @test */
    public function stop_distribution_marks_cancelled_even_when_solver_unreachable()
    {
        $term = SchoolTerm::factory()->create();

        Cache::put("allocation:{$term->id}", [
            'job_id' => 'stop-456',
            'status' => 'solving',
        ], now()->addHour());

        Http::fake([
            'http://solver.test/api/v1/jobs/stop-456/stop' => Http::response('', 500),
        ]);

        $response = $this->actingAsOperator()
            ->post('/rooms/distribution/stop');

        $response->assertRedirect();
        $response->assertSessionHas('alert-info');

        $cached = Cache::get("allocation:{$term->id}");
        $this->assertEquals('cancelled', $cached['status']);
    }

    /** @test */
    public function stop_distribution_warns_when_no_active_job()
    {
        SchoolTerm::factory()->create();

        $response = $this->actingAsOperator()
            ->post('/rooms/distribution/stop');

        $response->assertRedirect();
        $response->assertSessionHas('alert-warning');
    }

    /** @test */
    public function guests_cannot_dispatch_distribution()
    {
        $room = Room::factory()->create();

        $response = $this->patch('/rooms/distributes', ['rooms_id' => [$room->id]]);
        $response->assertForbidden();
    }

    /** @test */
    public function guests_cannot_access_monitor()
    {
        $response = $this->get('/monitor/getDistributionProcess');
        $response->assertForbidden();
    }

    /** @test */
    public function monitor_reports_failure_status()
    {
        $term = SchoolTerm::factory()->create();

        Cache::put("allocation:{$term->id}", [
            'job_id' => 'fail-123',
            'status' => 'error',
            'progress' => 100,
            'message' => 'Solver crashed',
        ], now()->addHour());

        $response = $this->actingAsOperator()
            ->get('/monitor/getDistributionProcess');

        $response->assertOk();
        $response->assertJson([
            'progress' => 100,
            'status' => 'error',
            'failed' => true,
        ]);
    }

    /** @test */
    public function monitor_reports_cancelled_status()
    {
        $term = SchoolTerm::factory()->create();

        Cache::put("allocation:{$term->id}", [
            'job_id' => 'cancel-123',
            'status' => 'cancelled',
            'progress' => 100,
            'message' => 'Distribuição cancelada.',
        ], now()->addHour());

        $response = $this->actingAsOperator()
            ->get('/monitor/getDistributionProcess');

        $response->assertOk();
        $response->assertJson([
            'progress' => 100,
            'status' => 'cancelled',
            'failed' => true,
        ]);
    }

    /** @test */
    public function new_distribution_allowed_after_cancelled()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();

        Cache::put("allocation:{$term->id}", [
            'job_id' => 'old-cancelled',
            'status' => 'cancelled',
            'progress' => 100,
        ], now()->addHour());

        Http::fake([
            'http://solver.test/api/v1/solve' => Http::response(['job_id' => 'new-job'], 200),
        ]);

        $response = $this->actingAsAdmin()
            ->patch('/rooms/distributes', ['rooms_id' => [$room->id]]);

        $response->assertRedirect();
        $response->assertSessionHas('alert-info');
    }

    /** @test */
    public function fallback_distribution_rescues_result_from_solver()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();
        $class = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => null,
        ]);

        Cache::put("allocation:{$term->id}", [
            'job_id' => 'fallback-123',
            'status' => 'solving',
        ], now()->addHour());

        Http::fake([
            'http://solver.test/api/v1/jobs/fallback-123/result' => Http::response([
                'job_id' => 'fallback-123',
                'status' => 'success',
                'allocations' => [
                    ['group_id' => $class->id, 'room_id' => $room->id],
                ],
                'unassigned_groups' => [],
                'suggestions' => [],
            ], 200),
        ]);

        $response = $this->actingAsOperator()
            ->post('/rooms/distribution/fallback');

        $response->assertRedirect();
        $response->assertSessionHas('alert-info');

        $this->assertDatabaseHas('school_classes', [
            'id' => $class->id,
            'room_id' => $room->id,
        ]);
    }

    /** @test */
    public function fallback_distribution_warns_when_no_job_exists()
    {
        SchoolTerm::factory()->create();

        $response = $this->actingAsOperator()
            ->post('/rooms/distribution/fallback');

        $response->assertRedirect();
        $response->assertSessionHas('alert-warning');
    }

    /** @test */
    public function result_webhook_generates_comparison_message()
    {
        $term = SchoolTerm::factory()->create();
        $roomA = Room::factory()->create();
        $roomB = Room::factory()->create();

        $classNew = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => null,
            'externa' => false,
        ]);
        $classChanged = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => $roomA->id,
            'externa' => false,
        ]);
        $classUnchanged = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => $roomA->id,
            'externa' => false,
        ]);

        $solverLog = \App\Models\SolverLog::factory()->create([
            'school_term_id' => $term->id,
            'job_id' => 'compare-123',
            'status' => 'solving',
        ]);

        \App\Models\AllocationState::create([
            'school_term_id' => $term->id,
            'name' => 'Pré-Solver',
            'solver_log_id' => $solverLog->id,
            'allocations' => [
                $classNew->id => null,
                $classChanged->id => $roomA->id,
                $classUnchanged->id => $roomA->id,
            ],
        ]);

        Cache::put("allocation:{$term->id}", [
            'job_id' => 'compare-123',
            'status' => 'solving',
            'progress' => 50,
        ], now()->addHour());

        Cache::put("allocation:job:compare-123", $term->id, now()->addHour());

        $response = $this->postJson('/api/webhooks/allocation-result', [
            'job_id' => 'compare-123',
            'status' => 'optimal',
            'allocations' => [
                ['group_id' => $classNew->id, 'room_id' => $roomB->id],
                ['group_id' => $classChanged->id, 'room_id' => $roomB->id],
                ['group_id' => $classUnchanged->id, 'room_id' => $roomA->id],
            ],
            'unassigned_groups' => [],
            'suggestions' => [],
        ]);

        $response->assertOk();

        $cached = Cache::get("allocation:{$term->id}");
        $this->assertStringContainsString('1 turma(s) nova(s) foi(ram) alocada(s)', $cached['message']);
        $this->assertStringContainsString('1 turma(s) mudou(aram) de sala', $cached['message']);
    }

    /** @test */
    public function comfort_zone_end_to_end_scenario_prefers_50_seat_room()
    {
        $term = SchoolTerm::factory()->create();

        $room42 = Room::factory()->create(['assentos' => 42]);
        $room50 = Room::factory()->create(['assentos' => 50]);
        $room100 = Room::factory()->create(['assentos' => 100]);

        $class = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => null,
            'externa' => false,
            'estmtr' => 40,
        ]);
        $schedule = ClassSchedule::factory()->seg()->morning()->create();
        $class->classschedules()->attach($schedule);

        Http::fake([
            'http://solver.test/api/v1/solve' => Http::response([
                'job_id' => 'comfort-zone-123',
            ], 202),
        ]);

        $response = $this->actingAsOperator()
            ->patch('/rooms/distributes', [
                'rooms_id' => [$room42->id, $room50->id, $room100->id],
                'solver_config' => [
                    'waste_penalty' => 10000.0,
                    'claustrophobia_penalty' => 10000.0,
                    'comfort_zone_min_percent' => 10.0,
                    'comfort_zone_max_percent' => 25.0,
                ],
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('alert-info');

        $cached = Cache::get("allocation:{$term->id}");
        $this->assertNotNull($cached);
        $this->assertEquals('comfort-zone-123', $cached['job_id']);

        $solverLog = \App\Models\SolverLog::where('job_id', 'comfort-zone-123')->first();
        $this->assertNotNull($solverLog);
        $this->assertEquals(10000.0, $solverLog->payload['config']['waste_penalty']);
        $this->assertEquals(10000.0, $solverLog->payload['config']['claustrophobia_penalty']);
        $this->assertEquals(10.0, $solverLog->payload['config']['comfort_zone_min_percent']);
        $this->assertEquals(25.0, $solverLog->payload['config']['comfort_zone_max_percent']);

        Http::assertSent(function ($request) {
            $payload = $request->data();

            if (! isset($payload['config'])) {
                return false;
            }

            return $payload['config']['waste_penalty'] === 10000.0
                && $payload['config']['claustrophobia_penalty'] === 10000.0
                && $payload['config']['comfort_zone_min_percent'] === 10.0
                && $payload['config']['comfort_zone_max_percent'] === 25.0;
        });

        $response = $this->postJson('/api/webhooks/allocation-result', [
            'job_id' => 'comfort-zone-123',
            'status' => 'optimal',
            'allocations' => [
                ['group_id' => $class->id, 'room_id' => $room50->id],
            ],
            'unassigned_groups' => [],
            'suggestions' => [],
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('school_classes', [
            'id' => $class->id,
            'room_id' => $room50->id,
        ]);

        $cached = Cache::get("allocation:{$term->id}");
        $this->assertEquals('completed', $cached['status']);
    }

    /** @test */
    public function cohort_split_scenario_accepts_separate_rooms()
    {
        $term = SchoolTerm::factory()->create();

        $room60 = Room::factory()->create(['assentos' => 60]);
        $room40 = Room::factory()->create(['assentos' => 40]);

        $course = \App\Models\Course::factory()->create([
            'sufixo_codtur' => '45',
        ]);

        $courseInfo = \App\Models\CourseInformation::factory()->create([
            'tipobg' => 'O',
            'numsemidl' => '1',
        ]);

        $classA = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'codtur' => '202445',
            'coddis' => 'MAT0123',
            'tiptur' => 'Graduação',
            'externa' => false,
            'fusion_id' => null,
            'room_id' => null,
            'estmtr' => 40,
        ]);
        $classA->courseinformations()->attach($courseInfo);

        $classB = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'codtur' => '202445',
            'coddis' => 'MAT0456',
            'tiptur' => 'Graduação',
            'externa' => false,
            'fusion_id' => null,
            'room_id' => null,
            'estmtr' => 40,
        ]);
        $classB->courseinformations()->attach($courseInfo);

        $scheduleA = ClassSchedule::factory()->seg()->morning()->create();
        $classA->classschedules()->attach($scheduleA);

        $scheduleB = ClassSchedule::factory()->ter()->morning()->create();
        $classB->classschedules()->attach($scheduleB);

        Http::fake([
            'http://solver.test/api/v1/solve' => Http::response([
                'job_id' => 'cohort-split-123',
            ], 202),
        ]);

        $response = $this->actingAsOperator()
            ->patch('/rooms/distributes', [
                'rooms_id' => [$room60->id, $room40->id],
                'solver_config' => [
                    'split_cohort_penalty' => 1000.0,
                ],
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('alert-info');

        $cached = Cache::get("allocation:{$term->id}");
        $this->assertNotNull($cached);
        $this->assertEquals('cohort-split-123', $cached['job_id']);

        $solverLog = \App\Models\SolverLog::where('job_id', 'cohort-split-123')->first();
        $this->assertNotNull($solverLog);
        $this->assertEquals(1000.0, $solverLog->payload['config']['split_cohort_penalty']);

        $groupA = collect($solverLog->payload['groups'])->firstWhere('id', $classA->id);
        $groupB = collect($solverLog->payload['groups'])->firstWhere('id', $classB->id);
        $this->assertNotNull($groupA);
        $this->assertNotNull($groupB);
        $this->assertEquals('cohort_45_sem_1', $groupA['same_room_cohort']);
        $this->assertEquals($groupA['same_room_cohort'], $groupB['same_room_cohort']);

        Http::assertSent(function ($request) {
            $payload = $request->data();

            if (! isset($payload['config'])) {
                return false;
            }

            return $payload['config']['split_cohort_penalty'] === 1000.0;
        });

        $response = $this->postJson('/api/webhooks/allocation-result', [
            'job_id' => 'cohort-split-123',
            'status' => 'feasible',
            'allocations' => [
                ['group_id' => $classA->id, 'room_id' => $room60->id],
                ['group_id' => $classB->id, 'room_id' => $room40->id],
            ],
            'unassigned_groups' => [],
            'suggestions' => [],
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('school_classes', [
            'id' => $classA->id,
            'room_id' => $room60->id,
        ]);
        $this->assertDatabaseHas('school_classes', [
            'id' => $classB->id,
            'room_id' => $room40->id,
        ]);

        $cached = Cache::get("allocation:{$term->id}");
        $this->assertEquals('completed', $cached['status']);
    }

    /** @test */
    public function result_webhook_marks_split_class_as_unassigned_and_stores_suggestions()
    {
        $term = SchoolTerm::factory()->create();

        $roomB01 = Room::factory()->create(['nome' => 'B01']);
        $roomB02 = Room::factory()->create(['nome' => 'B02']);

        $class = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => null,
            'externa' => false,
            'tiptur' => 'Graduação',
            'codtur' => '0123T14',
            'coddis' => 'MAT9999',
        ]);

        $scheduleSeg = ClassSchedule::factory()->seg()->morning()->create();
        $scheduleQua = ClassSchedule::factory()->qua()->morning()->create();
        $class->classschedules()->attach([$scheduleSeg->id, $scheduleQua->id]);

        \App\Models\SolverLog::create([
            'school_term_id' => $term->id,
            'job_id' => 'split-class-123',
            'payload' => [
                'timeslots' => [
                    ['id' => 0, 'label' => 'seg_0800_1000'],
                    ['id' => 1, 'label' => 'qua_0800_1000'],
                ],
            ],
            'status' => 'solving',
            'dispatched_at' => now(),
        ]);

        Cache::put("allocation:{$term->id}", [
            'job_id' => 'split-class-123',
            'status' => 'solving',
        ], now()->addHour());

        Cache::put("allocation:job:split-class-123", $term->id, now()->addHour());

        $response = $this->postJson('/api/webhooks/allocation-result', [
            'job_id' => 'split-class-123',
            'status' => 'optimal',
            'allocations' => [],
            'unassigned_groups' => [$class->id],
            'suggestions' => [
                ['group_id' => $class->id, 'timeslot_id' => 0, 'suggested_room_id' => $roomB01->id],
                ['group_id' => $class->id, 'timeslot_id' => 1, 'suggested_room_id' => $roomB02->id],
            ],
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('school_classes', [
            'id' => $class->id,
            'room_id' => null,
        ]);

        $cachedSuggestions = Cache::get("allocation_suggestions:{$term->id}");
        $this->assertCount(2, $cachedSuggestions['raw']);
        $this->assertContains('Turma T.14 - Seg: B01, Qua: B02', $cachedSuggestions['formatted']);

        $cached = Cache::get("allocation:{$term->id}");
        $this->assertEquals(1, $cached['unassigned_count']);
        $this->assertEquals(0, $cached['manual_count']);
    }
}
