<?php

namespace Database\Factories;

use App\Models\CourseInformation;
use Illuminate\Database\Eloquent\Factories\Factory;

class CourseInformationFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = CourseInformation::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'nomcur' => $this->faker->sentence(3),
            'codcur' => $this->faker->numerify('#####'),
            'numsemidl' => $this->faker->randomElement(['1', '2', '3', '4', '5', '6']),
            'perhab' => $this->faker->word,
            'codhab' => $this->faker->numerify('#'),
            'nomhab' => $this->faker->sentence(2),
            'tipobg' => $this->faker->randomElement(['O', 'C', 'L']),
        ];
    }

    /**
     * Configure the model factory for mandatory disciplines.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function mandatory()
    {
        return $this->state(function (array $attributes) {
            return [
                'tipobg' => 'O',
            ];
        });
    }

    /**
     * Configure the model factory for a specific ideal semester.
     *
     * @param string $semester
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function semester(string $semester)
    {
        return $this->state(function (array $attributes) use ($semester) {
            return [
                'numsemidl' => $semester,
            ];
        });
    }
}
