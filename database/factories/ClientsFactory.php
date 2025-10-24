<?php

namespace Database\Factories;

use App\Models\Clients;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClientsFactory extends Factory
{
    protected $model = Clients::class;

    public function definition(): array
    {
        return [
            'company' => fake()->company(),
            'email' => fake()->unique()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'has_duplicates' => false,
            'extras' => null,
        ];
    }

    public function withDuplicates(): static
    {
        return $this->state(fn (array $attributes) => [
            'has_duplicates' => true,
            'extras' => [
                'duplicate_ids' => [
                    'company' => [fake()->numberBetween(1, 100)],
                ],
            ],
        ]);
    }
}
