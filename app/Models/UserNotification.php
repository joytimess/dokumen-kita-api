<?php

namespace App\Models;

use App\Concerns\HasGuid;
use Database\Factories\UserNotificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['user_guid', 'document_guid', 'version_guid', 'assignment_guid', 'event_type', 'payload_json', 'deduplication_key', 'read_at'])]
class UserNotification extends Model
{
    /** @use HasFactory<UserNotificationFactory> */
    use HasFactory, HasGuid;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'read_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_guid', 'guid');
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_guid', 'guid');
    }

    /**
     * @return BelongsTo<DocumentVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'version_guid', 'guid');
    }

    /**
     * @return BelongsTo<SignerAssignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(SignerAssignment::class, 'assignment_guid', 'guid');
    }

    /**
     * @return HasOne<RealtimeDelivery, $this>
     */
    public function delivery(): HasOne
    {
        return $this->hasOne(RealtimeDelivery::class, 'notification_guid', 'guid');
    }
}
