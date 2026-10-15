<?php

namespace App\Models;

use App\Concerns\HasGuid;
use Database\Factories\EmailNotificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['version_guid', 'assignment_guid', 'recipient_email', 'notification_type', 'deduplication_key', 'status', 'attempt_count', 'scheduled_at', 'sent_at', 'payload', 'last_error_code'])]
class EmailNotification extends Model
{
    /** @use HasFactory<EmailNotificationFactory> */
    use HasFactory, HasGuid;

    protected $hidden = ['payload'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempt_count' => 'integer',
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
            'payload' => 'encrypted:array',
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
     * @return BelongsTo<SignerAssignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(SignerAssignment::class, 'assignment_guid', 'guid');
    }
}
