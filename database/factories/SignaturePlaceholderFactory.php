<?php

namespace Database\Factories;

use App\Models\SignaturePlaceholder;
use App\Models\SignerAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SignaturePlaceholder>
 */
class SignaturePlaceholderFactory extends Factory
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
            'page_number' => 1,
            'x' => 0.1,
            'y' => 0.2,
            'width' => 0.25,
            'height' => 0.1,
            'needs_review' => false,
        ];
    }
}
