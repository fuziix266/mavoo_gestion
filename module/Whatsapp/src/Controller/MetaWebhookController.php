<?php
declare(strict_types=1);

namespace Whatsapp\Controller;

use Laminas\Mvc\Controller\AbstractRestfulController;
use Laminas\View\Model\JsonModel;
use Laminas\EventManager\EventManagerInterface;
use Whatsapp\Model\ApiWhatsappTable;

class MetaWebhookController extends AbstractRestfulController
{
    private $apiWhatsappTable;

    public function __construct(ApiWhatsappTable $apiWhatsappTable)
    {
        $this->apiWhatsappTable = $apiWhatsappTable;
    }

    /**
     * Endpoint: /api/whatsapp/meta/webhook (GET)
     * Verificación del Webhook por parte de Meta.
     */
    public function verifyAction()
    {
        $request = $this->getRequest();
        $hubMode = $request->getQuery('hub_mode');
        $hubVerifyToken = $request->getQuery('hub_verify_token');
        $hubChallenge = $request->getQuery('hub_challenge');

        $verifyToken = 'ESTES_ES_MI_TOCKEN_SECRETO_LAMINAS_123'; // Reemplazar via config

        if ($hubMode === 'subscribe' && $hubVerifyToken === $verifyToken) {
            $response = $this->getResponse();
            $response->setContent($hubChallenge);
            return $response;
        }

        return new JsonModel(['status' => 'error', 'message' => 'Verification failed']);
    }

    /**
     * Endpoint: /api/whatsapp/meta/webhook (POST)
     * Recibe notificaciones y respuestas de Meta.
     */
    public function handleAction()
    {
        $request = $this->getRequest();
        $data = json_decode($request->getContent(), true);

        if (empty($data['entry'])) {
            return new JsonModel(['status' => 'error', 'message' => 'Invalid payload']);
        }

        // Obtener el EventManager global pidiéndolo a través del ServiceManager o MvcEvent
        $eventManager = $this->getEvent()->getApplication()->getEventManager();

        foreach ($data['entry'] as $entry) {
            foreach ($entry['changes'] as $change) {
                $value = $change['value'] ?? [];
                
                // Procesar mensajes (user to app)
                if (isset($value['messages'])) {
                    foreach ($value['messages'] as $message) {
                        $this->saveToLog($message, 'message');
                        
                        // Emitir un evento para que los demás módulos (ej: Ligas) puedan responder
                        $eventManager->trigger('whatsapp.message.received', $this, [
                            'message' => $message,
                            'contact' => $value['contacts'][0] ?? null
                        ]);
                    }
                }
                
                // Procesar estatus (app to user, entregado/leído)
                if (isset($value['statuses'])) {
                    foreach ($value['statuses'] as $status) {
                        $this->saveToLog($status, 'status');
                        
                        $eventManager->trigger('whatsapp.message.status', $this, [
                            'status' => $status
                        ]);
                    }
                }
            }
        }

        // Meta requiere una respuesta 200 OK rápida
        return new JsonModel(['status' => 'success']);
    }

    private function saveToLog(array $payload, string $tipo)
    {
        $apiWhatsapp = new \Whatsapp\Model\ApiWhatsapp();
        $apiWhatsapp->tipo = $tipo;
        $apiWhatsapp->raw1 = $payload; // Guardar payload crudo JSON
        
        if ($tipo === 'message') {
            $apiWhatsapp->data1 = $payload['from'] ?? null;
            $apiWhatsapp->data2 = $payload['type'] ?? null;
            
            // Extraer texto dependiendo del tipo
            if ($apiWhatsapp->data2 === 'text') {
                $apiWhatsapp->data3 = $payload['text']['body'] ?? null;
            } elseif ($apiWhatsapp->data2 === 'interactive') {
                $apiWhatsapp->data3 = $payload['interactive']['list_reply']['id'] ?? $payload['interactive']['button_reply']['id'] ?? null;
            }
        }

        $this->apiWhatsappTable->saveApiWhatsapp($apiWhatsapp);
    }
}
