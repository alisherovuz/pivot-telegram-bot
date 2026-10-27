<?php
declare(strict_types=1);
namespace Pivot;
final class Bot
{
    public const APPLY='🚀 Demo Day — Ariza topshirish';
    public const STATUS='📋 Arizam holati';
    public const ABOUT='ℹ️ Demo Day haqida';
    public const PROMPTS=[
        'name'=>'1️⃣ Ism Familiyangizni kiriting',
        'startup'=>'2️⃣ Startup nomini kiriting',
        'phone'=>"3️⃣ Telefon raqamingizni yuboring.\nPastdagi tugmani bosib, Telegram orqali raqamingizni yuboring 👇",
        'file'=>"4️⃣ Loyiha faylingizni (pitch deck, taqdimot, hujjat yoki rasm) yuboring.\nHujjat (PDF, DOCX, PPTX...) yoki rasm — istalgan formatda qabul qilinadi.",
    ];
    public const SUCCESS="✅ Loyihangiz muvaffaqiyatli qabul qilindi!\n\n3 ish kuni ichida sizga saralash javoblarini jo'natamiz.\n\nPivot jamoasi 🚀";
    private Queue $queue;
    private int $updateId=0;
    private int $sequence=0;
    private ?string $createdFile=null;
    public function __construct(private Db $db,private TelegramTransport $telegram,private array $config) { $this->queue=new Queue($db,$telegram); }
    public function ingest(array $update): bool {
        if (!isset($update['update_id']) || !is_int($update['update_id']) || $update['update_id']<0) throw new \InvalidArgumentException('Invalid update');
        return $this->db->run('INSERT IGNORE INTO updates(id,payload,received_at,next_attempt_at) VALUES(?,?,NOW(),NOW())',[$update['update_id'],json_encode($update,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)])->rowCount()===1;
    }
    public function process(int $id): bool {
        $this->updateId=$id; $this->sequence=0; $this->createdFile=null;
        $this->db->pdo->beginTransaction();
        try {
            $row=$this->db->one('SELECT * FROM updates WHERE id=? FOR UPDATE',[$id]);
            if (!$row || $row['processed_at']) { $this->db->pdo->commit(); return false; }
            $this->handle(json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR));
            $this->db->run('UPDATE updates SET processed_at=NOW(),last_error=NULL WHERE id=?',[$id]);
            $this->db->pdo->commit(); return true;
        } catch (\Throwable $e) {
            $this->db->pdo->rollBack();
            if ($this->createdFile && is_file($this->createdFile)) unlink($this->createdFile);
            throw $e;
        }
    }
    private function say(int $user,string $text,?array $markup=null): void {
        $payload=['chat_id'=>$user,'text'=>$text]; if($markup!==null) $payload['reply_markup']=$markup;
        $this->queue->add('update:'.$this->updateId.':'.++$this->sequence,$user,$payload);
    }
    public static function menu(): array { return ['keyboard'=>[[['text'=>self::APPLY]],[['text'=>self::STATUS],['text'=>self::ABOUT]]],'resize_keyboard'=>true]; }
    private function prompt(int $user,string $step): void {
        $rows=[]; if($step==='phone') $rows[]=[['text'=>'📱 Telefon raqamni yuborish','request_contact'=>true]];
        $rows[]=[['text'=>'⬅️ Orqaga'],['text'=>'❌ Bekor qilish']];
        $this->say($user,self::PROMPTS[$step].($step==='file'?"\n\nTelegram Bot API orqali yuklab olish chegarasi: 20 MB. Kattaroq faylni kichraytirib yuboring.":''),['keyboard'=>$rows,'resize_keyboard'=>true]);
    }
    private function member(int $user): bool {
        try {
            $r=$this->telegram->call('getChatMember',['chat_id'=>$this->config['channel'],'user_id'=>$user]);
            $ok=in_array($r['status']??'',['creator','administrator','member'],true)||(($r['status']??'')==='restricted'&&!empty($r['is_member']));
            $this->db->run('UPDATE users SET membership_checked_at=NOW()'.($ok?',membership_passed_at=NOW()':'').' WHERE id=?',[$user]);
        } catch (TelegramException) { $ok=false; }
        if(!$ok) $this->say($user,"Davom etish uchun @pivotuzbekistan kanaliga obuna bo‘ling. Tekshirish vaqtincha ishlamasa, qayta urinib ko‘ring. Ariza qoralamangiz saqlanadi.",['inline_keyboard'=>[[['text'=>'Kanalga obuna bo‘lish','url'=>'https://t.me/pivotuzbekistan']],[['text'=>'Obunani tekshirish','callback_data'=>'check_subscription']]]]);
        return $ok;
    }
    private function registrationOpen(array $s): bool { return $s['registration_open']==='1' && (empty($s['deadline'])||new \DateTimeImmutable()<=new \DateTimeImmutable($s['deadline'])); }
    private function handle(array $update): void {
        $callback=$update['callback_query']??null; $message=$callback['message']??$update['message']??null;
        $from=$callback['from']??$message['from']??null;
        if(!$message || !$from || !empty($from['is_bot']) || ($message['chat']['type']??'')!=='private' || (int)$message['chat']['id']!==(int)$from['id']) return;
        $user=(int)$from['id']; $text=(string)($callback['data']??$message['text']??'');
        $this->db->run('INSERT INTO users(id,username,first_seen,last_seen) VALUES(?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE username=VALUES(username),last_seen=NOW()',[$user,$from['username']??null]);
        $this->db->one('SELECT id FROM users WHERE id=? FOR UPDATE',[$user]);
        if($callback) $this->queue->add('callback:'.$this->updateId,$user,['callback_query_id'=>$callback['id']],'bot',null,'answerCallbackQuery');
        $settings=$this->db->settings(); $draft=$this->db->one('SELECT * FROM drafts WHERE user_id=?',[$user]);
        $app=$this->db->one('SELECT * FROM applications WHERE user_id=?',[$user]);
        if($text===self::ABOUT || $text==='/about') {
            $this->say($user,$settings['event_title']."\nStartup Garage va Yandex Uzbekistan tashkil etadi.\n".$settings['event_date'].' · '.$settings['event_time']." (Asia/Tashkent)\n".$settings['venue']."\n\nAriza topshirish finalga qabul qilinish degani emas. Bu Pivot botining alohida ariza bazasi; @SGdemodaybot bilan sinxronlanmaydi.\nYordam: ".$settings['support'],self::menu()); return;
        }
        if($text===self::STATUS || $text==='/status') {
            $this->say($user,$app?'Ariza: '.$app['reference']."\nHolati: ".self::statusLabel($app['status'])."\nKo‘rib chiqish muddati: ".$app['review_due_at'].' (Asia/Tashkent)':($draft?'Tugallanmagan arizangiz saqlangan. Ariza topshirish tugmasi orqali davom eting.':'Hozircha arizangiz yo‘q.'),self::menu()); return;
        }
        if(in_array($text,['❌ Bekor qilish','/cancel'],true)) {
            if($draft) $this->db->run('UPDATE drafts SET cancelled=1,updated_at=NOW() WHERE user_id=?',[$user]);
            $this->say($user,'Ariza to‘xtatildi. Qoralama saqlandi; ariza topshirish tugmasi bilan davom ettirishingiz mumkin.',self::menu()); return;
        }
        if($app) { $this->say($user,'Arizangiz allaqachon qabul qilingan: '.$app['reference'],self::menu()); return; }
        if(in_array($text,['/start',self::APPLY,'check_subscription'],true)) {
            if(!$this->member($user)) return;
            if(!$this->registrationOpen($settings)) { $this->say($user,'Arizalar qabuli yopilgan. Qoralamangiz saqlanadi. Yordam: '.$settings['support'],self::menu()); return; }
            if($text==='/start' && !$draft) { $this->say($user,'Pivot Uzbekistan — Grand Demo Day. Ariza topshirish uchun quyidagi tugmani bosing.',self::menu()); return; }
            if(!$draft) {
                $this->db->run("INSERT INTO drafts(user_id,started_at,updated_at) VALUES(?,NOW(),NOW())",[$user]);
                $this->say($user,"Ajoyib! Endi arizani to'ldiramiz."); $this->prompt($user,'name'); return;
            }
            $this->db->run('UPDATE drafts SET cancelled=0,updated_at=NOW() WHERE user_id=?',[$user]);
            if($draft['step']==='file' && $draft['pending_file']) { $this->submit($user,$draft,$settings); return; }
            $this->prompt($user,$draft['step']); return;
        }
        if(!$draft || $draft['cancelled']) { $this->say($user,'Ariza topshirish tugmasi orqali boshlang yoki davom eting.',self::menu()); return; }
        if($text==='⬅️ Orqaga' || $text==='/back') {
            $steps=array_keys(self::PROMPTS); $step=$steps[max(0,array_search($draft['step'],$steps,true)-1)];
            $this->db->run('UPDATE drafts SET step=?,pending_file=NULL,updated_at=NOW() WHERE user_id=?',[$step,$user]); $this->prompt($user,$step); return;
        }
        switch($draft['step']) {
            case 'name': case 'startup':
                $text=trim($text);
                if(mb_strlen($text)<2 || mb_strlen($text)>200 || preg_match('/[\x00-\x1F]/u',$text)) { $this->say($user,'Iltimos, 2–200 belgidan iborat matn kiriting.'); return; }
                $field=$draft['step']==='name'?'full_name':'startup'; $next=$field==='full_name'?'startup':'phone'; $max=$field==='full_name'?2:3;
                $this->db->run("UPDATE drafts SET $field=?,step=?,max_step=GREATEST(max_step,?),updated_at=NOW() WHERE user_id=?",[$text,$next,$max,$user]); $this->prompt($user,$next); return;
            case 'phone':
                $contact=$message['contact']??[]; $phone=$contact['phone_number']??'';
                if((int)($contact['user_id']??0)!==$user || !preg_match('/^\+?[0-9]{7,15}$/D',$phone)) { $this->say($user,'Faqat o‘zingizning Telegram kontaktingizni pastdagi tugma orqali yuboring.'); $this->prompt($user,'phone'); return; }
                $this->db->run("UPDATE drafts SET phone=?,step='file',max_step=4,updated_at=NOW() WHERE user_id=?",[$phone,$user]); $this->prompt($user,'file'); return;
            case 'file':
                $file=$message['document']??null;
                if(!$file && !empty($message['photo'])) { $file=end($message['photo']); $file['file_name']='photo.jpg'; }
                if(!$file || empty($file['file_id'])) { $this->prompt($user,'file'); return; }
                if(($file['file_size']??0)>Telegram::MAX_FILE) { $this->say($user,'Fayl 20 MB dan katta. Iltimos, kichikroq fayl yuboring. Qoralamangiz saqlanadi.'); return; }
                $draft['pending_file']=json_encode($file,JSON_THROW_ON_ERROR);
                $this->db->run('UPDATE drafts SET pending_file=?,updated_at=NOW() WHERE user_id=?',[$draft['pending_file'],$user]);
                if(!$this->member($user)) return;
                $this->submit($user,$draft,$settings); return;
        }
    }
    private function submit(int $user,array $draft,array $settings): void {
        if(!$this->registrationOpen($settings)) { $this->say($user,'Arizalar qabuli yopilgan. Qoralamangiz saqlanadi. Yordam: '.$settings['support'],self::menu()); return; }
        $file=json_decode($draft['pending_file'],true,512,JSON_THROW_ON_ERROR);
        $key=bin2hex(random_bytes(24)).'.bin'; $path=$this->config['storage'].'/private/'.$key;
        $this->createdFile=$path;
        try { $size=$this->telegram->download($file['file_id'],$path); }
        catch(\LengthException) { $this->db->run('UPDATE drafts SET pending_file=NULL WHERE user_id=?',[$user]); $this->say($user,'Faylni yuklab bo‘lmadi: 20 MB chegarasi. Kichikroq fayl yuboring.'); return; }
        catch(TelegramException $e) { if($e->apiCode!==400) throw $e; $this->db->run('UPDATE drafts SET pending_file=NULL WHERE user_id=?',[$user]); $this->say($user,'Telegram faylni yuklab bera olmadi. 20 MB dan oshmaydigan faylni qayta yuboring. Qoralamangiz saqlanadi.'); return; }
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($path)?:'application/octet-stream';
        $name=mb_substr(preg_replace('/[\x00-\x1F\x7F\/\\\\]/u','_', (string)($file['file_name']??'document')),0,240);
        $ref='PVT-'.date('Y').'-'.strtoupper(bin2hex(random_bytes(5)));
        $due=BusinessDays::due(new \DateTimeImmutable(),json_decode($settings['holidays'],true,512,JSON_THROW_ON_ERROR));
        $this->db->run('INSERT INTO applications(reference,user_id,full_name,startup,phone,file_key,file_name,file_mime,file_size,submitted_at,review_due_at) VALUES(?,?,?,?,?,?,?,?,?,NOW(),?)',[$ref,$user,$draft['full_name'],$draft['startup'],$draft['phone'],$key,$name,$mime,$size,$due]);
        $app=(int)$this->db->pdo->lastInsertId();
        $this->db->run("UPDATE drafts SET step='completed',max_step=5,pending_file=NULL,updated_at=NOW() WHERE user_id=?",[$user]);
        $this->db->audit(null,$app,'submitted',$ref);
        $this->say($user,self::SUCCESS."\n\nAriza raqami: ".$ref,self::menu());
    }
    public static function statusLabel(string $status): string { return ['new'=>'Yangi','under review'=>'Ko‘rib chiqilmoqda','shortlisted'=>'Qisqa ro‘yxatga kiritildi','accepted'=>'Qabul qilindi','rejected'=>'Rad etildi'][$status]??$status; }
}
