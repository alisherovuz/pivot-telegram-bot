<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Pivot\{Db,Bot,Worker,Queue,FakeTelegram,TelegramTransport,TelegramException};
date_default_timezone_set('Asia/Tashkent');
$config=require dirname(__DIR__).'/.dev/test-config.php';
if(!str_contains($config['dsn'],'dbname=pivot_test;') || $config['telegram_mode']!=='fake') throw new RuntimeException('Test fixtures only');
$db=new Db($config); $other=new Db($config); $tg=new FakeTelegram(); $bot=new Bot($db,$tg,$config); $worker=new Worker($db,$tg,$config);
$sequence=800000; $checks=0;
function verify(bool $condition,string $name): void {global $checks; if(!$condition) throw new RuntimeException('FAIL '.$name); $checks++; echo 'PASS '.$name."\n";}
function incoming(int $user,string $text): array {global $sequence,$bot; $u=['update_id'=>++$sequence,'message'=>['from'=>['id'=>$user],'chat'=>['id'=>$user,'type'=>'private'],'text'=>$text]]; $bot->ingest($u); return $u;}
$u=incoming(7001,Bot::APPLY); $worker->respond($u);
verify($db->value('SELECT step FROM drafts WHERE user_id=7001')==='name','webhook worker processes registration without cron');
verify((int)$db->value("SELECT COUNT(*) FROM notifications WHERE user_id=7001 AND status='sent'")===2,'greeting and first prompt sent immediately');
$count=count($tg->calls); $bot->ingest($u); $worker->respond($u);
verify(count($tg->calls)===$count,'duplicate webhook cannot repeat processed messages');
incoming(7001,'Test Applicant'); $u=incoming(7001,'Test Startup'); $worker->respond($u);
verify($db->value('SELECT step FROM drafts WHERE user_id=7001')==='phone','rapid successive messages processed in order');
verify((int)$db->value('SELECT COUNT(*) FROM updates WHERE id BETWEEN 800001 AND 800003 AND processed_at IS NULL')===0,'all conversation updates committed');
$u=incoming(7002,Bot::APPLY);
$lock='pivot-user-'.substr(hash('sha256',$config['dsn'].':7002'),0,48); $other->run('SELECT GET_LOCK(?,0)',[$lock]);
$worker->respond($u); verify($db->value('SELECT processed_at FROM updates WHERE id=?',[$u['update_id']])===null,'busy conversation remains durable');
$otherUser=incoming(7003,Bot::APPLY); $worker->respond($otherUser);
verify($db->value('SELECT step FROM drafts WHERE user_id=7003')==='name','one busy conversation does not block another');
$other->run('SELECT RELEASE_LOCK(?)',[$lock]); $worker->respond($u);
verify($db->value('SELECT step FROM drafts WHERE user_id=7002')==='name','released conversation can be resumed');
$u=incoming(7004,Bot::APPLY);
$lock='pivot-send-'.substr(hash('sha256','pivot_test:7004'),0,48); $other->run('SELECT GET_LOCK(?,0)',[$lock]);
$worker->respond($u);
verify((int)$db->value("SELECT COUNT(*) FROM notifications WHERE user_id=7004 AND status='pending'")===2,'busy delivery preserves outgoing jobs');
$other->run('SELECT RELEASE_LOCK(?)',[$lock]);
verify((new Queue($other,$tg))->deliver(10,7004)===2,'cron fallback delivers jobs left by a busy webhook');
$queue=new Queue($db,$tg); $queue->add('fast:retry:first',7005,['chat_id'=>7005,'text'=>'first']); $queue->add('fast:retry:second',7005,['chat_id'=>7005,'text'=>'second']);
$db->run("UPDATE notifications SET available_at=DATE_ADD(NOW(),INTERVAL 60 SECOND) WHERE dedupe_key='fast:retry:first'");
verify($queue->deliver(10,7005)===0,'delivery backoff cannot reorder the next prompt');
$db->run("UPDATE notifications SET available_at=NOW() WHERE user_id=7005"); $queue->deliver(10,7005);
$calls=array_values(array_filter($tg->calls,fn($r)=>($r[1]['chat_id']??0)===7005));
verify(array_column(array_column($calls,1),'text')===['first','second'],'deferred replies preserve order');
$u=incoming(7006,Bot::APPLY); $db->run('UPDATE updates SET next_attempt_at=DATE_ADD(NOW(),INTERVAL 60 SECOND) WHERE id=?',[$u['update_id']]);
$later=incoming(7006,'Future name'); $worker->respond($later);
verify($db->value('SELECT processed_at FROM updates WHERE id=?',[$later['update_id']])===null,'processing backoff blocks later messages for that user');
$db->run('UPDATE updates SET next_attempt_at=NOW(),attempts=8 WHERE id=?',[$u['update_id']]); $worker->respond($later);
verify($db->value('SELECT processed_at FROM updates WHERE id=?',[$later['update_id']])===null,'exhausted update is not bypassed');
$db->run('UPDATE updates SET attempts=0 WHERE id=?',[$u['update_id']]); $worker->respond($later);
verify($db->value('SELECT step FROM drafts WHERE user_id=7006')==='startup','owner retry releases preserved ordered messages');
$transport=new class($other) implements TelegramTransport {
    public int $sent=0; public int $overlap=-1;
    public function __construct(private Db $other) {}
    public function call(string $method,array $data): array {
        $this->sent++;
        $this->overlap=(new Queue($this->other,new FakeTelegram()))->deliver(1,7007);
        return ['message_id'=>123];
    }
    public function download(string $id,string $path): int {throw new RuntimeException('Not used');}
};
$queue->add('fast:overlap',7007,['chat_id'=>7007,'text'=>'only once']); (new Queue($db,$transport))->deliver(1,7007);
verify($transport->sent===1 && $transport->overlap===0,'overlapping cron/webhook cannot deliver the same job concurrently');
$queue->add('fast:recover',7008,['chat_id'=>7008,'text'=>'recover']);
$db->run("UPDATE notifications SET status='sending',locked_at=DATE_SUB(NOW(),INTERVAL 6 MINUTE) WHERE dedupe_key='fast:recover'");
verify($queue->deliver(1,7008)===1,'interrupted delivery recovers after lease expires');
$queue->add('fast:active',7008,['chat_id'=>7008,'text'=>'active']);
$db->run("UPDATE notifications SET status='sending',locked_at=NOW() WHERE dedupe_key='fast:active'");
verify($queue->deliver(1,7008)===0,'active delivery lease is not stolen');
$transport=new class implements TelegramTransport {
    public function call(string $method,array $data): array {if($method==='getChatMember') return ['status'=>'member']; throw new TelegramException(429,60);}
    public function download(string $id,string $path): int {throw new RuntimeException('Not used');}
};
$u=incoming(7009,Bot::APPLY); (new Worker($db,$transport,$config))->respond($u);
verify($db->value('SELECT step FROM drafts WHERE user_id=7009')==='name','delivery failure does not roll back persisted progress');
verify((int)$db->value("SELECT COUNT(*) FROM notifications WHERE user_id=7009 AND status='pending'")===2,'API rate limit retains replies for cron');
$db->run('UPDATE notifications SET available_at=NOW() WHERE user_id=7009');
verify($queue->deliver(10,7009)===2,'cron successfully retries immediate-path delivery errors');
echo "\n$checks immediate-reply/concurrency assertions passed. Fake Telegram only.\n";
