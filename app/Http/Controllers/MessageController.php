<?php
// app/Http/Controllers/MessageController.php
namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\Contact;
use App\Services\MetaWhatsAppService;
use App\Services\MessageService;
use App\Services\NotificationService;
use App\Services\MessageRepositoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\MediaService;
use Illuminate\Support\Facades\Storage;

class MessageController extends Controller
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

    public function getMessages($contactId, Request $request)
    {
        try {
            // Obtener parámetros de paginación
            $page = $request->input('page', 1);
            $limit = $request->input('limit', 20);
            $user = auth()->user();
            // Definir los tipos permitidos
            $allowedTypes = $user->permission != "" ? explode(',', $user->permission) : [
                Message::MESSAGE_TYPE_TEXT
            ];

            // Obtener los mensajes ordenados por id descendente (más recientes primero)
            $messagesQuery = Message::where('contact_id', $contactId)
                ->whereIn('message_type', $allowedTypes)
                ->orderBy('id', 'desc');

            // Aplicar paginación
            $offset = ($page - 1) * $limit;
            $messages = $messagesQuery->skip($offset)->take($limit)->get();

            // Reordenar los mensajes para que aparezcan en orden cronológico
            $messages = $messages->sortBy('id');

            // Formatear usando el servicio - manteniendo añadir 6 horas
            $formattedMessages = $this->messageService->formatMessageCollection($messages, true);

            // Total de mensajes permitidos para determinar si hay más
            $totalMessages = Message::where('contact_id', $contactId)
                ->whereIn('message_type', $allowedTypes)
                ->count();

            return response()->json([
                'success' => true,
                'data' => $formattedMessages,
                'message' => $messages->isEmpty() ? 'No hay mensajes disponibles' : 'Mensajes encontrados',
                'total' => $totalMessages,
                'page' => $page,
                'limit' => $limit,
                'has_more' => ($offset + $limit) < $totalMessages
            ]);
        } catch (\Exception $e) {
            Log::error('Error al obtener mensajes: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener mensajes',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function send(Request $request)
    {
        $request->validate([
            'contact_id' => 'required|exists:contacts,id',
            'content' => 'required|string|max:4096',
        ]);

        $user = auth()->user();
        $phoneNumber = $user->phone_number;
        $tokenMeta = $user->token_meta;

        try {
            // Obtener el contacto para obtener el número de teléfono
            $contact = Contact::findOrFail($request->contact_id);

            // Intentar enviar el mensaje a través del servicio de Meta
            $metaService = new MetaWhatsAppService($phoneNumber, $tokenMeta);
            $metaResponse = $metaService->sendMessage($contact->phone_number, $request->content);

            // Crear el mensaje con el estado inicial
            $messageData = [
                'user_id' => $user->id,
                'contact_id' => $request->contact_id,
                'content' => $request->content,
                'direction' => Message::DIRECTION_OUT,
                'status' => Message::STATUS_SENT,
                'meta_message_id' => $metaResponse->messages[0]->id ?? null,
                'message_type' => Message::MESSAGE_TYPE_TEXT,
                'sent_at' => now()
            ];

            // Usar el servicio para crear el mensaje
            $messageId = $this->messageRepositoryService->upsertMessage($messageData);
            $message = Message::find($messageId);

            // Formatear la respuesta - con 6 horas adicionales
            $messageData = $this->messageService->formatMessage($message, true);

            return response()->json([
                'success' => true,
                'data' => $messageData,
                'message' => 'Mensaje enviado exitosamente'
            ]);
        } catch (\Exception $e) {
            // Manejar cualquier error en el envío
            Log::error('Error al enviar mensaje: ' . $e->getMessage());

            // Si el envío falla, crear un mensaje con estado de error
            $failedMessageData = [
                'user_id' => $user->id,
                'contact_id' => $request->contact_id,
                'content' => $request->content,
                'direction' => Message::DIRECTION_OUT,
                'status' => Message::STATUS_FAILED,
                'sent_at' => now()
            ];

            $failedMessageId = $this->messageRepositoryService->upsertMessage($failedMessageData);
            $failedMessage = Message::find($failedMessageId);

            // Formatear la respuesta de error
            $messageData = $this->messageService->formatMessage($failedMessage, true);

            return response()->json([
                'success' => false,
                'data' => $messageData,
                'message' => 'Error al enviar mensaje',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function sendFile(Request $request)
    {
        $request->validate([
            'contact_id' => 'required|exists:contacts,id',
            'file' => 'required|file|max:16384', // 16MB máximo
            'type' => 'required|string|in:image,video,audio,document,voice',
            'caption' => 'nullable|string|max:1024',
        ]);

        $user = auth()->user();
        $phoneNumber = $user->phone_number;
        $tokenMeta = $user->token_meta;

        try {
            // Obtener el contacto
            $contact = Contact::findOrFail($request->contact_id);

            // Guardar el archivo temporalmente
            $file = $request->file('file');
            $originalFilename = $file->getClientOriginalName();
            $filePath = $file->storeAs('temp', $originalFilename, 'public');
            $fullPath = Storage::disk('public')->path($filePath);

            // Determinar el tipo de mensaje
            $messageType = $this->getMessageTypeFromFileType($request->type);

            // Inicializar el servicio Meta
            $metaService = new MetaWhatsAppService($phoneNumber, $tokenMeta);

            // Seleccionar el método apropiado según el tipo de archivo
            $metaResponse = null;
            switch ($request->type) {
                case 'image':
                    $metaResponse = $metaService->sendImage(
                        $contact->phone_number,
                        $fullPath,
                        $request->caption
                    );
                    break;

                case 'video':
                    $metaResponse = $metaService->sendVideo(
                        $contact->phone_number,
                        $fullPath,
                        $request->caption
                    );
                    break;

                case 'audio':
                case 'voice':
                    $metaResponse = $metaService->sendAudio(
                        $contact->phone_number,
                        $fullPath
                    );
                    break;

                case 'document':
                    $metaResponse = $metaService->sendDocument(
                        $contact->phone_number,
                        $fullPath,
                        $originalFilename,
                        $request->caption
                    );
                    break;
            }

            // Guardar el archivo permanentemente usando la misma lógica del webhook
            // Reemplazar con la llamada a tu servicio mediaService existente
            $mediaPath = $this->mediaService->saveOutgoingMedia(
                $file,
                $messageType,
                $originalFilename,
                $user
            );

            // Determinar URL pública del archivo guardado
            $mediaUrl = url(Storage::url($mediaPath));

            // Preparar contenido descriptivo (similar al webhook)
            $content = $request->caption ?: ($originalFilename ?: $this->mediaService->getDefaultContentByType($messageType));

            // Crear metadata básica
            $metadata = [
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'filename' => $originalFilename
            ];

            // Crear el mensaje con el estado inicial
            $messageData = [
                'user_id' => $user->id,
                'contact_id' => $request->contact_id,
                'content' => $content,
                'direction' => Message::DIRECTION_OUT,
                'status' => Message::STATUS_SENT,
                'meta_message_id' => $metaResponse->messages[0]->id ?? null,
                'message_type' => $messageType,
                'sent_at' => now(),
                'media_url' => $mediaUrl,
                'media_path' => $mediaPath,
                'caption' => $request->caption,
                'media_metadata' => json_encode($metadata)
            ];

            // Usar el servicio para crear el mensaje
            $messageId = $this->messageRepositoryService->upsertMessage($messageData);
            $message = Message::find($messageId);

            // Eliminar el archivo temporal
            Storage::disk('public')->delete($filePath);

            // Formatear la respuesta
            $messageData = $this->messageService->formatMessage($message, true);

            return response()->json([
                'success' => true,
                'data' => $messageData,
                'message' => 'Archivo enviado exitosamente'
            ]);
        } catch (\Exception $e) {
            // Manejar cualquier error en el envío
            Log::error('Error al enviar archivo: ' . $e->getMessage());

            // Intentar eliminar el archivo temporal si existe
            if (isset($filePath) && Storage::disk('public')->exists($filePath)) {
                Storage::disk('public')->delete($filePath);
            }

            // Si el envío falla, crear un mensaje con estado de error
            $failedMessageData = [
                'user_id' => $user->id,
                'contact_id' => $request->contact_id,
                'content' => $request->caption ?? $originalFilename ?? '',
                'direction' => Message::DIRECTION_OUT,
                'status' => Message::STATUS_FAILED,
                'message_type' => isset($messageType) ? $messageType : Message::MESSAGE_TYPE_TEXT,
                'sent_at' => now()
            ];

            $failedMessageId = $this->messageRepositoryService->upsertMessage($failedMessageData);
            $failedMessage = Message::find($failedMessageId);

            // Formatear la respuesta de error
            $messageData = $this->messageService->formatMessage($failedMessage, true);

            return response()->json([
                'success' => false,
                'data' => $messageData,
                'message' => 'Error al enviar archivo',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    private function getMessageTypeFromFileType($fileType)
    {
        $types = [
            'image' => Message::MESSAGE_TYPE_IMAGE,
            'video' => Message::MESSAGE_TYPE_VIDEO,
            'audio' => Message::MESSAGE_TYPE_AUDIO,
            'voice' => Message::MESSAGE_TYPE_VOICE,
            'document' => Message::MESSAGE_TYPE_DOCUMENT,
        ];

        return $types[$fileType] ?? Message::MESSAGE_TYPE_TEXT;
    }
    public function updateMessageStatus($contactId)
    {
        // Actualizar los mensajes usando el servicio
        $updatedCount = $this->messageRepositoryService->updateBulkMessageStatus($contactId, 'read');

        return response()->json([
            'message' => 'Mensajes actualizados',
            'updated_count' => $updatedCount
        ]);
    }

    /**
     * Manejar la notificación de un nuevo mensaje creado por el ERP
     */
    public function handleERPMessageCreated(Request $request)
    {
        try {
            // Validar la solicitud
            $request->validate([
                'message_id' => 'required|integer',
                'secret_key' => 'required|string'
            ]);

            // Verificar clave secreta para mayor seguridad
            if ($request->secret_key !== config('app.erp_webhook_secret')) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }
            Log::info('ejecutando: handleERPMessageCreated');

            // Buscar el mensaje
            $message = Message::find($request->message_id);
            if (!$message) {
                return response()->json(['success' => false, 'message' => 'Message not found'], 404);
            }

            Log::info('mensaje encontrado: handleERPMessageCreated');

            // Usar el servicio especializado para notificaciones ERP
            $this->notificationService->notifyERPMessage($message);

            Log::info('abky ejecutado: handleERPMessageCreated messages-channel-' . $message->contact_id);

            return response()->json(['success' => true, 'message' => 'Notification sent']);
        } catch (\Exception $e) {
            Log::error('Error en webhook ERP (mensaje creado): ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Manejar la notificación de actualización de estado de un mensaje por el ERP
     */
    public function handleERPMessageUpdated(Request $request)
    {
        try {

            Log::info('Se ejecuto handleERPMessageUpdated');
            // Validar la solicitud
            $request->validate([
                'message_id' => 'required|integer',
                'secret_key' => 'required|string'
            ]);

            // Verificar clave secreta
            if ($request->secret_key !== config('app.erp_webhook_secret')) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            // Buscar el mensaje
            $message = Message::find($request->message_id);
            if (!$message) {
                return response()->json(['success' => false, 'message' => 'Message not found'], 404);
            }

            // Usar el servicio para notificar la actualización
            $this->notificationService->notifyStatusUpdate($message);

            return response()->json(['success' => true, 'message' => 'Status update notification sent']);
        } catch (\Exception $e) {
            Log::error('Error en webhook ERP (mensaje actualizado): ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function getBatchInitialMessages(Request $request)
    {
        try {
            // Obtener parámetros
            $limit = $request->input('limit', 20);

            // Obtener el usuario autenticado
            $user = auth()->user();

            // Obtener todos los contactos del usuario
            $contacts = Contact::where('user_id', $user->id)->get();

            // Preparar el resultado
            $result = [];

            // Para cada contacto, obtener sus mensajes más recientes
            foreach ($contacts as $contact) {
                // Obtener mensajes ordenados por id descendente (más recientes primero)
                $messagesQuery = Message::where('contact_id', $contact->id)
                    ->orderBy('id', 'desc')
                    ->limit($limit)
                    ->get();

                // Reordenar los mensajes para que aparezcan en orden cronológico
                $messages = $messagesQuery->sortBy('id');

                // Formatear mensajes usando el servicio - manteniendo añadir 6 horas
                $formattedMessages = $this->messageService->formatMessageCollection($messages, true);

                // Añadir al resultado usando el ID del contacto como clave
                $result[$contact->id] = $formattedMessages;
            }

            return response()->json([
                'success' => true,
                'data' => $result
            ]);
        } catch (\Exception $e) {
            Log::error('Error al obtener mensajes en lote: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener mensajes en lote',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Endpoint para generar token de Ably para el frontend
     */
    public function getAblyToken()
    {
        try {
            // Obtener el usuario autenticado
            $user = auth()->user();

            // Generar token usando clientId del usuario (opcional, pero recomendado)
            // El clientId puede ayudar a rastrar quién está usando el token
            $clientId = "user-{$user->id}";

            // Generar el token request con el clientId
            $tokenRequest = $this->notificationService->getAblyService()->generateToken($clientId);

            // Registrar información para depuración
            Log::debug('Token Ably generado correctamente', [
                'user_id' => $user->id,
                'client_id' => $clientId
            ]);

            // Devolver el token request completo sin modificar
            return response()->json($tokenRequest);
        } catch (\Exception $e) {
            Log::error('Error al generar token de Ably: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'error' => 'Error al generar token',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
