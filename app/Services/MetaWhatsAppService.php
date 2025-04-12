<?php
// app/Services/MetaWhatsAppService.php
namespace App\Services;
use Illuminate\Support\Facades\Log;

class MetaWhatsAppService
{
    protected $client;
    protected $apiUrl;
    protected $token;

    public function __construct($phoneNumber, $tokenMeta)
    {
        $this->client = new \GuzzleHttp\Client();
        $this->apiUrl = "https://graph.facebook.com/v20.0/" . $phoneNumber;
        $this->token = $tokenMeta;
    }

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
}
