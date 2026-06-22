<?php

namespace Tests\Feature;

use App\Models\AllocationState;
use App\Models\ComparisonReport;
use App\Models\Room;
use App\Models\SchoolClass;
use App\Models\SchoolTerm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ComparisonReportControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): self
    {
        Role::firstOrCreate(['name' => 'Administrador', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole('Administrador');

        return $this->actingAs($user);
    }

    private function actingAsOperator(): self
    {
        Role::firstOrCreate(['name' => 'Operador', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole('Operador');

        return $this->actingAs($user);
    }

    /**
     * Cria um ComparisonReport concluído com métricas e alocações brutas
     * realistas para exercer o dashboard (cards, radar e scatter).
     */
    private function createCompletedReport(SchoolTerm $term): ComparisonReport
    {
        $baseState = AllocationState::create([
            'school_term_id' => $term->id,
            'name' => 'Base de Benchmarking',
            'allocations' => [],
            'solver_log_id' => null,
        ]);

        $roomLegacy = Room::factory()->create(['nome' => 'A120', 'assentos' => 150]);
        $roomSolver = Room::factory()->create(['nome' => 'A110', 'assentos' => 110]);

        $class = SchoolClass::factory()
            ->withSchoolTerm($term)
            ->withoutRoom()
            ->create(['estmtr' => 100, 'tiptur' => 'Graduação']);

        $legacyMetrics = [
            'allocation_rate' => 80.0,
            'comfort_zone_rate' => 40.0,
            'avg_waste_per_class' => 12.5,
            'avg_claustrophobia_per_class' => 5.0,
            'block_adherence_rate' => 90.0,
            'solve_time_seconds' => 30.0,
        ];

        $solverMetrics = [
            'allocation_rate' => 95.0,
            'comfort_zone_rate' => 85.0,
            'avg_waste_per_class' => 2.5,
            'avg_claustrophobia_per_class' => 1.0,
            'block_adherence_rate' => 98.0,
            'solve_time_seconds' => 10.0,
        ];

        return ComparisonReport::create([
            'school_term_id' => $term->id,
            'base_allocation_state_id' => $baseState->id,
            'status' => 'completed',
            'legacy_metrics' => $legacyMetrics,
            'solver_metrics' => $solverMetrics,
            'legacy_raw_allocations' => [$class->id => $roomLegacy->id],
            'solver_raw_allocations' => [$class->id => $roomSolver->id],
        ]);
    }

    /** @test */
    public function admin_can_list_comparison_reports()
    {
        $term = SchoolTerm::factory()->create();
        $report = $this->createCompletedReport($term);

        $response = $this->actingAsAdmin()->get('/comparison-reports');

        $response->assertOk();
        $response->assertViewHas('reports');
        $response->assertSee('Comparação de Algoritmos');
        $response->assertSee('#' . $report->id);
    }

    /** @test */
    public function non_admin_is_forbidden_on_index()
    {
        $response = $this->actingAsOperator()->get('/comparison-reports');

        $response->assertForbidden();
    }

    /** @test */
    public function guest_is_redirected_on_index()
    {
        $response = $this->get('/comparison-reports');

        $response->assertRedirect();
    }

    /** @test */
    public function show_renders_delta_cards_and_chart_canvases()
    {
        $term = SchoolTerm::factory()->create();
        $report = $this->createCompletedReport($term);

        $response = $this->actingAsAdmin()->get("/comparison-reports/{$report->id}");

        $response->assertOk();
        $response->assertViewHas(['report', 'scatterData', 'comfortZone']);

        // Delta cards: rótulos dos KPIs e valores formatados.
        $response->assertSee('Taxa de Alocação');
        $response->assertSee('Zona de Conforto');
        $response->assertSee('Desperdício Médio');
        $response->assertSee('Tempo de Resolução');

        // Estrutura dos gráficos Chart.js.
        $response->assertSee('radarChart', false);
        $response->assertSee('scatterChart', false);
        $response->assertSee('chart.js@4', false);

        // Scatter: os pontos (demanda, capacidade) são serializados no JSON.
        // Demanda = 100 (estmtr); capacidade solver = 110; capacidade legado = 150.
        $response->assertSee('"x":100', false);
        $response->assertSee('"y":110', false);
        $response->assertSee('"y":150', false);
    }

    /** @test */
    public function show_for_processing_report_does_not_render_charts()
    {
        $term = SchoolTerm::factory()->create();

        $baseState = AllocationState::create([
            'school_term_id' => $term->id,
            'name' => 'Base',
            'allocations' => [],
            'solver_log_id' => null,
        ]);

        $report = ComparisonReport::create([
            'school_term_id' => $term->id,
            'base_allocation_state_id' => $baseState->id,
            'status' => 'processing',
            'legacy_metrics' => [
                'allocation_rate' => 70.0,
                'comfort_zone_rate' => 30.0,
                'avg_waste_per_class' => 10.0,
                'avg_claustrophobia_per_class' => 3.0,
                'block_adherence_rate' => 80.0,
                'solve_time_seconds' => 25.0,
            ],
            'solver_metrics' => null,
        ]);

        $response = $this->actingAsAdmin()->get("/comparison-reports/{$report->id}");

        $response->assertOk();
        $response->assertSee('processando');
        $response->assertDontSee('radarChart', false);
        $response->assertDontSee('scatterChart', false);
    }

    /** @test */
    public function non_admin_is_forbidden_on_show()
    {
        $term = SchoolTerm::factory()->create();
        $report = $this->createCompletedReport($term);

        $response = $this->actingAsOperator()->get("/comparison-reports/{$report->id}");

        $response->assertForbidden();
    }
}
