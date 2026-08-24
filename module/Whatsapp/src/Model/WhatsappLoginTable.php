<?php
declare(strict_types=1);

namespace Whatsapp\Model;

use RuntimeException;
use Laminas\Db\TableGateway\TableGatewayInterface;

class WhatsappLoginTable
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

    public function getLoginBySessionAndScope($sessionId, $scope)
    {
        $rowset = $this->tableGateway->select([
            'session_id' => $sessionId,
            'scope'      => $scope,
        ]);
        // Ideally we'd want to check expires_at > now() and order by updated_at desc
        // We'll return the raw rowset and handle the logic in the service, or write a custom query.
        return $rowset;
    }

    public function getLoginByCode($code)
    {
        return $this->tableGateway->select([
            'code' => $code,
            'is_verified' => 0
        ]);
    }

    public function saveLogin(WhatsappLogin $login)
    {
        $data = [
            'session_id'  => $login->session_id,
            'scope'       => $login->scope,
            'code'        => $login->code,
            'lid'         => $login->lid,
            'is_verified' => $login->is_verified ? 1 : 0,
            'expires_at'  => $login->expires_at,
        ];

        date_default_timezone_set('America/Santiago');
        $now = date('Y-m-d H:i:s');

        $id = (int) $login->id;

        if ($id === 0) {
            $data['created_at'] = $now;
            $data['updated_at'] = $now;
            $this->tableGateway->insert($data);
            return $this->tableGateway->getLastInsertValue();
        }

        try {
            $this->getLogin($id);
        } catch (RuntimeException $e) {
            throw new RuntimeException(sprintf(
                'Cannot update login with identifier %d; does not exist',
                $id
            ));
        }

        $data['updated_at'] = $now;
        $this->tableGateway->update($data, ['id' => $id]);
        return $id;
    }

    public function getLogin($id)
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

    public function cleanExpired()
    {
        date_default_timezone_set('America/Santiago');
        $now = date('Y-m-d H:i:s');
        
        $this->tableGateway->delete([
            'is_verified' => 0,
            new \Laminas\Db\Sql\Predicate\Operator('expires_at', '<', $now)
        ]);
    }
}
