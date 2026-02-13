<?php

namespace App\Services\Whatsapp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class UltraMSGService
{
    private $token;
    private $instanceId;
    private $baseUrl;

    public function __construct()
    {
        $this->token = config('services.ultramsg.token');
        $this->instanceId = config('services.ultramsg.instance_id');
        $this->baseUrl = "https://api.ultramsg.com/instance{$this->instanceId}";
    }

    /**
     * Enviar mensaje de WhatsApp
     */
    public function sendMessage(
        string $to,
        string $body,
        int $priority = 1,
        string $referenceId = '',
        string $msgId = '',
        string $mentions = ''
    ): array {
        // $to="6643272597";
        try {
            // DEBUG: Analizar el contenido antes de enviar
            Log::info('UltraMsg - DEBUG análisis del mensaje:', [
                'body_original' => $body,
                'body_length' => strlen($body),
                'body_mb_length' => mb_strlen($body, 'UTF-8'),
                'encoding_detected' => mb_detect_encoding($body),
                'encoding_list' => mb_detect_encoding($body, ['UTF-8', 'ISO-8859-1', 'ASCII'], true),
                'contains_amplia' => strpos($body, 'amplía') !== false,
                'amplia_position' => strpos($body, 'amplía'),
                'hex_dump' => bin2hex(substr($body, max(0, strpos($body, 'amplía') - 5), 20))
            ]);

            // SOLUCIÓN 1: Asegurar que el string esté en UTF-8 válido
            $cleanBody = mb_convert_encoding($body, 'UTF-8', 'UTF-8');

            // SOLUCIÓN 2: Si aún hay problemas, intentar con iconv
            if (strpos($cleanBody, '?') !== false && strpos($body, 'í') !== false) {
                $cleanBody = iconv('UTF-8', 'UTF-8//IGNORE', $body);
                Log::info('UltraMsg - Aplicado iconv por caracteres problemáticos');
            }

            $params = [
                'token' => $this->token,
                'to' => $to,
                'body' => $cleanBody,
                'priority' => $priority,
                'referenceId' => $referenceId,
                'msgId' => $msgId,
                'mentions' => $mentions
            ];

            Log::info('UltraMsg - Enviando mensaje', [
                'url' => "{$this->baseUrl}/messages/chat",
                'to' => $to,
                'body_preview' => substr($cleanBody, 0, 100),
                'body_encoding_final' => mb_detect_encoding($cleanBody),
                'priority' => $priority,
                'contains_amplia_final' => strpos($cleanBody, 'amplía') !== false
            ]);

            // INTENTAR PRIMERO CON JSON (mejor para UTF-8)
            $response = Http::timeout(30)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json; charset=utf-8',
                ])
                ->post("{$this->baseUrl}/messages/chat", $params);

            // Si falla con JSON, intentar con form
            if (!$response->successful()) {
                Log::info('UltraMsg - JSON falló, intentando con form-data');

                $response = Http::timeout(30)
                    ->withHeaders([
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8',
                    ])
                    ->asForm()
                    ->post("{$this->baseUrl}/messages/chat", $params);
            }

            Log::info('UltraMsg - Respuesta recibida', [
                'status' => $response->status(),
                'successful' => $response->successful(),
                'response_preview' => substr($response->body(), 0, 200)
            ]);

            if ($response->successful()) {
                $result = $response->json();

                return [
                    'success' => true,
                    'data' => $result,
                    'message' => 'Mensaje enviado correctamente',
                    'status_code' => $response->status(),
                    'raw_response' => $response->body()
                ];
            } else {
                return [
                    'success' => false,
                    'error' => $response->body(),
                    'message' => 'Error al enviar el mensaje',
                    'status_code' => $response->status(),
                    'raw_response' => $response->body()
                ];
            }
        } catch (Exception $e) {
            Log::error('Excepción en UltraMSGService', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'to' => $to
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'message' => 'Error interno al enviar el mensaje',
                'exception' => get_class($e)
            ];
        }
    }

    /**
     * Verificar estado de la instancia
     */
    public function getInstanceStatus(): array
    {
        try {
            $url = "{$this->baseUrl}/instance/status";

            $response = Http::timeout(30)
                ->get($url, [
                    'token' => $this->token
                ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                    'status_code' => $response->status()
                ];
            }

            return [
                'success' => false,
                'error' => $response->body(),
                'status_code' => $response->status()
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ];
        }
    }

    /**
     * Método para debugging
     */
    public function getDebugInfo(): array
    {
        return [
            'token' => $this->token ? substr($this->token, 0, 8) . '...' : 'NOT SET',
            'instance_id' => $this->instanceId ?? 'NOT SET',
            'base_url' => $this->baseUrl,
            'config_token' => config('services.ultramsg.token') ? 'SET' : 'NOT SET',
            'config_instance' => config('services.ultramsg.instance_id') ? 'SET' : 'NOT SET'
        ];
    }



    /**
     * Obtener información de la instancia incluyendo el número de teléfono
     */
    public function getInstanceInfo(): array
    {
        try {
            $url = "{$this->baseUrl}/instance/me";

            $response = Http::timeout(30)
                ->get($url, [
                    'token' => $this->token
                ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                    'status_code' => $response->status()
                ];
            }

            return [
                'success' => false,
                'error' => $response->body(),
                'status_code' => $response->status()
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ];
        }
    }
}