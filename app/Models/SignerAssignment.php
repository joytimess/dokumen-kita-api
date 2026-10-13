<?php

namespace App\Models;

use App\Concerns\HasGuid;
use Database\Factories\SignerAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['version_guid', 'user_guid', 'recipient_email', 'normalized_recipient_email', 'sequence_number', 'status', 'claimed_at'])]
class SignerAssignment extends Model
{
    /** @use HasFactory<SignerAssignmentFactory> */
    use HasFactory, HasGuid;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'claimed_at' => 'datetime',
            'sequence_number' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<DocumentVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'version_guid', 'guid');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_guid', 'guid');
    }

    /**
     * @return HasMany<SignaturePlaceholder, $this>
     */
    public function placeholders(): HasMany
    {
        return $this->hasMany(SignaturePlaceholder::class, 'assignment_guid', 'guid');
    }

    /**
     * @return HasOne<ReadConfirmation, $this>
     */
    public function readConfirmation(): HasOne
    {
        return $this->hasOne(ReadConfirmation::class, 'assignment_guid', 'guid');
    }

    /**
     * @return HasOne<Signature, $this>
     */
    public function signature(): HasOne
    {
        return $this->hasOne(Signature::class, 'assignment_guid', 'guid');
    }

    /**
     * @return HasOne<RecipientResponse, $this>
     */
    public function response(): HasOne
    {
        return $this->hasOne(RecipientResponse::class, 'assignment_guid', 'guid');
    }

    /**
     * @return HasMany<InvitationLink, $this>
     */
    public function invitationLinks(): HasMany
    {
        return $this->hasMany(InvitationLink::class, 'assignment_guid', 'guid');
    }
}
