<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Models\Message;
use App\Models\User;

class MediaService
{
    /**
     * Obtiene información del medio desde la API de Meta
     */
    public function getMediaInfo($mediaId, $user)
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
                'media_id' => $mediaId
            ]);
            return null;
        }
    }

    /**
     * Descarga y guarda el medio en el almacenamiento
     */
    public function downloadAndSaveMedia($mediaInfo, $messageType, $originalFilename, $user)
    {
        try {
            if (!isset($mediaInfo['url'])) {
                Log::error("La información del medio no contiene URL");
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

            $mediaContent = $response->getBody()->getContents();

            // Determinar extensión y nombre de archivo
            $extension = $this->getExtensionByMessageType($messageType, $originalFilename);
            $filename = $originalFilename ?? uniqid('whatsapp_') . '.' . $extension;
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

            return $path;
        } catch (\Exception $e) {
            Log::error("Error al descargar y guardar medio: " . $e->getMessage());
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
    public function getDefaultContentByType($messageType)
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
     * Guarda un archivo multimedia saliente (enviado) en el almacenamiento
     *
     * @param UploadedFile $file Archivo cargado
     * @param string $messageType Tipo de mensaje
     * @param string|null $originalFilename Nombre original del archivo
     * @param User $user Usuario que envía el archivo
     * @return string Ruta relativa donde se guardó el archivo
     */
    public function saveOutgoingMedia($file, $messageType, $originalFilename = null, $user = null)
    {
        try {
            // Determinar extensión y nombre de archivo
            $extension = $this->getExtensionByMessageType($messageType, $originalFilename);
            $filename = $originalFilename ?? uniqid('whatsapp_') . '.' . $extension;
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
            Storage::disk('public')->putFileAs(
                dirname($path),
                $file,
                basename($path)
            );

            return $path;
        } catch (\Exception $e) {
            Log::error('Error al guardar archivo multimedia saliente', [
                'error' => $e->getMessage(),
                'messageType' => $messageType,
                'filename' => $originalFilename
            ]);

            return null;
        }
    }
}
