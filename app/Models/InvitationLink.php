<?php

namespace App\Models;

use App\Concerns\HasGuid;
use Database\Factories\InvitationLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['assignment_guid', 'token_hash', 'expires_at', 'revoked_at'])]
class InvitationLink extends Model
{
    /** @use HasFactory<InvitationLinkFactory> */
    use HasFactory, HasGuid;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
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
