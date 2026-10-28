<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Pivot\{Db,Bot,Worker,Queue,AdminBot,FakeTelegram,TelegramTransport,TelegramException};
date_default_timezone_set('Asia/Tashkent');
$config=require dirname(__DIR__).'/.dev/test-config.php';
if(!str_contains($config['dsn'],'dbname=pivot_test;') || $config['telegram_mode']!=='fake') throw new RuntimeException('Test fixtures only');
$db=new Db($config); $tg=new FakeTelegram(); $bot=new Bot($db,$tg,$config); $worker=new Worker($db,$tg,$config);
$seq=1200000; $checks=0; $user=91001; $outsider=91002; $reviewerUser=91003;
$owner=(int)$db->value("SELECT id FROM admins WHERE email='owner@example.test'");
$reviewer=(int)$db->value("SELECT id FROM admins WHERE role='reviewer' LIMIT 1");
$id=(int)$db->value('SELECT id FROM applications WHERE user_id=101');
function verify(bool $condition,string $label): void {global $checks;if(!$condition) throw new RuntimeException('FAIL '.$label); $checks++; echo 'PASS '.$label."\n";}
function incoming(int $user,string $text,bool $deliver=true,string $chatType='private',?int $chatId=null): array {
    global $seq,$bot,$worker; $u=['update_id'=>++$seq]; $chat=['id'=>$chatId??$user,'type'=>$chatType];
    if(str_starts_with($text,'adm:')) $u['callback_query']=['id'=>'cb'.$seq,'from'=>['id'=>$user],'message'=>['chat'=>$chat],'data'=>$text];
    else $u['message']=['from'=>['id'=>$user],'chat'=>$chat,'text'=>$text];
    $bot->ingest($u); if($deliver) $worker->respond($u); else $bot->process($u['update_id']); return $u;
}
function latest(int $user): array {global $tg;foreach(array_reverse($tg->calls) as [$method,$data]) if($method==='sendMessage' && ($data['chat_id']??0)===$user) return $data;return [];}
function confirmation(int $user): string {global $db;$v=json_decode($db->value('SELECT value FROM settings WHERE `key`=?',['telegram_confirm:'.$user]),true); return 'adm:confirm:'.$v['nonce'];}
function linkUser(int $user,int $admin): void {global $db;$db->run('INSERT INTO settings (`key`,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',['telegram_admin:'.$user,(string)$admin]);}
incoming($user,'/whoami'); verify(str_contains(latest($user)['text'],(string)$user),'private whoami returns numeric sender ID');
incoming($outsider,'/admin'); verify(str_contains(latest($outsider)['text'],'not enabled'),'unlinked account denied');
linkUser($user,$owner); linkUser($reviewerUser,$reviewer);
incoming($user,'/admin'); verify(str_contains(latest($user)['text'],'Pivot admin · owner'),'owner opens private panel');
$count=count($tg->calls); incoming($user,'/admin',true,'group',-100123);
verify(count($tg->calls)===$count,'group commands leak no admin content');
incoming($user,'adm:app:'.$id,true,'private',$outsider);
verify(count($tg->calls)===$count,'mismatched private-chat sender is rejected');
incoming($outsider,'adm:app:'.$id); verify(!str_contains(latest($outsider)['text'],'Aziza'),'forged outsider callback cannot view applicant');
incoming($user,'adm:list:all:0'); verify(str_contains(latest($user)['text'],'results'),'application list has results');
foreach(latest($user)['reply_markup']['inline_keyboard'] as $row) foreach($row as $button) verify(strlen($button['callback_data'])<=64,'callback fits Telegram 64-byte limit');
incoming($user,'adm:list:3:999999'); verify(str_contains(latest($user)['text'],'Page 1'),'empty filtered pagination clamped');
incoming($user,'adm:app:'.$id); verify(str_contains(latest($user)['text'],'Aziza'),'application details available to admin');
verify(count($db->all('SELECT * FROM drafts WHERE user_id=?',[$user]))===0,'admin navigation does not start applicant draft');
incoming($user,'adm:stats'); verify(str_contains(latest($user)['text'],'Completion rate'),'statistics available in bot');
$assigned=$db->value('SELECT assigned_to FROM applications WHERE id=?',[$id]);
$notifyBefore=(int)$db->value("SELECT COUNT(*) FROM notifications WHERE kind='review'");
incoming($user,'adm:status:'.$id.':3');
verify($db->value('SELECT status FROM applications WHERE id=?',[$id])==='new','status preview has no side effects');
$confirm=confirmation($user); incoming($outsider,$confirm);
verify($db->value('SELECT status FROM applications WHERE id=?',[$id])==='new','confirmation cannot be stolen by outsider');
incoming($reviewerUser,$confirm); verify($db->value('SELECT status FROM applications WHERE id=?',[$id])==='new','confirmation bound to originating admin chat');
$update=incoming($user,$confirm); $worker->respond($update);
verify($db->value('SELECT status FROM applications WHERE id=?',[$id])==='accepted','confirmed review updates shared database');
verify($db->value('SELECT assigned_to FROM applications WHERE id=?',[$id])===$assigned,'status change preserves reviewer assignment');
verify((int)$db->value("SELECT COUNT(*) FROM notifications WHERE kind='review'")===$notifyBefore,'review does not send applicant messages implicitly');
$audit=(int)$db->value("SELECT COUNT(*) FROM audit WHERE action='review_changed'"); incoming($user,$confirm);
verify((int)$db->value("SELECT COUNT(*) FROM audit WHERE action='review_changed'")===$audit,'repeated confirmation cannot repeat mutation');
incoming($user,'adm:status:'.$id.':4'); $cancelled=confirmation($user); incoming($user,'adm:cancel'); incoming($user,$cancelled);
verify($db->value('SELECT status FROM applications WHERE id=?',[$id])==='accepted','cancel invalidates confirmation');
incoming($user,'adm:status:'.$id.':4'); $stale=confirmation($user); $db->run("UPDATE applications SET status='shortlisted' WHERE id=?",[$id]); incoming($user,$stale);
verify($db->value('SELECT status FROM applications WHERE id=?',[$id])==='shortlisted','stale review confirmation cannot overwrite newer status');
incoming($user,'adm:notify:'.$id); verify((int)$db->value("SELECT COUNT(*) FROM notifications WHERE kind='review'")===$notifyBefore,'message preview never sends');
$send=confirmation($user); incoming($user,$send); incoming($user,$send);
verify((int)$db->value("SELECT COUNT(*) FROM notifications WHERE kind='review'")===$notifyBefore+1,'explicit send queues exactly one applicant notification');
incoming($reviewerUser,'adm:registration:0'); verify($db->value("SELECT value FROM settings WHERE `key`='registration_open'")==='1','reviewer cannot close registration');
incoming($user,'adm:registration:0'); $close=confirmation($user); $db->run("UPDATE admins SET role='reviewer' WHERE id=?",[$owner]); incoming($user,$close);
verify($db->value("SELECT value FROM settings WHERE `key`='registration_open'")==='1','owner role rechecked at confirmation');
$db->run("UPDATE admins SET role='owner' WHERE id=?",[$owner]); incoming($user,'adm:registration:0'); incoming($user,confirmation($user));
verify($db->value("SELECT value FROM settings WHERE `key`='registration_open'")==='0','owner confirms registration switch');
incoming($user,'adm:registration:1'); incoming($user,confirmation($user));
incoming($user,'adm:app:'.$id,false); $calls=count($tg->calls); $db->run('UPDATE admins SET active=0 WHERE id=?',[$owner]);
(new Queue($db,$tg,$config))->deliver(10,$user);
verify(count($tg->calls)===$calls+1,'revoked account blocks queued sensitive reply while callback ack remains harmless');
incoming($user,'/admin'); verify(str_contains(latest($user)['text'],'not enabled'),'website account disabling revokes bot immediately');
$db->run('UPDATE admins SET active=1 WHERE id=?',[$owner]);

