<?php

namespace Database\Factories;

use App\Models\InvitationLink;
use App\Models\SignerAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvitationLink>
 */
class InvitationLinkFactory extends Factory
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
            'token_hash' => hash('sha256', bin2hex(random_bytes(32))),
            'expires_at' => now()->addDays(7),
        ];
    }
}
