<?php
declare(strict_types=1);
namespace Pivot;
final class Telegram implements TelegramTransport
{
    public const MAX_FILE = 20 * 1024 * 1024;
    public function __construct(private string $token) {}
    public function call(string $method, array $data): array {
        $ch=curl_init('https://api.telegram.org/bot'.$this->token.'/'.$method);
        $multipart=($data['document']??null) instanceof \CURLFile;
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$multipart?$data:json_encode($data,JSON_THROW_ON_ERROR),CURLOPT_HTTPHEADER=>$multipart?[]:['Content-Type: application/json'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>$multipart?45:20,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
        $raw=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
        if ($raw===false) { throw new TelegramException(503); }
        $r=json_decode($raw,true);
        // Repeated taps and retries can ask Telegram to apply the same menu twice.
        if($method==='editMessageText' && is_array($r) && ($r['error_code']??0)===400 && str_contains(strtolower((string)($r['description']??'')),'message is not modified')) return ['message_id'=>$data['message_id']??null];
        if (!is_array($r) || empty($r['ok'])) { throw new TelegramException((int)($r['error_code']??$code), (int)($r['parameters']['retry_after']??0)); }
        return is_array($r['result']??null)?$r['result']:['ok'=>(bool)($r['result']??false)];
    }
    public function download(string $fileId, string $destination): int {
        $file=$this->call('getFile',['file_id'=>$fileId]);
        if (($file['file_size']??0)>self::MAX_FILE) { throw new \LengthException('File exceeds 20 MB'); }
        $path=$file['file_path']??'';
        if (!preg_match('~^[a-zA-Z0-9_./-]+$~D',$path) || str_contains($path,'..')) { throw new TelegramException(502); }
        $out=fopen($destination,'xb'); if (!$out) { throw new \RuntimeException('Cannot create private file'); }
        chmod($destination,0600); $bytes=0;
        $ch=curl_init('https://api.telegram.org/file/bot'.$this->token.'/'.$path);
        curl_setopt_array($ch,[CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>30,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk) use($out,&$bytes): int {
            $bytes+=strlen($chunk); if ($bytes>self::MAX_FILE) return 0; return fwrite($out,$chunk);
        }]);
        $ok=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch); fclose($out);
        if ($ok===false || $code!==200 || $bytes===0) { unlink($destination); if($bytes>self::MAX_FILE) throw new \LengthException('File exceeds 20 MB'); throw new TelegramException(503); }
        return $bytes;
    }
}
