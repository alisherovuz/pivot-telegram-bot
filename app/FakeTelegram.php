<?php
declare(strict_types=1);
namespace Pivot;
// No HTTP calls. Used only by automated tests and explicitly configured local development.
final class FakeTelegram implements TelegramTransport {
    public string $membership='member';
    public bool $restrictedMember=false;
    public ?TelegramException $failure=null;
    public array $calls=[];
    public function call(string $method,array $data): array {
        $this->calls[]=[$method,$data];
        if ($this->failure) throw $this->failure;
        if ($method==='getChatMember') return ['status'=>$this->membership,'is_member'=>$this->restrictedMember];
        return ['message_id'=>count($this->calls)];
    }
    public function download(string $fileId,string $destination): int { if($this->failure) throw $this->failure; $data="Fake private attachment: ".$fileId; file_put_contents($destination,$data); chmod($destination,0600); return strlen($data); }
}
