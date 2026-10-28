<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Pivot\{Db,BotUsers,Bot,Worker,Queue,FakeTelegram,Exports,TelegramTransport,TelegramException};
date_default_timezone_set('Asia/Tashkent');
$config=require dirname(__DIR__).'/.dev/test-config.php';
if(!str_contains($config['dsn'],'dbname=pivot_test;') || $config['telegram_mode']!=='fake') throw new RuntimeException('Test fixtures only');
$db=new Db($config); $users=new BotUsers($db); $checks=0;
function verify(bool $ok,string $label): void {global $checks;if(!$ok) throw new RuntimeException('FAIL '.$label);$checks++;echo 'PASS '.$label."\n";}
$before=$users->summary();
foreach([94001,94002,94003,94004,94005] as $id) $db->run('INSERT INTO users(id,username,first_seen,last_seen) VALUES(?,?,NOW(),NOW())',[$id,$id===94002?null:'user'.$id]);
$db->run("UPDATE users SET first_seen=DATE_SUB(NOW(),INTERVAL 10 DAY),last_seen=DATE_SUB(NOW(),INTERVAL 8 DAY) WHERE id=94001");
$db->run("INSERT INTO drafts(user_id,step,full_name,phone,cancelled,started_at,updated_at) VALUES(94003,'file','<script>Example</script>','+998000000001',1,NOW(),NOW()),(94004,'startup','=HYPERLINK(\"https://example.invalid\")',NULL,0,NOW(),NOW())");
$db->run("INSERT INTO applications(reference,user_id,full_name,startup,phone,file_key,file_name,file_mime,file_size,submitted_at,review_due_at) SELECT 'TEST-USERS-94005',94005,'Synthetic Person','Test project',phone,file_key,file_name,file_mime,file_size,NOW(),review_due_at FROM applications WHERE user_id=101");
$s=$users->summary();
verify($s['total']===$before['total']+5,'users include visitors without submitted applications');
verify($s['new_today']===$before['new_today']+4,'new-today count uses first interaction');
verify($s['active_7_days']===$before['active_7_days']+4,'seven-day count excludes inactive old visitor');
verify($s['not_started']===$before['not_started']+2 && $s['unfinished']===$before['unfinished']+2 && $s['submitted']===$before['submitted']+1,'progress categories are mutually exclusive');
verify($s['not_started']+$s['unfinished']+$s['submitted']===$s['total'],'progress categories cover every recorded user');
verify($users->count(['q'=>'9400','stage'=>'not_submitted'])===4,'no-submission filter includes both not-started and draft users');
verify($users->count(['q'=>'9400','stage'=>'draft'])===2,'unfinished filter includes paused drafts');
verify($users->count(['q'=>'@user94001'])===1,'username search accepts leading at-sign');
verify($users->count(['q'=>'94002'])===1,'numeric ID search finds visitor without username');
verify($users->count(['q'=>"' OR 1=1 --"])===0,'user search cannot inject SQL');
verify($users->count(['q'=>'9400','from'=>date('Y-m-d'),'to'=>date('Y-m-d')])===4,'date filters use first interaction inclusively');
$first=$users->search(['q'=>'9400'],2);$second=$users->search(['q'=>'9400'],2,2);
verify(count($first)===2 && count($second)===2 && !array_intersect(array_column($first,'id'),array_column($second,'id')),'pagination does not duplicate users');
verify(BotUsers::progress($users->get(94002))==='Not started','no draft is not mistaken for a submitted application');
verify(BotUsers::progress($users->get(94003))==='Paused · File','paused user shows current registration step');
verify($users->get(94002)['phone']===null && $users->get(94002)['full_name']===null,'unprovided names and phones remain unknown');
verify($users->get(94005)['application_id']!==null,'completed user links to application');
foreach([['stage'=>'invalid'],['from'=>'2026-02-31']] as $filter) {
    try {$users->count($filter);throw new RuntimeException('Expected invalid filter');}catch(InvalidArgumentException){verify(true,'invalid user filter rejected');}
}
$rows=$users->search(['q'=>'9400']);$xlsx=Exports::usersXlsx($rows);$path=$config['storage'].'/cache/test-users.xlsx';file_put_contents($path,$xlsx);
$book=\PhpOffice\PhpSpreadsheet\IOFactory::load($path);$sheet=$book->getSheetByName('Bot users');
verify($sheet->getHighestRow()===6 && $sheet->getCell('A1')->getValue()==='Telegram ID','user workbook includes all matching visitors');
$foundFormula=false;$foundPhone=false;
for($row=2;$row<=6;$row++) {
 if((string)$sheet->getCell('A'.$row)->getValue()==='94004') $foundFormula=$sheet->getCell('C'.$row)->getDataType()==='s' && str_starts_with($sheet->getCell('C'.$row)->getValue(),'=HYPERLINK');
 if((string)$sheet->getCell('A'.$row)->getValue()==='94003') $foundPhone=$sheet->getCell('D'.$row)->getDataType()==='s' && $sheet->getCell('D'.$row)->getValue()==='+998000000001';
}
verify($foundFormula && $foundPhone,'user exports preserve phone strings and neutralize spreadsheet formulas');
verify($book->getSheetByName('Definitions')!==null,'user workbook explains recording and membership limits');$book->disconnectWorksheets();unlink($path);
verify(str_starts_with(Exports::usersPdf($rows,$config['storage'].'/cache'),'%PDF-'),'user PDF is generated with unknown and unsubmitted users');

