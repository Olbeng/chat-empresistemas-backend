<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageStatus extends Model
{
    protected $fillable = [
        'meta_message_id',
        'status',
        'status_timestamp'
    ];

    protected $casts = [
        'status_timestamp' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /**
     * Obtener el mensaje asociado a este estado
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'meta_message_id', 'meta_message_id');
    }
}
