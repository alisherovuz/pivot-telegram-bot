<?php
declare(strict_types=1);
// Intercept PHP cURL functions in the transport namespace: no network calls.
namespace Pivot {
    function curl_init(string $url): object { if(!str_starts_with($url,'https://api.telegram.org/botTEST_FAKE_ONLY/')) throw new \RuntimeException('Fake token only');return new \stdClass(); }
    function curl_setopt_array(object $handle,array $options): bool { $GLOBALS['options']=$options;return true; }
    function curl_exec(object $handle): string { return json_encode($GLOBALS['response'],JSON_THROW_ON_ERROR); }
    function curl_getinfo(object $handle,int $option): int { return $GLOBALS['response']['error_code']??200; }
    function curl_close(object $handle): void {}
}
namespace {
    require dirname(__DIR__).'/vendor/autoload.php';
    $checks=0;
    function verify(bool $ok,string $name): void {global $checks;if(!$ok)throw new RuntimeException('FAIL '.$name);$checks++;echo 'PASS '.$name."\n";}
    $telegram=new Pivot\Telegram('TEST_FAKE_ONLY');
    $response=['ok'=>false,'error_code'=>400,'description'=>'Bad Request: message is not modified'];
    $result=$telegram->call('editMessageText',['chat_id'=>123,'message_id'=>456,'text'=>'same']);
    verify($result['message_id']===456,'unchanged menu is successful without duplicate fallback');
    verify(is_string($options[CURLOPT_POSTFIELDS]),'menu updates use JSON transport');
    $response=['ok'=>false,'error_code'=>400,'description'=>'Bad Request: message to edit not found'];
    try {$telegram->call('editMessageText',['chat_id'=>123,'message_id'=>456,'text'=>'new']);throw new RuntimeException('Expected exception');}
    catch(Pivot\TelegramException $e) {verify($e->apiCode===400,'missing menu remains distinguishable for fallback');}
    $response=['ok'=>false,'error_code'=>429,'parameters'=>['retry_after'=>60]];
    try {$telegram->call('editMessageText',['chat_id'=>123,'message_id'=>456,'text'=>'new']);throw new RuntimeException('Expected exception');}
    catch(Pivot\TelegramException $e) {verify($e->apiCode===429 && $e->retryAfter===60,'transport preserves rate-limit delay');}
    $response=['ok'=>true,'result'=>['message_id'=>789]];
    $telegram->call('sendDocument',['chat_id'=>123,'document'=>new CURLFile(__FILE__,'text/plain','test.txt')]);
    verify($options[CURLOPT_POSTFIELDS]['document'] instanceof CURLFile && $options[CURLOPT_HTTPHEADER]===[],'documents retain multipart upload support');
    echo "\n$checks transport assertions passed. No HTTP calls.\n";
}
