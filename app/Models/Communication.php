<?php

namespace App\Models;

use Database\Factories\CommunicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['lead_id', 'user_id', 'channel', 'summary', 'notes', 'occurred_at'])]
class Communication extends Model
{
    /** @use HasFactory<CommunicationFactory> */
    use HasFactory;

    public const CHANNEL_CALL = 'call';

    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const CHANNEL_SMS = 'sms';

    public const CHANNEL_VISIT = 'visit';

    public const CHANNEL_NOTE = 'note';

    public const CHANNEL_WEBSITE = 'website';

    /**
     * Channels used on the communication timeline (DESIGN §15).
     *
     * @return array<int, string>
     */
    public static function channels(): array
    {
        return [self::CHANNEL_CALL, self::CHANNEL_EMAIL, self::CHANNEL_WHATSAPP, self::CHANNEL_SMS, self::CHANNEL_VISIT, self::CHANNEL_NOTE, self::CHANNEL_WEBSITE];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
