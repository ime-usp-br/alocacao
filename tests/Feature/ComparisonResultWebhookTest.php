<?php

namespace Tests\Feature;

use App\Models\AllocationState;
use App\Models\ComparisonReport;
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
}
