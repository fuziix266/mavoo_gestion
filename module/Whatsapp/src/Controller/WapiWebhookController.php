<?php
declare(strict_types=1);

namespace Whatsapp\Controller;

use Laminas\Mvc\Controller\AbstractRestfulController;
use Laminas\View\Model\JsonModel;
use Laminas\EventManager\EventManagerInterface;
use Whatsapp\Model\WapiChatTable;

class WapiWebhookController extends AbstractRestfulController
{
    private $wapiChatTable;

    public function __construct(WapiChatTable $wapiChatTable)
    {
        $this->wapiChatTable = $wapiChatTable;
    }

    /**
     * Endpoint: /api/whatsapp/baileys/webhook (POST)
     * Recibe actualizaciones del servidor Node.js Baileys.
     */
    public function handleAction()
    {
        $request = $this->getRequest();
        if (!$request->isPost()) {
            return new JsonModel(['error' => 'Method not allowed']);
        }

        $data = json_decode($request->getContent(), true);

        // Simulamos la estructura enviada por `wapi.cuack.org`
        $event = $data['event'] ?? '';
        $eventData = $data['data'] ?? [];

        $eventManager = $this->getEvent()->getApplication()->getEventManager();

        switch ($event) {
            case 'messages.upsert':
                $this->handleMessageUpsert($eventData, $eventManager);
                break;
            case 'messages.update':
                $this->handleMessageUpdate($eventData, $eventManager);
                break;
            default:
                // Ignorar otros eventos o procesarlos si es necesario
                break;
        }

        return new JsonModel(['status' => 'success']);
    }

    private function handleMessageUpsert(array $data, EventManagerInterface $eventManager)
    {
        $messages = $data['messages'] ?? [];
        
        foreach ($messages as $msg) {
            // Guardar en tabla
            $chat = new \Whatsapp\Model\WapiChat();
            $chat->wa_id = $msg['key']['id'] ?? '';
            $chat->payload = $msg;
            $chat->received_at = date('Y-m-d H:i:s');
            
            $this->wapiChatTable->saveChat($chat);
            
            // Emitir evento
            $eventManager->trigger('baileys.message.received', $this, ['message' => $msg]);
        }
    }

    private function handleMessageUpdate(array $data, EventManagerInterface $eventManager)
    {
        foreach ($data as $update) {
            $wa_id = $update['key']['id'] ?? '';
            $status = $update['update']['status'] ?? null;
            
            if ($wa_id && $status !== null) {
                $statusType = '';
                if ($status == 3) $statusType = 'DELIVERED';
                if ($status == 4) $statusType = 'READ';
                
                if ($statusType) {
                    $this->wapiChatTable->updateMessageStatus($wa_id, $statusType, ['datetime' => date('Y-m-d H:i:s')]);
                }
            }
            
            // Emitir evento
            $eventManager->trigger('baileys.message.status', $this, ['update' => $update]);
        }
    }
}
