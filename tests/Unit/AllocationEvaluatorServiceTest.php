<?php

namespace Tests\Unit;

use App\Models\CourseInformation;
use App\Models\Room;
use App\Models\SchoolClass;
use App\Models\SchoolTerm;
use App\Services\AllocationEvaluatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AllocationEvaluatorServiceTest extends TestCase
{
    use RefreshDatabase;

    private AllocationEvaluatorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AllocationEvaluatorService();
    }

    /** @test */
    public function it_calculates_all_kpis_with_exact_math(): void
    {
        $term = SchoolTerm::factory()->create();

        $roomAZone = Room::factory()->create(['nome' => 'A120', 'assentos' => 120]);
        $roomBZone = Room::factory()->create(['nome' => 'B110', 'assentos' => 110]);
        $roomBMax = Room::factory()->create(['nome' => 'B125', 'assentos' => 125]);
        $roomAWaste = Room::factory()->create(['nome' => 'A150', 'assentos' => 150]);
        $roomAClaustro = Room::factory()->create(['nome' => 'A105', 'assentos' => 105]);

        $freshmanCi = CourseInformation::factory()->mandatory()->semester('1')->create();

        $cFreshmanA = $this->createClass($term, ['estmtr' => 100, 'tiptur' => 'Graduação'], [$freshmanCi]);
        $cFreshmanB = $this->createClass($term, ['estmtr' => 100, 'tiptur' => 'Graduação'], [$freshmanCi]);
        $cPosB = $this->createClass($term, ['estmtr' => 100, 'tiptur' => 'Pós Graduação']);
        $cPosA = $this->createClass($term, ['estmtr' => 100, 'tiptur' => 'Pós Graduação']);
        $cSenior = $this->createClass($term, ['estmtr' => 100, 'tiptur' => 'Graduação'], [
            CourseInformation::factory()->mandatory()->semester('5')->create(),
        ]);
        $cUnallocated = $this->createClass($term, ['estmtr' => 100, 'tiptur' => 'Graduação']);
        $cExterna = $this->createClass($term, ['estmtr' => 100, 'tiptur' => 'Graduação', 'externa' => true]);
        $cNoDemand = $this->createClass($term, ['estmtr' => 0, 'tiptur' => 'Graduação']);

        $allocations = [
            $cFreshmanA->id => $roomAZone->id,
            $cFreshmanB->id => $roomBZone->id,
            $cPosB->id => $roomBMax->id,
            $cPosA->id => $roomAWaste->id,
            $cSenior->id => $roomAClaustro->id,
            $cExterna->id => $roomAZone->id,
            $cNoDemand->id => $roomAZone->id,
        ];

        $metrics = $this->service->evaluate($term, $allocations, 12.5);

        $this->assertEqualsWithDelta(83.3333333, $metrics['allocation_rate'], 1e-6);
        $this->assertSame(60.0, $metrics['comfort_zone_rate']);
        $this->assertSame(5.0, $metrics['avg_waste_per_class']);
        $this->assertSame(1.0, $metrics['avg_claustrophobia_per_class']);
        $this->assertSame(50.0, $metrics['block_adherence_rate']);
        $this->assertSame(12.5, $metrics['solve_time_seconds']);
    }

    /** @test */
    public function comfort_zone_uses_inclusive_bounds(): void
    {
        $term = SchoolTerm::factory()->create();

        $roomMin = Room::factory()->create(['nome' => 'A110', 'assentos' => 110]);
        $roomMax = Room::factory()->create(['nome' => 'A125', 'assentos' => 125]);

        $cMin = $this->createClass($term, ['estmtr' => 100, 'tiptur' => 'Pós Graduação']);
        $cMax = $this->createClass($term, ['estmtr' => 100, 'tiptur' => 'Pós Graduação']);

        $metrics = $this->service->evaluate($term, [
            $cMin->id => $roomMin->id,
            $cMax->id => $roomMax->id,
        ]);

        $this->assertSame(100.0, $metrics['comfort_zone_rate']);
        $this->assertSame(0.0, $metrics['avg_waste_per_class']);
        $this->assertSame(0.0, $metrics['avg_claustrophobia_per_class']);
    }

    /** @test */
    public function waste_only_counts_excess_beyond_max_comfort_bound(): void
    {
        $term = SchoolTerm::factory()->create();

        $roomJustOver = Room::factory()->create(['nome' => 'A126', 'assentos' => 126]);
        $roomHuge = Room::factory()->create(['nome' => 'A200', 'assentos' => 200]);

        $c1 = $this->createClass($term, ['estmtr' => 100, 'tiptur' => 'Pós Graduação']);
        $c2 = $this->createClass($term, ['estmtr' => 100, 'tiptur' => 'Pós Graduação']);

        $metrics = $this->service->evaluate($term, [
            $c1->id => $roomJustOver->id,
            $c2->id => $roomHuge->id,
        ]);

        $this->assertSame(0.0, $metrics['comfort_zone_rate']);
        $this->assertSame((1.0 + 75.0) / 2.0, $metrics['avg_waste_per_class']);
        $this->assertSame(0.0, $metrics['avg_claustrophobia_per_class']);
    }

    /** @test */
    public function claustrophobia_only_counts_deficit_below_min_comfort_bound(): void
    {
        $term = SchoolTerm::factory()->create();

        $roomJustBelow = Room::factory()->create(['nome' => 'A109', 'assentos' => 109]);
        $roomTight = Room::factory()->create(['nome' => 'A100', 'assentos' => 100]);

        $c1 = $this->createClass($term, ['estmtr' => 100, 'tiptur' => 'Pós Graduação']);
        $c2 = $this->createClass($term, ['estmtr' => 100, 'tiptur' => 'Pós Graduação']);

        $metrics = $this->service->evaluate($term, [
            $c1->id => $roomJustBelow->id,
            $c2->id => $roomTight->id,
        ]);

        $this->assertSame(0.0, $metrics['comfort_zone_rate']);
        $this->assertSame(0.0, $metrics['avg_waste_per_class']);
        $this->assertEqualsWithDelta(5.5, $metrics['avg_claustrophobia_per_class'], 1e-9);
    }

    /** @test */
    public function block_adherence_excludes_non_constrained_classes(): void
    {
        $term = SchoolTerm::factory()->create();

        $roomA = Room::factory()->create(['nome' => 'A101', 'assentos' => 120]);
        $roomB = Room::factory()->create(['nome' => 'B101', 'assentos' => 120]);

        $seniorCi = CourseInformation::factory()->mandatory()->semester('6')->create();
        $cSenior = $this->createClass($term, ['estmtr' => 100, 'tiptur' => 'Graduação'], [$seniorCi]);

        $metrics = $this->service->evaluate($term, [$cSenior->id => $roomB->id]);

        $this->assertSame(0.0, $metrics['block_adherence_rate']);
        $this->assertSame(100.0, $metrics['allocation_rate']);

        $metricsA = $this->service->evaluate($term, [$cSenior->id => $roomA->id]);
        $this->assertSame(0.0, $metricsA['block_adherence_rate']);
    }

    /** @test */
    public function it_treats_null_and_unknown_room_ids_as_unallocated(): void
    {
        $term = SchoolTerm::factory()->create();

        $c1 = $this->createClass($term, ['estmtr' => 100, 'tiptur' => 'Pós Graduação']);
        $c2 = $this->createClass($term, ['estmtr' => 100, 'tiptur' => 'Pós Graduação']);
        $c3 = $this->createClass($term, ['estmtr' => 100, 'tiptur' => 'Pós Graduação']);

        $metrics = $this->service->evaluate($term, [
            $c1->id => null,
            $c2->id => 999999,
            $c3->id => Room::factory()->create(['nome' => 'B120', 'assentos' => 120])->id,
        ]);

        $this->assertEqualsWithDelta(33.3333333, $metrics['allocation_rate'], 1e-6);
        $this->assertSame(100.0, $metrics['comfort_zone_rate']);
    }

    /** @test */
    public function it_returns_zeros_when_no_eligible_classes(): void
    {
        $term = SchoolTerm::factory()->create();

        $this->createClass($term, ['estmtr' => 0, 'tiptur' => 'Graduação']);
        $this->createClass($term, ['estmtr' => 100, 'tiptur' => 'Graduação', 'externa' => true]);

        $metrics = $this->service->evaluate($term, []);

        $this->assertSame(0.0, $metrics['allocation_rate']);
        $this->assertSame(0.0, $metrics['comfort_zone_rate']);
        $this->assertSame(0.0, $metrics['avg_waste_per_class']);
        $this->assertSame(0.0, $metrics['avg_claustrophobia_per_class']);
        $this->assertSame(0.0, $metrics['block_adherence_rate']);
        $this->assertNull($metrics['solve_time_seconds']);
    }

    /** @test */
    public function it_returns_zeros_when_nothing_is_allocated(): void
    {
        $term = SchoolTerm::factory()->create();

        $this->createClass($term, ['estmtr' => 100, 'tiptur' => 'Pós Graduação']);
        $this->createClass($term, ['estmtr' => 100, 'tiptur' => 'Pós Graduação']);

        $metrics = $this->service->evaluate($term, []);

        $this->assertSame(0.0, $metrics['allocation_rate']);
        $this->assertSame(0.0, $metrics['comfort_zone_rate']);
        $this->assertSame(0.0, $metrics['avg_waste_per_class']);
        $this->assertSame(0.0, $metrics['avg_claustrophobia_per_class']);
    }

    /** @test */
    public function it_does_not_write_to_the_database(): void
    {
        $term = SchoolTerm::factory()->create();

        $originalRoom = Room::factory()->create(['nome' => 'A120', 'assentos' => 120]);
        $targetRoom = Room::factory()->create(['nome' => 'B150', 'assentos' => 150]);

        $class = $this->createClass($term, [
            'estmtr' => 100,
            'tiptur' => 'Pós Graduação',
            'room_id' => $originalRoom->id,
        ]);

        $this->service->evaluate($term, [$class->id => $targetRoom->id], 5.0);

        $this->assertSame(
            (int) $originalRoom->id,
            (int) SchoolClass::whereKey($class->id)->value('room_id'),
            'O evaluator não pode sobrescrever o room_id de produção.'
        );

        $this->assertSame($targetRoom->assentos, (int) Room::whereKey($targetRoom->id)->value('assentos'));
    }

    /** @test */
    public function solve_time_seconds_defaults_to_null(): void
    {
        $term = SchoolTerm::factory()->create();

        $c = $this->createClass($term, ['estmtr' => 100, 'tiptur' => 'Pós Graduação']);
        $room = Room::factory()->create(['nome' => 'B120', 'assentos' => 120]);

        $metrics = $this->service->evaluate($term, [$c->id => $room->id]);

        $this->assertNull($metrics['solve_time_seconds']);
    }

    private function createClass(SchoolTerm $term, array $attributes = [], array $courseInformations = []): SchoolClass
    {
        $class = SchoolClass::factory()
            ->withSchoolTerm($term)
            ->withoutRoom()
            ->create(array_merge([
                'tiptur' => 'Graduação',
                'externa' => false,
            ], $attributes));

        foreach ($courseInformations as $ci) {
            $class->courseinformations()->attach($ci->id);
        }

        return $class->fresh('courseinformations');
    }
}
