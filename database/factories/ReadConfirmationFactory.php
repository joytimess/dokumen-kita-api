<?php

namespace Database\Factories;

use App\Models\ReadConfirmation;
use App\Models\SignerAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReadConfirmation>
 */
class ReadConfirmationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'assignment_guid' => fn () => SignerAssignment::factory()->create()->guid,
            'version_content_hash' => fn (array $attributes) => SignerAssignment::where('guid', $attributes['assignment_guid'])->firstOrFail()->version->content_hash ?? str_repeat('a', 64),
            'confirmed_at' => now(),
        ];
    }
}
