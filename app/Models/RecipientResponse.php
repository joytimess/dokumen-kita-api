<?php

namespace App\Models;

use App\Concerns\HasGuid;
use Database\Factories\RecipientResponseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['assignment_guid', 'response_type', 'reason'])]
class RecipientResponse extends Model
{
    /** @use HasFactory<RecipientResponseFactory> */
    use HasFactory, HasGuid;

    /**
     * @return BelongsTo<SignerAssignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(SignerAssignment::class, 'assignment_guid', 'guid');
    }
}
