<?php
namespace App\Services;

use App\Models\Message;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;

class MessageRepositoryService
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Inserta o actualiza un mensaje
     * @param array $data Datos del mensaje
     * @param bool $isFromWebhook Indica si la operación viene desde un webhook
     */
    public function upsertMessage($data, $isFromWebhook = false)
    {
        try {
            // Verificar si el mensaje existe por meta_message_id
            $existingMessage = null;
            if (isset($data['meta_message_id'])) {
                $existingMessage = Message::where('meta_message_id', $data['meta_message_id'])->first();
            }

            $messageId = null;
            $isUpdate = false;
            Log::info("upsertMessage", [
                'message_type' => $data['message_type']
            ]);
            $isMedia = in_array($data['message_type'] ?? '', [
                Message::MESSAGE_TYPE_IMAGE,
                Message::MESSAGE_TYPE_AUDIO,
                Message::MESSAGE_TYPE_VIDEO,
                Message::MESSAGE_TYPE_DOCUMENT,
                Message::MESSAGE_TYPE_VOICE
            ]);

            if ($existingMessage) {
                // Actualizar mensaje existente
                $existingMessage->update($data);
                $messageId = $existingMessage->id;
                $isUpdate = true;
            } else {
                // Crear nuevo mensaje
                $message = Message::create($data);
                $messageId = $message->id;
            }

            // Obtener el mensaje actualizado o creado
            $message = Message::find($messageId);

            // Notificar al frontend a través de Ably con el formato correcto
            if ($messageId) {
                if ($isUpdate) {
                    // Publicar evento de actualización de estado
                    $this->notificationService->notifyStatusUpdate($message);
                } else {
                    // Publicar evento de nuevo mensaje con el flag correcto
                    $this->notificationService->notifyNewMessage($message, $isFromWebhook);
                }
            }

            return $messageId;
        } catch (\Exception $e) {
            Log::error("Error en upsertMessage: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'data' => $data
            ]);
            return null;
        }
    }

    /**
     * Método específico para mensajes desde ERP webhook
     */
    public function upsertERPMessage($data)
    {
        try {
            $existingMessage = null;
            if (isset($data['meta_message_id'])) {
                $existingMessage = Message::where('meta_message_id', $data['meta_message_id'])->first();
            }

            $messageId = null;

            if ($existingMessage) {
                $existingMessage->update($data);
                $messageId = $existingMessage->id;
            } else {
                $message = Message::create($data);
                $messageId = $message->id;
            }

            $message = Message::find($messageId);

            // Usar notificación específica para ERP que mantiene el formato de fecha correcto
            $this->notificationService->notifyERPMessage($message);

            return $messageId;
        } catch (\Exception $e) {
            Log::error("Error en upsertERPMessage: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'data' => $data
            ]);
            return null;
        }
    }

    /**
     * Actualiza el estado de un mensaje
     */
    public function updateMessageStatus($message, $status)
    {
        try {
            $message->updateStatus($status);
            $this->notificationService->notifyStatusUpdate($message);
            return true;
        } catch (\Exception $e) {
            Log::error("Error al actualizar estado del mensaje: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Actualiza el estado de múltiples mensajes
     */
    public function updateBulkMessageStatus($contactId, $status)
    {
        try {
            // Actualiza todos los mensajes del contacto
            $updatedCount = Message::where('contact_id', $contactId)
                ->update(['status' => $status]);

            // Obtener los mensajes actualizados
            $messages = Message::where('contact_id', $contactId)->get();

            // Notificar actualización
            $this->notificationService->notifyBulkStatusUpdate($contactId, $messages);

            return $updatedCount;
        } catch (\Exception $e) {
            Log::error("Error al actualizar estados de mensajes: " . $e->getMessage());
            return 0;
        }
    }
}
