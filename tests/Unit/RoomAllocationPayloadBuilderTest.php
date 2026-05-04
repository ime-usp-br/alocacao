<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\RoomAllocationPayloadBuilder;
use App\Models\SchoolClass;
use App\Models\SchoolTerm;
use App\Models\Room;
use App\Models\Fusion;
use App\Models\ClassSchedule;

class RoomAllocationPayloadBuilderTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_builds_payload_for_single_class_with_schedule()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();
        $class = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'coddis' => 'MAC0110',
            'codtur' => '20241',
            'nomdis' => 'Introducao a Computacao',
            'tiptur' => 'Graduacao',
            'estmtr' => 55,
            'externa' => false,
            'fusion_id' => null,
        ]);
        $schedule = ClassSchedule::factory()->seg()->morning()->create();
        $class->classschedules()->attach($schedule);

        $builder = new RoomAllocationPayloadBuilder();
        $payload = $builder->build($term, [$room->id]);

        $this->assertCount(1, $payload['groups']);
        $group = $payload['groups'][0];
        $this->assertEquals($class->id, $group['id']);
        $this->assertEquals('single', $group['type']);
        $this->assertEquals([$class->id], $group['class_ids']);
        $this->assertEquals('MAC0110', $group['coddis']);
        $this->assertEquals(55, $group['demand']);
        $this->assertFalse($group['has_null_enrollment']);
        $this->assertCount(1, $group['timeslot_ids']);
        $this->assertEquals(0, $group['timeslot_ids'][0]);
        $this->assertNull($group['preassigned_room_id']);
    }

    /** @test */
    public function it_builds_payload_for_fusion_with_two_classes()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();

        $fusion = Fusion::factory()->create();
        $classA = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'coddis' => 'MAT2453',
            'codtur' => '20241',
            'nomdis' => 'Calculo Diferencial e Integral I',
            'tiptur' => 'Graduacao',
            'estmtr' => 60,
            'fusion_id' => $fusion->id,
        ]);
        $classB = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'coddis' => 'MAT2453',
            'codtur' => '20242',
            'nomdis' => 'Calculo Diferencial e Integral I',
            'tiptur' => 'Graduacao',
            'estmtr' => 60,
            'fusion_id' => $fusion->id,
        ]);

        $schedule1 = ClassSchedule::factory()->seg()->morning()->create();
        $schedule2 = ClassSchedule::factory()->ter()->morning()->create();
        $classA->classschedules()->attach([$schedule1->id, $schedule2->id]);
        $classB->classschedules()->attach([$schedule2->id]);

        $builder = new RoomAllocationPayloadBuilder();
        $payload = $builder->build($term, [$room->id]);

        $this->assertCount(1, $payload['groups']);
        $group = $payload['groups'][0];
        $this->assertEquals('fusion', $group['type']);
        $this->assertEquals([$classA->id, $classB->id], $group['class_ids']);
        $this->assertEquals(120, $group['demand']);
        $this->assertCount(2, $group['timeslot_ids']);
    }

    /** @test */
    public function it_builds_payload_for_fusion_with_partial_null_enrollment()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();

        $fusion = Fusion::factory()->create();
        $classA = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'estmtr' => 40,
            'fusion_id' => $fusion->id,
        ]);
        $classB = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'estmtr' => null,
            'fusion_id' => $fusion->id,
        ]);

        $builder = new RoomAllocationPayloadBuilder();
        $payload = $builder->build($term, [$room->id]);

        $group = $payload['groups'][0];
        $this->assertTrue($group['has_null_enrollment']);
        $this->assertEquals(40, $group['demand']);
    }

    /** @test */
    public function it_excludes_external_classes()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();

        $external = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'externa' => true,
        ]);
        $normal = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'externa' => false,
        ]);

        $builder = new RoomAllocationPayloadBuilder();
        $payload = $builder->build($term, [$room->id]);

        $classIds = array_column($payload['groups'], 'id');
        $this->assertNotContains($external->id, $classIds);
        $this->assertContains($normal->id, $classIds);
    }

    /** @test */
    public function it_excludes_mae0116()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();

        $excluded = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'coddis' => 'MAE0116',
        ]);
        $normal = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'coddis' => 'MAC0110',
        ]);

        $builder = new RoomAllocationPayloadBuilder();
        $payload = $builder->build($term, [$room->id]);

        $classIds = array_column($payload['groups'], 'id');
        $this->assertNotContains($excluded->id, $classIds);
        $this->assertContains($normal->id, $classIds);
    }

    /** @test */
    public function it_only_includes_specified_rooms()
    {
        $term = SchoolTerm::factory()->create();
        $roomA = Room::factory()->create();
        $roomB = Room::factory()->create();
        $roomC = Room::factory()->create();

        SchoolClass::factory()->create([
            'school_term_id' => $term->id,
        ]);

        $builder = new RoomAllocationPayloadBuilder();
        $payload = $builder->build($term, [$roomA->id, $roomC->id]);

        $roomIds = array_column($payload['rooms'], 'id');
        $this->assertEquals([$roomA->id, $roomC->id], $roomIds);
        $this->assertNotContains($roomB->id, $roomIds);
    }

    /** @test */
    public function it_generates_unique_timeslots()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();

        $classA = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
        ]);
        $classB = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
        ]);

        $schedule = ClassSchedule::factory()->seg()->morning()->create();
        $classA->classschedules()->attach($schedule);
        $classB->classschedules()->attach($schedule);

        $builder = new RoomAllocationPayloadBuilder();
        $payload = $builder->build($term, [$room->id]);

        $this->assertCount(1, $payload['timeslots']);
        $this->assertEquals('seg_0800_1000', $payload['timeslots'][0]['label']);
        $this->assertEquals('seg', $payload['timeslots'][0]['day']);
        $this->assertEquals('08:00', $payload['timeslots'][0]['start']);
        $this->assertEquals('10:00', $payload['timeslots'][0]['end']);
    }

    /** @test */
    public function it_produces_deterministic_output()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();

        SchoolClass::factory()->count(5)->create([
            'school_term_id' => $term->id,
        ]);

        $builder = new RoomAllocationPayloadBuilder();
        $payload1 = $builder->build($term, [$room->id]);
        $payload2 = $builder->build($term, [$room->id]);

        // Reset generated_at before comparison
        $payload1['meta']['generated_at'] = '';
        $payload2['meta']['generated_at'] = '';

        $this->assertEquals($payload1, $payload2);
    }

    /** @test */
    public function it_includes_config_block_with_defaults()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();

        SchoolClass::factory()->create([
            'school_term_id' => $term->id,
        ]);

        $builder = new RoomAllocationPayloadBuilder();
        $payload = $builder->build($term, [$room->id]);

        $this->assertArrayHasKey('config', $payload);
        $this->assertArrayHasKey('strict_capacity', $payload['config']);
        $this->assertArrayHasKey('block_b_restriction_for_pos', $payload['config']);
        $this->assertArrayHasKey('wasted_seats_weight', $payload['config']);
        $this->assertArrayHasKey('unassigned_penalty', $payload['config']);
        $this->assertArrayHasKey('priority_weight', $payload['config']);

        $this->assertFalse($payload['config']['strict_capacity']);
        $this->assertTrue($payload['config']['block_b_restriction_for_pos']);
        $this->assertEquals(1.0, $payload['config']['wasted_seats_weight']);
        $this->assertEquals(1000.0, $payload['config']['unassigned_penalty']);
        $this->assertEquals(0.0, $payload['config']['priority_weight']);
    }

    /** @test */
    public function it_overrides_config_values()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();

        SchoolClass::factory()->create([
            'school_term_id' => $term->id,
        ]);

        $builder = new RoomAllocationPayloadBuilder();
        $payload = $builder->build($term, [$room->id], [
            'strict_capacity' => true,
            'wasted_seats_weight' => 5.0,
        ]);

        $this->assertTrue($payload['config']['strict_capacity']);
        $this->assertEquals(5.0, $payload['config']['wasted_seats_weight']);
        $this->assertTrue($payload['config']['block_b_restriction_for_pos']);
    }

    /** @test */
    public function it_includes_meta_block()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();

        SchoolClass::factory()->create([
            'school_term_id' => $term->id,
        ]);

        $builder = new RoomAllocationPayloadBuilder();
        $payload = $builder->build($term, [$room->id]);

        $this->assertArrayHasKey('meta', $payload);
        $this->assertEquals('1.0.0', $payload['meta']['version']);
        $this->assertEquals($term->id, $payload['meta']['school_term_id']);
        $this->assertEquals(RoomAllocationPayloadBuilder::class, $payload['meta']['builder_class']);
        $this->assertNotEmpty($payload['meta']['generated_at']);
    }

    /** @test */
    public function it_handles_class_without_schedules()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();

        SchoolClass::factory()->create([
            'school_term_id' => $term->id,
        ]);

        $builder = new RoomAllocationPayloadBuilder();
        $payload = $builder->build($term, [$room->id]);

        $group = $payload['groups'][0];
        $this->assertEmpty($group['timeslot_ids']);
        $this->assertEmpty($payload['timeslots']);
    }

    /** @test */
    public function it_does_not_read_priority_data()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();

        SchoolClass::factory()->create([
            'school_term_id' => $term->id,
        ]);

        $builder = new RoomAllocationPayloadBuilder();
        $payload = $builder->build($term, [$room->id]);

        $this->assertArrayNotHasKey('priorities', $payload);
        $this->assertArrayNotHasKey('priority', $payload);
    }

    /** @test */
    public function it_handles_fusion_without_master_using_min_id()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();

        $fusion = Fusion::factory()->create(['master_id' => null]);
        $classA = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'fusion_id' => $fusion->id,
        ]);
        $classB = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'fusion_id' => $fusion->id,
        ]);

        $builder = new RoomAllocationPayloadBuilder();
        $payload = $builder->build($term, [$room->id]);

        $group = $payload['groups'][0];
        $expectedId = min($classA->id, $classB->id);
        $this->assertEquals($expectedId, $group['id']);
        $this->assertEquals('fusion', $group['type']);
    }
}
