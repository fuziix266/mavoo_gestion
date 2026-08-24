<?php
declare(strict_types=1);

namespace Whatsapp\Model;

use RuntimeException;
use Laminas\Db\TableGateway\TableGatewayInterface;

class ApiWhatsappTable
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

    public function saveApiWhatsapp(ApiWhatsapp $apiWhatsapp)
    {
        $data = [
            'tipo'   => $apiWhatsapp->tipo,
            'data1'  => $apiWhatsapp->data1,
            'data2'  => $apiWhatsapp->data2,
            'data3'  => $apiWhatsapp->data3,
            'data4'  => $apiWhatsapp->data4,
            'data5'  => $apiWhatsapp->data5,
            'data6'  => $apiWhatsapp->data6,
            'data7'  => $apiWhatsapp->data7,
            'data8'  => $apiWhatsapp->data8,
            'data9'  => $apiWhatsapp->data9,
            'data10' => $apiWhatsapp->data10,
            'raw1'   => is_array($apiWhatsapp->raw1) ? json_encode($apiWhatsapp->raw1) : $apiWhatsapp->raw1,
            'raw2'   => is_array($apiWhatsapp->raw2) ? json_encode($apiWhatsapp->raw2) : $apiWhatsapp->raw2,
            'raw3'   => is_array($apiWhatsapp->raw3) ? json_encode($apiWhatsapp->raw3) : $apiWhatsapp->raw3,
        ];

        date_default_timezone_set('America/Santiago');
        $now = date('Y-m-d H:i:s');

        $id = (int) $apiWhatsapp->id;

        if ($id === 0) {
            $data['created_at'] = $now;
            $data['updated_at'] = $now;
            $this->tableGateway->insert($data);
            return $this->tableGateway->getLastInsertValue();
        }

        try {
            $this->getApiWhatsapp($id);
        } catch (RuntimeException $e) {
            throw new RuntimeException(sprintf(
                'Cannot update api_whatsapp with identifier %d; does not exist',
                $id
            ));
        }

        $data['updated_at'] = $now;
        $this->tableGateway->update($data, ['id' => $id]);
        return $id;
    }

    public function getApiWhatsapp($id)
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
