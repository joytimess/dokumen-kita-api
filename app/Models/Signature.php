<?php

namespace App\Models;

use App\Concerns\HasGuid;
use Database\Factories\SignatureFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['assignment_guid', 'asset_guid', 'version_content_hash', 'idempotency_key', 'signed_at'])]
class Signature extends Model
{
    /** @use HasFactory<SignatureFactory> */
    use HasFactory, HasGuid;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SignerAssignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(SignerAssignment::class, 'assignment_guid', 'guid');
    }

    /**
     * @return BelongsTo<SignatureAsset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(SignatureAsset::class, 'asset_guid', 'guid');
    }
}
