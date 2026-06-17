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
            'http://solver.test/api/v1/jobs/stop-123/stop' => Http::response(['status' => 'stopping'], 200),
        ]);

        $response = $this->actingAsOperator()
            ->post('/rooms/distribution/stop');

        $response->assertRedirect();
        $response->assertSessionHas('alert-info');

        Http::assertSent(function ($request) {
            return $request->url() === 'http://solver.test/api/v1/jobs/stop-123/stop';
        });

        $cached = Cache::get("allocation:{$term->id}");
        $this->assertEquals('stopping', $cached['status']);
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
}
