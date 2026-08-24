<?php
declare(strict_types=1);

namespace Whatsapp\Service;

use Laminas\Http\Client;

class MetaApiService
{
    private $token;
    private $apiUrl;
    
    public function __construct(array $config)
    {
        // Valores por defecto extraídos del proyecto Laravel, deberían pasarse por config
        $this->token  = $config['whatsapp']['meta']['token'] ?? 'EAAJ6c2Qg...';
        $this->apiUrl = $config['whatsapp']['meta']['api_url'] ?? 'https://graph.facebook.com/v13.0/825165380690232/messages';
    }

    public function sendTemplate(string $to, string $templateName, array $components = [], string $language = 'es_CL'): array
    {
        $client = new Client($this->apiUrl);
        $client->setOptions(['timeout' => 10]);
        $client->setHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Content-Type'  => 'application/json',
        ]);
        
        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $to,
            'type'              => 'template',
            'template'          => [
                'name'     => $templateName,
                'language' => ['code' => $language],
            ]
        ];

        if (!empty($components)) {
            $payload['template']['components'] = $components;
        }

        $client->setMethod('POST');
        $client->setRawBody(json_encode($payload));

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
