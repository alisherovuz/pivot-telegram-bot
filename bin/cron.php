<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit(1);
require dirname(__DIR__).'/app/bootstrap.php';
$lock='pivot-cron-'.hash('sha256',$config['dsn']);
if((int)$db->value('SELECT GET_LOCK(?,0)',[$lock])!==1) { echo "Another cron is running.\n"; exit; }
$start=microtime(true); $processed=0; $delivered=0;
try {
    $bot=new Pivot\Bot($db,$telegram,$config);
    // Earlier retrying updates block later updates for that user to preserve conversation order.
    $rows=$db->all('SELECT id,payload,attempts,next_attempt_at FROM updates WHERE processed_at IS NULL ORDER BY id LIMIT 100');
    $blocked=[];
    foreach($rows as $r) {
        if(microtime(true)-$start>20) break;
        $u=json_decode($r['payload'],true); $uid=$u['callback_query']['from']['id']??$u['message']['from']['id']??0;
        if(isset($blocked[$uid])) continue;
        if((int)$r['attempts']>=8 || $r['next_attempt_at']>date('Y-m-d H:i:s')) { $blocked[$uid]=true; continue; }
        try { $bot->process((int)$r['id']); $processed++; }
        catch(Throwable $e) {
            $blocked[$uid]=true;
            $db->run('UPDATE updates SET attempts=attempts+1,last_error=?,next_attempt_at=DATE_ADD(NOW(),INTERVAL 60 SECOND) WHERE id=?',[$e instanceof Pivot\TelegramException?$e->getMessage():'Processing error; inspect private application log',$r['id']]);
            error_log('Pivot update '.$r['id'].' failed: '.get_class($e));
        }
    }
    $db->run("INSERT IGNORE INTO reminders(application_id,created_at) SELECT id,NOW() FROM applications WHERE review_due_at<NOW() AND status IN ('new','under review')");
    $queue=new Pivot\Queue($db,$telegram);
    while(microtime(true)-$start<40) { if(!$queue->deliver(1)) break; $delivered++; if($delivered>=30) break; }
    echo "Processed $processed updates; attempted $delivered deliveries.\n";
} finally { $db->run('SELECT RELEASE_LOCK(?)',[$lock]); }
