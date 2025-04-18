<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use App\Models\Message;
use App\Models\User;
use App\Models\Contact;
use App\Services\MessageService;
use App\Services\NotificationService;
use App\Services\MessageRepositoryService;
use App\Services\MediaService;
use App\Services\MetaWhatsAppService;

class WhatsAppWebhookController extends Controller
{
    protected $messageService;
    protected $notificationService;
    protected $messageRepositoryService;
    protected $mediaService;

    public function __construct(
        MessageService $messageService,
        NotificationService $notificationService,
        MessageRepositoryService $messageRepositoryService,
        MediaService $mediaService
    ) {
        $this->messageService = $messageService;
        $this->notificationService = $notificationService;
        $this->messageRepositoryService = $messageRepositoryService;
        $this->mediaService = $mediaService;
    }

    /**
     * Maneja las solicitudes GET para verificación del webhook de WhatsApp
     */
    public function verify(Request $request)
    {
        try {
            // Validar la solicitud de verificación del webhook
            if (
                $request->has('hub_mode') &&
                $request->input('hub_mode') === 'subscribe' &&
                $request->has('hub_verify_token') &&
                $request->has('hub_challenge')
            ) {
                $hubVerifyToken = $request->input('hub_verify_token');
                $hubChallenge = $request->input('hub_challenge');

                // Verificar token en la base de datos usando el servicio
                $isValid = $this->messageService->verifyTokenExists($hubVerifyToken);

                if ($isValid) {
                    Log::info('Webhook de WhatsApp verificado exitosamente');
                    return response($hubChallenge);
                } else {
                    Log::warning('Token de verificación de WhatsApp inválido', [
                        'token' => $hubVerifyToken
                    ]);
                    return response('Token de verificación inválido', 403);
                }
            }

            return response('Solicitud incorrecta', 400);
        } catch (\Exception $e) {
            Log::error('Error al verificar webhook de WhatsApp: ' . $e->getMessage());
            return response('Error del servidor', 500);
        }
    }

