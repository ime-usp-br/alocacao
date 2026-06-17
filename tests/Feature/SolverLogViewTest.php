<?php

namespace Tests\Feature;

use App\Models\SchoolTerm;
use App\Models\SolverLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SolverLogViewTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function admin_can_view_solver_logs_list()
    {
        Role::create(['name' => 'Administrador']);
        $admin = User::factory()->create();
        $admin->assignRole('Administrador');

        $term = SchoolTerm::factory()->create();
        SolverLog::factory()->create([
            'school_term_id' => $term->id,
            'job_id' => 'job-123',
            'payload' => ['meta' => ['version' => '1.0.0']],
            'response' => ['status' => 'optimal'],
        ]);

        $response = $this->actingAs($admin)->get(route('solverlogs.index'));

        $response->assertStatus(200);
        $response->assertSee('Logs do Solver');
        $response->assertSee('job-123');
    }

    /** @test */
    public function admin_can_view_solver_log_details()
    {
        Role::create(['name' => 'Administrador']);
        $admin = User::factory()->create();
        $admin->assignRole('Administrador');

        $term = SchoolTerm::factory()->create();
        $log = SolverLog::factory()->create([
            'school_term_id' => $term->id,
            'job_id' => 'job-456',
            'payload' => ['meta' => ['version' => '1.0.0'], 'groups' => []],
            'response' => ['status' => 'optimal', 'allocations' => []],
            'status' => 'optimal',
            'allocations_count' => 10,
            'unassigned_count' => 5,
        ]);

        $response = $this->actingAs($admin)->get(route('solverlogs.show', $log));

        $response->assertStatus(200);
        $response->assertSee('Log do Solver #' . $log->id);
        $response->assertSee('Payload Enviado ao Solver');
        $response->assertSee('Resposta do Solver');
        $response->assertSee('job-456');
        $response->assertSee('10');
        $response->assertSee('5');
    }

    /** @test */
    public function non_admin_cannot_view_solver_logs()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('solverlogs.index'));

        $response->assertForbidden();
    }

    /** @test */
    public function guest_cannot_view_solver_logs()
    {
        $response = $this->get(route('solverlogs.index'));

        $response->assertForbidden();
    }
}
