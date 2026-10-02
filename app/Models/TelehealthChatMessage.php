<?php

namespace App\Models;

use Database\Factories\TelehealthChatMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['session_id', 'sender_id', 'sender_name', 'body'])]
class TelehealthChatMessage extends Model
{
    /** @use HasFactory<TelehealthChatMessageFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<TelehealthSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(TelehealthSession::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
