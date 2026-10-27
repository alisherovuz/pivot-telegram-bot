<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Pivot\{Db,Bot,Queue,FakeTelegram,Telegram,TelegramException,BusinessDays,Applications,Statistics,Exports,Auth,AdminActions};
date_default_timezone_set('Asia/Tashkent');
// This suite only destroys its own explicitly named test database, never config/local.php.
$host=getenv('PIVOT_TEST_DB_HOST')?:'pivot-db';
$root=new PDO('mysql:host='.$host.';charset=utf8mb4',getenv('PIVOT_TEST_DB_USER')?:'root',getenv('PIVOT_TEST_DB_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$root->exec('DROP DATABASE IF EXISTS pivot_test'); $root->exec('CREATE DATABASE pivot_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$config=['dsn'=>'mysql:host='.$host.';dbname=pivot_test;charset=utf8mb4','db_user'=>getenv('PIVOT_TEST_DB_USER')?:'root','db_password'=>getenv('PIVOT_TEST_DB_PASSWORD')?:'', 'storage'=>dirname(__DIR__).'/.dev/test-storage','channel'=>'@pivotuzbekistan','telegram_mode'=>'fake','session_secure'=>false,'base_url'=>'http://127.0.0.1:8081','bot_token'=>'TEST_FAKE_ONLY','webhook_secret'=>'test-webhook-secret-000000000000000000'];
@mkdir($config['storage'].'/private',0700,true); @mkdir($config['storage'].'/cache',0700,true);
file_put_contents(dirname(__DIR__).'/.dev/test-config.php','<?php return '.var_export($config,true).';');
$db=new Db($config); $db->pdo->exec(file_get_contents(dirname(__DIR__).'/database/schema.sql'));
$db->run("UPDATE settings SET value='2099-11-18 23:59:59' WHERE `key`='deadline'");
$tg=new FakeTelegram(); $bot=new Bot($db,$tg,$config); $queue=new Queue($db,$tg); $n=0;
ob_start(); Auth::session(false);
function check(bool $ok,string $label): void { global $n; if(!$ok) throw new RuntimeException('FAIL: '.$label); echo 'PASS '.$label."\n"; $n++; }
$seq=100;
function message(int $uid,array $body): array { global $seq; return ['update_id'=>++$seq,'message'=>['message_id'=>$seq,'chat'=>['id'=>$uid,'type'=>'private'],'from'=>['id'=>$uid,'username'=>'tester'.$uid],...$body]]; }
function send(int $uid,array $body): int { global $bot; $u=message($uid,$body); $bot->ingest($u); $bot->process($u['update_id']); return $u['update_id']; }
function step(int $uid): ?string { global $db; return $db->one('SELECT step FROM drafts WHERE user_id=?',[$uid])['step']??null; }
function prepare(int $uid): void { send($uid,['text'=>Bot::APPLY]); send($uid,['text'=>'O‘g‘iloy Ўзбекистон']); send($uid,['text'=>'=SUM(1,2)']); send($uid,['contact'=>['user_id'=>$uid,'phone_number'=>'+998001234567']]); }
$tg->membership='left'; send(101,['text'=>Bot::APPLY]);
check(step(101)===null,'membership gating prevents starting');
$payload=json_decode($db->value('SELECT payload FROM notifications ORDER BY id DESC LIMIT 1'),true);
check($payload['reply_markup']['inline_keyboard'][0][0]['text']==='Kanalga obuna bo‘lish','subscription buttons use required Uzbek labels');
$tg->membership='member'; send(101,['text'=>Bot::APPLY]); check(step(101)==='name','member can start');
check((int)$db->value('SELECT COUNT(*) FROM notifications WHERE payload LIKE ?', ["%Ajoyib! Endi arizani to'ldiramiz.%"])===1,'exact greeting is queued');
send(101,['text'=>'Aziza O‘ktamova']); send(101,['text'=>'Pivot AI']);
send(101,['contact'=>['user_id'=>999,'phone_number'=>'+998901234567']]); check(step(101)==='phone','foreign contact rejected');
send(101,['text'=>'+998901234567']); check(step(101)==='phone','typed phone rejected');
send(101,['contact'=>['user_id'=>101,'phone_number'=>'+998901234567']]); check(step(101)==='file','own contact accepted');
send(101,['text'=>'⬅️ Orqaga']); check(step(101)==='phone','back action persists step');
send(101,['text'=>'❌ Bekor qilish']); check((int)$db->value('SELECT cancelled FROM drafts WHERE user_id=101')===1,'cancel pauses and retains draft');
send(101,['text'=>Bot::APPLY]); check(step(101)==='phone','resume keeps previous fields');
send(101,['contact'=>['user_id'=>101,'phone_number'=>'+998901234567']]);
send(101,['document'=>['file_id'=>'big','file_size'=>Telegram::MAX_FILE+1]]); check(step(101)==='file','oversized attachment rejected');
$tg->membership='left'; send(101,['document'=>['file_id'=>'project101','file_name'=>'pitch.php','file_size'=>100]]);
check((int)$db->value('SELECT COUNT(*) FROM applications')===0,'membership is rechecked before acceptance');
check($db->value('SELECT pending_file FROM drafts WHERE user_id=101')!==null,'failed membership retains attachment draft');
$tg->membership='member'; $callback=['update_id'=>++$seq,'callback_query'=>['id'=>'callback-test','from'=>['id'=>101],'data'=>'check_subscription','message'=>['chat'=>['id'=>101,'type'=>'private']]]];
$bot->ingest($callback); $bot->process($callback['update_id']);
$app=$db->one('SELECT * FROM applications WHERE user_id=101'); check($app!==null,'subscription callback completes preserved draft');
check(step(101)==='completed','persistent completion state');
check(str_ends_with($app['file_key'],'.bin') && is_file($config['storage'].'/private/'.$app['file_key']),'attachment stored privately under random non-executable filename');
$payload=json_decode($db->value("SELECT payload FROM notifications WHERE payload LIKE ? ORDER BY id DESC LIMIT 1",['%Loyihangiz muvaffaqiyatli%']),true);
check(str_starts_with($payload['text'],Bot::SUCCESS) && str_contains($payload['text'],$app['reference']),'success wording and reference follow persistence');
check(!$bot->ingest($callback) && !$bot->process($callback['update_id']),'duplicate updates are idempotent');
send(101,['document'=>['file_id'=>'another','file_name'=>'another.pdf']]); check((int)$db->value('SELECT COUNT(*) FROM applications')===1,'duplicate submission prevented');
prepare(102); $db->run("UPDATE settings SET value='0' WHERE `key`='registration_open'"); send(102,['photo'=>[['file_id'=>'photo-small','file_size'=>30],['file_id'=>'photo-large','file_size'=>80]]]);
check(step(102)==='file' && (int)$db->value('SELECT COUNT(*) FROM applications')===1,'closed registration preserves drafts and blocks submission');
$db->run("UPDATE settings SET value='1' WHERE `key`='registration_open'"); send(102,['text'=>'check_subscription']);
$app2=$db->one('SELECT * FROM applications WHERE user_id=102'); check($app2['file_name']==='photo.jpg','photos accepted after reopening');
$tg->membership='restricted'; $tg->restrictedMember=false; send(103,['text'=>Bot::APPLY]); check(step(103)===null,'restricted non-member rejected');
$tg->restrictedMember=true; send(103,['text'=>Bot::APPLY]); check(step(103)==='name','restricted subscribed member accepted');
$tg->membership='member'; $tg->failure=new TelegramException(503); send(103,['text'=>Bot::APPLY]); check(step(103)==='name','membership API failure preserves draft'); $tg->failure=null;
prepare(104);
$update=message(104,['document'=>['file_id'=>'retry','file_name'=>'retry.pdf']]); $bot->ingest($update);
// A transport that permits membership but fails the download proves transaction rollback.
$failing=new class implements \Pivot\TelegramTransport { public function call(string $m,array $d):array {return ['status'=>'member'];} public function download(string $id,string $path):int {throw new TelegramException(503);} };
try { (new Bot($db,$failing,$config))->process($update['update_id']); check(false,'download should fail'); } catch(TelegramException) {}
check($db->value('SELECT processed_at FROM updates WHERE id=?',[$update['update_id']])===null,'failed download leaves update retryable');
check((int)$db->value('SELECT COUNT(*) FROM applications WHERE user_id=104')===0,'failed download creates no accepted application');
$bot->process($update['update_id']); check((int)$db->value('SELECT COUNT(*) FROM applications WHERE user_id=104')===1,'retry successfully persists application');
check(BusinessDays::due(new DateTimeImmutable('2026-11-13 15:00:00'),['2026-11-16'])==='2026-11-19 15:00:00','business days skip weekend and holiday');
check(BusinessDays::due(new DateTimeImmutable('2026-11-19 23:30:00+00:00'),[])==='2026-11-25 04:30:00','business days honor Tashkent timezone');
$stats=(new Statistics($db))->report();
check($stats['metrics']['Started applications (start-date cohort)']===4,'statistics count started drafts');
check($stats['metrics']['Completed in start-date cohort']===3,'statistics count completed drafts');
check($stats['metrics']['Completion rate (%)']===75.0,'completion rate calculated from start cohort');
check($stats['steps'][0]['unfinished_at_furthest_step']===1,'drop-off identifies unfinished name step');
$empty=(new Statistics($db))->report(['from'=>'2000-01-01','to'=>'2000-01-31']); check($empty['metrics']['New users in period']===0 && $empty['metrics']['Completion rate (%)']===0,'date-filtered empty cohorts handled');
$db->run("UPDATE applications SET review_due_at='2000-01-01 00:00:00' WHERE user_id=101");
check((new Statistics($db))->report()['metrics']['Overdue reviews']===1,'overdue statistics reflect deadline');
check((new Applications($db))->count(['q'=>'Aziza','status'=>'new'])===1,'application search and status filters');
check((new Applications($db))->count(['q'=>"' OR 1=1 --"])===0,'search SQL injection treated as text');
check((new Applications($db))->count(['overdue'=>1])===1,'overdue filter');
try { Applications::range(['from'=>'2026-02-31']); check(false,'invalid date'); } catch(InvalidArgumentException) { check(true,'invalid dates rejected'); }
$rows=(new Applications($db))->search([]); $xlsx=Exports::xlsx($rows,$stats); $out=dirname(__DIR__).'/.dev/test-output'; @mkdir($out,0700,true); file_put_contents($out.'/applications.xlsx',$xlsx);
check(substr($xlsx,0,2)==='PK','XLSX is a genuine ZIP workbook');
$book=\PhpOffice\PhpSpreadsheet\IOFactory::load($out.'/applications.xlsx');
check($book->getActiveSheet()->getCell('D2')->getValue()==='+998001234567','phone remains text with leading zeros and plus');
check($book->getActiveSheet()->getCell('C2')->getDataType()==='s' && $book->getActiveSheet()->getCell('C2')->getValue()==='=SUM(1,2)','spreadsheet formula injection blocked');
check($book->getSheetByName('Funnel')!==null && $book->getSheetByName('Definitions')!==null,'statistics workbook contains funnel and definitions');
$pdf=Exports::pdf($rows,$stats,$config['storage'].'/cache'); file_put_contents($out.'/report.pdf',$pdf);
check(str_starts_with($pdf,'%PDF-') && strlen($pdf)>10000,'PDF generated with embedded Unicode font');
check(str_contains($pdf,'DejaVuSans'),'PDF embeds DejaVu Sans');
$db->run("UPDATE notifications SET status='sent',sent_at=NOW()");
$queue->add('test-delivery',101,['chat_id'=>101,'text'=>'test']); $queue->add('test-delivery',101,['chat_id'=>101,'text'=>'test']);
check((int)$db->value("SELECT COUNT(*) FROM notifications WHERE dedupe_key='test-delivery'")===1,'queue dedupe key prevents duplicate jobs');
$tg->failure=new TelegramException(429,120); $queue->deliver(1);
$nrow=$db->one("SELECT * FROM notifications WHERE dedupe_key='test-delivery'"); check($nrow['status']==='pending' && (int)$nrow['attempts']===1 && strtotime($nrow['available_at'])>=time()+118,'rate limit respects retry_after');
$tg->failure=null; $db->run("UPDATE notifications SET available_at=NOW() WHERE dedupe_key='test-delivery'"); $queue->deliver(1);
check($db->value("SELECT status FROM notifications WHERE dedupe_key='test-delivery'")==='sent','queue retry delivers successfully');
$queue->add('test-forbidden',101,['chat_id'=>101,'text'=>'test']); $tg->failure=new TelegramException(403); $queue->deliver(1);
check($db->value("SELECT status FROM notifications WHERE dedupe_key='test-forbidden'")==='failed','permanent delivery failure recorded'); $tg->failure=null;
$db->run('INSERT INTO admins(email,password_hash,role) VALUES(?,?,?),(?,?,?)',['owner@example.test',Auth::password('TestOwner!12345'),'owner','reviewer@example.test',Auth::password('TestReviewer!12345'),'reviewer']);
$auth=new Auth($db); check(!$auth->login('owner@example.test','wrong','test-ip'),'bad admin password rejected');
check($auth->login('owner@example.test','TestOwner!12345','test-ip'),'owner login succeeds');
$owner=$auth->current(); check($owner['role']==='owner','session resolves active owner');
check(!Auth::csrf('bad') && Auth::csrf($_SESSION['csrf']),'CSRF validation rejects invalid token');
$reviewer=$db->one("SELECT * FROM admins WHERE role='reviewer'");
try { Auth::owner($reviewer); check(false,'reviewer owner permissions'); } catch(DomainException) { check(true,'reviewer denied owner permissions'); }
$actions=new AdminActions($db,$queue); $id=(int)$app['id'];
$actions->perform($reviewer,['action'=>'review','id'=>$id,'status'=>'shortlisted','assigned_to'=>$reviewer['id']]);
check($db->value('SELECT status FROM applications WHERE id=?',[$id])==='shortlisted','review status and assignment persist');
$actions->perform($reviewer,['action'=>'note','id'=>$id,'body'=>'Private note']); $actions->perform($reviewer,['action'=>'rating','id'=>$id,'score'=>4]);
check((int)$db->value('SELECT score FROM ratings WHERE application_id=?',[$id])===4 && (int)$db->value('SELECT COUNT(*) FROM notes WHERE application_id=?',[$id])===1,'internal notes and ratings persist');
$before=(int)$db->value('SELECT COUNT(*) FROM notifications');
$actions->perform($reviewer,['action'=>'preview','id'=>$id,'message'=>'Assalomu alaykum! O‘g‘iloy']);
check((int)$db->value('SELECT COUNT(*) FROM notifications')===$before,'preview does not send or queue a message');
$nonce=$_SESSION['notification_preview']['nonce']; $actions->perform($reviewer,['action'=>'send','id'=>$id,'nonce'=>$nonce]);
check((int)$db->value('SELECT COUNT(*) FROM notifications')===$before+1,'explicit send queues previewed message');
try { $actions->perform($reviewer,['action'=>'send','id'=>$id,'nonce'=>$nonce]); check(false,'repeat send'); } catch(InvalidArgumentException) { check(true,'repeated send rejected'); }
check((int)$db->value('SELECT COUNT(*) FROM audit WHERE application_id=?',[$id])>=5,'review and send actions audited');
try { $actions->perform($reviewer,['action'=>'settings']); check(false,'settings guard'); } catch(DomainException) { check(true,'settings action enforces owner role'); }
$db->run('UPDATE admins SET session_version=session_version+1 WHERE id=?',[$owner['id']]); check($auth->current()===null,'password reset version invalidates old sessions');
for($i=0;$i<11;$i++) $auth->login('owner@example.test','bad','blocked-ip'); check(!$auth->login('owner@example.test','TestOwner!12345','blocked-ip'),'login rate limiting enforced');
// Restore status for HTTP tests, leave explicit test fixtures in the test DB only.
$db->run("UPDATE applications SET status='new' WHERE id=?",[$id]);
file_put_contents($out.'/result.json',json_encode(['passed'=>$n,'failed'=>0,'transport'=>'fake','database'=>'MariaDB','generated_at'=>date(DATE_ATOM)],JSON_PRETTY_PRINT));
echo "\n$n assertions passed. No live Telegram requests made.\n";
ob_end_flush();
