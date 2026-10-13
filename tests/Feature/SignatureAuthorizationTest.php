<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SignatureAuthorizationTest extends TestCase
{
    use RefreshDatabase, SignatureFixtures;

    /** @return array<string, array{string, bool}> */
    public static function states(): array
    {
        return [
            'ready' => ['ready', true], 'signed' => ['signed', true],
            'revision requested' => ['revision_requested', true], 'rejected' => ['rejected', true],
            'waiting' => ['waiting', false], 'cancelled' => ['cancelled', false],
        ];
    }

    #[DataProvider('states')]
    public function test_pdf_and_assignment_policies_keep_waiting_content_private_and_owner_access_separate(string $state, bool $canRead): void
    {
        $owner = User::factory()->create();
        $signer = User::factory()->create();
        $outsider = User::factory()->create();
        $version = $this->publishedVersion($owner, [$signer]);
        $assignment = $version->assignments()->firstOrFail();
        $assignment->update(['status' => $state]);

        $this->assertSame($canRead, Gate::forUser($signer)->inspect('viewPdf', $version)->allowed());
        $this->assertTrue(Gate::forUser($owner)->allows('viewPdf', $version));
        $this->assertSame(404, Gate::forUser($outsider)->inspect('viewPdf', $version)->status());
        $this->assertTrue(Gate::forUser($signer)->allows('act', $assignment));
        $this->assertFalse(Gate::forUser($owner)->allows('act', $assignment));
        $this->assertSame(404, Gate::forUser($outsider)->inspect('act', $assignment)->status());
    }
}
