<?php
namespace App\Services;

use App\Models\Message;
use App\Services\MessageService;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    protected $ablyService;
    protected $messageService;

    public function __construct(AblyService $ablyService, MessageService $messageService)
    {
        $this->ablyService = $ablyService;
        $this->messageService = $messageService;
    }

    /**
     * Obtener el servicio Ably para uso en otros contextos
     */
    public function getAblyService()
    {
        return $this->ablyService;
    }

    /**
     * Notifica un nuevo mensaje con la configuración correcta de fechas
     * según el contexto de donde se llama
     */
    public function notifyNewMessage(Message $message, $isFromWebhook = false)
    {
        try {
            // Usar el método adecuado según el origen
            $messageData = $isFromWebhook
                ? $this->messageService->formatMessageWithTimestamp($message)
                : $this->messageService->formatMessage($message);

            // Publicar el evento
            $this->ablyService->publish(
                'messages-channel-' . $message->contact_id,
                'new-message',
                $messageData
            );

            Log::info("Notificación de nuevo mensaje enviada (messages-channel-" . $message->contact_id. ")", [
                'message_id' => $message->id
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error("Error al notificar nuevo mensaje: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Notifica nuevo mensaje desde webhook ERP
     */
    public function notifyERPMessage(Message $message)
    {
        try {
            // Usar formateo específico para ERP que añade 6 horas
            $messageData = $this->messageService->formatWebhookMessage($message);

            Log::info("➡️ Publicando en canal Ably", [
                'canal' => 'messages-channel-' . $message->contact_id,
                'evento' => 'new-message',
                'data' => $messageData,
            ]);

            // Publicar el evento
            $this->ablyService->publish(
                'messages-channel-' . $message->contact_id,
                'new-message',
                $messageData
            );

            Log::info("Notificación de nuevo mensaje ERP enviada", [
                'message_id' => $message->id
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error("Error al notificar mensaje ERP: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Notifica actualización de estado de mensaje
     */
    public function notifyStatusUpdate(Message $message)
    {
        try {
            $this->ablyService->publish(
                'messages-channel-' . $message->contact_id,
                'status-update',
                [
                    'messages' => [
                        [
                            'id' => $message->id,
                            'status' => $message->status
                        ]
                    ]
                ]
            );

            Log::info("Notificación de actualización de estado enviada", [
                'message_id' => $message->id
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error("Error al notificar actualización de estado: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Notifica actualización de múltiples mensajes
     */
    public function notifyBulkStatusUpdate($contactId, $messages)
    {
        try {
            $messageStatuses = $messages->map(function ($message) {
                return [
                    'id' => $message->id,
                    'status' => $message->status
                ];
            });

            $this->ablyService->publish(
                'messages-channel-' . $contactId,
                'status-update',
                [
                    'contact_id' => $contactId,
                    'messages' => $messageStatuses
                ]
            );

            return true;
        } catch (\Exception $e) {
            Log::error("Error al notificar actualización masiva: " . $e->getMessage());
            return false;
        }
    }
}
