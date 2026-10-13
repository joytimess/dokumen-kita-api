<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentVersion>
 */
class DocumentVersionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_guid' => fn () => Document::factory()->create()->guid,
            'version_number' => 1,
            'editor_data_json' => ['blocks' => []],
            'status' => 'draft',
        ];
    }
}