$owner=(int)$db->value("SELECT id FROM admins WHERE email='owner@example.test'");
$db->run('INSERT INTO settings (`key`,value) VALUES(?,?)',['telegram_admin:95001',(string)$owner]);
$tg=new FakeTelegram();$bot=new Bot($db,$tg,$config);$worker=new Worker($db,$tg,$config);$seq=1900000;
function incoming(int $user,string $route,bool $deliver=true): void {
 global $seq,$bot,$worker;$u=['update_id'=>++$seq,'callback_query'=>['id'=>'cb'.$seq,'from'=>['id'=>$user],'message'=>['message_id'=>445,'chat'=>['id'=>$user,'type'=>'private']],'data'=>'adm:'.$route]];
 $bot->ingest($u);if($deliver)$worker->respond($u);else $bot->process($u['update_id']);
}
function latest(): array {global $tg;foreach(array_reverse($tg->calls) as [$method,$p])if(in_array($method,['editMessageText','sendMessage'],true))return $p;throw new RuntimeException('No message');}
incoming(95001,'stats');verify(str_contains(latest()['text'],'Bot users:') && str_contains(latest()['text'],'New users today:'),'statistics includes bot audience beyond applications');
incoming(95001,'users');verify(str_contains(latest()['text'],'Not started:') && str_contains(latest()['text'],'Includes admins'),'user summary includes progress and definition');
incoming(95001,'userlist:not_submitted:0');verify(str_contains(latest()['text'],'No application submitted'),'user list can show visitors who did not submit');
incoming(95001,'user:94002');verify(str_contains(latest()['text'],'Telegram ID: 94002') && str_contains(latest()['text'],'Not available') && str_contains(latest()['text'],'Name provided: Not provided'),'user card exposes recorded ID without inventing profile fields');
$buttons=array_merge(...latest()['reply_markup']['inline_keyboard']);verify(end($buttons)['callback_data']==='adm:userlist:not_submitted:0','user Back remembers independent user-list filter');
incoming(95001,'user:94003');verify(str_contains(latest()['text'],'Paused · File'),'bot user card shows where a draft stopped');
incoming(95001,'user:94005');verify(str_starts_with(latest()['reply_markup']['inline_keyboard'][0][0]['callback_data'],'adm:app:'),'submitted user offers application link');
incoming(95001,'userlist:all:999999');verify(!str_contains(latest()['text'],'Page 1000000'),'user pagination clamps out-of-range pages');
incoming(95002,'user:94002');verify(str_contains(latest()['text'],'not enabled') && !str_contains(latest()['text'],'94002'),'unlinked account cannot inspect a user');
$capture=new class implements TelegramTransport {
 public array $documents=[];
 public function call(string $method,array $p): array {if($method==='sendDocument')$this->documents[]=['name'=>$p['document']->getPostFilename(),'body'=>file_get_contents($p['document']->getFilename()),'path'=>$p['document']->getFilename()];return ['message_id'=>100];}
 public function download(string $id,string $path): int {throw new RuntimeException('Not used');}
};
foreach(['xlsx'=>'PK','pdf'=>'%PDF-'] as $format=>$magic) {
 $db->run('DELETE FROM settings WHERE `key`=?',['telegram_export:95001']);incoming(95001,'userdoc:'.$format.':not_submitted',false);
 (new Queue($db,$capture,$config))->deliver(10,95001);$doc=end($capture->documents);
 verify($doc['name']==='pivot-bot-users.'.$format && str_starts_with($doc['body'],$magic),'user report delivered in Telegram: '.$format);
 verify(!is_file($doc['path']),'temporary user export is cleaned: '.$format);
}
$db->run('DELETE FROM settings WHERE `key`=?',['telegram_export:95001']);incoming(95001,'userdoc:xlsx:all',false);$before=count($capture->documents);
$db->run('DELETE FROM settings WHERE `key`=?',['telegram_admin:95001']);(new Queue($db,$capture,$config))->deliver(10,95001);
verify(count($capture->documents)===$before,'revoked admin cannot receive queued user directory');
echo "\n$checks bot-user assertions passed. Fake Telegram only.\n";
