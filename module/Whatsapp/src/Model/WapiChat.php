<?php
declare(strict_types=1);

namespace Whatsapp\Model;

class WapiChat
{
    public $id;
    public $wa_id;
    public $token;
    public $payload;
    public $media_url;
    public $received_at;
    public $delivered_at;
    public $read_at;
    public $created_at;
    public $updated_at;

    public function exchangeArray(array $data)
    {
        $this->id           = !empty($data['id']) ? $data['id'] : null;
        $this->wa_id        = !empty($data['wa_id']) ? $data['wa_id'] : null;
        $this->token        = !empty($data['token']) ? $data['token'] : null;
        $this->payload      = !empty($data['payload']) ? $data['payload'] : null;
        $this->media_url    = !empty($data['media_url']) ? $data['media_url'] : null;
        $this->received_at  = !empty($data['received_at']) ? $data['received_at'] : null;
        $this->delivered_at = !empty($data['delivered_at']) ? $data['delivered_at'] : null;
        $this->read_at      = !empty($data['read_at']) ? $data['read_at'] : null;
        $this->created_at   = !empty($data['created_at']) ? $data['created_at'] : null;
        $this->updated_at   = !empty($data['updated_at']) ? $data['updated_at'] : null;
    }

    public function getArrayCopy()
    {
        return get_object_vars($this);
    }
}
