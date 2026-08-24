<?php
declare(strict_types=1);

namespace Whatsapp\Model;

use RuntimeException;
use Laminas\Db\TableGateway\TableGatewayInterface;

class WapiChatTable
{
    private $tableGateway;

    public function __construct(TableGatewayInterface $tableGateway)
    {
        $this->tableGateway = $tableGateway;
    }

    public function fetchAll()
    {
        return $this->tableGateway->select();
    }

    public function saveChat(WapiChat $chat)
    {
        $data = [
            'wa_id'        => $chat->wa_id,
            'token'        => $chat->token,
            'payload'      => is_array($chat->payload) ? json_encode($chat->payload) : $chat->payload,
            'media_url'    => $chat->media_url,
            'received_at'  => $chat->received_at,
            'delivered_at' => $chat->delivered_at,
            'read_at'      => $chat->read_at,
        ];

        date_default_timezone_set('America/Santiago');
        $now = date('Y-m-d H:i:s');

        $id = (int) $chat->id;

        if ($id === 0) {
            $data['created_at'] = $now;
            $data['updated_at'] = $now;
            $this->tableGateway->insert($data);
            return $this->tableGateway->getLastInsertValue();
        }

        try {
            $this->getChat($id);
        } catch (RuntimeException $e) {
            throw new RuntimeException(sprintf(
                'Cannot update chat with identifier %d; does not exist',
                $id
            ));
        }

        $data['updated_at'] = $now;
        $this->tableGateway->update($data, ['id' => $id]);
        return $id;
    }

    public function getChat($id)
    {
        $id = (int) $id;
        $rowset = $this->tableGateway->select(['id' => $id]);
        $row = $rowset->current();
        if (! $row) {
            throw new RuntimeException(sprintf(
                'Could not find row with identifier %d',
                $id
            ));
        }

        return $row;
    }
    
    public function updateMessageStatus($wa_id, $statusType, $statusData)
    {
        date_default_timezone_set('America/Santiago');
        $now = date('Y-m-d H:i:s');
        
        $data = ['updated_at' => $now];
        
        if ($statusType === 'DELIVERED') {
            $data['delivered_at'] = $statusData['datetime'] ?? $now;
        } elseif ($statusType === 'READ') {
            $data['read_at'] = $statusData['datetime'] ?? $now;
        }
        
        $this->tableGateway->update($data, ['wa_id' => $wa_id]);
    }
}
