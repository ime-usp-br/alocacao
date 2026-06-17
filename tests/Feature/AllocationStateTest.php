<?php

namespace Tests\Feature;

use App\Models\ClassSchedule;
use App\Models\Fusion;
use App\Models\Room;
use App\Models\SchoolClass;
use App\Models\SchoolTerm;
use App\Models\SolverLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AllocationStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['alocacao.solver.url' => 'http://solver.test']);
        config(['alocacao.solver.api_token' => 'test-token']);
        Cache::flush();
    }

    private function actingAsOperator(): self
    {
        Role::firstOrCreate(['name' => 'Operador', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole('Operador');
        return $this->actingAs($user);
    }

    private function actingAsAdmin(): self
    {
        Role::firstOrCreate(['name' => 'Administrador', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole('Administrador');
        return $this->actingAs($user);
    }

    /** @test */
    public function operator_can_save_allocation_state_manually()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();
        $class = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => $room->id,
        ]);

        $response = $this->actingAsOperator()
            ->post('/allocation-states', ['name' => 'Meu Save']);

        $response->assertRedirect();
        $response->assertSessionHas('alert-info');

        $this->assertDatabaseHas('allocation_states', [
            'school_term_id' => $term->id,
            'name' => 'Meu Save',
        ]);

        $state = \App\Models\AllocationState::first();
        $this->assertArrayHasKey($class->id, $state->allocations);
        $this->assertEquals($room->id, $state->allocations[$class->id]);
    }

    /** @test */
    public function manual_save_uses_timestamp_when_name_is_empty()
    {
        $term = SchoolTerm::factory()->create();
        SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => Room::factory()->create()->id,
        ]);

        $response = $this->actingAsOperator()->post('/allocation-states');

        $response->assertRedirect();
        $this->assertCount(1, \App\Models\AllocationState::all());
        $this->assertMatchesRegularExpression('/\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}:\d{2}/', \App\Models\AllocationState::first()->name);
    }

    /** @test */
    public function save_captures_master_room_for_fused_classes()
    {
        $term = SchoolTerm::factory()->create();
        $masterRoom = Room::factory()->create();
        $childRoom = Room::factory()->create();

        $master = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => $masterRoom->id,
        ]);
        $child = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => $childRoom->id,
        ]);

        $fusion = Fusion::create(['master_id' => $master->id]);
        $master->fusion_id = $fusion->id;
        $master->save();
        $child->fusion_id = $fusion->id;
        $child->save();

        $this->actingAsOperator()->post('/allocation-states', ['name' => 'Fusion Save']);

        $state = \App\Models\AllocationState::first();
        $this->assertEquals($masterRoom->id, $state->allocations[$master->id]);
        $this->assertEquals($masterRoom->id, $state->allocations[$child->id]);
    }

    /** @test */
    public function save_falls_back_to_child_room_when_master_has_no_room()
    {
        $term = SchoolTerm::factory()->create();
        $childRoom = Room::factory()->create();

        $master = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => null,
        ]);
        $child = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => $childRoom->id,
        ]);

        $fusion = Fusion::create(['master_id' => $master->id]);
        $master->fusion_id = $fusion->id;
        $master->save();
        $child->fusion_id = $fusion->id;
        $child->save();

        $this->actingAsOperator()->post('/allocation-states', ['name' => 'Fallback Save']);

        $state = \App\Models\AllocationState::first();
        $this->assertEquals($childRoom->id, $state->allocations[$master->id]);
        $this->assertEquals($childRoom->id, $state->allocations[$child->id]);
    }

    /** @test */
    public function operator_can_restore_allocation_state()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();
        $class = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => null,
        ]);

        $state = \App\Models\AllocationState::create([
            'school_term_id' => $term->id,
            'name' => 'Restore Save',
            'allocations' => [$class->id => $room->id],
        ]);

        $response = $this->actingAsOperator()
            ->post("/allocation-states/{$state->id}/restore");

        $response->assertRedirect();
        $response->assertSessionHas('alert-info');

        $this->assertDatabaseHas('school_classes', [
            'id' => $class->id,
            'room_id' => $room->id,
        ]);
    }

    /** @test */
    public function restore_applies_room_to_fusion_master()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();

        $master = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => null,
        ]);
        $child = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => null,
        ]);

        $fusion = Fusion::create(['master_id' => $master->id]);
        $master->fusion_id = $fusion->id;
        $master->save();
        $child->fusion_id = $fusion->id;
        $child->save();

        $state = \App\Models\AllocationState::create([
            'school_term_id' => $term->id,
            'name' => 'Fusion Restore',
            'allocations' => [$child->id => $room->id],
        ]);

        $this->actingAsOperator()->post("/allocation-states/{$state->id}/restore");

        $this->assertDatabaseHas('school_classes', [
            'id' => $master->id,
            'room_id' => $room->id,
        ]);
    }

    /** @test */
    public function restore_does_not_count_external_classes_as_unassigned()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();

        $existingClass = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => $room->id,
        ]);

        $state = \App\Models\AllocationState::create([
            'school_term_id' => $term->id,
            'name' => 'External Save',
            'allocations' => [$existingClass->id => $room->id],
        ]);

        SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => null,
            'externa' => false,
        ]);

        SchoolClass::factory()->external()->create([
            'school_term_id' => $term->id,
            'room_id' => null,
        ]);

        $response = $this->actingAsOperator()->post("/allocation-states/{$state->id}/restore");

        $response->assertSessionHas('alert-info', function ($message) {
            return str_contains($message, '1 novas turmas ficaram sem sala');
        });
    }

    /** @test */
    public function restore_warns_about_missing_classes_and_unassigned_classes()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();

        $existingClass = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => $room->id,
        ]);

        $state = \App\Models\AllocationState::create([
            'school_term_id' => $term->id,
            'name' => 'Stale Save',
            'allocations' => [
                $existingClass->id => $room->id,
                99999 => $room->id,
            ],
        ]);

        SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => null,
        ]);

        $response = $this->actingAsOperator()->post("/allocation-states/{$state->id}/restore");

        $response->assertSessionHas('alert-info', function ($message) {
            return str_contains($message, '1 turmas do save não existem mais')
                && str_contains($message, '1 novas turmas ficaram sem sala');
        });
    }

    /** @test */
    public function index_returns_states_and_solving_status()
    {
        $term = SchoolTerm::factory()->create();
        $state = \App\Models\AllocationState::create([
            'school_term_id' => $term->id,
            'name' => 'Listed Save',
            'allocations' => [],
        ]);

        Cache::put("allocation:{$term->id}", ['status' => 'solving'], now()->addHour());

        $response = $this->actingAsOperator()->get('/allocation-states');

        $response->assertOk();
        $response->assertJsonPath('states.0.id', $state->id);
        $response->assertJsonPath('is_solving', true);
    }

    /** @test */
    public function restore_is_blocked_while_solver_is_running()
    {
        $term = SchoolTerm::factory()->create();
        $state = \App\Models\AllocationState::create([
            'school_term_id' => $term->id,
            'name' => 'Blocked Save',
            'allocations' => [],
        ]);

        Cache::put("allocation:{$term->id}", ['status' => 'solving'], now()->addHour());

        $response = $this->actingAsOperator()->post("/allocation-states/{$state->id}/restore");

        $response->assertRedirect();
        $response->assertSessionHas('alert-warning');
    }

    /** @test */
    public function guests_cannot_access_allocation_state_routes()
    {
        $term = SchoolTerm::factory()->create();
        $state = \App\Models\AllocationState::create([
            'school_term_id' => $term->id,
            'name' => 'Save',
            'allocations' => [],
        ]);

        $this->get('/allocation-states')->assertForbidden();
        $this->post('/allocation-states')->assertForbidden();
        $this->post("/allocation-states/{$state->id}/restore")->assertForbidden();
        $this->delete("/allocation-states/{$state->id}")->assertForbidden();
    }

    /** @test */
    public function operator_can_delete_allocation_state()
    {
        $term = SchoolTerm::factory()->create();
        $state = \App\Models\AllocationState::create([
            'school_term_id' => $term->id,
            'name' => 'To Delete',
            'allocations' => [],
        ]);

        $response = $this->actingAsOperator()->delete("/allocation-states/{$state->id}");

        $response->assertRedirect();
        $response->assertSessionHas('alert-info');
        $this->assertDatabaseMissing('allocation_states', ['id' => $state->id]);
    }

    /** @test */
    public function emptying_rooms_creates_pre_emptying_state()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();
        SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => $room->id,
        ]);

        $response = $this->actingAsOperator()
            ->patch('/rooms/empty', ['rooms_id' => [$room->id]]);

        $response->assertRedirect();
        $this->assertDatabaseHas('allocation_states', [
            'school_term_id' => $term->id,
        ]);

        $state = \App\Models\AllocationState::first();
        $this->assertStringStartsWith('Pré-Esvaziamento', $state->name);
        $this->assertNull($state->solver_log_id);
    }

    /** @test */
    public function dispatching_distribution_creates_pre_solver_state_linked_to_solver_log()
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
                'job_id' => 'pre-solver-123',
            ], 202),
        ]);

        $this->actingAsOperator()
            ->patch('/rooms/distributes', ['rooms_id' => [$room->id]]);

        $this->assertDatabaseHas('solver_logs', [
            'job_id' => 'pre-solver-123',
        ]);

        $solverLog = SolverLog::where('job_id', 'pre-solver-123')->first();

        $this->assertDatabaseHas('allocation_states', [
            'school_term_id' => $term->id,
            'solver_log_id' => $solverLog->id,
        ]);

        $state = \App\Models\AllocationState::where('solver_log_id', $solverLog->id)->first();
        $this->assertStringStartsWith('Pré-Solver', $state->name);
    }
}
