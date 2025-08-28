<?php

namespace Database\Factories;

use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

class RoomFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Room::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'nome' => $this->generateRoomName(),
            'assentos' => $this->faker->numberBetween(20, 100),
        ];
    }

    /**
     * Generate a realistic room name.
     *
     * @return string
     */
    private function generateRoomName()
    {
        $patterns = [
            'A' . $this->faker->numberBetween(101, 299),
            'B' . $this->faker->numberBetween(101, 299),
            'Auditório ' . $this->faker->lastName(),
            'Sala ' . $this->faker->numberBetween(1, 50),
        ];

        return $this->faker->randomElement($patterns);
    }

    /**
     * Configure the model factory for block A rooms.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function blockA()
    {
        return $this->state(function (array $attributes) {
            return [
                'nome' => 'A' . $this->faker->numberBetween(101, 299),
            ];
        });
    }

    /**
     * Configure the model factory for block B rooms.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function blockB()
    {
        return $this->state(function (array $attributes) {
            return [
                'nome' => 'B' . $this->faker->numberBetween(101, 299),
            ];
        });
    }

    /**
     * Configure the model factory for auditoriums.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function auditorium()
    {
        return $this->state(function (array $attributes) {
            $names = [
                'Auditório Jacy Monteiro',
                'Auditório Antonio Gilioli',
                'Auditório Imre Simon',
                'Auditório do CCSL',
                'Auditório do InovaUSP'
            ];

            return [
                'nome' => $this->faker->randomElement($names),
                'assentos' => $this->faker->numberBetween(100, 300),
            ];
        });
    }

    /**
     * Configure the model factory for small rooms.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function small()
    {
        return $this->state(function (array $attributes) {
            return [
                'assentos' => $this->faker->numberBetween(10, 30),
            ];
        });
    }

    /**
     * Configure the model factory for large rooms.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function large()
    {
        return $this->state(function (array $attributes) {
            return [
                'assentos' => $this->faker->numberBetween(50, 150),
            ];
        });
    }
}