<?php
declare(strict_types=1);
namespace Pivot;

/** Private-chat admin UI. Bot calls this only after matching chat ID to sender ID. */
final class AdminBot {
    private int $user;
    private int $update;
    private int $sequence=0;
    private ?int $messageId=null;
    private array $admin;
    public function __construct(private Db $db,private Queue $queue) {}

    public static function account(Db $db,int $user): ?array {
        $id=$db->value('SELECT value FROM settings WHERE `key`=?',['telegram_admin:'.$user]);
        if(!is_string($id) || !ctype_digit($id) || (int)$id<1) return null;
        return $db->one('SELECT id,role,email FROM admins WHERE id=? AND active=1',[(int)$id]);
    }
    private static function button(string $text,string $data): array { return ['text'=>$text,'callback_data'=>'adm:'.$data]; }
    private function say(string $text,array $rows=[],?string $back='home'): void {
        if($back!==null) $rows[]=[self::button('← Back',$back)];
        $payload=['chat_id'=>$this->user,'text'=>$text,'reply_markup'=>['inline_keyboard'=>$rows],'_admin_id'=>(int)$this->admin['id']];
        if($this->messageId!==null) $payload['message_id']=$this->messageId;
        $this->queue->add('admin-ui:'.$this->update.':'.++$this->sequence,$this->user,
            $payload, 'telegram_admin',null,$this->messageId===null?'sendMessage':'editMessageText');
    }
    public function handle(int $user,string $text,int $update,?int $messageId=null): bool {
        if(preg_match('~^/whoami(?:@[A-Za-z0-9_]+)?$~D',$text)) {
            $this->queue->add('whoami:'.$update,$user,['chat_id'=>$user,'text'=>'Your Telegram ID: '.$user]); return true;
        }
        $command=preg_match('~^/admin(?:@[A-Za-z0-9_]+)?$~D',$text);
        if(!$command && !str_starts_with($text,'adm:')) return false;
        $this->user=$user; $this->update=$update; $this->messageId=$messageId && $messageId>0?$messageId:null;
        $admin=self::account($this->db,$user);
        if(!$admin) {
            $this->queue->add('admin-denied:'.$update,$user,['chat_id'=>$user,'text'=>'Admin access is not enabled for this Telegram account.']); return true;
        }
        $this->admin=$admin; $route=$command?'home':substr($text,4);
        if($route==='home') {
            $this->db->run('DELETE FROM settings WHERE `key`=?',['telegram_confirm:'.$user]);
            $this->home();
        }
        elseif($route==='cancel') {
            $raw=$this->db->value('SELECT value FROM settings WHERE `key`=?',['telegram_confirm:'.$user]);
            $pending=$raw?json_decode($raw,true):null;
            $this->db->run('DELETE FROM settings WHERE `key`=?',['telegram_confirm:'.$user]);
            if(!empty($pending['action']['id'])) $this->application((int)$pending['action']['id']);
            else $this->home();
        }
        elseif(preg_match('/^list:(all|[0-4]):([0-9]{1,6})$/D',$route,$m)) $this->listing($m[1],(int)$m[2]);
        elseif(preg_match('/^app:([1-9][0-9]{0,9})$/D',$route,$m)) $this->application((int)$m[1]);
        elseif($route==='filters') {
            $rows=[[self::button('All applications','list:all:0')]];
            foreach(Applications::STATUSES as $i=>$status) $rows[]=[self::button(ucfirst($status),'list:'.$i.':0')];
            $this->say('Show which applications?',$rows,$this->listBack());
        }
        elseif(preg_match('/^exports:(all|[0-4])$/D',$route,$m)) {
            $this->say('Download applications · '.(self::filter($m[1])['status']??'all'),[[self::button('Excel spreadsheet','doc:xlsx:0:'.$m[1])],[self::button('PDF report','doc:pdf:0:'.$m[1])]],$this->listBack());
        }
        elseif(preg_match('/^(review|more):([1-9][0-9]{0,9})$/D',$route,$m)) {
            $a=$this->db->one('SELECT * FROM applications WHERE id=?',[(int)$m[2]]);
            if(!$a) { $this->say('Application not found.'); return true; }
            if($m[1]==='review') {
                $rows=[];
                foreach(Applications::STATUSES as $i=>$status) if($status!==$a['status']) $rows[]=[self::button(ucfirst($status),'status:'.$a['id'].':'.$i)];
                $this->say($a['startup']."\nCurrent status: ".ucfirst($a['status'])."\n\nChoose a new status. You’ll confirm it next.",$rows,'app:'.$a['id']);
            } else $this->say($a['startup']."\nMore options",[[self::button('Download application PDF','doc:applicationpdf:'.$a['id'].':all')],[self::button('Message applicant','notify:'.$a['id'])]],'app:'.$a['id']);
        }
        elseif($route==='settings') {
            if($admin['role']!=='owner') { $this->say('Owner access required.'); return true; }
            $settings=$this->db->settings();
            $this->say('Registration is '.($settings['registration_open']==='1'?'enabled':'paused').".\nDeadline: ".$settings['deadline']." (Tashkent)\nThe deadline still applies when registration is enabled.",[[self::button($settings['registration_open']==='1'?'Pause registration':'Enable registration','registration:'.($settings['registration_open']==='1'?'0':'1'))]]);
        }
        elseif($route==='stats') {
            $metrics=(new Statistics($this->db))->report()['metrics'];
            $this->say("Statistics · all time\n\nApplications: ".$metrics['Applications submitted in period']."\nAwaiting review: ".$metrics['Pending reviews']."\nOverdue: ".$metrics['Overdue reviews']."\nCompletion rate: ".$metrics['Completion rate (%)']."%\n\nCompletion is measured from started applications.",[[self::button('Download report','statsreports')]]);
        } elseif($route==='statsreports') {
            $this->say('Download statistics',[[self::button('Excel spreadsheet','doc:statsxlsx:0:all')],[self::button('PDF report','doc:statspdf:0:all')]],'stats');
        } elseif(preg_match('/^doc:(original|applicationpdf|xlsx|pdf|statsxlsx|statspdf):([0-9]{1,10}):(all|[0-4])$/D',$route,$m)) {
            $type=$m[1]; $id=(int)$m[2]; $filter=self::filter($m[3]);
            if(in_array($type,['original','applicationpdf'],true) && !$this->db->one('SELECT id FROM applications WHERE id=?',[$id])) { $this->say('Application not found.'); return true; }
            if(in_array($type,['xlsx','pdf'],true) && (new Applications($this->db))->count($filter)>($type==='pdf'?300:10000)) { $this->say('Too many applications for one export. Use a status filter or the website date filters.'); return true; }
            $key='telegram_export:'.$user;
            if((int)$this->db->value('SELECT value FROM settings WHERE `key`=?',[$key])>time()-10) { $this->say('Please wait 10 seconds between document requests.'); return true; }
            $this->save($key,(string)time());
            $this->queue->add('admin-doc:'.$update,$user,['chat_id'=>$user,'type'=>$type,'id'=>$id,'filter'=>$filter,'_admin_id'=>(int)$admin['id']],'telegram_admin',null,'adminDocument');
            $this->db->audit((int)$admin['id'],$id?:null,'telegram_document_requested',$type);
        } elseif(preg_match('/^status:([1-9][0-9]{0,9}):([0-4])$/D',$route,$m)) {
            $app=$this->db->one('SELECT * FROM applications WHERE id=?',[(int)$m[1]]);
            if(!$app) { $this->say('Application not found.'); return true; }
            $this->confirm(['action'=>'status','id'=>(int)$app['id'],'status'=>Applications::STATUSES[(int)$m[2]],'before'=>$app['status']],
                'Change '.$app['reference'].' to “'.Applications::STATUSES[(int)$m[2]].'”? This does not message the applicant.');
        } elseif(preg_match('/^notify:([1-9][0-9]{0,9})$/D',$route,$m)) {
            $app=$this->db->one('SELECT * FROM applications WHERE id=?',[(int)$m[1]]);
            if(!$app) { $this->say('Application not found.'); return true; }
            $template=$this->db->settings()['notification_template'];
            $message=strtr($template,['{name}'=>$app['full_name'],'{reference}'=>$app['reference'],'{status}'=>Bot::statusLabel($app['status'])]);
            if(mb_strlen($message)>3500) { $this->say('This message is too long. Edit the template on the website.'); return true; }
            $this->confirm(['action'=>'notify','id'=>(int)$app['id'],'before'=>$app['status'],'text'=>$message],"Send this message to ".$app['reference']."?\n\n".$message);
        } elseif(preg_match('/^registration:([01])$/D',$route,$m)) {
            if($admin['role']!=='owner') { $this->say('Owner access required.'); return true; }
            $this->confirm(['action'=>'registration','value'=>$m[1]],($m[1]==='1'?'Open':'Close').' registration? The configured deadline still applies.');
        } elseif(preg_match('/^confirm:([a-f0-9]{32})$/D',$route,$m)) $this->execute($m[1]);
        else $this->say('This button is no longer available. Open the admin menu.');
        return true;
    }
    private function home(): void {
        $rows=[[self::button('📋 Applications','list:all:0')],[self::button('📊 Statistics','stats')]];
        if($this->admin['role']==='owner') $rows[]=[self::button('⚙️ Settings','settings')];
        $pending=(int)$this->db->value("SELECT COUNT(*) FROM applications WHERE status IN ('new','under review')");
        $this->say("Pivot admin · ".$this->admin['role']."\n\n$pending applications awaiting review.\nWhat would you like to do?",$rows,null);
    }
    private static function filter(string $status): array { return $status==='all'?[]:['status'=>Applications::STATUSES[(int)$status]]; }
    private function listing(string $status,int $page): void {
        $apps=new Applications($this->db); $filter=self::filter($status); $total=$apps->count($filter);
        $page=min($page,max(0,(int)ceil($total/4)-1)); $rows=[];
        $this->save('telegram_list:'.$this->user,$status.':'.$page);
        $this->db->run('DELETE FROM settings WHERE `key`=?',['telegram_confirm:'.$this->user]);
        foreach($apps->search($filter,4,$page*4) as $app) $rows[]=[self::button(mb_substr($app['startup'],0,30).' · '.mb_substr($app['full_name'],0,22),'app:'.$app['id'])];
        $nav=[]; if($page>0) $nav[]=self::button('← Previous','list:'.$status.':'.($page-1));
        if(($page+1)*4<$total) $nav[]=self::button('Next →','list:'.$status.':'.($page+1));
        if($nav) $rows[]=$nav;
        $rows[]=[self::button('Filter','filters'),self::button('Download list','exports:'.$status)];
        $this->say('Applications · '.($filter['status']??'all')."\n$total results · Page ".($page+1)."\n\n".($total?'Choose an application to review.':'No applications here yet.'),$rows);
    }
    private function listBack(): string {
        $value=(string)$this->db->value('SELECT value FROM settings WHERE `key`=?',['telegram_list:'.$this->user]);
        return preg_match('/^(all|[0-4]):[0-9]{1,6}$/D',$value)?'list:'.$value:'list:all:0';
    }
    private function application(int $id,string $notice=''): void {
        $a=$this->db->one('SELECT * FROM applications WHERE id=?',[$id]);
        if(!$a) { $this->say('Application not found.'); return; }
        $this->db->run('DELETE FROM settings WHERE `key`=?',['telegram_confirm:'.$this->user]);
        $rows=[[self::button('📎 Project file','doc:original:'.$id.':all')],[self::button('Review application','review:'.$id)],[self::button('More options','more:'.$id)]];
        $this->say(($notice?$notice."\n\n":'').$a['startup']."\n".$a['full_name']." · ".$a['phone']."\n\nStatus: ".ucfirst($a['status'])."\nReview due: ".$a['review_due_at']." (Tashkent)\nFile: ".$a['file_name']."\nReference: ".$a['reference'],$rows,$this->listBack());
    }
    private function save(string $key,string $value): void {
        $this->db->run('INSERT INTO settings (`key`,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',[$key,$value]);
    }
    private function confirm(array $action,string $preview): void {
        $nonce=bin2hex(random_bytes(16));
        $this->save('telegram_confirm:'.$this->user,json_encode(['nonce'=>$nonce,'admin'=>(int)$this->admin['id'],'expires'=>time()+300,'action'=>$action],JSON_THROW_ON_ERROR));
        $this->say($preview,[[self::button($action['action']==='notify'?'Send message':'Confirm','confirm:'.$nonce)],[self::button('Cancel','cancel')]],null);
    }
    private function execute(string $nonce): void {
        $key='telegram_confirm:'.$this->user;
        $raw=$this->db->value('SELECT value FROM settings WHERE `key`=? FOR UPDATE',[$key]);
        $pending=$raw?json_decode($raw,true):null;
        if(!$pending || !hash_equals($pending['nonce'],$nonce) || $pending['expires']<time() || $pending['admin']!==(int)$this->admin['id']) { $this->say('Confirmation expired or already used. Please preview again.'); return; }
        $this->db->run('DELETE FROM settings WHERE `key`=?',[$key]); $p=$pending['action'];
        if($p['action']==='registration') {
            if($this->admin['role']!=='owner') { $this->say('Owner access required.'); return; }
            $this->db->run("UPDATE settings SET value=? WHERE `key`='registration_open'",[$p['value']]);
            $this->db->audit((int)$this->admin['id'],null,'telegram_registration_changed',$p['value']); $this->home(); return;
        }
        $app=$this->db->one('SELECT * FROM applications WHERE id=? FOR UPDATE',[$p['id']]);
        if(!$app || $app['status']!==$p['before']) { $this->say('The application changed. Reopen it and preview again.'); return; }
        if($p['action']==='status') {
            (new AdminActions($this->db,$this->queue))->perform($this->admin,['action'=>'review','id'=>$app['id'],'status'=>$p['status'],'assigned_to'=>$app['assigned_to']]);
            $this->application((int)$app['id'],'✓ Status saved. The applicant has not been notified.');
        } elseif($p['action']==='notify') {
            $this->queue->add('telegram-review:'.$nonce,(int)$app['user_id'],['chat_id'=>(int)$app['user_id'],'text'=>$p['text']],'review',(int)$app['id']);
            $this->db->audit((int)$this->admin['id'],(int)$app['id'],'notification_queued',$p['text']);
            $this->application((int)$app['id'],'✓ Message queued. Usually delivered within one minute.');
        }
    }
    public static function document(Db $db,TelegramTransport $telegram,array $config,array $payload): array {
        $temp=null;
        try {
            $type=$payload['type']; $id=(int)$payload['id'];
            $record=$id?$db->one('SELECT a.*,ad.email AS reviewer,(SELECT AVG(score) FROM ratings WHERE application_id=a.id) AS rating FROM applications a LEFT JOIN admins ad ON ad.id=a.assigned_to WHERE a.id=?',[$id]):null;
            if(in_array($type,['original','applicationpdf'],true) && !$record) throw new TelegramException(404);
            if($type==='original') {
                if(!preg_match('/^[a-f0-9]{48}\.bin$/D',$record['file_key'])) throw new TelegramException(404);
                $base=realpath($config['storage'].'/private'); $path=realpath($config['storage'].'/private/'.$record['file_key']);
                if(!$base || !$path || dirname($path)!==$base || !is_file($path) || !is_readable($path)) throw new TelegramException(404);
                $name=$record['file_name']; $mime='application/octet-stream';
            } else {
                $statsOnly=in_array($type,['statsxlsx','statspdf'],true); $format=in_array($type,['xlsx','statsxlsx'],true)?'xlsx':'pdf';
                $apps=new Applications($db); $filter=$payload['filter'];
                if(!$statsOnly && !$record && $apps->count($filter)>($format==='pdf'?300:10000)) throw new TelegramException(400);
                $rows=$record?[$record]:($statsOnly?[]:$apps->search($filter,10000));
                $stats=$statsOnly?(new Statistics($db))->report():null;
                $body=$format==='xlsx'?Exports::xlsx($rows,$stats):Exports::pdf($rows,$stats,$config['storage'].'/cache');
                $temp=tempnam($config['storage'].'/cache','tg-export-');
                if($temp===false) throw new \RuntimeException('Export storage unavailable');
                if(!chmod($temp,0600) || file_put_contents($temp,$body)!==strlen($body)) throw new \RuntimeException('Cannot write export');
                $path=$temp; $name='pivot-'.($statsOnly?'statistics':($record?$record['reference']:'applications')).'.'.$format;
                $mime=$format==='pdf'?'application/pdf':'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            }
            if(filesize($path)>50*1024*1024) throw new TelegramException(400);
            return $telegram->call('sendDocument',['chat_id'=>$payload['chat_id'],'document'=>new \CURLFile($path,$mime,$name)]);
        } catch(TelegramException $e) { throw $e; }
        catch(\Throwable $e) { error_log('Pivot Telegram document failed: '.get_class($e)); throw new TelegramException(503); }
        finally { if(is_string($temp) && is_file($temp)) unlink($temp); }
    }
}
