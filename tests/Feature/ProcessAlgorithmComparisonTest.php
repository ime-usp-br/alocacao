<?php

namespace Tests\Feature;

use App\Jobs\ProcessAlgorithmComparison;
use App\Models\AllocationState;
use App\Models\ClassSchedule;
use App\Models\ComparisonReport;
use App\Models\Room;
use App\Models\SchoolClass;
use App\Models\SchoolTerm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ProcessAlgorithmComparisonTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_has_correct_unique_id()
    {
        $job = new ProcessAlgorithmComparison(42, 7, [1, 2]);
        $this->assertEquals('algorithm-comparison-42', $job->uniqueId());
    }

    /** @test */
    public function it_creates_a_comparison_report_with_processing_status()
    {
        $term = $this->latestTerm();
        [$room, $class] = $this->allocatableClass($term);

        $baseState = AllocationState::create([
            'school_term_id' => $term->id,
            'name' => 'Base de Benchmarking',
            'allocations' => [$class->id => null],
            'solver_log_id' => null,
        ]);

        $job = new ProcessAlgorithmComparison($term->id, $baseState->id, [$room->id]);
        $job->handle();

        $this->assertDatabaseHas('comparison_reports', [
            'school_term_id' => $term->id,
            'base_allocation_state_id' => $baseState->id,
            'status' => 'processing',
        ]);
    }

    /** @test */
    public function it_populates_legacy_metrics_and_raw_allocations()
    {
        $term = $this->latestTerm();
        [$room, $class] = $this->allocatableClass($term);

        $baseState = AllocationState::create([
            'school_term_id' => $term->id,
            'name' => 'Base de Benchmarking',
            'allocations' => [$class->id => null],
            'solver_log_id' => null,
        ]);

        $job = new ProcessAlgorithmComparison($term->id, $baseState->id, [$room->id]);
        $job->handle();

        $report = ComparisonReport::first();

        $this->assertNotNull($report);
        $this->assertNotNull($report->legacy_metrics);
        $this->assertArrayHasKey('allocation_rate', $report->legacy_metrics);
        $this->assertArrayHasKey('comfort_zone_rate', $report->legacy_metrics);
        $this->assertArrayHasKey('avg_waste_per_class', $report->legacy_metrics);
        $this->assertArrayHasKey('avg_claustrophobia_per_class', $report->legacy_metrics);
        $this->assertArrayHasKey('block_adherence_rate', $report->legacy_metrics);
        $this->assertArrayHasKey('solve_time_seconds', $report->legacy_metrics);

        $this->assertNotNull($report->legacy_raw_allocations);
        $this->assertArrayHasKey($class->id, $report->legacy_raw_allocations);
        $this->assertEquals($room->id, $report->legacy_raw_allocations[$class->id]);

        // Solver ainda nao foi avaliado neste fluxo (issue dedicada).
        $this->assertNull($report->solver_metrics);
        $this->assertNull($report->solver_raw_allocations);
    }

    /** @test */
    public function it_does_not_mutate_production_database()
    {
        $term = $this->latestTerm();
        [$room, $class] = $this->allocatableClass($term);

        // Estado de producao anterior: turma sem sala.
        $this->assertNull($class->fresh()->room_id);

        $baseState = AllocationState::create([
            'school_term_id' => $term->id,
            'name' => 'Base de Benchmarking',
            'allocations' => [$class->id => null],
            'solver_log_id' => null,
        ]);

        $job = new ProcessAlgorithmComparison($term->id, $baseState->id, [$room->id]);
        $job->handle();

        // DoD: o banco de producao DEVE continuar intacto. A turma nao pode
        // permanecer alocada apos o benchmarking.
        $this->assertNull($class->fresh()->room_id);

        // Nenhum AllocationState residual de "Pré-Legado" deve persistir
        // (a capture interna do legado e envelopada pela transacao revertida).
        $this->assertEquals(1, AllocationState::count());
    }

    /** @test */
    public function it_does_not_disturb_production_allocation_cache()
    {
        $term = $this->latestTerm();
        [$room, $class] = $this->allocatableClass($term);

        $cacheKey = "allocation:{$term->id}";
        Cache::put($cacheKey, [
            'job_id' => 'abc',
            'status' => 'solving',
            'mode' => 'solver',
        ], now()->addHours(4));

        $baseState = AllocationState::create([
            'school_term_id' => $term->id,
            'name' => 'Base de Benchmarking',
            'allocations' => [$class->id => null],
            'solver_log_id' => null,
        ]);

        $job = new ProcessAlgorithmComparison($term->id, $baseState->id, [$room->id]);
        $job->handle();

        $cached = Cache::get($cacheKey);
        $this->assertNotNull($cached);
        $this->assertEquals('solver', $cached['mode']);
        $this->assertEquals('solving', $cached['status']);
    }

    /** @test */
    public function it_clears_allocation_cache_when_none_existed_before()
    {
        $term = $this->latestTerm();
        [$room, $class] = $this->allocatableClass($term);

        $baseState = AllocationState::create([
            'school_term_id' => $term->id,
            'name' => 'Base de Benchmarking',
            'allocations' => [$class->id => null],
            'solver_log_id' => null,
        ]);

        $job = new ProcessAlgorithmComparison($term->id, $baseState->id, [$room->id]);
        $job->handle();

        $this->assertNull(Cache::get("allocation:{$term->id}"));
    }

    /** @test */
    public function it_restores_base_state_as_starting_point_for_legacy()
    {
        $term = $this->latestTerm();
        [$room, $class] = $this->allocatableClass($term);

        // Simula uma pre-alocacao manual (trava) definida pela comissao que
        // deve servir de ponto de partida para a heuristica legada.
        $manualRoom = Room::factory()->blockA()->create(['assentos' => 200]);
        $class->room_id = $manualRoom->id;
        $class->save();

        $baseState = AllocationState::create([
            'school_term_id' => $term->id,
            'name' => 'Base de Benchmarking',
            'allocations' => [$class->id => $manualRoom->id],
            'solver_log_id' => null,
        ]);

        // Estado de producao atual: turma sem sala (diferente do base).
        $class->room_id = null;
        $class->save();
        $this->assertNull($class->fresh()->room_id);

        $job = new ProcessAlgorithmComparison($term->id, $baseState->id, [$room->id]);
        $job->handle();

        $report = ComparisonReport::first();

        // A heuristica legada respeita o guard de "ja alocada" (room existe),
        // logo a turma deve permanecer na sala manual do estado base e o mapa
        // coletado deve refletir essa sala.
        $this->assertEquals($manualRoom->id, $report->legacy_raw_allocations[$class->id]);

        // Banco de producao permanece intacto (sem sala).
        $this->assertNull($class->fresh()->room_id);
    }

    /** @test */
    public function it_marks_report_failed_via_failed_callback()
    {
        $term = $this->latestTerm();

        $baseState = AllocationState::create([
            'school_term_id' => $term->id,
            'name' => 'Base de Benchmarking',
            'allocations' => [],
            'solver_log_id' => null,
        ]);

        $report = ComparisonReport::create([
            'school_term_id' => $term->id,
            'base_allocation_state_id' => $baseState->id,
            'status' => 'processing',
        ]);

        $job = new ProcessAlgorithmComparison($term->id, $baseState->id, [1]);
        $job->comparisonReportId = $report->id;

        $job->failed(new \RuntimeException('boom'));

        $this->assertEquals('failed', $report->fresh()->status);
    }

    /** @test */
    public function it_does_not_create_report_when_base_state_is_missing()
    {
        $term = $this->latestTerm();

        $job = new ProcessAlgorithmComparison($term->id, 999999, [1]);

        try {
            $job->handle();
        } catch (\Throwable $e) {
            // AllocationState::findOrFail lanca ModelNotFoundException.
        }

        $this->assertEquals(0, ComparisonReport::count());
    }

    private function latestTerm(): SchoolTerm
    {
        // AllocationStateService::restore utiliza SchoolTerm::getLatest(),
        // logo o semestre do benchmark deve ser o mais recente.
        return SchoolTerm::factory()->create([
            'year' => now()->year + 1,
            'period' => '2° Semestre',
        ]);
    }

    private function allocatableClass(SchoolTerm $term): array
    {
        $room = Room::factory()->blockA()->create(['assentos' => 100]);

        $class = SchoolClass::factory()
            ->undergraduate()
            ->withoutRoom()
            ->withSchoolTerm($term)
            ->create(['estmtr' => 30]);

        $schedule = ClassSchedule::factory()->seg()->morning()->create();
        $class->classschedules()->attach($schedule);

        return [$room, $class];
    }
}
