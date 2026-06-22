<?php

namespace Database\Factories;

use App\Models\AllocationState;
use App\Models\ComparisonReport;
use App\Models\SchoolTerm;
use Illuminate\Database\Eloquent\Factories\Factory;

class ComparisonReportFactory extends Factory
{
    /**
     * The name of the model's corresponding factory.
     *
     * @var string
     */
    protected $model = ComparisonReport::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'school_term_id' => SchoolTerm::factory(),
            'base_allocation_state_id' => AllocationState::factory(),
            'status' => 'processing',
            'legacy_metrics' => null,
            'solver_metrics' => null,
            'legacy_raw_allocations' => null,
            'solver_raw_allocations' => null,
        ];
    }
}
