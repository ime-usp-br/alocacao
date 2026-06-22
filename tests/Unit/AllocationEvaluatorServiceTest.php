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

    /** @test */
    public function breakdown_returns_only_eligible_classes()
    {
        $term = SchoolTerm::factory()->create();
        $eligible = SchoolClass::factory()->withSchoolTerm($term)->create([
            'estmtr' => 50,
            'externa' => false,
        ]);
        $external = SchoolClass::factory()->withSchoolTerm($term)->create([
            'estmtr' => 50,
            'externa' => true,
        ]);
        $zeroDemand = SchoolClass::factory()->withSchoolTerm($term)->create([
            'estmtr' => 0,
            'externa' => false,
        ]);

        $service = new AllocationEvaluatorService();
        $breakdown = $service->breakdown($term, [$eligible->id => null]);

        $ids = array_column($breakdown, 'class_id');
        $this->assertContains($eligible->id, $ids);
        $this->assertNotContains($external->id, $ids);
        $this->assertNotContains($zeroDemand->id, $ids);
    }

    /** @test */
    public function breakdown_computes_spatial_metrics_correctly()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create(['nome' => 'A101', 'assentos' => 100]);

        // demand = 80, capacity = 100
        // comfort zone 10%-25% => 88-100
        // capacity = 100 is exactly at maxComfortCapacity (80*1.25=100) => inside comfort zone
        $class = SchoolClass::factory()->withSchoolTerm($term)->create([
            'estmtr' => 80,
            'externa' => false,
        ]);

        $service = new AllocationEvaluatorService();
        $breakdown = $service->breakdown($term, [$class->id => $room->id]);
        $record = collect($breakdown)->firstWhere('class_id', $class->id);

        $this->assertTrue($record['allocated']);
        $this->assertEquals(80.0, $record['demand']);
        $this->assertEquals(100.0, $record['capacity']);
        $this->assertEquals(0.8, $record['occupancy_ratio']);
        $this->assertTrue($record['in_comfort_zone']);
        $this->assertEquals(0.0, $record['waste']);
        $this->assertEquals(0.0, $record['claustrophobia']);
    }

    /** @test */
    public function breakdown_detects_waste_and_claustrophobia()
    {
        $term = SchoolTerm::factory()->create();
        // Waste: capacity well above max comfort
        $roomWaste = Room::factory()->create(['nome' => 'A101', 'assentos' => 150]);
        $classWaste = SchoolClass::factory()->withSchoolTerm($term)->create([
            'estmtr' => 80,
            'externa' => false,
        ]);

        // Claustrophobia: capacity below min comfort
        $roomClaus = Room::factory()->create(['nome' => 'A102', 'assentos' => 80]);
        $classClaus = SchoolClass::factory()->withSchoolTerm($term)->create([
            'estmtr' => 100,
            'externa' => false,
        ]);

        $service = new AllocationEvaluatorService();
        $bd = $service->breakdown($term, [
            $classWaste->id => $roomWaste->id,
            $classClaus->id => $roomClaus->id,
        ]);

        $rWaste = collect($bd)->firstWhere('class_id', $classWaste->id);
        $rClaus = collect($bd)->firstWhere('class_id', $classClaus->id);

        // demand 80, max comfort = 100, capacity 150 => waste 50
        $this->assertEquals(50.0, $rWaste['waste']);
        $this->assertFalse($rWaste['in_comfort_zone']);

        // demand 100, min comfort = 110, capacity 80 => claustrophobia 30
        $this->assertEquals(30.0, $rClaus['claustrophobia']);
        $this->assertFalse($rClaus['in_comfort_zone']);
    }

    /** @test */
    public function breakdown_block_adherence_for_freshman_and_postgrad()
    {
        $term = SchoolTerm::factory()->create();

        // Freshman (Graduação, mandatory, 1st semester) => expected block A
        $freshman = SchoolClass::factory()->withSchoolTerm($term)->undergraduate()->create([
            'estmtr' => 30,
            'externa' => false,
        ]);
        $ci = \App\Models\CourseInformation::factory()->mandatory()->semester('1')->create();
        $freshman->courseinformations()->attach($ci);

        // Post-grad => expected block B
        $postgrad = SchoolClass::factory()->withSchoolTerm($term)->graduate()->create([
            'estmtr' => 20,
            'externa' => false,
        ]);

        // Regular undergrad (not freshman) => no expected block
        $regular = SchoolClass::factory()->withSchoolTerm($term)->undergraduate()->create([
            'estmtr' => 40,
            'externa' => false,
        ]);
        $ciRegular = \App\Models\CourseInformation::factory()->create(['tipobg' => 'O', 'numsemidl' => '3']);
        $regular->courseinformations()->attach($ciRegular);

        $roomA = Room::factory()->create(['nome' => 'A101', 'assentos' => 50]);
        $roomB = Room::factory()->create(['nome' => 'B101', 'assentos' => 50]);

        $service = new AllocationEvaluatorService();
        $bd = $service->breakdown($term, [
            $freshman->id => $roomA->id,
            $postgrad->id => $roomB->id,
            $regular->id => $roomA->id,
        ]);

        $rf = collect($bd)->firstWhere('class_id', $freshman->id);
        $rp = collect($bd)->firstWhere('class_id', $postgrad->id);
        $rr = collect($bd)->firstWhere('class_id', $regular->id);

        $this->assertEquals('A', $rf['expected_block']);
        $this->assertEquals('A', $rf['actual_block']);

        $this->assertEquals('B', $rp['expected_block']);
        $this->assertEquals('B', $rp['actual_block']);

        $this->assertNull($rr['expected_block']);
        $this->assertEquals('A', $rr['actual_block']);
    }

    /** @test */
    public function evaluate_aggregates_breakdown_correctly()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create(['nome' => 'A101', 'assentos' => 100]);
        $class = SchoolClass::factory()->withSchoolTerm($term)->create([
            'estmtr' => 80,
            'externa' => false,
        ]);

        $service = new AllocationEvaluatorService();
        $metrics = $service->evaluate($term, [$class->id => $room->id]);

        $this->assertEquals(100.0, $metrics['allocation_rate']);
        $this->assertEquals(100.0, $metrics['comfort_zone_rate']);
        $this->assertEquals(0.0, $metrics['avg_waste_per_class']);
        $this->assertEquals(0.0, $metrics['avg_claustrophobia_per_class']);
    }

    /** @test */
    public function evaluate_is_regression_identical_to_previous_implementation()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create(['nome' => 'A101', 'assentos' => 120]);
        $class = SchoolClass::factory()->withSchoolTerm($term)->create([
            'estmtr' => 100,
            'externa' => false,
        ]);

        $service = new AllocationEvaluatorService();
        $metrics = $service->evaluate($term, [$class->id => $room->id], 5.0);

        // capacity 120, demand 100, min comfort 110, max comfort 125
        // capacity 120 > max comfort 125? No, 120 <= 125 => inside comfort zone
        // waste = 0 (120 <= 125)
        // claustrophobia = 0 (120 >= 110)
        $this->assertEquals(100.0, $metrics['allocation_rate']);
        $this->assertEquals(100.0, $metrics['comfort_zone_rate']);
        $this->assertEquals(0.0, $metrics['avg_waste_per_class']);
        $this->assertEquals(0.0, $metrics['avg_claustrophobia_per_class']);
        $this->assertEquals(5.0, $metrics['solve_time_seconds']);
    }

    /** @test */
    public function breakdown_handles_unassigned_class()
    {
        $term = SchoolTerm::factory()->create();
        $class = SchoolClass::factory()->withSchoolTerm($term)->create([
            'estmtr' => 50,
            'externa' => false,
        ]);

        $service = new AllocationEvaluatorService();
        $bd = $service->breakdown($term, [$class->id => null]);
        $r = collect($bd)->firstWhere('class_id', $class->id);

        $this->assertFalse($r['allocated']);
        $this->assertNull($r['capacity']);
        $this->assertNull($r['occupancy_ratio']);
        $this->assertEquals(0.0, $r['waste']);
        $this->assertEquals(0.0, $r['claustrophobia']);
        $this->assertNull($r['actual_block']);
    }
}
