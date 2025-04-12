<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use App\Models\Message;
use App\Models\User;
use App\Models\Contact;
use App\Services\AblyService;

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
            Log::error('Error al procesar webhook de WhatsApp: ' . $e->getMessage());
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

        // Procesar mensajes de texto
        if (isset($value['messages'])) {
            foreach ($value['messages'] as $message) {
                if ($message['type'] === 'text') {
                    $data = [
                        "meta_message_id" => $message['id'],
                        "sender" => $message['from'],
                        "content" => $message['text']['body'],
                        "timestamp" => $message['timestamp'],
                        "status" => "received",
                        "direction" => Message::DIRECTION_IN
                    ];
                    $this->handleTextMessage($phone_number_id, $data, Message::MESSAGE_TYPE_TEXT);
                }
            }
        }

        // Procesar mensajes de plantillas
        if (isset($value['statuses'])) {
            foreach ($value['statuses'] as $status) {
                Log::info("Procesando mensaje de estado", ['status' => $status]);

                $data = [
                    "meta_message_id" => $status['id'],
                    "content" => 'mensaje automatico del sistema',
                    "sender" => $status['recipient_id'],
                    "timestamp" => $status['timestamp'],
                    "status" => $status['status'],
                    "direction" => Message::DIRECTION_OUT
                ];

                // Verificar si es de tipo utilidad
                if (
                    isset($status['conversation']['origin']['type']) &&
                    $status['conversation']['origin']['type'] === 'utility'
                ) {
                    $this->handleTextMessage($phone_number_id, $data, Message::MESSAGE_TYPE_TEMPLATE);
                }
            }
        }

        // Procesar cambios de estado
        if (isset($value['statuses'])) {
            foreach ($value['statuses'] as $status) {
                $this->handleMessageStatus($status);
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
        $this->upsertMessage($messageData);

        // Registrar éxito
        Log::info("Mensaje de texto procesado exitosamente", [
            'message_data' => $messageData
        ]);
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

            if ($existingMessage) {
                // Actualizar mensaje existente
                $existingMessage->status = isset($data['status']) ? $data['status'] : 'received';
                $existingMessage->save();

                $messageId = $existingMessage->id;
                $isUpdate = true;
            } else {
                // Crear nuevo mensaje
                $message = Message::create($data);
                $messageId = $message->id;
            }

            // Notificar al frontend a través de Ably
            if ($messageId) {
                if ($isUpdate) {
                    Log::info("Preparando llamada a webhook para actualización de mensaje", [
                        'message_id' => $messageId
                    ]);

                    // Obtener mensaje actualizado
                    $message = Message::find($messageId);

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
                    Log::info("Preparando llamada a webhook para creación de mensaje", [
                        'message_id' => $messageId
                    ]);

                    // Obtener mensaje creado
                    $message = Message::find($messageId);

                    // Preparar datos para Ably
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

            return true;
        } catch (\Exception $e) {
            Log::error("Error en upsertMessage: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return false;
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
