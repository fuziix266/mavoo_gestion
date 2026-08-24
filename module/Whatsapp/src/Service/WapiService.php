<?php
declare(strict_types=1);

namespace Whatsapp\Service;

use Laminas\Http\Client;

class WapiService
{
    private $token;
    private $apiUrl;

    public function __construct(array $config)
    {
        $this->token = $config['whatsapp']['wapi']['token'] ?? 'f0nCRhQfdurQcTZLdvU02bvqVk6gXq-56954286572';
        $this->apiUrl = rtrim($config['whatsapp']['wapi']['api_url'] ?? 'http://localhost:3001', '/');
    }

    public function sendText(string $destino, string $texto): array
    {
        $client = new Client($this->apiUrl . '/send-message');
        $client->setOptions([
            'timeout' => 10,
        ]);
        
        $client->setHeaders([
            'X-Wapi-Token' => $this->token,
            'Content-Type' => 'application/json',
        ]);
        
        $client->setMethod('POST');
        $client->setRawBody(json_encode([
            'number'  => $destino,
            'message' => $texto,
        ]));

        try {
            $response = $client->send();
            if ($response->isSuccess()) {
                return json_decode($response->getBody(), true) ?? ['status' => 'success', 'raw' => $response->getBody()];
            } else {
                return [
                    'status' => 'error',
                    'code'   => $response->getStatusCode(),
                    'error'  => $response->getReasonPhrase(),
                    'body'   => $response->getBody(),
                ];
            }
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'error'  => $e->getMessage()
            ];
        }
    }
}
