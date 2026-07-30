<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\SchoolClass;
use App\Models\SchoolTerm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ObservadorRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Garante que as permissoes/roles existem (espelha o seeder).
        Permission::firstOrCreate(['name' => 'visualizar salas']);
        Permission::firstOrCreate(['name' => 'visualizar periodo letivo']);
        Permission::firstOrCreate(['name' => 'visualizar turmas']);
        Permission::firstOrCreate(['name' => 'visualizar grade curricular']);
        Permission::firstOrCreate(['name' => 'visualizar oferecimentos especiais']);
        Permission::firstOrCreate(['name' => 'visualizar observações']);
        Permission::firstOrCreate(['name' => 'visualizar dobradinhas']);
        Permission::firstOrCreate(['name' => 'visualizar turmas externas']);
        Permission::firstOrCreate(['name' => 'visualizar menu config']);
        // Necessario para o gate-before do senhaunica-socialite (Gate::before chama
        // hasPermissionTo('admin'); se a permissao nao existir, uma excecao e lancada).
        Permission::firstOrCreate(['name' => 'admin']);
        Permission::firstOrCreate(['name' => 'editar usuario']);
        $observador = Role::firstOrCreate(['name' => 'Observador', 'guard_name' => 'web']);
        $observador->givePermissionTo([
            'visualizar salas', 'visualizar periodo letivo', 'visualizar turmas',
            'visualizar grade curricular', 'visualizar oferecimentos especiais',
            'visualizar observações', 'visualizar dobradinhas', 'visualizar turmas externas',
            'visualizar menu config',
        ]);
        Role::firstOrCreate(['name' => 'Administrador', 'guard_name' => 'web']);
    }

    private function actingAsObservador(): self
    {
        $user = User::factory()->create();
        $user->assignRole('Observador');
        return $this->actingAs($user);
    }

    /** @test */
    public function observador_can_view_read_only_pages()
    {
        SchoolTerm::factory()->create();

        $this->actingAsObservador()->get('/rooms')->assertOk();
        $this->actingAsObservador()->get('/schoolclasses')->assertOk();
        $this->actingAsObservador()->get('/schoolterms')->assertOk();
        $this->actingAsObservador()->get('/curriculum')->assertOk();
        $this->actingAsObservador()->get('/fusions')->assertOk();
        $this->actingAsObservador()->get('/specialoffers')->assertOk();
        $this->actingAsObservador()->get('/observations')->assertOk();
        $this->actingAsObservador()->get('/allocation-states')->assertOk();
        $this->actingAsObservador()->get('/comparison-reports')->assertOk();
        $this->actingAsObservador()->get('/solverlogs')->assertOk();
    }

    /** @test */
    public function observador_is_blocked_from_non_get_mutations_by_middleware()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();

        // Middleware bloqueia verbo nao-seguro antes da validacao do FormRequest.
        $this->actingAsObservador()->post('/allocation-states', [])->assertForbidden();
        $this->actingAsObservador()->patch("/rooms/{$room->id}/allocate", [])->assertForbidden();
        $this->actingAsObservador()->post('/schoolclasses/destroyInBatch', [])->assertForbidden();
        $this->actingAsObservador()->delete('/allocation-states/1')->assertForbidden();
    }

    /** @test */
    public function observador_is_blocked_from_get_routes_that_mutate()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();
        $class = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => $room->id,
        ]);

        // Esses GETs tm efeito colateral (job/DB) e ficam restritos a Admin/Operador.
        $this->actingAsObservador()->get('/rooms/makeReport')->assertForbidden();
        $this->actingAsObservador()->get('/schoolclasses/import')->assertForbidden();
        $this->actingAsObservador()->get("/rooms/dissociate/{$class->id}")->assertForbidden();
    }

    /** @test */
    public function observador_still_can_logout()
    {
        // A rota de logout (POST) precisa ser liberada mesmo para o Observador.
        $this->actingAsObservador()
            ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
            ->post('/logout')
            ->assertRedirect();
    }

    /** @test */
    public function observador_cannot_edit_users()
    {
        $this->actingAsObservador()->get('/users')->assertForbidden();
    }
}