<?php
// app/Http/Controllers/MessageController.php
namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\Contact;
use App\Services\MetaWhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\AblyService;
use Illuminate\Support\Facades\Log;

class MessageController extends Controller
{
    protected $ablyService;

    public function __construct(AblyService $ablyService)
    {
        $this->ablyService = $ablyService;
    }
    public function getMessages($contactId, Request $request)
    {
        try {
            // Obtener parámetros de paginación
            $page = $request->input('page', 1);
            $limit = $request->input('limit', 20);

            // Obtener los mensajes ordenados por id descendente (más recientes primero)
            $messagesQuery = Message::where('contact_id', $contactId)
                ->orderBy('id', 'desc');

            // Aplicar paginación
            $offset = ($page - 1) * $limit;
            $messages = $messagesQuery->skip($offset)->take($limit)->get();

            // Reordenar los mensajes para que aparezcan en orden cronológico
            $messages = $messages->sortBy('id');

            // Resto del código para formatear y devolver los mensajes...
            $formattedMessages = $messages->map(function ($message) {
                return [
                    'id' => $message->id,
                    'text' => $message->content,
                    'sender' => $message->direction == "out" ? 'user' : 'other',
                    'timestamp' => $message->created_at ? $message->created_at->addHours(6)->toIso8601String() : null,
                    'status' => $message->status
                ];
            })->values()->all();

            // Total de mensajes para determinar si hay más
            $totalMessages = Message::where('contact_id', $contactId)->count();

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
            $message = Message::create([
                'user_id' => $user->id,
                'contact_id' => $request->contact_id,
                'content' => $request->content,
                'direction' => Message::DIRECTION_OUT,
                'status' => Message::STATUS_SENT,
                'meta_message_id' => $metaResponse->messages[0]->id ?? null,
                'message_type' => Message::MESSAGE_TYPE_TEXT,
                'sent_at' => now()
            ]);

            $messageData = [
                'id' => $message->id,
                'text' => $message->content,
                'sender' => 'user',
                'timestamp' => $message->created_at->addHours(6)->toIso8601String(),
                'status' => $message->status
            ];

            // Publicar evento de nuevo mensaje en Ably
            $this->ablyService->publish(
                'messages-channel-' . $request->contact_id,
                'new-message',
                $messageData
            );

            return response()->json([
                'success' => true,
                'data' => $messageData,
                'message' => 'Mensaje enviado exitosamente'
            ]);
        } catch (\Exception $e) {
            // Manejar cualquier error en el envío
            Log::error('Error al enviar mensaje: ' . $e->getMessage());

            // Si el envío falla, crear un mensaje con estado de error
            $failedMessage = Message::create([
                'user_id' => $user->id,
                'contact_id' => $request->contact_id,
                'content' => $request->content,
                'direction' => Message::DIRECTION_OUT,
                'status' => Message::STATUS_FAILED,
                'sent_at' => now()
            ]);

            return response()->json([
                'success' => false,
                'data' => [
                    'id' => $failedMessage->id,
                    'text' => $failedMessage->content,
                    'sender' => 'user',
                    'timestamp' => $failedMessage->created_at->toIso8601String(),
                    'status' => $failedMessage->status
                ],
                'message' => 'Error al enviar mensaje',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updateMessageStatus($contactId)
    {
        // Actualiza todos los mensajes del contacto estableciendo un estado predeterminado
        $updatedMessages = Message::where('contact_id', $contactId)
            ->update([
                'status' => 'read'
            ]);

        // Obtener los mensajes actualizados para enviarlos por Ably
        $messages = Message::where('contact_id', $contactId)
            ->get()
            ->map(function ($message) {
                return [
                    'id' => $message->id,
                    'status' => $message->status
                ];
            });

        // Publicar evento de actualización de estado en Ably
        $this->ablyService->publish(
            'messages-channel-' . $contactId,
            'status-update',
            [
                'contact_id' => $contactId,
                'messages' => $messages
            ]
        );

        return response()->json([
            'message' => 'Mensajes actualizados',
            'updated_count' => $updatedMessages
        ]);
    }
    // Nuevos métodos para los webhooks del ERP

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
            Log::error('ejecutando: handleERPMessageCreated');

            // Buscar el mensaje
            $message = Message::find($request->message_id);
            if (!$message) {
                return response()->json(['success' => false, 'message' => 'Message not found'], 404);
            }

            Log::error('mensaje encontrado: handleERPMessageCreated');
            // Preparar los datos
            $messageData = [
                'id' => $message->id,
                'text' => $message->content,
                'sender' => $message->direction == "out" ? 'user' : 'other',
                'timestamp' => $message->created_at ? $message->created_at->addHours(6)->toIso8601String() : null,
                'status' => $message->status
            ];
            Log::error('mensaje fecha: handleERPMessageCreated '.($message->created_at ? $message->created_at->addHours(6)->toIso8601String() : null));

            // Publicar en Ably
            $this->ablyService->publish(
                'messages-channel-' . $message->contact_id,
                'new-message',
                $messageData
            );
            Log::error('abky ejecutado: handleERPMessageCreated messages-channel-'. $message->contact_id);

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

            Log::error('Se ejecuto handleERPMessageUpdated');
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

            // Publicar en Ably
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
            // Exactamente como en getMessages
            $messagesQuery = Message::where('contact_id', $contact->id)
                ->orderBy('id', 'desc')
                ->limit($limit)
                ->get();

            // Reordenar los mensajes para que aparezcan en orden cronológico
            // El sortBy genera una colección ordenada ascendentemente
            $messages = $messagesQuery->sortBy('id');

            // Formatear los mensajes igual que en getMessages
            $formattedMessages = $messages->map(function ($message) {
                return [
                    'id' => $message->id,
                    'text' => $message->content,
                    'sender' => $message->direction == "out" ? 'user' : 'other',
                    'timestamp' => $message->created_at ? $message->created_at->addHours(6)->toIso8601String() : null,
                    'status' => $message->status
                ];
            })->values()->all();

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
            $tokenRequest = $this->ablyService->generateToken($clientId);

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
