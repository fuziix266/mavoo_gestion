<?php
namespace Mavoot\Model;

use Laminas\Db\TableGateway\TableGatewayInterface;
use Laminas\Db\Sql\Select;

class MavootSessionTable
{
    private $tableGateway;

    public function __construct(TableGatewayInterface $tableGateway)
    {
        $this->tableGateway = $tableGateway;
    }

    public function createSession($userId)
    {
        $data = [
            'user_id' => $userId,
            'status' => 'active',
            'bot_responses_count' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
        $this->tableGateway->insert($data);
        return $this->tableGateway->getLastInsertValue();
    }

    public function getActiveSession($userId)
    {
        $rowset = $this->tableGateway->select(function(Select $select) use ($userId) {
            $select->where([
                'user_id' => $userId,
                'status'  => 'active'
            ]);
            // Filtrar las sesiones que han estado inactivas por más de 10 minutos
            $select->where->greaterThanOrEqualTo('updated_at', date('Y-m-d H:i:s', strtotime('-10 minutes')));
            $select->order('updated_at DESC');
            $select->limit(1);
        });

        $row = $rowset->current();
        return $row ?: null;
    }

    public function updateSessionActivity($sessionId)
    {
        $this->tableGateway->update(
            ['updated_at' => date('Y-m-d H:i:s')],
            ['id' => $sessionId]
        );
    }

    /**
     * Devuelve la sesión por id, o null si no existe.
     */
    public function getSession($sessionId)
    {
        $rowset = $this->tableGateway->select(['id' => $sessionId]);

        return $rowset->current() ?: null;
    }

    /**
     * Incrementa el contador de respuestas del bot y devuelve el nuevo valor.
     */
    public function incrementBotResponses($sessionId): int
    {
        $session = $this->getSession($sessionId);
        $newCount = ((int) ($session->bot_responses_count ?? 0)) + 1;

        $this->tableGateway->update(
            ['bot_responses_count' => $newCount, 'updated_at' => date('Y-m-d H:i:s')],
            ['id' => $sessionId]
        );

        return $newCount;
    }

    /**
     * Cambia el status de la sesión ('active', 'transferred_to_human', 'closed').
     */
    public function updateStatus($sessionId, string $status): void
    {
        $this->tableGateway->update(
            ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')],
            ['id' => $sessionId]
        );
    }
}