    /**
     * Maneja las solicitudes POST para los mensajes entrantes de WhatsApp
     */
    public function handleWebhook(Request $request)
    {
        try {
            // Obtener los datos JSON
            $messageData = $request->all();

            // Registrar toda la solicitud para depuración
            Log::info('Webhook de WhatsApp recibido', [
                'data' => $messageData
            ]);

            if (!$messageData || !isset($messageData['entry'][0]['changes'][0]['value'])) {
                return response()->json([
                    'success' => false,
                    'error' => 'Formato de datos inválido'
                ], 400);
            }

            // Procesar los datos del webhook
            $this->processMessage($messageData);

            return response()->json([
                'success' => true
            ]);
        } catch (\Exception $e) {
            Log::error('Error al procesar webhook de WhatsApp: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'error' => 'Error del servidor: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Procesa los datos de mensajes entrantes de WhatsApp
     */
    private function processMessage($messageData)
    {
        if (!$messageData || !isset($messageData['entry'][0]['changes'][0]['value'])) {
            return false;
        }

        $phone_number_id = "";
        $value = $messageData['entry'][0]['changes'][0]['value'];

        if (isset($value['metadata']['phone_number_id'])) {
            $phone_number_id = $value['metadata']['phone_number_id'];
        }

        if ($phone_number_id === "") {
            Log::error("Falta phone_number_id en los datos del webhook de WhatsApp");
            return false;
        }

        // Procesar mensajes entrantes
        if (isset($value['messages'])) {
            foreach ($value['messages'] as $message) {
                // Validar que el mensaje tenga un ID
                if (!isset($message['id'])) {
                    Log::error("Mensaje sin ID recibido", ['message' => $message]);
                    continue;
                }

                // Validar que el mensaje tenga un remitente
                if (!isset($message['from'])) {
                    Log::error("Mensaje sin remitente recibido", ['message' => $message]);
                    continue;
                }

                // Identificar tipo de mensaje
                $messageType = $message['type'] ?? 'unknown';

                Log::info("Procesando mensaje de tipo: " . $messageType, [
                    'message_id' => $message['id']
                ]);

                switch ($messageType) {
                    case 'text':
                        // Verificar que tenga la estructura esperada
                        if (!isset($message['text']['body'])) {
                            Log::error("Mensaje de texto sin cuerpo", ['message' => $message]);
                            continue 2;
                        }

                        $data = [
                            "meta_message_id" => $message['id'],
                            "sender" => $message['from'],
                            "content" => $message['text']['body'],
                            "timestamp" => $message['timestamp'],
                            "status" => "received",
                            "direction" => Message::DIRECTION_IN
                        ];
                        $this->handleTextMessage($phone_number_id, $data, Message::MESSAGE_TYPE_TEXT);
                        break;

                    case 'audio':
                    case 'image':
                    case 'video':
                    case 'document':
                    case 'voice':
                        $this->handleMediaMessage($phone_number_id, $message, $messageType);
                        break;

                    default:
                        Log::info('Tipo de mensaje no soportado: ' . $messageType, [
                            'message' => $message
                        ]);
                        break;
                }
            }
        }

        // Procesar mensajes de plantillas y cambios de estado
        if (isset($value['statuses'])) {
            foreach ($value['statuses'] as $status) {
                // Verificar que el status tenga un ID
                if (!isset($status['id'])) {
                    Log::error("Status sin ID recibido", ['status' => $status]);
                    continue;
                }

                // Procesar el estado - solo actualiza el estado, no el tipo si ya existe
                $this->handleMessageStatus($status);

                // Si es un mensaje de plantilla Y no existe en la base de datos, crearlo
                if (
                    isset($status['conversation']['origin']['type']) &&
                    $status['conversation']['origin']['type'] === 'utility'
                ) {
                    // Verificar si el mensaje ya existe
                    $existingMessage = Message::where('meta_message_id', $status['id'])->first();

                    // Solo crear un nuevo mensaje si no existe
                    if (!$existingMessage) {
                        Log::info("Creando nuevo mensaje de plantilla", ['status' => $status]);

                        $data = [
                            "meta_message_id" => $status['id'],
                            "sender" => $status['recipient_id'],
                            "timestamp" => $status['timestamp'],
                            "status" => $status['status'],
                            "direction" => Message::DIRECTION_OUT
                        ];

                        $this->handleTextMessage($phone_number_id, $data, Message::MESSAGE_TYPE_TEMPLATE);
                    }
                }
            }
        }

        return true;
    }

    /**
     * Maneja los mensajes de texto entrantes
     */
    private function handleTextMessage($phone_number_id, $data, $typeMessage)
    {
        Log::info('Procesando mensaje de texto', [
            'data' => $data,
            'tipo' => $typeMessage
        ]);

        $sender = $data['sender'];
        $content = isset($data['content']) ? $data['content'] : '';
        $timestamp = $data['timestamp'];
        $date = Carbon::createFromTimestamp($timestamp);

        // Obtener el ID del usuario basado en el phone_number_id
        $userId = $this->messageService->getUserIdByPhoneNumber($phone_number_id);

        // Obtener el ID del contacto
        $contactId = $this->messageService->getContactIdByUserIdAndPhone($userId, $sender);

        // Si no se encuentra el contacto, registrar el error
        if (!$contactId) {
            Log::error("Contacto no encontrado", [
                'user_id' => $userId,
                'phone' => $sender
            ]);
            return;
        }

        // Preparar datos del mensaje
        $messageData = [
            'user_id' => $userId,
            'contact_id' => $contactId,
            'meta_message_id' => isset($data['meta_message_id']) ? $data['meta_message_id'] : null,
            'direction' => $data['direction'],
            'status' => $data['status'],
            'message_type' => $typeMessage,
            'sent_at' => $date
        ];
        // Agregar content solo si no está vacío
        if ($content != "") {
            $messageData['content'] = $content;
        }

        // Insertar o actualizar mensaje usando el servicio de repositorio
        // Indicar que viene de webhook para formatear correctamente la fecha
        $messageId = $this->messageRepositoryService->upsertMessage($messageData, true);

        // Registrar éxito
        Log::info("Mensaje de texto procesado exitosamente", [
            'message_id' => $messageId
        ]);
    }

    /**
     * Maneja los mensajes multimedia entrantes
     */
    private function handleMediaMessage($phone_number_id, $message, $messageType)
    {
        Log::info('Procesando mensaje multimedia', [
            'tipo' => $messageType,
            'message' => $message
        ]);

        $sender = $message['from'];
        $timestamp = $message['timestamp'];
        $date = Carbon::createFromTimestamp($timestamp);
        $mediaId = null;
        $caption = null;
        $filename = null;
        $metadata = [];

        // Obtener los detalles según el tipo de medio
        switch ($messageType) {
            case Message::MESSAGE_TYPE_AUDIO:
                $mediaId = $message['audio']['id'];
                $metadata = $message['audio'];
                break;

            case Message::MESSAGE_TYPE_VOICE:
                $mediaId = $message['voice']['id'];
                $metadata = $message['voice'];
                break;

            case Message::MESSAGE_TYPE_IMAGE:
                $mediaId = $message['image']['id'];
                $caption = isset($message['image']['caption']) ? $message['image']['caption'] : null;
                $metadata = $message['image'];
                break;

            case Message::MESSAGE_TYPE_VIDEO:
                $mediaId = $message['video']['id'];
                $caption = isset($message['video']['caption']) ? $message['video']['caption'] : null;
                $metadata = $message['video'];
                break;

            case Message::MESSAGE_TYPE_DOCUMENT:
                $mediaId = $message['document']['id'];
                $filename = isset($message['document']['filename']) ? $message['document']['filename'] : null;
                $caption = isset($message['document']['caption']) ? $message['document']['caption'] : null;
                $metadata = $message['document'];
                break;
        }

        // Obtener usuario por phone_number_id
        $user = User::where('phone_number', $phone_number_id)->first();

        if (!$user) {
            Log::error("Usuario no encontrado para phone_number_id", [
                'phone_number_id' => $phone_number_id
            ]);
            return;
        }

        // Obtener información del medio desde la API de Meta
        $mediaInfo = $this->mediaService->getMediaInfo($mediaId, $user);

        if (!$mediaInfo) {
            Log::error("No se pudo obtener información del medio", [
                'media_id' => $mediaId
            ]);
            return;
        }

        // Descargar y guardar el medio
        $mediaPath = $this->mediaService->downloadAndSaveMedia($mediaInfo, $messageType, $filename, $user);

        if (!$mediaPath) {
            Log::error("Error al descargar y guardar el medio", [
                'media_id' => $mediaId
            ]);
            return;
        }

        // Obtener el ID del usuario basado en el phone_number_id
        $userId = $user->id;

        // Obtener el ID del contacto
        $contactId = $this->messageService->getContactIdByUserIdAndPhone($userId, $sender);

        // Si no se encuentra el contacto, registrar el error
        if (!$contactId) {
            Log::error("Contacto no encontrado", [
                'user_id' => $userId,
                'phone' => $sender
            ]);
            return;
        }

        // Preparar contenido descriptivo
        $content = $caption ?: ($filename ?: $this->mediaService->getDefaultContentByType($messageType));

        // Preparar datos del mensaje
        $messageData = [
            'user_id' => $userId,
            'contact_id' => $contactId,
            'meta_message_id' => $message['id'],
            'direction' => Message::DIRECTION_IN,
            'content' => $content,
            'status' => 'received',
            'message_type' => $messageType,
            'sent_at' => $date,
            'media_url' => $mediaInfo['url'] ?? null,
            'media_path' => $mediaPath,
            'caption' => $caption,
            'media_metadata' => json_encode($metadata)
        ];

        // Insertar o actualizar mensaje usando el servicio de repositorio
        // Indicar que viene de webhook para formatear correctamente la fecha
        $messageId = $this->messageRepositoryService->upsertMessage($messageData, true);

        // Registrar éxito
        Log::info("Mensaje multimedia procesado exitosamente", $messageData);
    }

    /**
     * Maneja las actualizaciones de estado de mensajes
     */
    /**
     * Maneja las actualizaciones de estado de los mensajes
     *
     * @param array $status Los datos del estado del mensaje
     * @return bool Éxito de la operación
     */
    private function handleMessageStatus($status)
    {
        // Extraer información relevante
        $metaMessageId = $status['id'];
        $statusValue = $status['status'];

        // Buscar el mensaje en la base de datos
        $message = Message::where('meta_message_id', $metaMessageId)->first();

        if (!$message) {
            Log::info("Mensaje no encontrado para actualización de estado", [
                'meta_message_id' => $metaMessageId,
                'status' => $statusValue
            ]);
            return false;
        }

        // Determinar si es un mensaje de plantilla basado en la conversación
        $isTemplateMessage = false;
        if (
            isset($status['conversation']['origin']['type']) &&
            $status['conversation']['origin']['type'] === 'utility'
        ) {
            $isTemplateMessage = true;
        }

        // Actualizar solo si es necesario
        if ($message->status !== $statusValue) {

            // Preparar datos para actualización
            $updateData = [
                'status' => $statusValue
            ];
            // Actualizar el mensaje
            $message->update($updateData);

            Log::info("Estado de mensaje actualizado", [
                'meta_message_id' => $metaMessageId,
                'status' => $statusValue,
                'is_template' => $isTemplateMessage
            ]);
        }

        return true;
    }
}
