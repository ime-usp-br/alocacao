<?php

namespace Database\Factories;

use App\Models\AllocationState;
use App\Models\SchoolTerm;
use Illuminate\Database\Eloquent\Factories\Factory;

class AllocationStateFactory extends Factory
{
    /**
     * The name of the model's corresponding factory.
     *
     * @var string
     */
    protected $model = AllocationState::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'school_term_id' => SchoolTerm::factory(),
            'name' => $this->faker->words(3, true),
            'allocations' => [],
            'solver_log_id' => null,
        ];
    }
}
