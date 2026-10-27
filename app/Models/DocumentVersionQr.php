<?php

namespace App\Models;

use App\Concerns\HasGuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['version_guid', 'token_hash', 'encrypted_token', 'revoked_at'])]
class DocumentVersionQr extends Model
{
    use HasGuid;

    protected $hidden = ['token_hash', 'encrypted_token'];

    protected function casts(): array
    {
        return ['revoked_at' => 'datetime'];
    }

    /** @return BelongsTo<DocumentVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'version_guid', 'guid');
    }
}
