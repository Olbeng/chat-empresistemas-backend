<?php

namespace App\Models;

use App\Models\User;
use App\Models\Contact;
use App\Models\MessageStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Message extends Model
{
    /**
     * Los atributos que son asignables masivamente.
     */
    protected $fillable = [
        'user_id',
        'contact_id',
        'meta_message_id',
        'direction',
        'content',
        'status',
        'message_type',
        'sent_at'
    ];

    /**
     * Los atributos que deben ser convertidos a tipos nativos.
     */
    protected $casts = [
        'sent_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Los atributos que deben ser incluidos en la serialización.
     */
    protected $appends = [
        'is_outbound',
        'formatted_sent_time'
    ];
    protected static function booted()
    {
        static::creating(function ($model) {
            $model->created_at = $model->freshTimestamp()->addHours(-6);
            $model->updated_at = $model->freshTimestamp()->addHours(-6);
        });
    }
    /**
     * Constantes para los estados de los mensajes
     */
    const STATUS_SENDING = 'sending';
    const STATUS_SENT = 'sent';
    const STATUS_DELIVERED = 'delivered';
    const STATUS_READ = 'read';
    const STATUS_FAILED = 'failed';

    /**
     * Constantes para la dirección de los mensajes
     */
    const DIRECTION_IN = 'in';
    const DIRECTION_OUT = 'out';

    /**
     * Constantes para el tipo de los mensajes
     */
    const MESSAGE_TYPE_TEXT = 'text';
    const MESSAGE_TYPE_TEMPLATE = 'template';

    /**
     * Relación con el usuario
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relación con el contacto
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Relación con los estados del mensaje
     */
    public function statuses(): HasMany
    {
        return $this->hasMany(MessageStatus::class, 'meta_message_id', 'meta_message_id');
    }

    /**
     * Último estado del mensaje
     */
    public function latestStatus(): BelongsTo
    {
        return $this->belongsTo(MessageStatus::class, 'meta_message_id', 'meta_message_id')
            ->latest('status_timestamp');
    }

    /**
     * Scope para mensajes de un contacto específico
     */
    public function scopeForContact($query, $contactId)
    {
        return $query->where('contact_id', $contactId);
    }

    /**
     * Scope para mensajes enviados
     */
    public function scopeOutbound($query)
    {
        return $query->where('direction', self::DIRECTION_OUT);
    }

    /**
     * Scope para mensajes recibidos
     */
    public function scopeInbound($query)
    {
        return $query->where('direction', self::DIRECTION_IN);
    }

    /**
     * Scope para mensajes recientes
     */
    public function scopeRecent($query, int $days = 2)
    {
        return $query->where('created_at', '>=', Carbon::now()->subDays($days));
    }

    /**
     * Accessor para verificar si el mensaje es saliente
     */
    public function getIsOutboundAttribute(): bool
    {
        return $this->direction === self::DIRECTION_OUT;
    }

    /**
     * Accessor para obtener el tiempo formateado
     */
    public function getFormattedSentTimeAttribute(): string
    {
        return $this->sent_at->format('M d, Y H:i');
    }

    /**
     * Actualizar el estado del mensaje
     */
    public function updateStatus(string $status): void
    {
        $this->statuses()->create([
            'meta_message_id' => $this->meta_message_id,
            'status' => $status,
            'status_timestamp' => Carbon::now()
        ]);

        $this->update(['status' => $status]);
    }

    /**
     * Marcar el mensaje como fallido
     */
    public function markAsFailed(string $errorMessage = null): void
    {
        $this->updateStatus(self::STATUS_FAILED);

        if ($errorMessage) {
            $this->update(['error_message' => $errorMessage]);
        }
    }

    /**
     * Verificar si el mensaje está en un estado específico
     */
    public function isInStatus(string $status): bool
    {
        return $this->status === $status;
    }

    /**
     * Obtener el siguiente estado válido
     */
    public static function getNextValidStatus(string $currentStatus): ?string
    {
        $flow = [
            self::STATUS_SENDING => self::STATUS_SENT,
            self::STATUS_SENT => self::STATUS_DELIVERED,
            self::STATUS_DELIVERED => self::STATUS_READ
        ];

        return $flow[$currentStatus] ?? null;
    }
}
