<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\RoomAllocationPayloadBuilder;
use App\Services\HistoricalEnrollmentService;
use App\Models\SchoolClass;
use App\Models\SchoolTerm;
use App\Models\Room;
use App\Models\Fusion;
use App\Models\ClassSchedule;
use Mockery;

class RoomAllocationPayloadBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

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
            'room_id' => null,
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
        $this->assertArrayHasKey('same_room_cohort', $group);
        $this->assertNull($group['same_room_cohort']);
        $this->assertArrayHasKey('is_freshmen', $group);
        $this->assertFalse($group['is_freshmen']);
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
        $this->assertArrayHasKey('same_room_cohort', $group);
        $this->assertNull($group['same_room_cohort']);
        $this->assertArrayHasKey('is_freshmen', $group);
        $this->assertFalse($group['is_freshmen']);
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
        $this->assertArrayHasKey('same_room_cohort', $group);
        $this->assertNull($group['same_room_cohort']);
        $this->assertArrayHasKey('is_freshmen', $group);
        $this->assertFalse($group['is_freshmen']);
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
    public function it_includes_all_rooms_and_marks_only_selected_as_available_for_auto()
    {
        $term = SchoolTerm::factory()->create();
        $roomA = Room::factory()->create();
        $roomB = Room::factory()->create();
        $roomC = Room::factory()->create();

        SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => null,
        ]);

        $builder = new RoomAllocationPayloadBuilder();
        $payload = $builder->build($term, [$roomA->id, $roomC->id]);

        $roomMap = array_column($payload['rooms'], null, 'id');
        $this->assertArrayHasKey($roomA->id, $roomMap);
        $this->assertArrayHasKey($roomB->id, $roomMap);
        $this->assertArrayHasKey($roomC->id, $roomMap);

        $this->assertTrue($roomMap[$roomA->id]['available_for_auto']);
        $this->assertFalse($roomMap[$roomB->id]['available_for_auto']);
        $this->assertTrue($roomMap[$roomC->id]['available_for_auto']);
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
        $this->assertArrayHasKey('block_a_restriction_for_freshmen', $payload['config']);
        $this->assertArrayHasKey('undergrad_in_block_a_penalty', $payload['config']);
        $this->assertArrayHasKey('pos_in_block_b_penalty', $payload['config']);
        $this->assertArrayHasKey('wasted_seats_weight', $payload['config']);
        $this->assertArrayHasKey('unassigned_penalty', $payload['config']);
        $this->assertArrayHasKey('priority_weight', $payload['config']);

        $this->assertTrue($payload['config']['strict_capacity']);
        $this->assertTrue($payload['config']['block_b_restriction_for_pos']);
        $this->assertTrue($payload['config']['block_a_restriction_for_freshmen']);
        $this->assertEquals(500.0, $payload['config']['undergrad_in_block_a_penalty']);
        $this->assertEquals(500.0, $payload['config']['pos_in_block_b_penalty']);
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
        $this->assertEquals('1.1.0', $payload['meta']['version']);
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
        $this->assertArrayHasKey('same_room_cohort', $group);
        $this->assertNull($group['same_room_cohort']);
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
        $this->assertArrayHasKey('same_room_cohort', $group);
        $this->assertNull($group['same_room_cohort']);
    }

    /** @test */
    public function it_handles_all_null_enrollment()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();

        $fusion = Fusion::factory()->create();
        $classA = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'estmtr' => null,
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
        $this->assertEquals(0, $group['demand']);
        $this->assertArrayHasKey('same_room_cohort', $group);
        $this->assertNull($group['same_room_cohort']);
    }

    /** @test */
    public function it_handles_empty_school_term()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();

        $builder = new RoomAllocationPayloadBuilder();
        $payload = $builder->build($term, [$room->id]);

        $this->assertEmpty($payload['groups']);
        $this->assertEmpty($payload['timeslots']);
        $this->assertCount(1, $payload['rooms']);
    }

    /** @test */
    public function it_rejects_all_classes_when_filtered()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();

        SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => $room->id,
            'externa' => true,
        ]);
        SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => $room->id,
            'coddis' => 'MAE0116',
        ]);

        $builder = new RoomAllocationPayloadBuilder();
        $payload = $builder->build($term, [$room->id]);

        $this->assertEmpty($payload['groups']);
        $this->assertEmpty($payload['timeslots']);
    }

    /** @test */
    public function it_handles_room_ids_empty_array()
    {
        $term = SchoolTerm::factory()->create();

        SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => null,
        ]);

        $builder = new RoomAllocationPayloadBuilder();
        $payload = $builder->build($term, []);

        $this->assertEmpty($payload['rooms']);
        $this->assertCount(1, $payload['groups']);
    }

    /** @test */
    public function it_handles_fusion_with_one_class_having_no_schedules()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();

        $fusion = Fusion::factory()->create();
        $classA = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'fusion_id' => $fusion->id,
        ]);
        $classB = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'fusion_id' => $fusion->id,
        ]);

        $schedule = ClassSchedule::factory()->seg()->morning()->create();
        $classA->classschedules()->attach($schedule);

        $builder = new RoomAllocationPayloadBuilder();
        $payload = $builder->build($term, [$room->id]);

        $group = $payload['groups'][0];
        $this->assertCount(1, $group['timeslot_ids']);
        $this->assertCount(1, $payload['timeslots']);
        $this->assertArrayHasKey('same_room_cohort', $group);
        $this->assertNull($group['same_room_cohort']);
    }

    /** @test */
    public function it_merges_overlapping_fusion_schedules_on_same_day()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();

        $fusion = Fusion::factory()->create();
        $classA = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'fusion_id' => $fusion->id,
        ]);
        $classB = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'fusion_id' => $fusion->id,
        ]);

        $sQuaA = ClassSchedule::factory()->qua()->state(['horent' => '14:00', 'horsai' => '15:40'])->create();
        $sSegA = ClassSchedule::factory()->seg()->state(['horent' => '16:00', 'horsai' => '17:40'])->create();
        $sQuaB = ClassSchedule::factory()->qua()->state(['horent' => '14:00', 'horsai' => '16:00'])->create();
        $sSegB = ClassSchedule::factory()->seg()->state(['horent' => '16:00', 'horsai' => '18:00'])->create();

        $classA->classschedules()->attach([$sQuaA->id, $sSegA->id]);
        $classB->classschedules()->attach([$sQuaB->id, $sSegB->id]);

        $builder = new RoomAllocationPayloadBuilder();
        $payload = $builder->build($term, [$room->id]);

        $group = $payload['groups'][0];
        $this->assertEquals('fusion', $group['type']);
        $this->assertCount(2, $group['timeslot_ids']);

        $labels = array_column($payload['timeslots'], 'label');
        $this->assertContains('qua_1400_1600', $labels);
        $this->assertContains('seg_1600_1800', $labels);
        $this->assertArrayHasKey('same_room_cohort', $group);
        $this->assertNull($group['same_room_cohort']);
    }

    /** @test */
    public function it_keeps_identical_fusion_schedules_as_single_label()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();

        $fusion = Fusion::factory()->create();
        $classA = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'fusion_id' => $fusion->id,
        ]);
        $classB = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'fusion_id' => $fusion->id,
        ]);

        $schedule = ClassSchedule::factory()->ter()->morning()->create();
        $classA->classschedules()->attach($schedule);
        $classB->classschedules()->attach($schedule);

        $builder = new RoomAllocationPayloadBuilder();
        $payload = $builder->build($term, [$room->id]);

        $group = $payload['groups'][0];
        $this->assertEquals('fusion', $group['type']);
        $this->assertCount(1, $group['timeslot_ids']);
        $this->assertEquals('ter_0800_1000', $payload['timeslots'][0]['label']);
        $this->assertArrayHasKey('same_room_cohort', $group);
        $this->assertNull($group['same_room_cohort']);
    }

    /** @test */
    public function it_handles_fusion_with_schedules_on_different_days_without_overlap()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();

        $fusion = Fusion::factory()->create();
        $classA = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'fusion_id' => $fusion->id,
        ]);
        $classB = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'fusion_id' => $fusion->id,
        ]);

        $sSeg = ClassSchedule::factory()->seg()->morning()->create();
        $sTer = ClassSchedule::factory()->ter()->morning()->create();

        $classA->classschedules()->attach($sSeg);
        $classB->classschedules()->attach($sTer);

        $builder = new RoomAllocationPayloadBuilder();
        $payload = $builder->build($term, [$room->id]);

        $group = $payload['groups'][0];
        $this->assertEquals('fusion', $group['type']);
        $this->assertCount(2, $group['timeslot_ids']);

        $labels = array_column($payload['timeslots'], 'label');
        $this->assertContains('seg_0800_1000', $labels);
        $this->assertContains('ter_0800_1000', $labels);
        $this->assertArrayHasKey('same_room_cohort', $group);
        $this->assertNull($group['same_room_cohort']);
    }

    /** @test */
    public function it_sorts_timeslots_lexicographically()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();

        $class = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
        ]);

        $s1 = ClassSchedule::factory()->qui()->afternoon()->create();
        $s2 = ClassSchedule::factory()->seg()->morning()->create();
        $s3 = ClassSchedule::factory()->ter()->morning()->create();
        $class->classschedules()->attach([$s1->id, $s2->id, $s3->id]);

        $builder = new RoomAllocationPayloadBuilder();
        $payload = $builder->build($term, [$room->id]);

        $timeslots = $payload['timeslots'];
        $this->assertCount(3, $timeslots);

        $labels = array_column($timeslots, 'label');
        $sorted = $labels;
        sort($sorted);
        $this->assertEquals($sorted, $labels);

        $this->assertEquals('qui_1400_1600', $labels[0]);
        $this->assertEquals('seg_0800_1000', $labels[1]);
        $this->assertEquals('ter_0800_1000', $labels[2]);
    }

    /** @test */
    public function it_assigns_same_room_cohort_to_mandatory_initial_semesters()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();

        $course = \App\Models\Course::factory()->create([
            'sufixo_codtur' => '45',
        ]);

        $courseInfo = \App\Models\CourseInformation::factory()->create([
            'tipobg' => 'O',
            'numsemidl' => '1',
        ]);

        $class = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'codtur' => '202445',
            'tiptur' => 'Graduação',
            'externa' => false,
            'fusion_id' => null,
            'room_id' => null,
        ]);

        $class->courseinformations()->attach($courseInfo);

        $schedule = ClassSchedule::factory()->seg()->morning()->create();
        $class->classschedules()->attach($schedule);

        $builder = new RoomAllocationPayloadBuilder();
        $payload = $builder->build($term, [$room->id]);

        $group = $payload['groups'][0];
        $this->assertEquals('cohort_45_sem_1', $group['same_room_cohort']);
        $this->assertTrue($group['is_freshmen']);
    }

    /** @test */
    public function it_marks_freshmen_single_class_as_is_freshmen_true()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();

        $course = \App\Models\Course::factory()->create([
            'sufixo_codtur' => '45',
        ]);

        $courseInfo = \App\Models\CourseInformation::factory()->create([
            'tipobg' => 'O',
            'numsemidl' => '1',
        ]);

        $class = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'codtur' => '202445',
            'tiptur' => 'Graduação',
            'externa' => false,
            'fusion_id' => null,
        ]);

        $class->courseinformations()->attach($courseInfo);

        $schedule = ClassSchedule::factory()->seg()->morning()->create();
        $class->classschedules()->attach($schedule);

        $builder = new RoomAllocationPayloadBuilder();
        $payload = $builder->build($term, [$room->id]);

        $group = $payload['groups'][0];
        $this->assertTrue($group['is_freshmen']);
    }

    /** @test */
    public function it_marks_pos_grad_class_as_is_freshmen_false()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();

        $class = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'tiptur' => 'Pós Graduação',
            'externa' => false,
            'fusion_id' => null,
        ]);

        $schedule = ClassSchedule::factory()->seg()->morning()->create();
        $class->classschedules()->attach($schedule);

        $builder = new RoomAllocationPayloadBuilder();
        $payload = $builder->build($term, [$room->id]);

        $group = $payload['groups'][0];
        $this->assertFalse($group['is_freshmen']);
    }

    /** @test */
    public function it_marks_non_mandatory_class_as_is_freshmen_false()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();

        $course = \App\Models\Course::factory()->create([
            'sufixo_codtur' => '45',
        ]);

        $courseInfo = \App\Models\CourseInformation::factory()->create([
            'tipobg' => 'C',
            'numsemidl' => '1',
        ]);

        $class = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'codtur' => '202445',
            'tiptur' => 'Graduação',
            'externa' => false,
            'fusion_id' => null,
        ]);

        $class->courseinformations()->attach($courseInfo);

        $schedule = ClassSchedule::factory()->seg()->morning()->create();
        $class->classschedules()->attach($schedule);

        $builder = new RoomAllocationPayloadBuilder();
        $payload = $builder->build($term, [$room->id]);

        $group = $payload['groups'][0];
        $this->assertFalse($group['is_freshmen']);
    }

    /** @test */
    public function it_marks_advanced_semester_class_as_is_freshmen_false()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();

        $course = \App\Models\Course::factory()->create([
            'sufixo_codtur' => '45',
        ]);

        $courseInfo = \App\Models\CourseInformation::factory()->create([
            'tipobg' => 'O',
            'numsemidl' => '3',
        ]);

        $class = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'codtur' => '202445',
            'tiptur' => 'Graduação',
            'externa' => false,
            'fusion_id' => null,
        ]);

        $class->courseinformations()->attach($courseInfo);

        $schedule = ClassSchedule::factory()->seg()->morning()->create();
        $class->classschedules()->attach($schedule);

        $builder = new RoomAllocationPayloadBuilder();
        $payload = $builder->build($term, [$room->id]);

        $group = $payload['groups'][0];
        $this->assertFalse($group['is_freshmen']);
    }

    /** @test */
    public function it_marks_fusion_as_freshmen_when_any_class_qualifies()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();

        $course = \App\Models\Course::factory()->create([
            'sufixo_codtur' => '45',
        ]);

        $courseInfo = \App\Models\CourseInformation::factory()->create([
            'tipobg' => 'O',
            'numsemidl' => '2',
        ]);

        $fusion = Fusion::factory()->create();

        $classA = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'codtur' => '202445',
            'tiptur' => 'Graduação',
            'externa' => false,
            'fusion_id' => $fusion->id,
            'room_id' => null,
        ]);
        $classA->courseinformations()->attach($courseInfo);

        $classB = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'codtur' => '202446',
            'tiptur' => 'Graduação',
            'externa' => false,
            'fusion_id' => $fusion->id,
            'room_id' => null,
        ]);

        $schedule = ClassSchedule::factory()->seg()->morning()->create();
        $classA->classschedules()->attach($schedule);
        $classB->classschedules()->attach($schedule);

        $builder = new RoomAllocationPayloadBuilder();
        $payload = $builder->build($term, [$room->id]);

        $group = $payload['groups'][0];
        $this->assertTrue($group['is_freshmen']);
        $this->assertEquals('cohort_45_sem_2', $group['same_room_cohort']);
    }

    /** @test */
    public function it_removes_manual_class_from_same_room_cohort()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();

        $course = \App\Models\Course::factory()->create([
            'sufixo_codtur' => '45',
        ]);

        $courseInfo = \App\Models\CourseInformation::factory()->create([
            'tipobg' => 'O',
            'numsemidl' => '1',
        ]);

        $class = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'codtur' => '202445',
            'tiptur' => 'Graduação',
            'externa' => false,
            'fusion_id' => null,
            'room_id' => $room->id,
        ]);

        $class->courseinformations()->attach($courseInfo);

        $schedule = ClassSchedule::factory()->seg()->morning()->create();
        $class->classschedules()->attach($schedule);

        $builder = new RoomAllocationPayloadBuilder();
        $payload = $builder->build($term, [$room->id]);

        $group = $payload['groups'][0];
        $this->assertNull($group['same_room_cohort']);
        $this->assertEquals($room->id, $group['preassigned_room_id']);
        $this->assertTrue($group['is_freshmen']);
    }

    /** @test */
    public function it_sets_preassigned_room_id_when_class_has_room_in_available_list()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();

        $class = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => $room->id,
            'externa' => false,
            'fusion_id' => null,
        ]);

        $schedule = ClassSchedule::factory()->seg()->morning()->create();
        $class->classschedules()->attach($schedule);

        $builder = new RoomAllocationPayloadBuilder();
        $payload = $builder->build($term, [$room->id]);

        $group = $payload['groups'][0];
        $this->assertEquals($room->id, $group['preassigned_room_id']);
    }

    /** @test */
    public function it_sets_preassigned_room_id_even_when_class_room_is_not_in_available_list()
    {
        $term = SchoolTerm::factory()->create();
        $roomA = Room::factory()->create();
        $roomB = Room::factory()->create();

        $class = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => $roomB->id,
            'externa' => false,
            'fusion_id' => null,
        ]);

        $schedule = ClassSchedule::factory()->seg()->morning()->create();
        $class->classschedules()->attach($schedule);

        $builder = new RoomAllocationPayloadBuilder();
        $payload = $builder->build($term, [$roomA->id]);

        $group = $payload['groups'][0];
        $this->assertEquals($roomB->id, $group['preassigned_room_id']);

        $roomIds = array_column($payload['rooms'], 'id');
        $this->assertContains($roomB->id, $roomIds);

        $roomMap = array_column($payload['rooms'], null, 'id');
        $this->assertTrue($roomMap[$roomA->id]['available_for_auto']);
        $this->assertFalse($roomMap[$roomB->id]['available_for_auto']);
    }

    /** @test */
    public function it_sets_preassigned_room_id_for_fusion_master_when_available()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();

        $fusion = Fusion::factory()->create();
        $master = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => $room->id,
            'fusion_id' => $fusion->id,
        ]);
        $slave = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'fusion_id' => $fusion->id,
        ]);
        $fusion->master()->associate($master);
        $fusion->save();

        $schedule = ClassSchedule::factory()->seg()->morning()->create();
        $master->classschedules()->attach($schedule);
        $slave->classschedules()->attach($schedule);

        $builder = new RoomAllocationPayloadBuilder();
        $payload = $builder->build($term, [$room->id]);

        $group = $payload['groups'][0];
        $this->assertEquals($room->id, $group['preassigned_room_id']);
    }

    /** @test */
    public function it_includes_manual_room_in_payload_with_available_for_auto_false()
    {
        $term = SchoolTerm::factory()->create();
        $autoRoom = Room::factory()->create();
        $manualRoom = Room::factory()->create();

        $class = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => $manualRoom->id,
            'externa' => false,
            'fusion_id' => null,
        ]);

        $schedule = ClassSchedule::factory()->seg()->morning()->create();
        $class->classschedules()->attach($schedule);

        $builder = new RoomAllocationPayloadBuilder();
        $payload = $builder->build($term, [$autoRoom->id]);

        $this->assertCount(2, $payload['rooms']);

        $roomMap = array_column($payload['rooms'], null, 'id');
        $this->assertTrue($roomMap[$autoRoom->id]['available_for_auto']);
        $this->assertFalse($roomMap[$manualRoom->id]['available_for_auto']);
    }

    /** @test */
    public function it_includes_manual_room_for_fusion_with_available_for_auto_false()
    {
        $term = SchoolTerm::factory()->create();
        $autoRoom = Room::factory()->create();
        $manualRoom = Room::factory()->create();

        $fusion = Fusion::factory()->create();
        $master = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => $manualRoom->id,
            'fusion_id' => $fusion->id,
        ]);
        $slave = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'fusion_id' => $fusion->id,
        ]);
        $fusion->master()->associate($master);
        $fusion->save();

        $schedule = ClassSchedule::factory()->seg()->morning()->create();
        $master->classschedules()->attach($schedule);
        $slave->classschedules()->attach($schedule);

        $builder = new RoomAllocationPayloadBuilder();
        $payload = $builder->build($term, [$autoRoom->id]);

        $group = $payload['groups'][0];
        $this->assertEquals($manualRoom->id, $group['preassigned_room_id']);

        $roomMap = array_column($payload['rooms'], null, 'id');
        $this->assertTrue($roomMap[$autoRoom->id]['available_for_auto']);
        $this->assertFalse($roomMap[$manualRoom->id]['available_for_auto']);
    }

    /** @test */
    public function it_marks_unrelated_rooms_as_unavailable_for_auto()
    {
        $term = SchoolTerm::factory()->create();
        $autoRoom = Room::factory()->create();
        $manualRoom = Room::factory()->create();
        $unusedRoom = Room::factory()->create();

        $class = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => $manualRoom->id,
            'externa' => false,
            'fusion_id' => null,
        ]);

        $schedule = ClassSchedule::factory()->seg()->morning()->create();
        $class->classschedules()->attach($schedule);

        $builder = new RoomAllocationPayloadBuilder();
        $payload = $builder->build($term, [$autoRoom->id]);

        $roomMap = array_column($payload['rooms'], null, 'id');
        $this->assertTrue($roomMap[$autoRoom->id]['available_for_auto']);
        $this->assertFalse($roomMap[$manualRoom->id]['available_for_auto']);
        $this->assertFalse($roomMap[$unusedRoom->id]['available_for_auto']);
    }

    /** @test */
    public function it_sets_room_available_for_auto_true_for_selected_rooms()
    {
        $term = SchoolTerm::factory()->create();
        $roomA = Room::factory()->create();
        $roomB = Room::factory()->create();

        SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => null,
            'externa' => false,
            'fusion_id' => null,
        ]);

        $builder = new RoomAllocationPayloadBuilder();
        $payload = $builder->build($term, [$roomA->id, $roomB->id]);

        $roomMap = array_column($payload['rooms'], null, 'id');
        $this->assertTrue($roomMap[$roomA->id]['available_for_auto']);
        $this->assertTrue($roomMap[$roomB->id]['available_for_auto']);
    }

    /** @test */
    public function it_bumps_schema_version_to_1_1_0()
    {
        $this->assertEquals('1.1.0', RoomAllocationPayloadBuilder::schemaVersion());
    }

    /** @test */
    public function it_applies_historical_adjustment_to_freshmen_class_demand()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();

        $course = \App\Models\Course::factory()->create([
            'sufixo_codtur' => '45',
        ]);

        $courseInfo = \App\Models\CourseInformation::factory()->create([
            'tipobg' => 'O',
            'numsemidl' => '1',
        ]);

        $class = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'codtur' => '202445',
            'coddis' => 'MAC0110',
            'tiptur' => 'Graduação',
            'externa' => false,
            'fusion_id' => null,
            'estmtr' => 10,
        ]);
        $class->courseinformations()->attach($courseInfo);

        $schedule = ClassSchedule::factory()->seg()->morning()->create();
        $class->classschedules()->attach($schedule);

        $historicalServiceMock = Mockery::mock(HistoricalEnrollmentService::class);
        $historicalServiceMock->shouldReceive('calculateAdjustedDemand')
            ->andReturn([
                'demand' => 80,
                'applied' => true,
                'metadata' => [
                    'average' => 70.0,
                    'stddev' => 5.0,
                    'cap' => 100,
                ],
            ]);

        $builder = new RoomAllocationPayloadBuilder($historicalServiceMock);
        $payload = $builder->build($term, [$room->id]);

        $group = $payload['groups'][0];
        $this->assertEquals(80, $group['demand']);
        $this->assertTrue($group['historical_adjustment_applied']);
        $this->assertNotNull($group['historical_adjustment_metadata']);
        $this->assertEquals(80, $group['historical_adjustment_metadata'][0]['adjusted_demand']);
    }

    /** @test */
    public function it_keeps_original_demand_when_historical_adjustment_is_not_applied()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();

        $class = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'estmtr' => 55,
        ]);

        $schedule = ClassSchedule::factory()->seg()->morning()->create();
        $class->classschedules()->attach($schedule);

        $historicalServiceMock = Mockery::mock(HistoricalEnrollmentService::class);
        $historicalServiceMock->shouldReceive('calculateAdjustedDemand')
            ->andReturn([
                'demand' => 55,
                'applied' => false,
                'metadata' => null,
            ]);

        $builder = new RoomAllocationPayloadBuilder($historicalServiceMock);
        $payload = $builder->build($term, [$room->id]);

        $group = $payload['groups'][0];
        $this->assertEquals(55, $group['demand']);
        $this->assertFalse($group['historical_adjustment_applied']);
        $this->assertNull($group['historical_adjustment_metadata']);
    }

    /** @test */
    public function it_does_not_persist_historical_adjustment_to_database()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();

        $course = \App\Models\Course::factory()->create([
            'sufixo_codtur' => '45',
        ]);

        $courseInfo = \App\Models\CourseInformation::factory()->create([
            'tipobg' => 'O',
            'numsemidl' => '1',
        ]);

        $class = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'codtur' => '202445',
            'coddis' => 'MAC0110',
            'tiptur' => 'Graduação',
            'externa' => false,
            'fusion_id' => null,
            'estmtr' => 10,
        ]);
        $class->courseinformations()->attach($courseInfo);

        $schedule = ClassSchedule::factory()->seg()->morning()->create();
        $class->classschedules()->attach($schedule);

        $historicalServiceMock = Mockery::mock(HistoricalEnrollmentService::class);
        $historicalServiceMock->shouldReceive('calculateAdjustedDemand')
            ->andReturn([
                'demand' => 80,
                'applied' => true,
                'metadata' => ['average' => 70.0],
            ]);

        $builder = new RoomAllocationPayloadBuilder($historicalServiceMock);
        $builder->build($term, [$room->id]);

        $class->refresh();
        $this->assertEquals(10, $class->estmtr);
    }
}
