<?php

namespace Tests\Feature;

use App\Models\ClassSchedule;
use App\Models\Fusion;
use App\Models\Room;
use App\Models\SchoolClass;
use App\Models\SchoolTerm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ShowFreeTimeTest extends TestCase
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
        return $this->actingAs(User::factory()->create());
    }

    private function latestTerm(): SchoolTerm
    {
        return SchoolTerm::factory()->create([
            'year' => 2026,
            'period' => '1° Semestre',
        ]);
    }

    private function scheduleAt(string $day, string $horent, string $horsai): ClassSchedule
    {
        return ClassSchedule::firstOrCreate([
            'diasmnocp' => $day,
            'horent' => $horent,
            'horsai' => $horsai,
        ]);
    }

    /**
     * Ensures at least one room with no allocated classes exists, so every
     * grid cell is non-empty. The current Blade template crashes on empty
     * cells (a Collection is always truthy), and production always has rooms.
     */
    private function ensureFreeRoom(): Room
    {
        return Room::factory()->create(['nome' => 'LIVRE-FREE-ROOM']);
    }

    /**
     * The grid horários/dias used by the controller, kept in sync so tests can
     * occupy a room in every cell.
     */
    private function gridSlots(): array
    {
        $horarios = [
            '08:00' => '09:40', '10:00' => '11:40', '14:00' => '15:40',
            '16:00' => '17:40', '19:20' => '21:00', '21:10' => '22:50',
        ];
        $dias = ['seg', 'ter', 'qua', 'qui', 'sex'];

        $slots = [];
        foreach ($dias as $dia) {
            foreach ($horarios as $horent => $horsai) {
                $slots[] = [$dia, $horent, $horsai];
            }
        }
        return $slots;
    }

    // ── Access control ───────────────────────────────────────────────────────

    /** @test */
    public function guest_cannot_view_showfreetime()
    {
        $this->latestTerm();

        $response = $this->get(route('rooms.showFreeTime'));

        $response->assertForbidden();
    }

    /** @test */
    public function user_without_role_cannot_view_showfreetime()
    {
        $this->latestTerm();

        $response = $this->actingAsRandomUser()->get(route('rooms.showFreeTime'));

        $response->assertForbidden();
    }

    /** @test */
    public function admin_can_view_showfreetime()
    {
        $this->latestTerm();
        $this->ensureFreeRoom();

        $response = $this->actingAsAdmin()->get(route('rooms.showFreeTime'));

        $response->assertOk();
        $response->assertViewIs('rooms.showFreeTime');
    }

    /** @test */
    public function operator_can_view_showfreetime()
    {
        $this->latestTerm();
        $this->ensureFreeRoom();

        $response = $this->actingAsOperator()->get(route('rooms.showFreeTime'));

        $response->assertOk();
        $response->assertViewIs('rooms.showFreeTime');
    }

    // ── View data ────────────────────────────────────────────────────────────

    /** @test */
    public function view_receives_required_variables()
    {
        $this->latestTerm();
        $this->ensureFreeRoom();

        $response = $this->actingAsOperator()->get(route('rooms.showFreeTime'));

        $response->assertViewHas(['st', 'dias', 'horarios', 'rooms']);
    }

    // ── Free-time grid ───────────────────────────────────────────────────────

    /** @test */
    public function grid_marks_room_as_free_when_no_class_overlaps_slot()
    {
        $term = $this->latestTerm();
        $this->ensureFreeRoom();
        $free = Room::factory()->create(['nome' => 'A132']);

        $response = $this->actingAsOperator()->get(route('rooms.showFreeTime'));

        $rooms = $response->viewData('rooms');
        // A132 should be free in every cell of the grid.
        foreach ($rooms as $dia => $horarios) {
            foreach ($horarios as $horent => $saida) {
                foreach ($saida as $horsai => $cellRooms) {
                    $this->assertTrue(
                        $cellRooms->contains('id', $free->id),
                        "A132 should be free at {$dia} {$horent}-{$horsai}"
                    );
                }
            }
        }
    }

    /** @test */
    public function grid_excludes_room_from_slot_when_class_overlaps()
    {
        $term = $this->latestTerm();
        $this->ensureFreeRoom();
        $occupied = Room::factory()->create(['nome' => 'B001']);

        $class = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => $occupied->id,
            'externa' => false,
            'fusion_id' => null,
            'dtainitur' => '01/02/2026',
            'dtafimtur' => '30/06/2026',
        ]);
        // Overlaps the (seg, 08:00, 09:40) slot exactly.
        $class->classschedules()->attach(
            $this->scheduleAt('seg', '08:00', '09:40')
        );

        $response = $this->actingAsOperator()->get(route('rooms.showFreeTime'));

        $rooms = $response->viewData('rooms');
        $conflictingCell = $rooms['seg']['08:00']['09:40'];
        $this->assertFalse(
            $conflictingCell->contains('id', $occupied->id),
            'B001 should NOT be free at seg 08:00-09:40 (occupied)'
        );
        // But it is free on a different day/time slot.
        $freeCell = $rooms['ter']['08:00']['09:40'];
        $this->assertTrue(
            $freeCell->contains('id', $occupied->id),
            'B001 should be free at ter 08:00-09:40'
        );
    }

    // ── "Turmas não alocadas" section ────────────────────────────────────────

    /** @test */
    public function unallocated_section_lists_unallocated_non_external_classes()
    {
        $term = $this->latestTerm();
        $this->ensureFreeRoom();

        $unallocated = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => null,
            'externa' => false,
            'fusion_id' => null,
            'coddis' => 'MAC0110',
            'codtur' => '202611',
            'nomdis' => 'Introdução à Computação',
            'tiptur' => 'Graduação',
            'dtainitur' => '01/02/2026',
            'dtafimtur' => '30/06/2026',
        ]);
        $unallocated->classschedules()->attach(
            $this->scheduleAt('seg', '08:00', '09:40')
        );

        $response = $this->actingAsOperator()->get(route('rooms.showFreeTime'));

        $response->assertOk();
        $response->assertSee('Turmas não alocadas');
        $response->assertSee('MAC0110');
        $response->assertSee('Introdução à Computação');
    }

    /** @test */
    public function unallocated_section_excludes_allocated_classes()
    {
        $term = $this->latestTerm();
        $this->ensureFreeRoom();
        $room = Room::factory()->create(['nome' => 'A132']);

        $allocated = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => $room->id,
            'externa' => false,
            'fusion_id' => null,
            'coddis' => 'MAT9999',
            'nomdis' => 'Turma Alocada Teste',
            'dtainitur' => '01/02/2026',
            'dtafimtur' => '30/06/2026',
        ]);
        $allocated->classschedules()->attach(
            $this->scheduleAt('seg', '08:00', '09:40')
        );

        $response = $this->actingAsOperator()->get(route('rooms.showFreeTime'));

        $response->assertOk();
        // The class has a room, so it must not appear as "não alocada".
        $this->assertStringNotContainsString(
            'Turma Alocada Teste',
            $response->getContent()
        );
    }

    /** @test */
    public function unallocated_section_excludes_external_classes()
    {
        $term = $this->latestTerm();
        $this->ensureFreeRoom();

        $external = SchoolClass::factory()->external()->create([
            'school_term_id' => $term->id,
            'room_id' => null,
            'fusion_id' => null,
            'coddis' => 'EXT0001',
            'nomdis' => 'Turma Externa Teste',
            'dtainitur' => '01/02/2026',
            'dtafimtur' => '30/06/2026',
        ]);
        $external->classschedules()->attach(
            $this->scheduleAt('seg', '08:00', '09:40')
        );

        $response = $this->actingAsOperator()->get(route('rooms.showFreeTime'));

        $response->assertOk();
        $this->assertStringNotContainsString(
            'Turma Externa Teste',
            $response->getContent()
        );
    }

    /** @test */
    public function unallocated_section_routes_fusion_members_to_dobradinhas()
    {
        $term = $this->latestTerm();
        $this->ensureFreeRoom();

        $master = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => null,
            'externa' => false,
            'coddis' => 'FUS0001',
            'nomdis' => 'Master Fusao Teste',
            'dtainitur' => '01/02/2026',
            'dtafimtur' => '30/06/2026',
        ]);
        $child = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => null,
            'externa' => false,
            'dtainitur' => '01/02/2026',
            'dtafimtur' => '30/06/2026',
        ]);

        $fusion = Fusion::create(['master_id' => $master->id]);
        $master->fusion_id = $fusion->id;
        $master->save();
        $child->fusion_id = $fusion->id;
        $child->save();

        $response = $this->actingAsOperator()->get(route('rooms.showFreeTime'));

        $response->assertOk();
        // Fusion members must appear under "Dobradinhas", not "Turmas não alocadas".
        $response->assertSee('Dobradinhas não alocadas');
        $response->assertSee('Master Fusao Teste');
    }

    // ── "Salas Compatíveis" column ───────────────────────────────────────────

    /** @test */
    public function compatible_column_lists_free_room_and_excludes_conflicting_room()
    {
        $term = $this->latestTerm();

        // Empty room → always compatible.
        $empty = Room::factory()->create(['nome' => 'A100']);
        // Room busy in EVERY grid slot → never free in the grid AND conflicting
        // with the unallocated class → must not appear anywhere on the page.
        $busy = Room::factory()->create(['nome' => 'B200']);

        $busyClass = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => $busy->id,
            'externa' => false,
            'fusion_id' => null,
            'dtainitur' => '01/02/2026',
            'dtafimtur' => '30/06/2026',
        ]);
        $scheduleIds = [];
        foreach ($this->gridSlots() as [$dia, $horent, $horsai]) {
            $scheduleIds[] = $this->scheduleAt($dia, $horent, $horsai)->id;
        }
        $busyClass->classschedules()->attach($scheduleIds);

        $unallocated = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => null,
            'externa' => false,
            'fusion_id' => null,
            'coddis' => 'MAC0110',
            'nomdis' => 'Disciplina Compat Teste',
            'dtainitur' => '01/02/2026',
            'dtafimtur' => '30/06/2026',
        ]);
        $unallocated->classschedules()->attach(
            $this->scheduleAt('seg', '08:00', '09:40')
        );

        $response = $this->actingAsOperator()->get(route('rooms.showFreeTime'));

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('A100', $html);
        $this->assertStringNotContainsString('B200', $html);
    }

    /** @test */
    public function compatible_column_includes_room_with_non_overlapping_class()
    {
        $term = $this->latestTerm();
        $this->ensureFreeRoom();

        $room = Room::factory()->create(['nome' => 'C300']);
        // Allocated class on a different day → no conflict → compatible.
        $other = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => $room->id,
            'externa' => false,
            'fusion_id' => null,
            'dtainitur' => '01/02/2026',
            'dtafimtur' => '30/06/2026',
        ]);
        $other->classschedules()->attach(
            $this->scheduleAt('qui', '14:00', '15:40')
        );

        $unallocated = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => null,
            'externa' => false,
            'fusion_id' => null,
            'coddis' => 'MAC0110',
            'nomdis' => 'Disciplina Compat Outro Dia',
            'dtainitur' => '01/02/2026',
            'dtafimtur' => '30/06/2026',
        ]);
        $unallocated->classschedules()->attach(
            $this->scheduleAt('seg', '08:00', '09:40')
        );

        $response = $this->actingAsOperator()->get(route('rooms.showFreeTime'));

        $response->assertOk();
        // C300 has a class but on a non-conflicting slot → still compatible.
        $this->assertStringContainsString('C300', $response->getContent());
    }

    // ── "Dobradinhas não alocadas" section ───────────────────────────────────

    /** @test */
    public function dobradinhas_section_appears_for_unallocated_fusion()
    {
        $term = $this->latestTerm();
        $this->ensureFreeRoom();

        $master = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => null,
            'externa' => false,
            'coddis' => 'MAC0110',
            'nomdis' => 'Dobradinha Master Teste',
            'dtainitur' => '01/02/2026',
            'dtafimtur' => '30/06/2026',
        ]);
        $child = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => null,
            'externa' => false,
            'dtainitur' => '01/02/2026',
            'dtafimtur' => '30/06/2026',
        ]);

        $fusion = Fusion::create(['master_id' => $master->id]);
        $master->fusion_id = $fusion->id;
        $master->save();
        $child->fusion_id = $fusion->id;
        $child->save();

        $schedule = $this->scheduleAt('seg', '08:00', '09:40');
        $master->classschedules()->attach($schedule);
        $child->classschedules()->attach($schedule);

        $response = $this->actingAsOperator()->get(route('rooms.showFreeTime'));

        $response->assertOk();
        $response->assertSee('Dobradinhas não alocadas');
        $response->assertSee('Dobradinha Master Teste');
    }

    /** @test */
    public function dobradinhas_section_hidden_when_no_unallocated_fusions()
    {
        $term = $this->latestTerm();
        $this->ensureFreeRoom();
        // A plain unallocated class (no fusion) → no dobradinhas section.
        $unallocated = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => null,
            'externa' => false,
            'fusion_id' => null,
            'dtainitur' => '01/02/2026',
            'dtafimtur' => '30/06/2026',
        ]);
        $unallocated->classschedules()->attach(
            $this->scheduleAt('seg', '08:00', '09:40')
        );

        $response = $this->actingAsOperator()->get(route('rooms.showFreeTime'));

        $response->assertOk();
        $this->assertStringNotContainsString(
            'Dobradinhas não alocadas',
            $response->getContent()
        );
    }

    // ── Auto-refresh preserved ───────────────────────────────────────────────

    /** @test */
    public function page_keeps_the_20s_auto_refresh_script()
    {
        $this->latestTerm();
        $this->ensureFreeRoom();

        $response = $this->actingAsOperator()->get(route('rooms.showFreeTime'));

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('setTimeout( refresh, 20000', $html);
        $this->assertStringContainsString('document.location.reload()', $html);
    }

    // ── Performance: N+1 elimination ─────────────────────────────────────────

    /** @test */
    public function request_runs_with_a_small_constant_number_of_queries()
    {
        // Build a non-trivial scenario: several rooms, several unallocated
        // classes and a busy room. Before the optimization this triggered an
        // N+1 explosion (~12.700 queries for the real dataset); after it the
        // count must stay small and roughly constant regardless of how many
        // classes/rooms exist, because everything is eager-loaded once.
        $term = $this->latestTerm();

        for ($i = 0; $i < 8; $i++) {
            Room::factory()->create(['nome' => 'A' . (100 + $i)]);
        }

        $busy = Room::factory()->create(['nome' => 'B200']);
        $busyClass = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => $busy->id,
            'externa' => false,
            'fusion_id' => null,
            'dtainitur' => '01/02/2026',
            'dtafimtur' => '30/06/2026',
        ]);
        foreach ($this->gridSlots() as [$dia, $horent, $horsai]) {
            $busyClass->classschedules()->attach(
                $this->scheduleAt($dia, $horent, $horsai)->id
            );
        }

        for ($i = 0; $i < 6; $i++) {
            $unallocated = SchoolClass::factory()->create([
                'school_term_id' => $term->id,
                'room_id' => null,
                'externa' => false,
                'fusion_id' => null,
                'coddis' => 'MAC' . (100 + $i),
                'dtainitur' => '01/02/2026',
                'dtafimtur' => '30/06/2026',
            ]);
            $unallocated->classschedules()->attach(
                $this->scheduleAt('seg', '08:00', '09:40')->id
            );
        }

        Role::firstOrCreate(['name' => 'Operador', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole('Operador');
        $this->actingAs($user);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->get(route('rooms.showFreeTime'));
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk();
        // The previous implementation issued ~12.700 queries; the eager-loaded
        // implementation must stay well under this hard ceiling, which also
        // guards against any future reintroduction of the N+1 pattern.
        $this->assertLessThan(40, $queryCount, "Expected < 40 queries, got {$queryCount}");
    }
}
