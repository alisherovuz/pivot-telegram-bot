<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit(1);
require dirname(__DIR__).'/app/bootstrap.php';
$lock='pivot-cron-'.hash('sha256',$config['dsn']);
if((int)$db->value('SELECT GET_LOCK(?,0)',[$lock])!==1) { echo "Another cron is running.\n"; exit; }
$start=microtime(true); $processed=0; $delivered=0;
try {
    $worker=new Pivot\Worker($db,$telegram,$config);
    $rows=$db->all('SELECT payload FROM updates WHERE processed_at IS NULL ORDER BY id LIMIT 100');
    $seen=[];
    foreach($rows as $r) {
        if(microtime(true)-$start>20) break;
        $user=Pivot\Worker::userId(json_decode($r['payload'],true,512,JSON_THROW_ON_ERROR));
        if(isset($seen[$user])) continue;
        $seen[$user]=true;
        $processed+=$worker->processUser($user,10,max(0.1,20-(microtime(true)-$start)));
    }
    $db->run("INSERT IGNORE INTO reminders(application_id,created_at) SELECT id,NOW() FROM applications WHERE review_due_at<NOW() AND status IN ('new','under review')");
    $queue=new Pivot\Queue($db,$telegram);
    while(microtime(true)-$start<40) { if(!$queue->deliver(1)) break; $delivered++; if($delivered>=30) break; }
    echo "Processed $processed updates; attempted $delivered deliveries.\n";
} finally { $db->run('SELECT RELEASE_LOCK(?)',[$lock]); }
