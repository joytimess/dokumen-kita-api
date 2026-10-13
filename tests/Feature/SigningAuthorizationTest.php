<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SigningAuthorizationTest extends TestCase
{
    use RefreshDatabase, SigningFixtures;

    /** @return array<string, array{string, bool, int|null}> */
    public static function roles(): array
    {
        return [
            'owner' => ['owner', true, null],
            'recipient' => ['recipient', false, null],
            'outsider' => ['outsider', false, 404],
        ];
    }

    #[DataProvider('roles')]
    public function test_setup_and_resend_policy_respects_owner_recipient_and_outsider_roles(string $role, bool $allowed, ?int $status): void
    {
        $owner = User::factory()->create();
        $recipient = User::factory()->create();
        $outsider = User::factory()->create();
        $version = $this->preparedDraft($owner);
        $assignment = $version->assignments()->firstOrFail();
        $assignment->update(['user_guid' => $recipient->guid]);
        $actor = match ($role) {
            'owner' => $owner,
            'recipient' => $recipient,
            'outsider' => $outsider,
        };

        $versionAccess = Gate::forUser($actor)->inspect('update', $version);
        $assignmentAccess = Gate::forUser($actor)->inspect('manage', $assignment);

        $this->assertSame($allowed, $versionAccess->allowed());
        $this->assertSame($status, $versionAccess->status());
        $this->assertSame($allowed, $assignmentAccess->allowed());
        $this->assertSame($status, $assignmentAccess->status());
    }
}
