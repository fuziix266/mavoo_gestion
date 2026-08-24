<?php
namespace Mavoot\Model;

use Laminas\Db\TableGateway\TableGatewayInterface;
use Laminas\Db\Sql\Select;

class MavootMessageTable
{
    private $tableGateway;

    public function __construct(TableGatewayInterface $tableGateway)
    {
        $this->tableGateway = $tableGateway;
    }

    public function createMessage($sessionId, $senderType, $text, $status = 'done')
    {
        $data = [
            'session_id'   => $sessionId,
            'sender_type'  => $senderType,
            'message_text' => $text,
            'status'       => $status,
            'created_at'   => date('Y-m-d H:i:s')
        ];
        $this->tableGateway->insert($data);
        return $this->tableGateway->getLastInsertValue();
    }

    public function getLatestResponses($sessionId, $lastMessageId)
    {
        $rowset = $this->tableGateway->select(function(Select $select) use ($sessionId, $lastMessageId) {
            $select->where(['session_id' => $sessionId]);
            // Solo buscar respuestas desde el bot o el humano
            $select->where->in('sender_type', ['mavoot', 'human']);
            $select->where->greaterThan('id', $lastMessageId);
            $select->order('id ASC');
        });

        $messages = [];
        foreach ($rowset as $row) {
            $messages[] = [
                'id' => $row->id,
                'sender' => $row->sender_type,
                'text' => $row->message_text,
                'created_at' => $row->created_at
            ];
        }
        return $messages;
    }
}
