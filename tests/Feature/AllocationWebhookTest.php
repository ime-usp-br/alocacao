<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use App\Models\SchoolTerm;
use App\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AllocationWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['alocacao.solver.api_token' => 'webhook-secret']);
    }

    private function withWebhookToken(): self
    {
        return $this->withHeader('X-Webhook-Token', 'webhook-secret');
    }

    /** @test */
    public function progress_webhook_updates_cache()
    {
        $term = SchoolTerm::factory()->create();
        Cache::put("allocation:{$term->id}", [
            'job_id' => 'job-123',
            'status' => 'solving',
            'progress' => 0,
        ], now()->addHour());

        $response = $this->withWebhookToken()->postJson('/api/webhooks/allocation-progress', [
            'job_id' => 'job-123',
            'progress' => 45,
            'message' => 'Otimizando...',
        ]);

        $response->assertOk();
        $response->assertJson(['message' => 'Progress updated']);

        $cached = Cache::get("allocation:{$term->id}");
        $this->assertEquals(45, $cached['progress']);
        $this->assertEquals('Otimizando...', $cached['message']);
    }

    /** @test */
    public function progress_webhook_rejects_invalid_token()
    {
        $response = $this->withHeader('X-Webhook-Token', 'wrong')
            ->postJson('/api/webhooks/allocation-progress', [
                'job_id' => 'job-123',
                'progress' => 50,
            ]);

        $response->assertUnauthorized();
    }

    /** @test */
    public function progress_webhook_returns_404_for_unknown_job()
    {
        $response = $this->withWebhookToken()->postJson('/api/webhooks/allocation-progress', [
            'job_id' => 'unknown-job',
            'progress' => 50,
        ]);

        $response->assertNotFound();
    }

    /** @test */
    public function result_webhook_applies_assignments_atomically()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();

        $classA = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => null,
        ]);
        $classB = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => null,
        ]);

        Cache::put("allocation:{$term->id}", [
            'job_id' => 'job-456',
            'status' => 'solving',
            'progress' => 99,
        ], now()->addHour());

        $response = $this->withWebhookToken()->postJson('/api/webhooks/allocation-result', [
            'job_id' => 'job-456',
            'status' => 'success',
            'assignments' => [
                ['group_id' => $classA->id, 'room_id' => $room->id],
                ['group_id' => $classB->id, 'room_id' => $room->id],
            ],
            'unassigned_groups' => [],
            'suggestions' => [],
        ]);

        $response->assertOk();
        $response->assertJson(['message' => 'Results applied']);

        $this->assertDatabaseHas('school_classes', [
            'id' => $classA->id,
            'room_id' => $room->id,
        ]);
        $this->assertDatabaseHas('school_classes', [
            'id' => $classB->id,
            'room_id' => $room->id,
        ]);

        $cached = Cache::get("allocation:{$term->id}");
        $this->assertEquals('completed', $cached['status']);
        $this->assertEquals(100, $cached['progress']);
    }

    /** @test */
    public function result_webhook_clears_unassigned_groups()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();

        $classA = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => $room->id,
        ]);
        $classB = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => $room->id,
        ]);

        Cache::put("allocation:{$term->id}", [
            'job_id' => 'job-789',
            'status' => 'solving',
        ], now()->addHour());

        $response = $this->withWebhookToken()->postJson('/api/webhooks/allocation-result', [
            'job_id' => 'job-789',
            'status' => 'success',
            'assignments' => [
                ['group_id' => $classA->id, 'room_id' => $room->id],
            ],
            'unassigned_groups' => [$classB->id],
            'suggestions' => [],
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('school_classes', [
            'id' => $classA->id,
            'room_id' => $room->id,
        ]);
        $this->assertDatabaseHas('school_classes', [
            'id' => $classB->id,
            'room_id' => null,
        ]);
    }

    /** @test */
    public function result_webhook_ignores_obsolete_jobs()
    {
        $term = SchoolTerm::factory()->create();

        // Old index still points to this term, but the active cache was overwritten
        Cache::put("allocation:job:old-job", $term->id, now()->addHour());
        Cache::put("allocation:{$term->id}", [
            'job_id' => 'new-job',
            'status' => 'solving',
        ], now()->addHour());

        $response = $this->withWebhookToken()->postJson('/api/webhooks/allocation-result', [
            'job_id' => 'old-job',
            'status' => 'success',
            'assignments' => [],
        ]);

        $response->assertOk();
        $response->assertJson(['message' => 'Ignored. Obsolete job.']);
    }

    /** @test */
    public function result_webhook_stores_suggestions_in_cache()
    {
        $term = SchoolTerm::factory()->create();

        Cache::put("allocation:{$term->id}", [
            'job_id' => 'job-sugg',
            'status' => 'solving',
        ], now()->addHour());

        $suggestions = [
            ['group_id' => 1, 'suggested_splits' => [['room_id' => 2, 'days' => ['seg']]]],
        ];

        $response = $this->withWebhookToken()->postJson('/api/webhooks/allocation-result', [
            'job_id' => 'job-sugg',
            'status' => 'success',
            'assignments' => [],
            'unassigned_groups' => [],
            'suggestions' => $suggestions,
        ]);

        $response->assertOk();

        $cachedSuggestions = Cache::get("allocation_suggestions:{$term->id}");
        $this->assertEquals($suggestions, $cachedSuggestions);
    }

    /** @test */
    public function result_webhook_handles_error_status()
    {
        $term = SchoolTerm::factory()->create();

        Cache::put("allocation:{$term->id}", [
            'job_id' => 'job-err',
            'status' => 'solving',
        ], now()->addHour());

        $response = $this->withWebhookToken()->postJson('/api/webhooks/allocation-result', [
            'job_id' => 'job-err',
            'status' => 'error',
            'assignments' => [],
        ]);

        $response->assertOk();
        $response->assertJson(['message' => 'Error recorded']);

        $cached = Cache::get("allocation:{$term->id}");
        $this->assertEquals('error', $cached['status']);
    }

    /** @test */
    public function result_webhook_is_idempotent()
    {
        $term = SchoolTerm::factory()->create();
        $room = Room::factory()->create();
        $class = SchoolClass::factory()->create([
            'school_term_id' => $term->id,
            'room_id' => null,
        ]);

        Cache::put("allocation:{$term->id}", [
            'job_id' => 'job-idem',
            'status' => 'solving',
        ], now()->addHour());

        $payload = [
            'job_id' => 'job-idem',
            'status' => 'success',
            'assignments' => [
                ['group_id' => $class->id, 'room_id' => $room->id],
            ],
            'unassigned_groups' => [],
        ];

        $this->withWebhookToken()->postJson('/api/webhooks/allocation-result', $payload)->assertOk();
        $this->withWebhookToken()->postJson('/api/webhooks/allocation-result', $payload)->assertOk();

        // Should still be correct (no duplicates or corruption)
        $this->assertDatabaseHas('school_classes', [
            'id' => $class->id,
            'room_id' => $room->id,
        ]);
    }
}
