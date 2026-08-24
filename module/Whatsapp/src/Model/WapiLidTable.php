<?php
declare(strict_types=1);

namespace Whatsapp\Model;

use RuntimeException;
use Laminas\Db\TableGateway\TableGatewayInterface;

class WapiLidTable
{
    private $tableGateway;

    public function __construct(TableGatewayInterface $tableGateway)
    {
        $this->tableGateway = $tableGateway;
    }

    public function getByLid($lid)
    {
        $rowset = $this->tableGateway->select(['lid' => $lid]);
        return $rowset->current();
    }

    public function saveLid(WapiLid $wapiLid)
    {
        $data = [
            'lid'               => $wapiLid->lid,
            'phone'             => $wapiLid->phone,
            'verification_code' => $wapiLid->verification_code,
            'is_verified'       => $wapiLid->is_verified ? 1 : 0,
            'expires_at'        => $wapiLid->expires_at,
        ];

        date_default_timezone_set('America/Santiago');
        $now = date('Y-m-d H:i:s');

        $id = (int) $wapiLid->id;

        if ($id === 0) {
            $data['created_at'] = $now;
            $data['updated_at'] = $now;
            
            // Upsert logic could be needed here depending on DB engine.
            // For now let's just insert
            $this->tableGateway->insert($data);
            return $this->tableGateway->getLastInsertValue();
        }

        try {
            $this->getLidById($id);
        } catch (RuntimeException $e) {
            throw new RuntimeException(sprintf(
                'Cannot update lid with identifier %d; does not exist',
                $id
            ));
        }

        $data['updated_at'] = $now;
        $this->tableGateway->update($data, ['id' => $id]);
        return $id;
    }

    public function getLidById($id)
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
}
