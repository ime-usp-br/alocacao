<?php

namespace Tests\Feature;

use App\Models\ClassSchedule;
use App\Models\Fusion;
use App\Models\Room;
use App\Models\SchoolClass;
use App\Models\SchoolTerm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoomControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'admin']);
    }

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

    private function actingAsRandomUser(): self
    {
        $user = User::factory()->create();
        return $this->actingAs($user);
    }

    // ── Index ────────────────────────────────────────────────────────────────

    /** @test */
    public function admin_can_view_rooms_index()
    {
        Room::factory()->create(['nome' => 'A132']);
        SchoolTerm::factory()->create();

        $response = $this->actingAsAdmin()->get('/rooms');

        $response->assertOk();
        $response->assertViewIs('rooms.index');
        $response->assertViewHas('salas');
        $response->assertSee('A132');
        $response->assertSee('Salas');
    }

    /** @test */
    public function operator_can_view_rooms_index()
    {
        Room::factory()->create();
        SchoolTerm::factory()->create();

        $response = $this->actingAsOperator()->get('/rooms');

        $response->assertOk();
        $response->assertViewIs('rooms.index');
        $response->assertViewHas('salas');
    }

    /** @test */
    public function guest_cannot_view_rooms_index()
    {
        $response = $this->get('/rooms');
        $response->assertForbidden();
    }

    /** @test */
    public function user_without_role_cannot_view_rooms_index()
    {
        $response = $this->actingAsRandomUser()->get('/rooms');
        $response->assertForbidden();
    }

    /** @test */
    public function index_renders_empty_state_without_rooms()
    {
        SchoolTerm::factory()->create();

        $response = $this->actingAsOperator()->get('/rooms');

        $response->assertOk();
        $response->assertSee('Não há salas cadastradas');
    }

    /** @test */
    public function index_shows_compatibility_labels_with_unallocated_classes()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create(['nome' => 'B01', 'assentos' => 70]);

        $unallocatedClass = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => null,
            'externa' => false,
            'tiptur' => 'Graduação',
            'coddis' => 'MAC0110',
            'codtur' => '202611',
            'nomdis' => 'Introdução à Computação',
            'fusion_id' => null,
        ]);
        $schedule = ClassSchedule::factory()->seg()->morning()->create();
        $unallocatedClass->classschedules()->attach($schedule);

        $response = $this->actingAsOperator()->get('/rooms');

        $response->assertOk();
        $response->assertSee('Ver Sala');
        // The compatibility label should appear in the tooltip
        $response->assertSee('Compativel com');
    }

    /** @test */
    public function index_shows_compatibility_labels_with_unallocated_fusions()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create(['nome' => 'B01', 'assentos' => 70]);

        $master = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => null,
            'externa' => false,
            'tiptur' => 'Graduação',
            'coddis' => 'MAC0110',
            'codtur' => '202611',
        ]);
        $child = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => null,
            'externa' => false,
            'tiptur' => 'Graduação',
            'coddis' => 'MAC0110',
            'codtur' => '202612',
        ]);

        $fusion = Fusion::create(['master_id' => $master->id]);
        $master->fusion_id = $fusion->id;
        $master->save();
        $child->fusion_id = $fusion->id;
        $child->save();

        $schedule = ClassSchedule::factory()->seg()->morning()->create();
        $master->classschedules()->attach($schedule);
        $child->classschedules()->attach($schedule);

        $response = $this->actingAsOperator()->get('/rooms');

        $response->assertOk();
        $response->assertSee('Ver Sala');
    }

    /** @test */
    public function index_shows_no_compatibility_when_no_unallocated_classes()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create(['nome' => 'A132', 'assentos' => 45]);

        $allocatedClass = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => $room->id,
            'externa' => false,
        ]);
        $schedule = ClassSchedule::factory()->seg()->morning()->create();
        $allocatedClass->classschedules()->attach($schedule);

        $response = $this->actingAsOperator()->get('/rooms');

        $response->assertOk();
        $response->assertSee('Nenhuma turma compativel');
    }

    /** @test */
    public function index_excludes_external_classes_from_compatibility()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create(['nome' => 'B01', 'assentos' => 70]);

        $externalClass = SchoolClass::factory()->external()->create([
            'school_term_id' => $term->id,
            'room_id' => null,
        ]);
        $schedule = ClassSchedule::factory()->seg()->morning()->create();
        $externalClass->classschedules()->attach($schedule);

        $response = $this->actingAsOperator()->get('/rooms');

        $response->assertOk();
        // External classes are skipped in isCompatible (returns false immediately)
        // So "Nenhuma turma compativel" should appear
        $response->assertSee('Nenhuma turma compativel');
    }

    /** @test */
    public function index_renders_all_rooms_passed_to_view()
    {
        SchoolTerm::factory()->create();
        $room1 = Room::factory()->create(['nome' => 'A100']);
        $room2 = Room::factory()->create(['nome' => 'B200']);

        $response = $this->actingAsOperator()->get('/rooms');

        $response->assertSee('A100');
        $response->assertSee('B200');
    }

    // ── Show ─────────────────────────────────────────────────────────────────

    /** @test */
    public function operator_can_view_room_show()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create(['nome' => 'A132', 'assentos' => 45]);

        $class = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => $room->id,
            'tiptur' => 'Graduação',
            'coddis' => 'MAC0110',
            'codtur' => '202611',
            'nomdis' => 'Introdução à Computação',
        ]);
        $schedule = ClassSchedule::factory()->seg()->morning()->create();
        $class->classschedules()->attach($schedule);

        $response = $this->actingAsOperator()->get("/rooms/{$room->id}");

        $response->assertOk();
        $response->assertViewIs('rooms.show');
        $response->assertViewHas('room');
        $response->assertSee('A132');
        $response->assertSee('45');
        $response->assertSee('MAC0110');
    }

    /** @test */
    public function guest_cannot_view_room_show()
    {
        $room = Room::factory()->create();

        $response = $this->get("/rooms/{$room->id}");
        $response->assertForbidden();
    }

    /** @test */
    public function show_displays_no_classes_message_when_room_is_empty()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();

        $response = $this->actingAsOperator()->get("/rooms/{$room->id}");

        $response->assertOk();
        $response->assertSee('Não há turmas nessa sala');
    }

    /** @test */
    public function show_displays_allocated_class_in_timetable()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create(['nome' => 'A132', 'assentos' => 45]);

        $class = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => $room->id,
            'tiptur' => 'Graduação',
            'coddis' => 'MAC0110',
            'codtur' => '202611',
        ]);
        $schedule = ClassSchedule::factory()->seg()->morning()->create();
        $class->classschedules()->attach($schedule);

        $response = $this->actingAsOperator()->get("/rooms/{$room->id}");

        $response->assertOk();
        // The timetable should show the course code
        $response->assertSee('MAC0110');
    }

    /** @test */
    public function show_displays_unallocated_classes_section()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create(['nome' => 'B01', 'assentos' => 70]);

        $allocatedClass = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => $room->id,
        ]);
        $scheduleA = ClassSchedule::factory()->seg()->morning()->create();
        $allocatedClass->classschedules()->attach($scheduleA);

        $unallocatedClass = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => null,
            'externa' => false,
            'fusion_id' => null,
            'coddis' => 'MAT0111',
            'codtur' => '202621',
            'nomdis' => 'Cálculo I',
        ]);
        $scheduleB = ClassSchedule::factory()->ter()->morning()->create();
        $unallocatedClass->classschedules()->attach($scheduleB);

        $response = $this->actingAsOperator()->get("/rooms/{$room->id}");

        $response->assertOk();
        $response->assertSee('Turmas não alocadas');
        $response->assertSee('MAT0111');
    }

    /** @test */
    public function show_displays_unallocated_fusions_section()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create(['nome' => 'B01', 'assentos' => 70]);

        $allocatedClass = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => $room->id,
        ]);
        $scheduleA = ClassSchedule::factory()->seg()->morning()->create();
        $allocatedClass->classschedules()->attach($scheduleA);

        $master = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => null,
            'externa' => false,
        ]);
        $child = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => null,
            'externa' => false,
        ]);

        $fusion = Fusion::create(['master_id' => $master->id]);
        $master->fusion_id = $fusion->id;
        $master->save();
        $child->fusion_id = $fusion->id;
        $child->save();

        $scheduleB = ClassSchedule::factory()->qua()->morning()->create();
        $master->classschedules()->attach($scheduleB);
        $child->classschedules()->attach($scheduleB);

        $response = $this->actingAsOperator()->get("/rooms/{$room->id}");

        $response->assertOk();
        $response->assertSee('Dobradinhas não alocadas');
    }

    /** @test */
    public function show_shows_compatible_status_colors_for_unallocated()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create(['nome' => 'A132', 'assentos' => 45]);

        $existingClass = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => $room->id,
        ]);
        // Different day from unallocated → compatible
        $scheduleA = ClassSchedule::factory()->seg()->morning()->create();
        $existingClass->classschedules()->attach($scheduleA);

        $unallocatedClass = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => null,
            'externa' => false,
            'fusion_id' => null,
        ]);
        // Different day → no conflict → compatible (green)
        $scheduleB = ClassSchedule::factory()->ter()->morning()->create();
        $unallocatedClass->classschedules()->attach($scheduleB);

        $response = $this->actingAsOperator()->get("/rooms/{$room->id}");

        $response->assertOk();
        // Compatible classes show green color indicator
        $response->assertSee('color:green');
    }
}
