<?php

namespace Tests\Feature;

use App\Models\AllocationState;
use App\Models\ComparisonReport;
use App\Models\Fusion;
use App\Models\Room;
use App\Models\SchoolClass;
use App\Models\SchoolTerm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ComparisonResultWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['alocacao.solver.api_token' => 'webhook-secret']);
    }

    private function withWebhookToken(): self
    {
        return $this->withHeader('X-Webhook-Token', 'webhook-secret');
    }

    /**
     * Cria um ComparisonReport em 'processing' vinculado ao termo informado,
     * reaproveitando um unico SchoolTerm para evitar colisoes de unicidade
     * (year, period) geradas pelas factories aninhadas padrao.
     */
    private function createReport(SchoolTerm $term, array $overrides = []): ComparisonReport
    {
        $baseState = AllocationState::create([
            'school_term_id' => $term->id,
            'name' => 'Base de Benchmarking',
            'allocations' => [],
            'solver_log_id' => null,
        ]);

        return ComparisonReport::create(array_merge([
            'school_term_id' => $term->id,
            'base_allocation_state_id' => $baseState->id,
            'status' => 'processing',
        ], $overrides));
    }

    /** @test */
    public function result_webhook_completes_report_with_solver_metrics(): void
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create(['nome' => 'A120', 'assentos' => 120]);

        $class = SchoolClass::factory()
            ->withSchoolTerm($term)
            ->withoutRoom()
            ->create(['estmtr' => 100, 'tiptur' => 'Pós Graduação']);

        $report = $this->createReport($term);

        Cache::put("comparison:job:job-solver-1", $report->id, now()->addHours(4));

        $response = $this->withWebhookToken()->postJson('/api/webhooks/comparison-result', [
            'job_id' => 'job-solver-1',
            'status' => 'optimal',
            'allocations' => [
                ['group_id' => $class->id, 'room_id' => $room->id],
            ],
            'solve_time_seconds' => 7.5,
        ]);

        $response->assertOk();
        $response->assertJson(['message' => 'Comparison report completed']);

        $report->refresh();

        $this->assertEquals('completed', $report->status);
        $this->assertNotNull($report->solver_metrics);
        $this->assertArrayHasKey('allocation_rate', $report->solver_metrics);
        $this->assertArrayHasKey('comfort_zone_rate', $report->solver_metrics);
        $this->assertArrayHasKey('avg_waste_per_class', $report->solver_metrics);
        $this->assertArrayHasKey('avg_claustrophobia_per_class', $report->solver_metrics);
        $this->assertArrayHasKey('block_adherence_rate', $report->solver_metrics);
        $this->assertArrayHasKey('solve_time_seconds', $report->solver_metrics);
        $this->assertEquals(7.5, $report->solver_metrics['solve_time_seconds']);
        $this->assertEquals(100.0, $report->solver_metrics['allocation_rate']);

        $this->assertNotNull($report->solver_raw_allocations);
        $this->assertArrayHasKey($class->id, $report->solver_raw_allocations);
        $this->assertEquals($room->id, $report->solver_raw_allocations[$class->id]);
    }

    /** @test */
    public function result_webhook_does_not_mutate_production_school_classes(): void
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create(['nome' => 'B120', 'assentos' => 120]);

        $class = SchoolClass::factory()
            ->withSchoolTerm($term)
            ->withoutRoom()
            ->create(['estmtr' => 100, 'tiptur' => 'Pós Graduação']);

        $this->assertNull($class->fresh()->room_id);

        $report = $this->createReport($term);

        Cache::put("comparison:job:job-no-mutate", $report->id, now()->addHours(4));

        $response = $this->withWebhookToken()->postJson('/api/webhooks/comparison-result', [
            'job_id' => 'job-no-mutate',
            'status' => 'feasible',
            'allocations' => [
                ['group_id' => $class->id, 'room_id' => $room->id],
            ],
        ]);

        $response->assertOk();

        // DoD: nenhuma sala de producao pode ser modificada por este webhook.
        $this->assertNull($class->fresh()->room_id);
    }

    /** @test */
    public function result_webhook_marks_report_failed_on_infeasible_status(): void
    {
        $term = SchoolTerm::factory()->create();
        $report = $this->createReport($term);

        Cache::put("comparison:job:job-infeasible", $report->id, now()->addHours(4));

        $response = $this->withWebhookToken()->postJson('/api/webhooks/comparison-result', [
            'job_id' => 'job-infeasible',
            'status' => 'infeasible',
            'allocations' => [],
        ]);

        $response->assertOk();
        $response->assertJson(['message' => 'Comparison marked as failed']);

        $this->assertEquals('failed', $report->fresh()->status);
        $this->assertNull($report->fresh()->solver_metrics);
    }

    /** @test */
    public function result_webhook_marks_report_failed_on_error_status(): void
    {
        $term = SchoolTerm::factory()->create();
        $report = $this->createReport($term);

        Cache::put("comparison:job:job-error", $report->id, now()->addHours(4));

        $response = $this->withWebhookToken()->postJson('/api/webhooks/comparison-result', [
            'job_id' => 'job-error',
            'status' => 'error',
        ]);

        $response->assertOk();
        $response->assertJson(['message' => 'Comparison marked as failed']);

        $this->assertEquals('failed', $report->fresh()->status);
    }

    /** @test */
    public function result_webhook_returns_404_for_unknown_job(): void
    {
        $response = $this->withWebhookToken()->postJson('/api/webhooks/comparison-result', [
            'job_id' => 'unknown-job',
            'status' => 'optimal',
            'allocations' => [],
        ]);

        $response->assertNotFound();
    }

    /** @test */
    public function result_webhook_ignores_already_finished_reports(): void
    {
        $term = SchoolTerm::factory()->create();
        $report = $this->createReport($term, [
            'status' => 'completed',
            'solver_metrics' => ['allocation_rate' => 50.0],
        ]);

        Cache::put("comparison:job:job-late", $report->id, now()->addHours(4));

        $response = $this->withWebhookToken()->postJson('/api/webhooks/comparison-result', [
            'job_id' => 'job-late',
            'status' => 'optimal',
            'allocations' => [],
        ]);

        $response->assertOk();
        $response->assertJson(['message' => 'Ignored. Report already finished.']);

        // As metricas previas nao podem ser sobrescritas por um callback tardio.
        $this->assertEquals(['allocation_rate' => 50.0], $report->fresh()->solver_metrics);
        $this->assertEquals('completed', $report->fresh()->status);
    }

    /** @test */
    public function result_webhook_validates_payload(): void
    {
        $response = $this->withWebhookToken()->postJson('/api/webhooks/comparison-result', [
            'status' => 'optimal',
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function result_webhook_expands_fusion_children_via_database_collection(): void
    {
        // Cenario que expoe a assimetria original: o solver retorna apenas o
        // group_id da mestre da fusao. A coleta via DB (com precedencia de
        // mestre) deve reconhecer a turma filha como alocada — coisa que a
        // coleta direta do payload nao fazia.
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->blockA()->create(['nome' => 'A120', 'assentos' => 120]);

        $master = SchoolClass::factory()
            ->withSchoolTerm($term)
            ->withoutRoom()
            ->create(['estmtr' => 100, 'tiptur' => 'Pós Graduação']);

        $slave = SchoolClass::factory()
            ->withSchoolTerm($term)
            ->withoutRoom()
            ->create(['estmtr' => 0, 'tiptur' => 'Pós Graduação']);

        $fusion = Fusion::factory()->withMaster($master)->create();
        $master->fusion()->associate($fusion)->save();
        $slave->fusion()->associate($fusion)->save();

        $report = $this->createReport($term);

        Cache::put("comparison:job:job-fusion", $report->id, now()->addHours(4));

        $response = $this->withWebhookToken()->postJson('/api/webhooks/comparison-result', [
            'job_id' => 'job-fusion',
            'status' => 'optimal',
            'allocations' => [
                ['group_id' => $master->id, 'room_id' => $room->id],
            ],
            'solve_time_seconds' => 3.2,
        ]);

        $response->assertOk();

        $report->refresh();

        // A filha da fusao deve constar como alocada no mapa bruto (resolvida
        // via mestre), provando que a coleta via DB expande fusoes.
        $this->assertArrayHasKey($slave->id, $report->solver_raw_allocations);
        $this->assertEquals($room->id, $report->solver_raw_allocations[$slave->id]);
        $this->assertEquals($room->id, $report->solver_raw_allocations[$master->id]);

        // Ambas as turmas (mestre + filha) contribuem para a taxa de alocacao.
        // Como a filha tem estmtr = 0 ela nao e elegivel, logo apenas a mestre
        // conta no denominador — mas o mapa bruto deve conter ambas.
        $this->assertEquals(100.0, $report->solver_metrics['allocation_rate']);
    }

    /** @test */
    public function result_webhook_collects_solver_allocations_from_database_not_payload(): void
    {
        // Prova que a coleta simetrica percorre TODAS as turmas do semestre
        // (nao apenas as presentes no payload), espelhando o motor legado.
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->blockA()->create(['nome' => 'A120', 'assentos' => 120]);

        $allocated = SchoolClass::factory()
            ->withSchoolTerm($term)
            ->withoutRoom()
            ->create(['estmtr' => 100, 'tiptur' => 'Pós Graduação']);

        $orphan = SchoolClass::factory()
            ->withSchoolTerm($term)
            ->withoutRoom()
            ->create(['estmtr' => 80, 'tiptur' => 'Pós Graduação']);

        $report = $this->createReport($term);

        Cache::put("comparison:job:job-symmetric", $report->id, now()->addHours(4));

        $response = $this->withWebhookToken()->postJson('/api/webhooks/comparison-result', [
            'job_id' => 'job-symmetric',
            'status' => 'feasible',
            'allocations' => [
                ['group_id' => $allocated->id, 'room_id' => $room->id],
            ],
            'unassigned_groups' => [$orphan->id],
        ]);

        $response->assertOk();

        $report->refresh();

        // O orfao (nao alocado pelo solver) deve aparecer no mapa bruto com
        // room_id null — provando que a coleta via DB abrange toda a populacao
        // de turmas, nao apenas as listadas no payload.
        $this->assertArrayHasKey($orphan->id, $report->solver_raw_allocations);
        $this->assertNull($report->solver_raw_allocations[$orphan->id]);

        $this->assertEquals(50.0, $report->solver_metrics['allocation_rate']);
    }

    /** @test */
    public function result_webhook_restores_base_state_before_applying_solver_assignments(): void
    {
        // Garante que o solver parte do mesmo estado base que o legado.
        // Uma trava manual no estado base deve aparecer no mapa bruto mesmo
        // quando o solver nao a lista explicitamente em suas alocacoes.
        $term = SchoolTerm::factory()->create();
        $manualRoom = Room::factory()->blockA()->create(['nome' => 'A200', 'assentos' => 200]);
        $solverRoom = Room::factory()->blockA()->create(['nome' => 'A120', 'assentos' => 120]);

        $manualClass = SchoolClass::factory()
            ->withSchoolTerm($term)
            ->withoutRoom()
            ->create(['estmtr' => 50, 'tiptur' => 'Pós Graduação']);

        $autoClass = SchoolClass::factory()
            ->withSchoolTerm($term)
            ->withoutRoom()
            ->create(['estmtr' => 100, 'tiptur' => 'Pós Graduação']);

        $baseState = AllocationState::create([
            'school_term_id' => $term->id,
            'name' => 'Base de Benchmarking',
            'allocations' => [
                $manualClass->id => $manualRoom->id,
                $autoClass->id => null,
            ],
            'solver_log_id' => null,
        ]);

        $report = ComparisonReport::create([
            'school_term_id' => $term->id,
            'base_allocation_state_id' => $baseState->id,
            'status' => 'processing',
        ]);

        Cache::put("comparison:job:job-basestate", $report->id, now()->addHours(4));

        // O solver aloca apenas a turma automatica; a manual ja estava travada.
        $response = $this->withWebhookToken()->postJson('/api/webhooks/comparison-result', [
            'job_id' => 'job-basestate',
            'status' => 'optimal',
            'allocations' => [
                ['group_id' => $autoClass->id, 'room_id' => $solverRoom->id],
            ],
        ]);

        $response->assertOk();

        $report->refresh();

        // A trava manual do estado base deve ser reconhecida (o solver nao
        // precisa relista-la — a restauracao do estado base a colocou no DB).
        $this->assertEquals($manualRoom->id, $report->solver_raw_allocations[$manualClass->id]);
        $this->assertEquals($solverRoom->id, $report->solver_raw_allocations[$autoClass->id]);

        // Banco de producao permanece intacto (rollback).
        $this->assertNull($manualClass->fresh()->room_id);
        $this->assertNull($autoClass->fresh()->room_id);

        // Ambas alocadas => taxa 100%.
        $this->assertEquals(100.0, $report->solver_metrics['allocation_rate']);
    }
}
