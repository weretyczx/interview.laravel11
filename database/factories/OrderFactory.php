<?php

namespace Database\Factories;

use App\Services\Sequence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'no' => Sequence::no('T'),
            'status' => 1,
            'user_id' => 1,
            'ticket_id' => 1,
            'qty' => 1,
            'cost' => 100,

        ];
    }
}
