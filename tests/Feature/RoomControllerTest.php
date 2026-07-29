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

    /** @test */
    public function show_multi_slot_class_emits_rowspan_without_extra_cells_on_continuation_rows()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create(['nome' => 'B01', 'assentos' => 70]);

        // A class spanning three consecutive hour slots on sex (13:00-17:40),
        // plus two single-slot classes on seg and qui at 14:00-17:40 so those
        // columns have content on the same middle row(s).
        $multi = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => $room->id,
            'coddis' => 'CiniME',
            'codtur' => '01',
        ]);
        // sex 13:00-19:00 → spans 13:00-14:00, 14:00-17:40, 17:40-19:00 rows (rowspan=3)
        $multi->classschedules()->attach(
            ClassSchedule::factory()->sex()->state(['horent' => '13:00', 'horsai' => '19:00'])->create()
        );

        $seg = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => $room->id,
            'coddis' => 'MAE0116',
            'codtur' => '35',
        ]);
        $seg->classschedules()->attach(
            ClassSchedule::factory()->seg()->state(['horent' => '14:00', 'horsai' => '17:40'])->create()
        );

        $qui = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => $room->id,
            'coddis' => 'MAE0116',
            'codtur' => '03',
        ]);
        $qui->classschedules()->attach(
            ClassSchedule::factory()->qui()->state(['horent' => '14:00', 'horsai' => '17:40'])->create()
        );

        $response = $this->actingAsOperator()->get("/rooms/{$room->id}");

        $response->assertOk();
        $html = $response->getContent();

        // The multi-slot cell must carry the rowspan that covers all its rows.
        $this->assertMatchesRegularExpression('/rowspan=3/', $html);

        // Count <td> elements (columns + the time-slot column) per row: the
        // row where 13:00 opens the multi-slot class should have exactly 6 td
        // (Horário + 5 weekday columns, opening the rowspan on sex).
        $rows = $this->extractTrCells($html);
        $this->assertSame(6, count($rows[0]), 'Header row should have 6 columns.');
        // Opening row of the multi-slot class: horário + seg + ter + qua + qui + sex(opening rowspan)
        $this->assertSame(6, count($rows[1]), 'Opening row of multi-slot class must have 6 cells.');
        // Continuation rows of the multi-slot class (sex is covered by rowspan)
        // must NOT carry an extra empty <td> for sex.
        $this->assertSame(5, count($rows[2]), 'Continuation row 1 must have 5 cells (sex spanned out).');
        $this->assertSame(5, count($rows[3]), 'Continuation row 2 must have 5 cells (sex spanned out).');
    }

    /**
     * Extract, in order, the <td>/<th> count per <tr> in the first timetable table.
     *
     * @return array<int, list<string>>
     */
    private function extractTrCells(string $html): array
    {
        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/s', $html, $trMatches);
        $rows = [];
        foreach ($trMatches[1] as $tr) {
            preg_match_all('/<t[dh][^>]*>/', $tr, $cells);
            $rows[] = $cells[0];
        }
        return $rows;
    }
}
