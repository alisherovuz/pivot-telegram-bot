<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit(1);
require dirname(__DIR__).'/app/bootstrap.php';
if(($argv[1]??'')!=='register' && ($argv[1]??'')!=='info') { fwrite(STDERR,"Usage: php bin/webhook.php register|info\nRegister changes Telegram configuration; run only after deployment approval.\n"); exit(1); }
if($config['telegram_mode']!=='live') { fwrite(STDERR,"Refusing webhook administration in fake mode.\n"); exit(1); }
if(!preg_match('~^https://[^/]+(?:/.*)?$~D',$config['base_url'])||!preg_match('/^[A-Za-z0-9_-]{32,256}$/D',$config['webhook_secret'])) { fwrite(STDERR,"Configure HTTPS base_url and a 32–256 character webhook secret.\n"); exit(1); }
try {
    if($argv[1]==='register') { $telegram->call('setWebhook',['url'=>rtrim($config['base_url'],'/').'/webhook.php','secret_token'=>$config['webhook_secret'],'allowed_updates'=>['message','callback_query'],'drop_pending_updates'=>false,'max_connections'=>10]); echo "Webhook registered.\n"; }
    else { $r=$telegram->call('getWebhookInfo',[]); echo 'Pending updates: '.($r['pending_update_count']??0)."\n"; echo isset($r['last_error_date'])?"Telegram reports a webhook error; inspect deployment HTTPS and logs.\n":"No webhook error reported.\n"; }
} catch(Pivot\TelegramException $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
