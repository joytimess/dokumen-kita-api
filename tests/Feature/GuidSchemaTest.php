<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\SignerAssignment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuidSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_models_receive_uuid_guid_and_relate_through_guid(): void
    {
        $assignment = SignerAssignment::factory()->create();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $assignment->guid);
        $this->assertSame($assignment->version_guid, $assignment->version->guid);
        $this->assertInstanceOf(Document::class, $assignment->version->document);
        $this->assertInstanceOf(User::class, $assignment->version->document->owner);
        $this->assertTrue($assignment->version->document->versions->first()->is($assignment->version));
    }

    public function test_assignment_sequence_and_email_are_unique_per_version(): void
    {
        $version = DocumentVersion::factory()->create();
        SignerAssignment::factory()->create([
            'version_guid' => $version->guid,
            'normalized_recipient_email' => 'a@example.com',
            'sequence_number' => 1,
        ]);

        $this->expectException(QueryException::class);

        SignerAssignment::factory()->create([
            'version_guid' => $version->guid,
            'normalized_recipient_email' => 'b@example.com',
            'sequence_number' => 1,
        ]);
    }

    public function test_version_number_is_unique_per_document(): void
    {
        $version = DocumentVersion::factory()->create();

        $this->expectException(QueryException::class);

        DocumentVersion::factory()->create([
            'document_guid' => $version->document_guid,
            'version_number' => 1,
        ]);
    }
}
