<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use GuzzleHttp\Client;
use App\Models\Message;
use App\Models\User;
use App\Models\Contact;
use App\Services\AblyService;
use App\Services\MetaWhatsAppService;

class WhatsAppWebhookController extends Controller
{
    protected $ablyService;

    public function __construct(AblyService $ablyService)
    {
        $this->ablyService = $ablyService;
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

                // Verificar token en la base de datos
                $isValid = $this->verifyTokenExists($hubVerifyToken);

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
                            continue;
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
                        // Verificar que tenga la estructura esperada
                        if (!isset($message['audio']['id'])) {
                            Log::error("Mensaje de audio sin ID de medio", ['message' => $message]);
                            continue;
                        }

                        $this->handleMediaMessage($phone_number_id, $message, Message::MESSAGE_TYPE_AUDIO);
                        break;

                    case 'image':
                        // Verificar que tenga la estructura esperada
                        if (!isset($message['image']['id'])) {
                            Log::error("Mensaje de imagen sin ID de medio", ['message' => $message]);
                            continue;
                        }

                        $this->handleMediaMessage($phone_number_id, $message, Message::MESSAGE_TYPE_IMAGE);
                        break;

                    case 'video':
                        // Verificar que tenga la estructura esperada
                        if (!isset($message['video']['id'])) {
                            Log::error("Mensaje de video sin ID de medio", ['message' => $message]);
                            continue;
                        }

                        $this->handleMediaMessage($phone_number_id, $message, Message::MESSAGE_TYPE_VIDEO);
                        break;

                    case 'document':
                        // Verificar que tenga la estructura esperada
                        if (!isset($message['document']['id'])) {
                            Log::error("Mensaje de documento sin ID de medio", ['message' => $message]);
                            continue;
                        }

                        $this->handleMediaMessage($phone_number_id, $message, Message::MESSAGE_TYPE_DOCUMENT);
                        break;

                    case 'voice':
                        // Verificar que tenga la estructura esperada
                        if (!isset($message['voice']['id'])) {
                            Log::error("Mensaje de voz sin ID de medio", ['message' => $message]);
                            continue;
                        }

                        $this->handleMediaMessage($phone_number_id, $message, Message::MESSAGE_TYPE_VOICE);
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

                // Procesar el estado
                $this->handleMessageStatus($status);

                // Si es un mensaje de plantilla
                if (
                    isset($status['conversation']['origin']['type']) &&
                    $status['conversation']['origin']['type'] === 'utility'
                ) {
                    Log::info("Procesando mensaje de plantilla", ['status' => $status]);

                    $data = [
                        "meta_message_id" => $status['id'],
                        "content" => 'mensaje automatico del sistema',
                        "sender" => $status['recipient_id'],
                        "timestamp" => $status['timestamp'],
                        "status" => $status['status'],
                        "direction" => Message::DIRECTION_OUT
                    ];

                    $this->handleTextMessage($phone_number_id, $data, Message::MESSAGE_TYPE_TEMPLATE);
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
        $content = isset($data['content']) ? $data['content'] : '[Sin texto]';
        $timestamp = $data['timestamp'];
        $date = Carbon::createFromTimestamp($timestamp);

        // Obtener el ID del usuario basado en el phone_number_id
        $userId = $this->getUserIdByPhoneNumber($phone_number_id);

        // Obtener el ID del contacto
        $contactId = $this->getContactIdByUserIdAndPhone($userId, $sender);

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
            'content' => $content,
            'status' => $data['status'],
            'message_type' => $typeMessage,
            'sent_at' => $date
        ];

        // Insertar o actualizar mensaje
        $messageId = $this->upsertMessage($messageData);

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
        $mediaInfo = $this->getMediaInfo($mediaId, $user);

        if (!$mediaInfo) {
            Log::error("No se pudo obtener información del medio", [
                'media_id' => $mediaId
            ]);
            return;
        }

        // Descargar y guardar el medio
        $mediaPath = $this->downloadAndSaveMedia($mediaInfo, $messageType, $filename, $user);

        if (!$mediaPath) {
            Log::error("Error al descargar y guardar el medio", [
                'media_id' => $mediaId
            ]);
            return;
        }

        // Obtener el ID del usuario basado en el phone_number_id
        $userId = $user->id;

        // Obtener el ID del contacto
        $contactId = $this->getContactIdByUserIdAndPhone($userId, $sender);

        // Si no se encuentra el contacto, registrar el error
        if (!$contactId) {
            Log::error("Contacto no encontrado", [
                'user_id' => $userId,
                'phone' => $sender
            ]);
            return;
        }

        // Preparar contenido descriptivo
        $content = $caption ?: ($filename ?: $this->getDefaultContentByType($messageType));

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

        // Insertar o actualizar mensaje
        $messageId = $this->upsertMessage($messageData);

        // Registrar éxito
        Log::info("Mensaje multimedia procesado exitosamente", [
            'message_id' => $messageId,
            'media_path' => $mediaPath
        ]);
    }

