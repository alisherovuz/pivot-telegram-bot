<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
date_default_timezone_set('Asia/Tashkent');
$configFile = getenv('PIVOT_CONFIG') ?: dirname(__DIR__).'/config/local.php';
if (!is_file($configFile)) { throw new RuntimeException('Create private config/local.php using config/example.php.'); }
$config = require $configFile;
foreach (['private','cache'] as $dir) {
    if (!is_dir($config['storage'].'/'.$dir) && !mkdir($config['storage'].'/'.$dir,0700,true)) { throw new RuntimeException('Private storage is not writable.'); }
}
$db = new Pivot\Db($config);
$telegram = $config['telegram_mode']==='fake' ? new Pivot\FakeTelegram() : new Pivot\Telegram($config['bot_token']);
