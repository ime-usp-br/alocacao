<?php

namespace Database\Factories;

use App\Models\SchoolTerm;
use Illuminate\Database\Eloquent\Factories\Factory;

class SchoolTermFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = SchoolTerm::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'year' => $this->faker->numberBetween(2020, 2030),
            'period' => $this->faker->randomElement(['1° Semestre', '2° Semestre']),
            'dtamaxres' => $this->faker->dateTimeBetween('+1 month', '+6 months')->format('d/m/Y'),
        ];
    }

    /**
     * Configure the model factory for first semester.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function firstSemester()
    {
        return $this->state(function (array $attributes) {
            return [
                'period' => '1° Semestre',
                'dtamaxres' => '30/06/' . $attributes['year'],
            ];
        });
    }

    /**
     * Configure the model factory for second semester.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function secondSemester()
    {
        return $this->state(function (array $attributes) {
            return [
                'period' => '2° Semestre',
                'dtamaxres' => '30/11/' . $attributes['year'],
            ];
        });
    }

    /**
     * Configure the model factory for current year.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function currentYear()
    {
        return $this->state(function (array $attributes) {
            return [
                'year' => now()->year,
            ];
        });
    }
}