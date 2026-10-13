<?php

namespace App\Models;

use App\Concerns\HasGuid;
use Database\Factories\DocumentVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['document_guid', 'version_number', 'editor_data_json', 'source_type', 'source_path', 'preview_pdf_path', 'preview_status', 'final_pdf_path', 'content_hash', 'manifest_hash', 'final_pdf_hash', 'status', 'published_at'])]
class DocumentVersion extends Model
{
    /** @use HasFactory<DocumentVersionFactory> */
    use HasFactory, HasGuid;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'editor_data_json' => 'array',
            'published_at' => 'datetime',
            'version_number' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_guid', 'guid');
    }

    /**
     * @return HasMany<SignerAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(SignerAssignment::class, 'version_guid', 'guid');
    }
}
