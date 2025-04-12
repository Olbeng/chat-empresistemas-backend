<?php
// app/Services/MetaWhatsAppService.php
namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use GuzzleHttp\Client;

class MetaWhatsAppService
{
    protected $client;
    protected $apiUrl;
    protected $token;
    protected $phoneNumberId;

    public function __construct($phoneNumber, $tokenMeta)
    {
        $this->client = new Client();
        $this->apiUrl = "https://graph.facebook.com/v20.0/" . $phoneNumber;
        $this->token = $tokenMeta;
        $this->phoneNumberId = $phoneNumber;
    }

    /**
     * Envía un mensaje de texto a WhatsApp
     */
    public function sendMessage($phone, $message)
    {
        try {
            $response = $this->client->post($this->apiUrl . '/messages', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token,
                    'Content-Type' => 'application/json'
                ],
                'json' => [
                    'messaging_product' => 'whatsapp',
                    'to' => $phone,
                    'type' => 'text',
                    'text' => ['body' => $message]
                ]
            ]);

            // Obtener el contenido de la respuesta
            $responseBody = $response->getBody()->getContents();

            // Registrar la respuesta completa
            Log::channel('meta_messages')->info('Meta API Response', [
                'phone' => $phone,
                'message' => $message,
                'response' => $responseBody,
                'timestamp' => now()
            ]);

