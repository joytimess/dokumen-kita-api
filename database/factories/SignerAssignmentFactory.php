<?php

namespace Database\Factories;

use App\Models\DocumentVersion;
use App\Models\SignerAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SignerAssignment>
 */
class SignerAssignmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'version_guid' => fn () => DocumentVersion::factory()->create()->guid,
            'recipient_email' => $email = fake()->unique()->safeEmail(),
            'normalized_recipient_email' => Str::lower($email),
            'sequence_number' => 1,
            'status' => 'waiting',
        ];
    }
}
