<?php
declare(strict_types=1);

namespace Whatsapp\Model;

class ApiWhatsapp
{
    public $id;
    public $tipo;
    public $data1;
    public $data2;
    public $data3;
    public $data4;
    public $data5;
    public $data6;
    public $data7;
    public $data8;
    public $data9;
    public $data10;
    public $raw1;
    public $raw2;
    public $raw3;
    public $created_at;
    public $updated_at;

    public function exchangeArray(array $data)
    {
        $this->id         = !empty($data['id']) ? $data['id'] : null;
        $this->tipo       = !empty($data['tipo']) ? $data['tipo'] : null;
        $this->data1      = !empty($data['data1']) ? $data['data1'] : null;
        $this->data2      = !empty($data['data2']) ? $data['data2'] : null;
        $this->data3      = !empty($data['data3']) ? $data['data3'] : null;
        $this->data4      = !empty($data['data4']) ? $data['data4'] : null;
        $this->data5      = !empty($data['data5']) ? $data['data5'] : null;
        $this->data6      = !empty($data['data6']) ? $data['data6'] : null;
        $this->data7      = !empty($data['data7']) ? $data['data7'] : null;
        $this->data8      = !empty($data['data8']) ? $data['data8'] : null;
        $this->data9      = !empty($data['data9']) ? $data['data9'] : null;
        $this->data10     = !empty($data['data10']) ? $data['data10'] : null;
        $this->raw1       = !empty($data['raw1']) ? $data['raw1'] : null;
        $this->raw2       = !empty($data['raw2']) ? $data['raw2'] : null;
        $this->raw3       = !empty($data['raw3']) ? $data['raw3'] : null;
        $this->created_at = !empty($data['created_at']) ? $data['created_at'] : null;
        $this->updated_at = !empty($data['updated_at']) ? $data['updated_at'] : null;
    }

    public function getArrayCopy()
    {
        return get_object_vars($this);
    }
}
