<?php
// app/Services/AblyService.php
namespace App\Services;

use Ably\AblyRest;
use Illuminate\Support\Facades\Log;

class AblyService
{
    protected $ably;

    public function __construct()
    {
        try {
            $this->ably = new AblyRest(config('ably.key'));
        } catch (\Exception $e) {
            Log::error('Error al inicializar Ably: ' . $e->getMessage());
            throw $e;
        }
    }

    public function getClient()
    {
        return $this->ably;
    }

    public function publish($channel, $event, $data)
    {
        try {
            $channel = $this->ably->channels->get($channel);
            Log::error('publicar en Ably: ' . $channel->publish($event, $data));
            return $channel->publish($event, $data);
        } catch (\Exception $e) {
            Log::error('Error al publicar en Ably: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Genera un token de Ably para el cliente
     *
     * @param string|null $clientId ID del cliente (opcional)
     * @return array Token request en formato adecuado para Ably Realtime en JS
     */
    public function generateToken($clientId = null)
    {
        try {
            // Crear opciones con capacidades explícitas
            $options = [
                'capability' => [
                    '*' => ['publish', 'subscribe', 'presence'] // Permiso para todos los canales
                ],
                'ttl' => 3600 * 1000 // 1 hora en milisegundos
            ];

            // Solo agregar clientId si se proporciona explícitamente
            if ($clientId) {
                $options['clientId'] = $clientId;
            }

            // Crear token request con las opciones
            $tokenRequest = $this->ably->auth->createTokenRequest($options);

            // Registrar detalles del token para depuración
            Log::debug('Token Ably generado con éxito', [
                'tokenDetails' => json_encode($tokenRequest),
                'clientId' => $clientId
            ]);

            return $tokenRequest;
        } catch (\Exception $e) {
            Log::error('Error al generar token Ably: ' . $e->getMessage(), [
                'clientId' => $clientId,
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}