$capture=new class implements TelegramTransport {
    public array $documents=[]; public ?TelegramException $failure=null;
    public function call(string $method,array $data): array {
        if($method==='sendDocument') {
            $file=$data['document'];
            $this->documents[]=['chat'=>$data['chat_id'],'name'=>$file->getPostFilename(),'bytes'=>file_get_contents($file->getFilename()),'path'=>$file->getFilename(),'mode'=>fileperms($file->getFilename()) & 0777];
            if($this->failure) throw $this->failure;
        }
        return ['message_id'=>543];
    }
    public function download(string $id,string $path): int {throw new RuntimeException('No downloads in admin test');}
};
$docQueue=new Queue($db,$capture,$config);
foreach(['original','applicationpdf','xlsx','pdf','statsxlsx','statspdf'] as $type) {
    $db->run('DELETE FROM settings WHERE `key`=?',['telegram_export:'.$user]);
    incoming($user,'adm:doc:'.$type.':'.(in_array($type,['original','applicationpdf'],true)?$id:0).':all',false);
    $docQueue->deliver(10,$user); $doc=end($capture->documents);
    verify($doc['chat']===$user,'document delivered to authorized private chat: '.$type);
    if($type==='original') verify($doc['bytes']==='Fake private attachment: project101' && $doc['name']==='pitch.php','original attachment bytes and original filename preserved');
    else {
        verify(str_starts_with($doc['bytes'],str_contains($type,'xlsx')?'PK':'%PDF-'),'generated document format valid: '.$type);
        verify(!is_file($doc['path']) && $doc['mode']===0600,'temporary export private and deleted: '.$type);
    }
}
$before=(int)$db->value("SELECT COUNT(*) FROM notifications WHERE method='adminDocument'"); incoming($user,'adm:doc:xlsx:0:all');
verify((int)$db->value("SELECT COUNT(*) FROM notifications WHERE method='adminDocument'")===$before,'document request rate limit enforced');
$db->run('DELETE FROM settings WHERE `key`=?',['telegram_export:'.$user]); incoming($user,'adm:doc:original:'.$id.':all',false);
$docs=count($capture->documents); $db->run('DELETE FROM settings WHERE `key`=?',['telegram_admin:'.$user]); $docQueue->deliver(10,$user);
verify(count($capture->documents)===$docs,'unlink blocks document already in queue');
linkUser($user,$owner); $db->run('DELETE FROM settings WHERE `key`=?',['telegram_export:'.$user]); incoming($user,'adm:doc:applicationpdf:'.$id.':all',false);
$capture->failure=new TelegramException(429,60); $docQueue->deliver(10,$user); $doc=end($capture->documents);
verify(!is_file($doc['path']),'failed upload cleans private export temporary file');
verify($db->value("SELECT status FROM notifications WHERE method='adminDocument' ORDER BY id DESC LIMIT 1")==='pending','document rate limit retained for retry');
$capture->failure=null; $db->run('UPDATE notifications SET available_at=NOW() WHERE user_id=?',[$user]); $docQueue->deliver(10,$user);
verify($db->value("SELECT status FROM notifications WHERE method='adminDocument' ORDER BY id DESC LIMIT 1")==='sent','document retry succeeds');
$db->run('DELETE FROM settings WHERE `key`=?',['telegram_export:'.$user]); incoming($user,'adm:doc:original:'.$id.':all',false);
$saved=$db->value('SELECT file_key FROM applications WHERE id=?',[$id]); $db->run("UPDATE applications SET file_key='../config/local.php' WHERE id=?",[$id]); $docQueue->deliver(10,$user);
verify($db->value("SELECT status FROM notifications WHERE method='adminDocument' ORDER BY id DESC LIMIT 1")==='failed','invalid attachment path cannot expose configuration');
$db->run('UPDATE applications SET file_key=?,status=\'new\' WHERE id=?',[$saved,$id]);
$queue=new Queue($db,$tg,$config); $queue->deliver(10,$user);
verify(str_contains(latest($user)['text'],'could not be delivered'),'terminal document failure explains next step');
incoming($outsider,Bot::APPLY); verify($db->value('SELECT step FROM drafts WHERE user_id=?',[$outsider])==='name','normal applicant registration still works');
echo "\n$checks Telegram admin assertions passed. Fake transport only.\n";
