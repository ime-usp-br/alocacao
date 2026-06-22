<?php

namespace Tests\Unit;

use App\Jobs\ProcessLegacyRoomDistribution;
use App\Models\ClassSchedule;
use App\Models\Fusion;
use App\Models\Room;
use App\Models\SchoolClass;
use App\Models\SchoolTerm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ProcessLegacyRoomDistributionTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_has_correct_unique_id()
    {
        $job = new ProcessLegacyRoomDistribution(42, [1, 2]);
        $this->assertEquals('legacy-room-distribution-42', $job->uniqueId());
    }

    /** @test */
    public function it_allocates_unassigned_classes_and_marks_cache_completed()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->blockA()->create(['assentos' => 100]);

        $class = SchoolClass::factory()->undergraduate()->withoutRoom()->withSchoolTerm($term)->create([
            'estmtr' => 30,
        ]);
        $schedule = ClassSchedule::factory()->seg()->morning()->create();
        $class->classschedules()->attach($schedule);

        $job = new ProcessLegacyRoomDistribution($term->id, [$room->id]);
        $job->handle();

        $this->assertNotNull($class->fresh()->room_id);
        $this->assertEquals($room->id, $class->fresh()->room_id);

        $cached = Cache::get("allocation:{$term->id}");
        $this->assertNotNull($cached);
        $this->assertEquals('completed', $cached['status']);
        $this->assertEquals(100, $cached['progress']);
        $this->assertEquals('legacy', $cached['mode']);
        $this->assertGreaterThan(0, $cached['assignments_count']);
    }

    /** @test */
    public function it_ignores_external_classes()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->blockA()->create(['assentos' => 100]);

        $external = SchoolClass::factory()->external()->withoutRoom()->withSchoolTerm($term)->create();
        $schedule = ClassSchedule::factory()->seg()->morning()->create();
        $external->classschedules()->attach($schedule);

        $job = new ProcessLegacyRoomDistribution($term->id, [$room->id]);
        $job->handle();

        $this->assertNull($external->fresh()->room_id);
    }

    /** @test */
    public function it_skips_mae0116_classes()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->blockA()->create(['assentos' => 100]);

        $mae = SchoolClass::factory()->undergraduate()->withoutRoom()->withSchoolTerm($term)->create([
            'coddis' => 'MAE0116',
            'estmtr' => 30,
        ]);
        $schedule = ClassSchedule::factory()->seg()->morning()->create();
        $mae->classschedules()->attach($schedule);

        $job = new ProcessLegacyRoomDistribution($term->id, [$room->id]);
        $job->handle();

        $this->assertNull($mae->fresh()->room_id);
    }

    /** @test */
    public function it_marks_error_in_cache_on_failure()
    {
        $term = SchoolTerm::factory()->create();

        $cacheKey = "allocation:{$term->id}";
        Cache::put($cacheKey, [
            'job_id' => 'legacy',
            'status' => 'solving',
            'progress' => 0,
            'mode' => 'legacy',
        ], now()->addHours(4));

        $job = new ProcessLegacyRoomDistribution($term->id, [999999]);

        try {
            $job->failed(new \RuntimeException('boom'));
        } catch (\Throwable $e) {
        }

        $cached = Cache::get($cacheKey);
        $this->assertEquals('error', $cached['status']);
        $this->assertStringContainsString('boom', $cached['message']);
    }

    /** @test */
    public function it_does_not_inflate_unassigned_with_fusion_slaves_or_mae0116()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->blockA()->create(['assentos' => 100]);

        $schedule = ClassSchedule::factory()->seg()->morning()->create();

        // Fusao: mestre sera alocado, escrava fica sem room_id proprio mas
        // coberta pelo mestre. Nao deve contar como nao-alocada.
        $master = SchoolClass::factory()->undergraduate()->withoutRoom()->withSchoolTerm($term)->create(['estmtr' => 30]);
        $master->classschedules()->attach($schedule);
        $slave = SchoolClass::factory()->undergraduate()->withoutRoom()->withSchoolTerm($term)->create(['estmtr' => 30]);
        $fusion = Fusion::factory()->withMaster($master)->create();
        $master->fusion()->associate($fusion)->save();
        $slave->fusion()->associate($fusion)->save();

        // MAE0116: hardcoded-skip por design, nao conta como nao-alocada.
        $mae = SchoolClass::factory()->undergraduate()->withoutRoom()->withSchoolTerm($term)->create([
            'coddis' => 'MAE0116',
            'estmtr' => 30,
        ]);
        $mae->classschedules()->attach($schedule);

        // Turma genuinamente sem sala (demanda > capacidade de qualquer sala).
        $orphan = SchoolClass::factory()->undergraduate()->withoutRoom()->withSchoolTerm($term)->create(['estmtr' => 200]);

        $job = new ProcessLegacyRoomDistribution($term->id, [$room->id]);
        $job->handle();

        $cached = Cache::get("allocation:{$term->id}");

        // Unidades: 1 fusao (alocada) + 1 standalone orfa (nao alocada).
        // MAE0116 e escrava da fusao nao contam como unidades separadas.
        $this->assertEquals(1, $cached['unassigned_count']);
        // assignments_count = unidades alocadas pelo job = 1 (a fusao).
        $this->assertEquals(1, $cached['assignments_count']);
        $this->assertEquals(0, $cached['manual_count']);
    }
}
