<?php
namespace App\Services;

use App\Models\Message;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class MessageService
{
    /**
     * Formatea un mensaje para la respuesta API, respetando el formato de fechas original
     */
    public function formatMessage(Message $message, $addSixHours = true)
    {
        return [
            'id' => $message->id,
            'text' => $message->content,
            'sender' => $message->direction == "out" ? 'user' : 'other',
            'timestamp' => $message->created_at ?
                ($addSixHours ? $message->created_at->addHours(6)->toIso8601String() : $message->created_at->toIso8601String()) :
                null,
            'status' => $message->status,
            'type' => $message->message_type,
            'media_url' => $message->media_full_url,
            'caption' => $message->caption
        ];
    }

    /**
     * Formatea una colección de mensajes, con opción para ajustar horas
     */
    public function formatMessageCollection($messages, $addSixHours = true)
    {
        return $messages->map(function ($message) use ($addSixHours) {
            return $this->formatMessage($message, $addSixHours);
        })->values()->all();
    }

    /**
     * Formatea mensaje con marca de tiempo específica (para webhooks)
     * Esto mantiene compatibilidad con el formato en WhatsAppWebhookController
     */
    public function formatMessageWithTimestamp(Message $message, $timestamp = null)
    {
        return [
            'id' => $message->id,
            'text' => $message->content,
            'sender' => $message->direction == "out" ? 'user' : 'other',
            'timestamp' => $timestamp ?: $message->sent_at, // No se añaden horas aquí según el código original
            'status' => $message->status,
            'type' => $message->message_type,
            'media_url' => $message->media_full_url,
            'caption' => $message->caption
        ];
    }

    /**
     * Formatea datos para nuevo mensaje desde webhook
     * Esta función es específica para handleERPMessageCreated donde se añaden 6 horas
     */
    public function formatWebhookMessage(Message $message)
    {
        return [
            'id' => $message->id,
            'text' => $message->content,
            'sender' => $message->direction == "out" ? 'user' : 'other',
            'timestamp' => $message->created_at ? $message->created_at->addHours(6)->toIso8601String() : null,
            'status' => $message->status,
            'type' => $message->message_type,
            'media_url' => $message->media_full_url,
            'caption' => $message->caption
        ];
    }

    /**
     * Obtiene el ID del usuario basado en el phone_number_id
     */
    public function getUserIdByPhoneNumber($phoneNumber)
    {
        try {
            $user = User::where('phone_number', $phoneNumber)->first();
            return $user ? $user->id : null;
        } catch (\Exception $e) {
            Log::error("Error buscando usuario por número de teléfono: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Obtiene el ID del contacto
     */
    public function getContactIdByUserIdAndPhone($userId, $phoneNumber)
    {
        try {
            if (!$userId) {
                return null;
            }

            $contact = Contact::where('user_id', $userId)
                ->where('phone_number', $phoneNumber)
                ->first();

            return $contact ? $contact->id : null;
        } catch (\Exception $e) {
            Log::error("Error buscando contacto: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Mapea el estado de la API de Meta al estado interno
     */
    public function mapStatusToInternal($metaStatus)
    {
        $statusMap = [
            'sent' => Message::STATUS_SENT,
            'delivered' => Message::STATUS_DELIVERED,
            'read' => Message::STATUS_READ,
            'failed' => Message::STATUS_FAILED
        ];

        return isset($statusMap[$metaStatus]) ? $statusMap[$metaStatus] : 'received';
    }

    /**
     * Verifica si el token existe en la base de datos
     */
    public function verifyTokenExists($token)
    {
        try {
            return User::where('verify_token', $token)->exists();
        } catch (\Exception $e) {
            Log::error("Error verificando token: " . $e->getMessage());
            return false;
        }
    }
}
