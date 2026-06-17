<?php

namespace Database\Factories;

use App\Models\SchoolTerm;
use App\Models\SolverLog;
use Illuminate\Database\Eloquent\Factories\Factory;

class SolverLogFactory extends Factory
{
    /**
     * The name of the model's corresponding factory.
     *
     * @var string
     */
    protected $model = SolverLog::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'school_term_id' => SchoolTerm::factory(),
            'job_id' => $this->faker->uuid(),
            'payload' => [
                'meta' => [
                    'version' => '1.0.0',
                    'school_term_id' => 1,
                ],
                'config' => [],
                'timeslots' => [],
                'rooms' => [],
                'groups' => [],
            ],
            'response' => null,
            'status' => 'solving',
            'allocations_count' => 0,
            'unassigned_count' => 0,
            'manual_count' => 0,
            'dispatched_at' => now(),
            'responded_at' => null,
        ];
    }
}