    /**
     * Obtiene información del medio desde la API de Meta
     */
    private function getMediaInfo($mediaId, $user)
    {
        try {
            $client = new Client();
            $url = "https://graph.facebook.com/v20.0/{$mediaId}";

            $response = $client->request('GET', $url, [
                'headers' => [
                    'Authorization' => "Bearer {$user->token_meta}"
                ]
            ]);

            if ($response->getStatusCode() !== 200) {
                Log::error("Error al obtener información del medio", [
                    'media_id' => $mediaId,
                    'status' => $response->getStatusCode()
                ]);
                return null;
            }

            return json_decode($response->getBody(), true);
        } catch (\Exception $e) {
            Log::error("Error al obtener información del medio: " . $e->getMessage(), [
                'media_id' => $mediaId,
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Descarga y guarda el medio en el almacenamiento
     */
    private function downloadAndSaveMedia($mediaInfo, $messageType, $originalFilename, $user)
    {
        try {
            if (!isset($mediaInfo['url'])) {
                Log::error("La información del medio no contiene URL", [
                    'media_info' => $mediaInfo
                ]);
                return null;
            }

            $mediaUrl = $mediaInfo['url'];
            $client = new Client();

            // Descargar contenido del medio
            $response = $client->request('GET', $mediaUrl, [
                'headers' => [
                    'Authorization' => "Bearer {$user->token_meta}"
                ]
            ]);

            if ($response->getStatusCode() !== 200) {
                Log::error("Error al descargar medio", [
                    'url' => $mediaUrl,
                    'status' => $response->getStatusCode()
                ]);
                return null;
            }

            $mediaContent = $response->getBody()->getContents();

            // Determinar extensión y nombre de archivo
            $extension = $this->getExtensionByMessageType($messageType, $originalFilename);
            $filename = $originalFilename ?? uniqid('whatsapp_') . '.' . $extension;

            // Asegurar que el nombre del archivo solo tenga caracteres seguros
            $filename = $this->sanitizeFilename($filename);

            // Determinar carpeta de almacenamiento
            $folder = $this->getFolderByMessageType($messageType);
            $path = "whatsapp/{$folder}/" . date('Y/m/d') . '/' . $filename;

            // Asegurar que la carpeta existe
            $directory = dirname(storage_path('app/public/' . $path));
            if (!file_exists($directory)) {
                mkdir($directory, 0755, true);
            }

            // Guardar archivo en el almacenamiento
            Storage::disk('public')->put($path, $mediaContent);

            Log::info("Medio guardado exitosamente", [
                'path' => $path
            ]);

            return $path;
        } catch (\Exception $e) {
            Log::error("Error al descargar y guardar medio: " . $e->getMessage(), [
                'media_info' => $mediaInfo,
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Sanea el nombre de archivo para evitar problemas de seguridad
     */
    private function sanitizeFilename($filename)
    {
        // Eliminar caracteres no seguros
        $filename = preg_replace('/[^\w\-\.]/', '_', $filename);

        // Asegurar que no haya rutas de directorios
        $filename = basename($filename);

        return $filename;
    }

    /**
     * Obtiene la extensión de archivo basada en el tipo de mensaje
     */
    private function getExtensionByMessageType($messageType, $originalFilename = null)
    {
        // Si tenemos el nombre original, intentar obtener la extensión de allí
        if ($originalFilename) {
            $parts = explode('.', $originalFilename);
            if (count($parts) > 1) {
                return strtolower(end($parts));
            }
        }

        // Asignar extensiones por defecto según el tipo
        switch ($messageType) {
            case Message::MESSAGE_TYPE_AUDIO:
            case Message::MESSAGE_TYPE_VOICE:
                return 'mp3';
            case Message::MESSAGE_TYPE_IMAGE:
                return 'jpg';
            case Message::MESSAGE_TYPE_VIDEO:
                return 'mp4';
            case Message::MESSAGE_TYPE_DOCUMENT:
                return 'pdf';
            default:
                return 'bin';
        }
    }

    /**
     * Obtiene la carpeta de almacenamiento basada en el tipo de mensaje
     */
    private function getFolderByMessageType($messageType)
    {
        switch ($messageType) {
            case Message::MESSAGE_TYPE_AUDIO:
            case Message::MESSAGE_TYPE_VOICE:
                return 'audios';
            case Message::MESSAGE_TYPE_IMAGE:
                return 'images';
            case Message::MESSAGE_TYPE_VIDEO:
                return 'videos';
            case Message::MESSAGE_TYPE_DOCUMENT:
                return 'documents';
            default:
                return 'others';
        }
    }

    /**
     * Obtiene un contenido descriptivo predeterminado según el tipo de mensaje
     */
    private function getDefaultContentByType($messageType)
    {
        switch ($messageType) {
            case Message::MESSAGE_TYPE_AUDIO:
                return 'Mensaje de audio';
            case Message::MESSAGE_TYPE_VOICE:
                return 'Mensaje de voz';
            case Message::MESSAGE_TYPE_IMAGE:
                return 'Imagen';
            case Message::MESSAGE_TYPE_VIDEO:
                return 'Video';
            case Message::MESSAGE_TYPE_DOCUMENT:
                return 'Documento';
            default:
                return 'Mensaje multimedia';
        }
    }

    /**
     * Maneja las actualizaciones de estado de mensajes
     */
    private function handleMessageStatus($status)
    {
        $messageId = $status['id'];
        $statusText = $status['status'];
        $statusTimestamp = Carbon::createFromTimestamp($status['timestamp']);

        // Buscar mensaje por meta_message_id
        $message = Message::where('meta_message_id', $messageId)->first();

        if ($message) {
            // Mapear el estado y actualizar el mensaje
            $internalStatus = $this->mapStatusToInternal($statusText);

            // Utilizar el método updateStatus del modelo
            $message->updateStatus($internalStatus);

            // Notificar actualización de estado a través de Ably
            $this->notifyStatusUpdate($message);

            Log::info("Estado de mensaje actualizado", [
                'meta_message_id' => $messageId,
                'status' => $internalStatus
            ]);
        } else {
            Log::warning("No se encontró el mensaje para actualizar el estado", [
                'meta_message_id' => $messageId
            ]);
        }
    }

    /**
     * Notifica la actualización de estado a través de Ably
     */
    private function notifyStatusUpdate($message)
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
                'message_id' => $message->id,
                'contact_id' => $message->contact_id
            ]);
        } catch (\Exception $e) {
            Log::error("Error al notificar actualización de estado: " . $e->getMessage());
        }
    }

    /**
     * Notifica nuevo mensaje multimedia
     */
    private function notifyNewMediaMessage($message)
    {
        try {
            // Preparar datos del mensaje para el frontend
            $messageData = [
                'id' => $message->id,
                'text' => $message->content,
                'sender' => $message->direction == Message::DIRECTION_OUT ? 'user' : 'other',
                'timestamp' => $message->sent_at,
                'status' => $message->status,
                'type' => $message->message_type,
                'media_url' => $message->media_full_url,
                'caption' => $message->caption
            ];

            // Publicar evento
            $this->ablyService->publish(
                'messages-channel-' . $message->contact_id,
                'new-message',
                $messageData
            );

            Log::info("Notificación de nuevo mensaje multimedia enviada", [
                'message_id' => $message->id,
                'contact_id' => $message->contact_id
            ]);
        } catch (\Exception $e) {
            Log::error("Error al notificar nuevo mensaje multimedia: " . $e->getMessage());
        }
    }

    /**
     * Mapea el estado de la API de Meta al estado interno
     */
    private function mapStatusToInternal($metaStatus)
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
     * Determina el tipo de mensaje basado en el estado
     */
    private function determineMessageType($status)
    {
        return isset($status['marketing']) ? Message::MESSAGE_TYPE_TEMPLATE : Message::MESSAGE_TYPE_TEXT;
    }

    /**
     * Inserta o actualiza un mensaje
     */
    private function upsertMessage($data)
    {
        try {
            // Verificar si el mensaje existe por meta_message_id
            $existingMessage = Message::where('meta_message_id', $data['meta_message_id'])->first();
            $messageId = null;
            $isUpdate = false;
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

            // Notificar al frontend a través de Ably
            if ($messageId) {
                if ($isUpdate) {
                    Log::info("Preparando notificación para actualización de mensaje", [
                        'message_id' => $messageId
                    ]);

                    // Publicar evento de actualización de estado
                    $this->ablyService->publish(
                        'messages-channel-' . $data['contact_id'],
                        'status-update',
                        [
                            'messages' => [
                                [
                                    'id' => $messageId,
                                    'status' => $message->status
                                ]
                            ]
                        ]
                    );
                } else {
                    Log::info("Preparando notificación para creación de mensaje", [
                        'message_id' => $messageId,
                        'message_type' => $data['message_type']
                    ]);

                    // Si es un mensaje multimedia, usar la notificación específica
                    if ($isMedia) {
                        $this->notifyNewMediaMessage($message);
                    } else {
                        // Preparar datos para Ably para mensaje de texto normal
                        $messageData = [
                            'id' => $message->id,
                            'text' => $message->content,
                            'sender' => $message->direction == Message::DIRECTION_OUT ? 'user' : 'other',
                            'timestamp' => $message->sent_at,
                            'status' => $message->status
                        ];

                        // Publicar evento de nuevo mensaje
                        $this->ablyService->publish(
                            'messages-channel-' . $data['contact_id'],
                            'new-message',
                            $messageData
                        );
                    }
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
     * Obtiene ID de usuario por número de teléfono
     */
    private function getUserIdByPhoneNumber($phoneNumber)
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
     * Obtiene ID de contacto por ID de usuario y teléfono
     */
    private function getContactIdByUserIdAndPhone($userId, $phoneNumber)
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
     * Verifica si el token existe en la base de datos
     */
    private function verifyTokenExists($token)
    {
        try {
            return User::where('verify_token', $token)->exists();
        } catch (\Exception $e) {
            Log::error("Error verificando token: " . $e->getMessage());
            return false;
        }
    }
}
