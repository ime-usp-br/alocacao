<?php

namespace Database\Factories;

use App\Models\Fusion;
use App\Models\SchoolClass;
use Illuminate\Database\Eloquent\Factories\Factory;

class FusionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Fusion::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'master_id' => null,
        ];
    }

    /**
     * Configure the model factory with a specific master class.
     *
     * @param SchoolClass $schoolClass
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function withMaster(SchoolClass $schoolClass)
    {
        return $this->state(function (array $attributes) use ($schoolClass) {
            return [
                'master_id' => $schoolClass->id,
            ];
        });
    }
}
