<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Pivot\{Db,Bot,Worker,Queue,FakeTelegram,TelegramTransport,TelegramException};
date_default_timezone_set('Asia/Tashkent');
$config=require dirname(__DIR__).'/.dev/test-config.php';
if(!str_contains($config['dsn'],'dbname=pivot_test;') || $config['telegram_mode']!=='fake') throw new RuntimeException('Test fixtures only');
$db=new Db($config); $tg=new FakeTelegram(); $bot=new Bot($db,$tg,$config); $worker=new Worker($db,$tg,$config);
$owner=(int)$db->value("SELECT id FROM admins WHERE email='owner@example.test'");
$reviewer=(int)$db->value("SELECT id FROM admins WHERE role='reviewer' LIMIT 1");
$user=93001; $seq=1500000; $checks=0;
function verify(bool $ok,string $label): void {global $checks;if(!$ok) throw new RuntimeException('FAIL '.$label);$checks++;echo 'PASS '.$label."\n";}
function incoming(int $user,string $text,bool $deliver=true,int $messageId=445): array {
    global $seq,$bot,$worker;
    $u=['update_id'=>++$seq];$message=['chat'=>['id'=>$user,'type'=>'private'],'message_id'=>$messageId];
    if(str_starts_with($text,'adm:')) $u['callback_query']=['id'=>'cb'.$seq,'from'=>['id'=>$user],'message'=>$message,'data'=>$text];
    else $u['message']=[...$message,'from'=>['id'=>$user],'text'=>$text];
    $bot->ingest($u);if($deliver)$worker->respond($u);else $bot->process($u['update_id']);return $u;
}
function screen(): array {global $tg;foreach(array_reverse($tg->calls) as [$method,$p])if(in_array($method,['sendMessage','editMessageText'],true))return [$method,$p];throw new RuntimeException('No menu');}
function buttons(): array {return array_merge(...screen()[1]['reply_markup']['inline_keyboard']);}
function routes(): array {return array_column(buttons(),'callback_data');}
foreach([$user=>$owner,93002=>$reviewer,93003=>$owner] as $uid=>$admin) $db->run('INSERT INTO settings (`key`,value) VALUES(?,?)',['telegram_admin:'.$uid,(string)$admin]);
$id=(int)$db->value('SELECT id FROM applications WHERE user_id=101');
foreach(range(93010,93015) as $uid) {
    $db->run('INSERT INTO users(id,first_seen,last_seen) VALUES(?,NOW(),NOW())',[$uid]);
    $db->run("INSERT INTO applications(reference,user_id,full_name,startup,phone,file_key,file_name,file_mime,file_size,submitted_at,review_due_at) SELECT ?,?,'Test applicant','Test startup',phone,file_key,file_name,file_mime,file_size,NOW(),review_due_at FROM applications WHERE id=?",['TEST-SIMPLE-'.$uid,$uid,$id]);
}
incoming($user,'/admin');verify(routes()===['adm:list:all:0','adm:stats','adm:settings'],'owner home has exactly three clear choices');
incoming(93002,'/admin');verify(routes()===['adm:list:all:0','adm:stats'],'reviewer home has no owner control');
$before=count($tg->calls);incoming($user,'adm:list:all:1');$calls=array_slice($tg->calls,$before);
verify(array_column($calls,0)===['answerCallbackQuery','editMessageText'],'navigation acknowledges tap and edits current message only');
verify(screen()[1]['message_id']===445,'menu edit targets tapped message');
verify(count(array_filter(routes(),fn($r)=>str_starts_with($r,'adm:app:')))===4,'application list shows four applicants per page');
verify(!array_filter(routes(),fn($r)=>str_starts_with($r,'adm:doc:')||str_starts_with($r,'adm:status:')),'list hides report formats and review controls');
incoming(93003,'adm:list:3:0');incoming($user,'adm:app:'.$id);
verify(array_slice(buttons(),-1)[0]['callback_data']==='adm:list:all:1','Back preserves this admin page independently of other admins');
verify(count(buttons())===4 && in_array('adm:review:'.$id,routes(),true),'application has three actions and one Back button');
incoming($user,'adm:review:'.$id);
verify(!in_array('adm:status:'.$id.':0',routes(),true),'review screen omits already-selected status');
verify(array_slice(buttons(),-1)[0]['callback_data']==='adm:app:'.$id,'review Back returns to application');
incoming($user,'adm:status:'.$id.':3');$confirm=routes()[0];
verify(count(buttons())===2,'confirmation offers only Confirm and Cancel');
incoming($user,'adm:cancel');verify(str_contains(screen()[1]['text'],'Aziza'),'cancel returns to application');
incoming($user,$confirm);verify(str_contains(screen()[1]['text'],'expired'),'cancelled action stays invalid');
incoming($user,'adm:status:'.$id.':3');$confirm=routes()[0];$before=count($tg->calls);$update=incoming($user,$confirm);
verify(count(array_filter(array_slice($tg->calls,$before),fn($r)=>$r[0]==='editMessageText'))===1,'confirmed review produces one refreshed menu');
verify(str_contains(screen()[1]['text'],'Status saved') && str_contains(screen()[1]['text'],'Accepted'),'refreshed card shows success and updated status');
$before=count($tg->calls);$worker->respond($update);verify(count($tg->calls)===$before,'duplicate webhook does not repeat menu update');
incoming($user,'adm:more:'.$id);verify(routes()===['adm:doc:applicationpdf:'.$id.':all','adm:notify:'.$id,'adm:app:'.$id],'secondary actions stay available under More options');
incoming($user,'adm:filters');verify(count(buttons())===7,'dedicated filter screen contains statuses and Back');
incoming($user,'adm:exports:3');verify(routes()===['adm:doc:xlsx:0:3','adm:doc:pdf:0:3','adm:list:all:1'],'report choices preserve requested status filter');
incoming($user,'adm:stats');verify(count(buttons())===3 && in_array('adm:users',routes(),true) && str_contains(screen()[1]['text'],'Awaiting review'),'statistics includes user directory and report action');
incoming($user,'adm:statsreports');verify(routes()===['adm:doc:statsxlsx:0:all','adm:doc:statspdf:0:all','adm:stats'],'statistics export formats remain reachable');
incoming($user,'adm:settings');verify(count(buttons())===2 && str_contains(screen()[1]['text'],'Deadline'),'owner setting stays in its own screen');
incoming(93002,'adm:settings');verify(str_contains(screen()[1]['text'],'Owner access required'),'forged settings navigation still enforces owner');

