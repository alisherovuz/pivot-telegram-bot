<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') { http_response_code(404); exit; }
ini_set('display_errors','0');
require dirname(__DIR__).'/app/bootstrap.php';
$operation=$argv[1]??''; $id=$argv[2]??'';
if(!in_array($operation,['link','revoke'],true) || !preg_match('/^[1-9][0-9]{0,14}$/D',$id)) {
    fwrite(STDERR,"Usage: php bin/telegram-admin.php link TELEGRAM_ID ADMIN_EMAIL\n       php bin/telegram-admin.php revoke TELEGRAM_ID\nGet your ID by sending /whoami to this bot in a private chat. Never supply a token or password here.\n"); exit(1);
}
try {
    $db->pdo->beginTransaction();
    $key='telegram_admin:'.$id;
    if($operation==='revoke') {
        $db->run('DELETE FROM settings WHERE `key` IN (?,?)',[$key,'telegram_confirm:'.$id]);
        $db->audit(null,null,'telegram_admin_revoked','Telegram ID '.$id);
    } else {
        $email=mb_strtolower(trim($argv[3]??''));
        $account=$db->one('SELECT id FROM admins WHERE email=? AND active=1',[$email]);
        if(!$account) throw new InvalidArgumentException('An active website admin with that email was not found.');
        if(!$db->one('SELECT id FROM users WHERE id=?',[$id])) throw new InvalidArgumentException('Send /whoami to the bot first, then use the ID it returns.');
        $db->run('INSERT INTO settings (`key`,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',[$key,(string)$account['id']]);
        $db->run('DELETE FROM settings WHERE `key`=?',['telegram_confirm:'.$id]);
        $db->audit((int)$account['id'],null,'telegram_admin_linked','Telegram ID '.$id);
    }
    $db->pdo->commit();
    echo $operation==='link'?"Telegram admin linked. Send /admin in your private bot chat.\n":"Telegram admin access revoked. Queued admin deliveries will be blocked.\n";
} catch(Throwable $e) {
    if($db->pdo->inTransaction()) $db->pdo->rollBack();
    fwrite(STDERR,$e instanceof InvalidArgumentException?$e->getMessage()."\n":"Unable to change Telegram admin access. Check private server logs.\n"); exit(1);
}
