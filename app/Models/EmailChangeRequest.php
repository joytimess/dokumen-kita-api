<?php

namespace App\Models;

use App\Concerns\HasGuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_guid', 'new_email', 'token_hash', 'expires_at'])]
class EmailChangeRequest extends Model
{
    use HasGuid;

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
