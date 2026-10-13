<?php

namespace App\Models;

use App\Concerns\HasGuid;
use Database\Factories\ReadConfirmationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['assignment_guid', 'version_content_hash', 'confirmed_at'])]
class ReadConfirmation extends Model
{
    /** @use HasFactory<ReadConfirmationFactory> */
    use HasFactory, HasGuid;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
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
