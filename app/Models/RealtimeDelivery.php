<?php

namespace App\Models;

use App\Concerns\HasGuid;
use Database\Factories\RealtimeDeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['notification_guid', 'status', 'attempt_count', 'next_attempt_at', 'delivered_at'])]
class RealtimeDelivery extends Model
{
    /** @use HasFactory<RealtimeDeliveryFactory> */
    use HasFactory, HasGuid;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempt_count' => 'integer',
            'next_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<UserNotification, $this>
     */
    public function notification(): BelongsTo
    {
        return $this->belongsTo(UserNotification::class, 'notification_guid', 'guid');
    }
}
