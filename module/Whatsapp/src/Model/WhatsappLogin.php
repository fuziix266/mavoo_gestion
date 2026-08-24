<?php
declare(strict_types=1);

namespace Whatsapp\Model;

class WhatsappLogin
{
    public $id;
    public $session_id;
    public $scope;
    public $code;
    public $lid;
    public $is_verified;
    public $expires_at;
    public $created_at;
    public $updated_at;

    public function exchangeArray(array $data)
    {
        $this->id          = !empty($data['id']) ? $data['id'] : null;
        $this->session_id  = !empty($data['session_id']) ? $data['session_id'] : null;
        $this->scope       = !empty($data['scope']) ? $data['scope'] : null;
        $this->code        = !empty($data['code']) ? $data['code'] : null;
        $this->lid         = !empty($data['lid']) ? $data['lid'] : null;
        $this->is_verified = isset($data['is_verified']) ? (bool) $data['is_verified'] : false;
        $this->expires_at  = !empty($data['expires_at']) ? $data['expires_at'] : null;
        $this->created_at  = !empty($data['created_at']) ? $data['created_at'] : null;
        $this->updated_at  = !empty($data['updated_at']) ? $data['updated_at'] : null;
    }

    public function getArrayCopy()
    {
        return get_object_vars($this);
    }
}