$transport=new class implements TelegramTransport {
    public array $calls=[];public int $error=400;
    public function call(string $method,array $data): array {$this->calls[]=[$method,$data];if($method==='editMessageText')throw new TelegramException($this->error,60);return ['message_id'=>900];}
    public function download(string $id,string $path): int {throw new RuntimeException('Unused');}
};
incoming($user,'adm:home',false);(new Queue($db,$transport,$config))->deliver(10,$user);
verify(array_column($transport->calls,0)===['answerCallbackQuery','editMessageText','sendMessage'],'uneditable old menu falls back to a new message');
verify(!isset($transport->calls[2][1]['message_id']) && !isset($transport->calls[2][1]['_admin_id']),'fallback removes internal and edit-only fields');
$transport->calls=[];$transport->error=429;incoming($user,'adm:home',false);(new Queue($db,$transport,$config))->deliver(10,$user);
verify(array_column($transport->calls,0)===['answerCallbackQuery','editMessageText'],'rate limit retains edit for retry instead of adding a message');
verify($db->value("SELECT status FROM notifications WHERE user_id=? AND method='editMessageText' ORDER BY id DESC LIMIT 1",[$user])==='pending','rate-limited menu edit remains queued');
$db->run('DELETE FROM settings WHERE `key`=?',['telegram_admin:'.$user]);$db->run('UPDATE notifications SET available_at=NOW() WHERE user_id=?',[$user]);$before=count($tg->calls);(new Queue($db,$tg,$config))->deliver(10,$user);
verify(count($tg->calls)===$before,'revoked admin cannot receive queued edit or its fallback');
$db->run("UPDATE applications SET status='new' WHERE id=?",[$id]);
echo "\n$checks simplified-menu assertions passed. Fake Telegram only.\n";
