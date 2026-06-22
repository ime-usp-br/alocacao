<?php

namespace Tests\Unit;

use App\Models\AllocationState;
use App\Models\ComparisonReport;
use App\Models\SchoolTerm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComparisonReportTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_be_created_with_fillable_attributes()
    {
        $term = SchoolTerm::factory()->create();
        $state = AllocationState::factory()->create(['school_term_id' => $term->id]);

        $report = ComparisonReport::create([
            'school_term_id' => $term->id,
            'base_allocation_state_id' => $state->id,
            'status' => 'processing',
        ]);

        $this->assertDatabaseHas('comparison_reports', [
            'id' => $report->id,
            'school_term_id' => $term->id,
            'base_allocation_state_id' => $state->id,
            'status' => 'processing',
        ]);
    }

    /** @test */
    public function status_defaults_to_processing()
    {
        $term = SchoolTerm::factory()->create();
        $state = AllocationState::factory()->create(['school_term_id' => $term->id]);

        $report = ComparisonReport::create([
            'school_term_id' => $term->id,
            'base_allocation_state_id' => $state->id,
        ]);

        $this->assertSame('processing', $report->fresh()->status);
    }

    /** @test */
    public function json_fields_are_cast_to_arrays()
    {
        $term = SchoolTerm::factory()->create();
        $state = AllocationState::factory()->create(['school_term_id' => $term->id]);

        $report = ComparisonReport::create([
            'school_term_id' => $term->id,
            'base_allocation_state_id' => $state->id,
            'legacy_metrics' => ['allocation_rate' => 95.5, 'comfort_zone_rate' => 80.0],
            'solver_metrics' => ['allocation_rate' => 98.2, 'comfort_zone_rate' => 92.1],
            'legacy_raw_allocations' => [1 => 10, 2 => 20],
            'solver_raw_allocations' => [1 => 11, 2 => 21],
        ]);

        $report = $report->fresh();

        $this->assertIsArray($report->legacy_metrics);
        $this->assertIsArray($report->solver_metrics);
        $this->assertIsArray($report->legacy_raw_allocations);
        $this->assertIsArray($report->solver_raw_allocations);

        $this->assertSame(95.5, $report->legacy_metrics['allocation_rate']);
        $this->assertSame(98.2, $report->solver_metrics['allocation_rate']);
        $this->assertSame(10, $report->legacy_raw_allocations[1]);
        $this->assertSame(21, $report->solver_raw_allocations[2]);
    }

    /** @test */
    public function json_fields_can_be_null_by_default()
    {
        $term = SchoolTerm::factory()->create();
        $state = AllocationState::factory()->create(['school_term_id' => $term->id]);

        $report = ComparisonReport::create([
            'school_term_id' => $term->id,
            'base_allocation_state_id' => $state->id,
        ]);

        $report = $report->fresh();

        $this->assertNull($report->legacy_metrics);
        $this->assertNull($report->solver_metrics);
        $this->assertNull($report->legacy_raw_allocations);
        $this->assertNull($report->solver_raw_allocations);
    }

    /** @test */
    public function json_casts_survive_round_trips()
    {
        $term = SchoolTerm::factory()->create();
        $state = AllocationState::factory()->create(['school_term_id' => $term->id]);

        $nested = [
            'kpis' => [
                'allocation_rate' => 100.0,
                'comfort_zone_rate' => 50.0,
            ],
            'solve_time_seconds' => 12.34,
        ];

        $report = ComparisonReport::create([
            'school_term_id' => $term->id,
            'base_allocation_state_id' => $state->id,
            'solver_metrics' => $nested,
        ]);

        $persisted = $report->fresh()->solver_metrics;
        $this->assertEquals($nested['kpis']['allocation_rate'], $persisted['kpis']['allocation_rate']);
        $this->assertEquals($nested['kpis']['comfort_zone_rate'], $persisted['kpis']['comfort_zone_rate']);
        $this->assertSame($nested['solve_time_seconds'], $persisted['solve_time_seconds']);
    }

    /** @test */
    public function it_belongs_to_school_term()
    {
        $term = SchoolTerm::factory()->create();
        $state = AllocationState::factory()->create(['school_term_id' => $term->id]);

        $report = ComparisonReport::create([
            'school_term_id' => $term->id,
            'base_allocation_state_id' => $state->id,
        ]);

        $this->assertInstanceOf(SchoolTerm::class, $report->schoolTerm);
        $this->assertSame($term->id, $report->schoolTerm->id);
    }

    /** @test */
    public function it_belongs_to_base_allocation_state()
    {
        $term = SchoolTerm::factory()->create();
        $state = AllocationState::factory()->create(['school_term_id' => $term->id]);

        $report = ComparisonReport::create([
            'school_term_id' => $term->id,
            'base_allocation_state_id' => $state->id,
        ]);

        $this->assertInstanceOf(AllocationState::class, $report->baseAllocationState);
        $this->assertSame($state->id, $report->baseAllocationState->id);
    }

    /** @test */
    public function deleting_school_term_cascades_to_report()
    {
        $term = SchoolTerm::factory()->create();
        $state = AllocationState::factory()->create(['school_term_id' => $term->id]);

        $report = ComparisonReport::create([
            'school_term_id' => $term->id,
            'base_allocation_state_id' => $state->id,
        ]);

        $term->delete();

        $this->assertDatabaseMissing('comparison_reports', ['id' => $report->id]);
    }

    /** @test */
    public function deleting_base_allocation_state_cascades_to_report()
    {
        $term = SchoolTerm::factory()->create();
        $state = AllocationState::factory()->create(['school_term_id' => $term->id]);

        $report = ComparisonReport::create([
            'school_term_id' => $term->id,
            'base_allocation_state_id' => $state->id,
        ]);

        $state->delete();

        $this->assertDatabaseMissing('comparison_reports', ['id' => $report->id]);
    }
}
