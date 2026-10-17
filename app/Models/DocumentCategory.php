<?php

namespace App\Models;

use App\Concerns\HasGuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['owner_guid', 'name', 'normalized_name', 'color_token', 'description'])]
class DocumentCategory extends Model
{
    use HasGuid;

    public const COLOR_TOKENS = ['gray', 'blue', 'green', 'yellow', 'orange', 'red', 'purple'];

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_guid', 'guid');
    }

    /** @return HasMany<Document, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'category_guid', 'guid');
    }
}
