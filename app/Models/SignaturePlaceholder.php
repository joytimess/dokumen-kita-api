<?php

namespace App\Models;

use App\Concerns\HasGuid;
use Database\Factories\SignaturePlaceholderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['assignment_guid', 'page_number', 'x', 'y', 'width', 'height', 'needs_review'])]
class SignaturePlaceholder extends Model
{
    /** @use HasFactory<SignaturePlaceholderFactory> */
    use HasFactory, HasGuid;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'page_number' => 'integer',
            'x' => 'float',
            'y' => 'float',
            'width' => 'float',
            'height' => 'float',
            'needs_review' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<SignerAssignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(SignerAssignment::class, 'assignment_guid', 'guid');
    }
}
