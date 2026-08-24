<?php
declare(strict_types=1);

namespace Whatsapp\Model;

class WapiLid
{
    public $id;
    public $lid;
    public $phone;
    public $verification_code;
    public $is_verified;
    public $expires_at;
    public $created_at;
    public $updated_at;

    public function exchangeArray(array $data)
    {
        $this->id                = !empty($data['id']) ? $data['id'] : null;
        $this->lid               = !empty($data['lid']) ? $data['lid'] : null;
        $this->phone             = !empty($data['phone']) ? $data['phone'] : null;
        $this->verification_code = !empty($data['verification_code']) ? $data['verification_code'] : null;
        $this->is_verified       = isset($data['is_verified']) ? (bool) $data['is_verified'] : false;
        $this->expires_at        = !empty($data['expires_at']) ? $data['expires_at'] : null;
        $this->created_at        = !empty($data['created_at']) ? $data['created_at'] : null;
        $this->updated_at        = !empty($data['updated_at']) ? $data['updated_at'] : null;
    }

    public function getArrayCopy()
    {
        return get_object_vars($this);
    }
}
