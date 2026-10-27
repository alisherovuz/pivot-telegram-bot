<?php
declare(strict_types=1);
ini_set('display_errors','0');
try {
    $file=getenv('PIVOT_CONFIG')?:(require __DIR__.'/paths.php').'/config/local.php';
    $c=require $file;
    if($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); exit; }
    $secret=$c['webhook_secret']??'';
    if(strlen($secret)<32 || !hash_equals($secret,$_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']??'')) { http_response_code(403); exit; }
    $raw=file_get_contents('php://input',false,null,0,1048577);
    if(strlen($raw)>1048576) { http_response_code(413); exit; }
    $update=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
    if(!is_array($update)) { http_response_code(400); exit; }
    require (require __DIR__.'/paths.php').'/app/bootstrap.php';
    (new Pivot\Bot($db,$telegram,$config))->ingest($update);
    header('Content-Type: application/json'); echo '{"ok":true}';
} catch (JsonException|InvalidArgumentException) { http_response_code(400); }
catch (Throwable) { error_log('Pivot webhook persistence failed; inspect configuration/database.'); http_response_code(503); }
