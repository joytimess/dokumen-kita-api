<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_guid' => fn () => User::factory()->create()->guid,
            'public_reference' => Str::random(32),
            'title' => fake()->sentence(3),
            'status' => 'draft',
        ];
    }
}
