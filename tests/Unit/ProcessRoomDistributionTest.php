<?php

namespace Tests\Unit;

use App\Jobs\ProcessRoomDistribution;
use App\Models\ClassSchedule;
use App\Models\Room;
use App\Models\SchoolClass;
use App\Models\SchoolTerm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProcessRoomDistributionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['alocacao.solver.url' => 'http://solver.test']);
        config(['alocacao.solver.api_token' => 'test-token']);
    }

    /** @test */
    public function it_dispatches_payload_to_solver_and_caches_job_id()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();
        $class = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => null,
            'externa' => false,
        ]);
        $schedule = ClassSchedule::factory()->seg()->morning()->create();
        $class->classschedules()->attach($schedule);

        Http::fake([
            'http://solver.test/api/v1/solve' => Http::response([
                'job_id' => 'abc-123',
            ], 202),
        ]);

        $job = new ProcessRoomDistribution($term->id, [$room->id]);
        $job->handle();

        Http::assertSent(function ($request) use ($term, $room) {
            $data = $request->data();
            $this->assertArrayHasKey('payload', $data);
            $this->assertArrayHasKey('webhook_url', $data);
            $this->assertArrayHasKey('progress_webhook_url', $data);
            $this->assertEquals([$room->id], array_column($data['payload']['rooms'], 'id'));
            return true;
        });

        $cached = Cache::get("allocation:{$term->id}");
        $this->assertNotNull($cached);
        $this->assertEquals('abc-123', $cached['job_id']);
        $this->assertEquals('solving', $cached['status']);
        $this->assertEquals(0, $cached['progress']);
    }

    /** @test */
    public function it_throws_exception_when_solver_returns_error()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();

        Http::fake([
            'http://solver.test/api/v1/solve' => Http::response('Internal Server Error', 500),
        ]);

        $job = new ProcessRoomDistribution($term->id, [$room->id]);

        $this->expectException(\RuntimeException::class);
        $job->handle();
    }

    /** @test */
    public function it_throws_exception_when_solver_response_lacks_job_id()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();

        Http::fake([
            'http://solver.test/api/v1/solve' => Http::response(['status' => 'ok'], 200),
        ]);

        $job = new ProcessRoomDistribution($term->id, [$room->id]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('job_id');
        $job->handle();
    }

    /** @test */
    public function it_has_correct_unique_id()
    {
        $job = new ProcessRoomDistribution(42, [1, 2]);
        $this->assertEquals('room-distribution-42', $job->uniqueId());
    }

    /** @test */
    public function it_ignores_external_classes_in_payload()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();

        $external = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'externa' => true,
            'room_id' => null,
        ]);

        $normal = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'externa' => false,
            'room_id' => null,
        ]);
        $schedule = ClassSchedule::factory()->seg()->morning()->create();
        $normal->classschedules()->attach($schedule);

        Http::fake([
            'http://solver.test/api/v1/solve' => Http::response(['job_id' => 'xyz'], 202),
        ]);

        $job = new ProcessRoomDistribution($term->id, [$room->id]);
        $job->handle();

        Http::assertSent(function ($request) use ($external, $normal) {
            $groupIds = array_column($request->data()['payload']['groups'], 'id');
            $this->assertNotContains($external->id, $groupIds);
            $this->assertContains($normal->id, $groupIds);
            return true;
        });
    }
}