            // Parsear y devolver la respuesta
            return json_decode($responseBody);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            // Registrar errores de solicitud
            Log::channel('meta_messages')->error('Meta API Error', [
                'phone' => $phone,
                'message' => $message,
                'error' => $e->getMessage(),
                'response' => $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : null,
                'timestamp' => now()
            ]);
            throw $e;
        }
    }

    /**
     * Envía una imagen a WhatsApp
     *
     * @param string $phone Número de teléfono del destinatario
     * @param string $imagePath Ruta al archivo de imagen (local o URL)
     * @param string $caption Descripción opcional para la imagen
     * @return object Respuesta de la API
     */
    public function sendImage($phone, $imagePath, $caption = null)
    {
        return $this->sendMedia($phone, $imagePath, 'image', $caption);
    }

    /**
     * Envía un documento a WhatsApp
     *
     * @param string $phone Número de teléfono del destinatario
     * @param string $documentPath Ruta al archivo de documento (local o URL)
     * @param string $filename Nombre del archivo a mostrar
     * @param string $caption Descripción opcional para el documento
     * @return object Respuesta de la API
     */
    public function sendDocument($phone, $documentPath, $filename = null, $caption = null)
    {
        return $this->sendMedia($phone, $documentPath, 'document', $caption, $filename);
    }

    /**
     * Envía un audio a WhatsApp
     *
     * @param string $phone Número de teléfono del destinatario
     * @param string $audioPath Ruta al archivo de audio (local o URL)
     * @return object Respuesta de la API
     */
    public function sendAudio($phone, $audioPath)
    {
        return $this->sendMedia($phone, $audioPath, 'audio');
    }

    /**
     * Envía un video a WhatsApp
     *
     * @param string $phone Número de teléfono del destinatario
     * @param string $videoPath Ruta al archivo de video (local o URL)
     * @param string $caption Descripción opcional para el video
     * @return object Respuesta de la API
     */
    public function sendVideo($phone, $videoPath, $caption = null)
    {
        return $this->sendMedia($phone, $videoPath, 'video', $caption);
    }

    /**
     * Envía contenido multimedia a WhatsApp
     *
     * @param string $phone Número de teléfono del destinatario
     * @param string $mediaPath Ruta al archivo multimedia (local o URL)
     * @param string $mediaType Tipo de medio: 'image', 'document', 'audio', 'video'
     * @param string $caption Descripción opcional para imágenes y videos
     * @param string $filename Nombre de archivo opcional para documentos
     * @return object Respuesta de la API
     */
    protected function sendMedia($phone, $mediaPath, $mediaType, $caption = null, $filename = null)
    {
        try {
            // Determinar si es una URL o una ruta local
            $isUrl = filter_var($mediaPath, FILTER_VALIDATE_URL) !== false;

            // Preparar el payload según el tipo de medio
            $payload = [
                'messaging_product' => 'whatsapp',
                'to' => $phone,
                'type' => $mediaType,
                $mediaType => []
            ];

            // Si es una URL, usar directamente
            if ($isUrl) {
                $payload[$mediaType]['link'] = $mediaPath;
            } else {
                // Si es ruta local, primero subir a WhatsApp Media API
                $mediaId = $this->uploadMedia($mediaPath, $mediaType);
                if (!$mediaId) {
                    throw new \Exception("No se pudo subir el archivo multimedia");
                }
                $payload[$mediaType]['id'] = $mediaId;
            }

            // Añadir propiedades adicionales según el tipo
            if (in_array($mediaType, ['image', 'video', 'document']) && $caption) {
                $payload[$mediaType]['caption'] = $caption;
            }

            if ($mediaType === 'document' && $filename) {
                $payload[$mediaType]['filename'] = $filename;
            }

            // Enviar la solicitud
            $response = $this->client->post($this->apiUrl . '/messages', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token,
                    'Content-Type' => 'application/json'
                ],
                'json' => $payload
            ]);

            // Procesar la respuesta
            $responseBody = $response->getBody()->getContents();

            Log::channel('meta_messages')->info('Meta API Media Response', [
                'phone' => $phone,
                'media_type' => $mediaType,
                'response' => $responseBody,
                'timestamp' => now()
            ]);

            return json_decode($responseBody);
        } catch (\Exception $e) {
            Log::channel('meta_messages')->error('Meta API Media Error', [
                'phone' => $phone,
                'media_type' => $mediaType,
                'media_path' => $mediaPath,
                'error' => $e->getMessage(),
                'response' => $e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse()
                    ? $e->getResponse()->getBody()->getContents()
                    : null,
                'timestamp' => now()
            ]);
            throw $e;
        }
    }

    /**
     * Sube un archivo multimedia a WhatsApp Media API
     *
     * @param string $mediaPath Ruta local al archivo multimedia
     * @param string $mediaType Tipo de medio: 'image', 'document', 'audio', 'video'
     * @return string|null ID del medio subido o null si falla
     */
    protected function uploadMedia($mediaPath, $mediaType)
    {
        try {
            if (!file_exists($mediaPath)) {
                // Verificar si está en el almacenamiento de Laravel
                if (Storage::exists($mediaPath)) {
                    $mediaPath = Storage::path($mediaPath);
                } else {
                    throw new \Exception("El archivo no existe: $mediaPath");
                }
            }

            // Determinar el tipo MIME basado en la extensión del archivo
            $mimeType = $this->getMimeTypeByPath($mediaPath, $mediaType);

            // Crear la solicitud para subir el medio
            $response = $this->client->post('https://graph.facebook.com/v20.0/' . $this->phoneNumberId . '/media', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token
                ],
                'multipart' => [
                    [
                        'name' => 'file',
                        'contents' => fopen($mediaPath, 'r'),
                        'filename' => basename($mediaPath),
                        'headers' => [
                            'Content-Type' => $mimeType
                        ]
                    ],
                    [
                        'name' => 'messaging_product',
                        'contents' => 'whatsapp'
                    ],
                    [
                        'name' => 'type',
                        'contents' => $mimeType
                    ]
                ]
            ]);

            $responseBody = json_decode($response->getBody()->getContents(), true);

            Log::channel('meta_messages')->info('Media Upload Response', [
                'media_path' => $mediaPath,
                'media_type' => $mediaType,
                'response' => $responseBody,
                'timestamp' => now()
            ]);

            return $responseBody['id'] ?? null;
        } catch (\Exception $e) {
            Log::channel('meta_messages')->error('Media Upload Error', [
                'media_path' => $mediaPath,
                'media_type' => $mediaType,
                'error' => $e->getMessage(),
                'timestamp' => now()
            ]);
            return null;
        }
    }

    /**
     * Determina el tipo MIME basado en la extensión del archivo
     *
     * @param string $path Ruta al archivo
     * @param string $mediaType Tipo de medio para ayudar a determinar el MIME
     * @return string Tipo MIME
     */
    protected function getMimeTypeByPath($path, $mediaType)
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $mimeTypes = [
            // Imágenes
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',

            // Documentos
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'txt' => 'text/plain',

            // Audio
            'mp3' => 'audio/mpeg',
            'ogg' => 'audio/ogg',
            'wav' => 'audio/wav',
            'aac' => 'audio/aac',

            // Video
            'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
            'avi' => 'video/x-msvideo',
            'webm' => 'video/webm'
        ];

        // Si la extensión existe en nuestro array, devolver el MIME
        if (isset($mimeTypes[$extension])) {
            return $mimeTypes[$extension];
        }

        // Si no, usar el MIME por defecto según el tipo de medio
        $defaultMimes = [
            'image' => 'image/jpeg',
            'document' => 'application/pdf',
            'audio' => 'audio/mpeg',
            'video' => 'video/mp4'
        ];

        return $defaultMimes[$mediaType] ?? 'application/octet-stream';
    }

    /**
     * Envía un mensaje de plantilla a WhatsApp
     *
     * @param string $phone Número de teléfono del destinatario
     * @param string $templateName Nombre de la plantilla
     * @param array $components Componentes de la plantilla (header, body, buttons)
     * @param string $language Código de idioma (default: es_MX)
     * @return object Respuesta de la API
     */
    public function sendTemplate($phone, $templateName, $components = [], $language = 'es_MX')
    {
        try {
            $payload = [
                'messaging_product' => 'whatsapp',
                'to' => $phone,
                'type' => 'template',
                'template' => [
                    'name' => $templateName,
                    'language' => [
                        'code' => $language
                    ]
                ]
            ];

            // Añadir componentes si existen
            if (!empty($components)) {
                $payload['template']['components'] = $components;
            }

            $response = $this->client->post($this->apiUrl . '/messages', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token,
                    'Content-Type' => 'application/json'
                ],
                'json' => $payload
            ]);

            $responseBody = $response->getBody()->getContents();

            Log::channel('meta_messages')->info('Template Message Response', [
                'phone' => $phone,
                'template' => $templateName,
                'response' => $responseBody,
                'timestamp' => now()
            ]);

            return json_decode($responseBody);
        } catch (\Exception $e) {
            Log::channel('meta_messages')->error('Template Message Error', [
                'phone' => $phone,
                'template' => $templateName,
                'error' => $e->getMessage(),
                'response' => $e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse()
                    ? $e->getResponse()->getBody()->getContents()
                    : null,
                'timestamp' => now()
            ]);
            throw $e;
        }
    }
}
