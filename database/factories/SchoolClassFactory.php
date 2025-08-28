<?php

namespace Database\Factories;

use App\Models\SchoolClass;
use App\Models\SchoolTerm;
use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

class SchoolClassFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = SchoolClass::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        $year = $this->faker->numberBetween(2020, 2030);
        $semester = $this->faker->randomElement(['1', '2']);
        $discipline = strtoupper($this->faker->lexify('???'));
        $classNumber = $this->faker->numberBetween(10, 99);

        return [
            'codtur' => $year . $semester . $classNumber,
            'tiptur' => $this->faker->randomElement(['Graduação', 'Pós Graduação']),
            'nomdis' => $this->faker->sentence(3),
            'coddis' => $discipline . $this->faker->numberBetween(100, 999),
            'estmtr' => $this->faker->numberBetween(20, 80),
            'externa' => false,
            'dtainitur' => $this->faker->dateTimeBetween('-1 month', '+1 month')->format('d/m/Y'),
            'dtafimtur' => $this->faker->dateTimeBetween('+2 months', '+6 months')->format('d/m/Y'),
            'school_term_id' => SchoolTerm::factory(),
            'room_id' => Room::factory(),
            'fusion_id' => null,
        ];
    }

    /**
     * Configure the model factory for undergraduate classes.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function undergraduate()
    {
        return $this->state(function (array $attributes) {
            return [
                'tiptur' => 'Graduação',
            ];
        });
    }

    /**
     * Configure the model factory for graduate classes.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function graduate()
    {
        return $this->state(function (array $attributes) {
            return [
                'tiptur' => 'Pós Graduação',
            ];
        });
    }

    /**
     * Configure the model factory for external classes.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function external()
    {
        return $this->state(function (array $attributes) {
            return [
                'externa' => true,
            ];
        });
    }

    /**
     * Configure the model factory for first semester.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function firstSemester()
    {
        return $this->state(function (array $attributes) {
            $year = substr($attributes['codtur'], 0, 4);
            $classNumber = substr($attributes['codtur'], -2);
            
            return [
                'codtur' => $year . '1' . $classNumber,
                'dtainitur' => '01/02/' . $year,
                'dtafimtur' => '30/06/' . $year,
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
            $year = substr($attributes['codtur'], 0, 4);
            $classNumber = substr($attributes['codtur'], -2);
            
            return [
                'codtur' => $year . '2' . $classNumber,
                'dtainitur' => '01/08/' . $year,
                'dtafimtur' => '30/11/' . $year,
            ];
        });
    }

    /**
     * Configure the model factory without room allocation.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function withoutRoom()
    {
        return $this->state(function (array $attributes) {
            return [
                'room_id' => null,
            ];
        });
    }

    /**
     * Configure the model factory with specific room.
     *
     * @param Room|int $room
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function withRoom($room)
    {
        return $this->state(function (array $attributes) use ($room) {
            return [
                'room_id' => $room instanceof Room ? $room->id : $room,
            ];
        });
    }

    /**
     * Configure the model factory with specific school term.
     *
     * @param SchoolTerm|int $schoolTerm
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function withSchoolTerm($schoolTerm)
    {
        return $this->state(function (array $attributes) use ($schoolTerm) {
            return [
                'school_term_id' => $schoolTerm instanceof SchoolTerm ? $schoolTerm->id : $schoolTerm,
            ];
        });
    }
}