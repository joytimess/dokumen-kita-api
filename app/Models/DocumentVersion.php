<?php

namespace App\Models;

use App\Concerns\HasGuid;
use App\Enums\PreviewStatus;
use App\Enums\SourceType;
use App\Enums\VersionStatus;
use Database\Factories\DocumentVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['document_guid', 'version_number', 'editor_data_json', 'letterhead', 'footer', 'source_type', 'source_path', 'preview_pdf_path', 'preview_status', 'preview_page_count', 'preview_error_code', 'preview_error_message', 'final_pdf_path', 'content_hash', 'manifest_hash', 'final_pdf_hash', 'status', 'published_at', 'preview_generation', 'preview_source', 'finalization_error_code', 'conversion_status', 'conversion_generation', 'conversion_error_code', 'conversion_warnings', 'conversion_page_count', 'editor_revision', 'signing_mode'])]
class DocumentVersion extends Model
{
    /** @use HasFactory<DocumentVersionFactory> */
    use HasFactory, HasGuid;

    protected $attributes = ['signing_mode' => 'sequential'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'editor_data_json' => 'array',
            'letterhead' => 'array',
            'footer' => 'array',
            'status' => VersionStatus::class,
            'preview_status' => PreviewStatus::class,
            'source_type' => SourceType::class,
            'published_at' => 'datetime',
            'version_number' => 'integer',
            'preview_page_count' => 'integer',
            'conversion_warnings' => 'array',
            'conversion_page_count' => 'integer',
            'editor_revision' => 'integer',
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
        return $this->hasMany(SignerAssignment::class, 'version_guid', 'guid')->orderBy('sequence_number');
    }

    /** @return HasOne<DocumentVersionQr, $this> */
    public function signingQr(): HasOne
    {
        return $this->hasOne(DocumentVersionQr::class, 'version_guid', 'guid');
    }
}
