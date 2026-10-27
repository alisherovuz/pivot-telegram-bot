<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit(1);
$errors=[];
if(PHP_VERSION_ID<80300) $errors[]='PHP 8.3 or newer required';
foreach(['pdo_mysql','curl','mbstring','fileinfo','dom','xml','xmlreader','xmlwriter','simplexml','gd','zip','iconv'] as $ext) if(!extension_loaded($ext)) $errors[]='Missing PHP extension: '.$ext;
try {
    require dirname(__DIR__).'/app/bootstrap.php';
    $db->value('SELECT COUNT(*) FROM applications'); $db->value('SELECT COUNT(*) FROM updates');
    foreach(['private','cache'] as $folder) if(!is_writable($config['storage'].'/'.$folder)) $errors[]='Storage is not writable: '.$folder;
    $public=realpath($argv[1]??dirname(__DIR__).'/public'); $storage=realpath($config['storage']);
    if(!$public || !$storage) $errors[]='Pass the actual document root as the first argument';
    elseif(str_starts_with($storage.'/',$public.'/') || str_starts_with(realpath(dirname(__DIR__).'/config').'/',$public.'/')) $errors[]='Configuration and storage must be outside the document root';
    if($config['telegram_mode']==='live') {
        if(!$config['session_secure']) $errors[]='Enable session_secure for production';
        if(!str_starts_with($config['base_url'],'https://')) $errors[]='Production base_url must use HTTPS';
        if(!preg_match('/^[A-Za-z0-9_-]{32,256}$/D',$config['webhook_secret']) || str_contains($config['webhook_secret'],'REPLACE')) $errors[]='Configure a random webhook secret';
        if(!preg_match('/^[0-9]+:[A-Za-z0-9_-]{20,}$/D',$config['bot_token'])) $errors[]='Configure the bot token privately';
    }
    echo 'Database reachable; transport mode: '.$config['telegram_mode']."\n";
} catch(Throwable) { $errors[]='Configuration/database check failed; inspect private settings and schema'; }
foreach($errors as $error) fwrite(STDERR,$error."\n");
if($errors) exit(1);
echo "Preflight passed. No Telegram API requests made.\n";
