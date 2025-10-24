<?php

namespace Database\Factories;

use App\Enums\ImportStatus;
use App\Models\Import;
use Illuminate\Database\Eloquent\Factories\Factory;

class ImportFactory extends Factory
{
    protected $model = Import::class;

    public function definition(): array
    {
        return [
            'importable_type' => 'clients',
            'file_signature' => fake()->sha256(),
            'file_path' => 'imports/' . fake()->uuid() . '.csv',
            'status' => fake()->randomElement([
                ImportStatus::PENDING,
                ImportStatus::QUEUED,
                ImportStatus::PROCESSING,
                ImportStatus::COMPLETED,
                ImportStatus::FAILED,
            ]),
            'total_rows' => fake()->numberBetween(10, 1000),
            'metadata' => [
                'original_filename' => fake()->word() . '.csv',
                'mime_type' => 'text/csv',
                'detected_extension' => 'csv',
                'file_size' => fake()->numberBetween(1000, 1000000),
                'uploaded_at' => now()->toDateTimeString(),
            ],
            'data' => [
                'rows' => [],
                'summary' => [
                    'total' => 0,
                    'imported' => 0,
                    'failed' => 0,
                    'duplicates' => 0,
                ],
            ],
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ImportStatus::COMPLETED,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ImportStatus::FAILED,
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ImportStatus::PROCESSING,
        ]);
    }
}
