<?php

namespace App\Models;

use App\Concerns\HasGuid;
use Database\Factories\SignatureAssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_guid', 'file_path', 'file_hash', 'source_type'])]
class SignatureAsset extends Model
{
    /** @use HasFactory<SignatureAssetFactory> */
    use HasFactory, HasGuid;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_guid', 'guid');
    }
}
