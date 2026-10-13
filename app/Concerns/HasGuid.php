<?php

namespace App\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Gives a model a server-generated UUIDv4 `guid` used as its public identifier and relation key.
 *
 * @mixin Model
 */
trait HasGuid
{
    protected static function bootHasGuid(): void
    {
        static::creating(function (Model $model): void {
            if (empty($model->guid)) {
                $model->guid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'guid';
    }
}
