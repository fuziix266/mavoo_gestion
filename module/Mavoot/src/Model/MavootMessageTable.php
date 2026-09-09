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

    /**
     * Mensajes de usuario pendientes de ser procesados por el Worker de IA.
     * Ver documentacion_mavoot/mavoot.md, sección 2.3.
     */
    public function getPendingMessages(int $limit = 20): array
    {
        $rowset = $this->tableGateway->select(function (Select $select) use ($limit) {
            $select->where(['status' => 'pending', 'sender_type' => 'user']);
            $select->order('id ASC');
            $select->limit($limit);
        });

        $messages = [];
        foreach ($rowset as $row) {
            $messages[] = [
                'id' => $row->id,
                'session_id' => $row->session_id,
                'text' => $row->message_text,
                'created_at' => $row->created_at,
            ];
        }

        return $messages;
    }

    public function getMessage($messageId)
    {
        $rowset = $this->tableGateway->select(['id' => $messageId]);

        return $rowset->current() ?: null;
    }

    /**
     * Marca un mensaje como 'processing' y anota qué worker lo reclamó.
     * (El esquema real no tiene columna worker_id dedicada -a diferencia
     * de lo documentado en mavoot.md-, se guarda en `metadata` como JSON.)
     */
    public function claimMessage($messageId, string $workerId): bool
    {
        $message = $this->getMessage($messageId);
        if (! $message || $message->status !== 'pending') {
            return false;
        }

        $this->tableGateway->update(
            [
                'status' => 'processing',
                'metadata' => json_encode(['worker_id' => $workerId, 'claimed_at' => date('c')]),
            ],
            ['id' => $messageId]
        );

        return true;
    }

    /**
     * Historial completo de una sesión, para dar contexto al Worker de IA.
     */
    public function getSessionMessages($sessionId): array
    {
        $rowset = $this->tableGateway->select(function (Select $select) use ($sessionId) {
            $select->where(['session_id' => $sessionId]);
            $select->order('id ASC');
        });

        $messages = [];
        foreach ($rowset as $row) {
            $messages[] = [
                'id' => $row->id,
                'sender' => $row->sender_type,
                'text' => $row->message_text,
                'status' => $row->status,
                'created_at' => $row->created_at,
            ];
        }

        return $messages;
    }

    public function markDone($messageId): void
    {
        $this->tableGateway->update(['status' => 'done'], ['id' => $messageId]);
    }

    /**
     * Mensaje informativo del sistema (límite de respuestas, transferencia a
     * humano, cierre de sesión). No hay un sender_type 'system' documentado;
     * se usa 'mavoot' para que el frontend lo renderice igual que una
     * respuesta del bot.
     */
    public function createSystemMessage($sessionId, string $text)
    {
        return $this->createMessage($sessionId, 'mavoot', $text, 'done');
    }
}
