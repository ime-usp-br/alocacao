<?php

namespace Database\Factories;

use App\Models\ClassSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClassScheduleFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = ClassSchedule::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        $days = ['seg', 'ter', 'qua', 'qui', 'sex'];
        $day = $this->faker->randomElement($days);

        $startHour = $this->faker->numberBetween(8, 16);
        $startMinute = $this->faker->randomElement(['00', '40']);
        $start = sprintf('%02d:%s', $startHour, $startMinute);

        $endHour = $startHour + 2;
        $endMinute = $startMinute;
        $end = sprintf('%02d:%s', $endHour, $endMinute);

        return [
            'diasmnocp' => $day,
            'horent' => $start,
            'horsai' => $end,
        ];
    }

    public function seg()
    {
        return $this->state(['diasmnocp' => 'seg']);
    }

    public function ter()
    {
        return $this->state(['diasmnocp' => 'ter']);
    }

    public function qua()
    {
        return $this->state(['diasmnocp' => 'qua']);
    }

    public function qui()
    {
        return $this->state(['diasmnocp' => 'qui']);
    }

    public function sex()
    {
        return $this->state(['diasmnocp' => 'sex']);
    }

    public function morning()
    {
        return $this->state(function (array $attributes) {
            return [
                'horent' => '08:00',
                'horsai' => '10:00',
            ];
        });
    }

    public function afternoon()
    {
        return $this->state(function (array $attributes) {
            return [
                'horent' => '14:00',
                'horsai' => '16:00',
            ];
        });
    }
}
