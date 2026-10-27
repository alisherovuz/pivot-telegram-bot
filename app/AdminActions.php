<?php
declare(strict_types=1);
namespace Pivot;
final class AdminActions {
    public function __construct(private Db $db,private Queue $queue) {}
    public function perform(array $a,array $p): string {
        $action=(string)($p['action']??''); $id=(int)($p['id']??0); $actor=(int)$a['id'];
        if($action==='password') {
            if(!password_verify((string)($p['current_password']??''),$a['password_hash'])) throw new \InvalidArgumentException('Current password is incorrect');
            $hash=Auth::password((string)($p['password']??''));
            $this->db->run('UPDATE admins SET password_hash=?,session_version=session_version+1 WHERE id=?',[$hash,$actor]);
            $_SESSION['session_version']=(int)$a['session_version']+1; session_regenerate_id(true);
            $this->db->audit($actor,null,'password_changed','Own password changed'); return '?page=account';
        }
        if(in_array($action,['review','note','rating','preview','send'],true)) {
            $app=$this->db->one('SELECT * FROM applications WHERE id=? FOR UPDATE',[$id]);
            if(!$app) throw new \InvalidArgumentException('Application not found');
            if($action==='review') {
                $status=(string)($p['status']??''); if(!in_array($status,Applications::STATUSES,true)) throw new \InvalidArgumentException('Invalid status');
                $assigned=empty($p['assigned_to'])?null:(int)$p['assigned_to'];
                if($assigned && !$this->db->one('SELECT id FROM admins WHERE id=? AND active=1',[$assigned])) throw new \InvalidArgumentException('Invalid reviewer');
                $this->db->run('UPDATE applications SET status=?,assigned_to=? WHERE id=?',[$status,$assigned,$id]);
                $this->db->audit($actor,$id,'review_changed',json_encode(['before'=>['status'=>$app['status'],'assigned_to'=>$app['assigned_to']],'after'=>['status'=>$status,'assigned_to'=>$assigned]],JSON_THROW_ON_ERROR));
            } elseif($action==='note') {
                $text=trim((string)($p['body']??'')); if($text===''||mb_strlen($text)>5000) throw new \InvalidArgumentException('Note must contain 1–5000 characters');
                $this->db->run('INSERT INTO notes(application_id,admin_id,body,created_at) VALUES(?,?,?,NOW())',[$id,$actor,$text]); $this->db->audit($actor,$id,'note_added','Internal note added');
            } elseif($action==='rating') {
                $score=filter_var($p['score']??null,FILTER_VALIDATE_INT); if($score<1||$score>5) throw new \InvalidArgumentException('Rating must be 1–5');
                $this->db->run('INSERT INTO ratings(application_id,admin_id,score,updated_at) VALUES(?,?,?,NOW()) ON DUPLICATE KEY UPDATE score=VALUES(score),updated_at=NOW()',[$id,$actor,$score]); $this->db->audit($actor,$id,'rating_changed',(string)$score);
            } elseif($action==='preview') {
                $text=trim((string)($p['message']??'')); if($text===''||mb_strlen($text)>4000) throw new \InvalidArgumentException('Message must contain 1–4000 characters');
                $_SESSION['notification_preview']=['id'=>$id,'text'=>$text,'nonce'=>bin2hex(random_bytes(20)),'expires'=>time()+900];
            } else {
                $preview=$_SESSION['notification_preview']??null;
                if(!$preview || $preview['id']!==$id || $preview['expires']<time() || !hash_equals($preview['nonce'],(string)($p['nonce']??''))) throw new \InvalidArgumentException('Preview expired. Preview the message again.');
                $this->queue->add('admin:'.$preview['nonce'],(int)$app['user_id'],['chat_id'=>(int)$app['user_id'],'text'=>$preview['text']],'review',$id);
                $this->db->audit($actor,$id,'notification_queued',$preview['text']); unset($_SESSION['notification_preview']);
            }
            return '?page=application&id='.$id;
        }
        Auth::owner($a);
        if($action==='settings') {
            $values=[];
            foreach(['event_title','event_date','event_time','venue','support','deadline','notification_template'] as $k) { $values[$k]=trim((string)($p[$k]??'')); if($values[$k]===''||mb_strlen($values[$k])>2000) throw new \InvalidArgumentException('Invalid setting: '.$k); }
            Applications::date($values['event_date']);
            $deadline=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$values['deadline']);
            if(!$deadline || $deadline->format('Y-m-d H:i:s')!==$values['deadline']) throw new \InvalidArgumentException('Deadline format: YYYY-MM-DD HH:MM:SS');
            $holidays=preg_split('/[\s,]+/',trim((string)($p['holidays']??'')),-1,PREG_SPLIT_NO_EMPTY);
            if(count($holidays)>365) throw new \InvalidArgumentException('At most 365 holidays');
            foreach($holidays as $day) Applications::date($day);
            $values['holidays']=json_encode(array_values(array_unique($holidays)),JSON_THROW_ON_ERROR);
            $values['registration_open']=isset($p['registration_open'])?'1':'0';
            foreach($values as $key=>$value) $this->db->run('UPDATE settings SET value=? WHERE `key`=?',[$value,$key]);
            $this->db->audit($actor,null,'settings_changed',json_encode($values,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)); return '?page=settings';
        }
        if($action==='admin_create') {
            $email=mb_strtolower(trim((string)($p['email']??''))); if(!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>190) throw new \InvalidArgumentException('Invalid email');
            $role=(string)($p['role']??''); if(!in_array($role,['owner','reviewer'],true)) throw new \InvalidArgumentException('Invalid role');
            $this->db->run('INSERT INTO admins(email,password_hash,role) VALUES(?,?,?)',[$email,Auth::password((string)($p['password']??'')),$role]);
            $this->db->audit($actor,null,'admin_created',$email.' '.$role); return '?page=team';
        }
        if($action==='admin_reset' || $action==='admin_toggle') {
            if($id===$actor) throw new \InvalidArgumentException('Use your account page to manage your own password');
            $target=$this->db->one('SELECT id,email FROM admins WHERE id=?',[$id]); if(!$target) throw new \InvalidArgumentException('Admin not found');
            if($action==='admin_reset') $this->db->run('UPDATE admins SET password_hash=?,session_version=session_version+1 WHERE id=?',[Auth::password((string)($p['password']??'')),$id]);
            else $this->db->run('UPDATE admins SET active=1-active,session_version=session_version+1 WHERE id=?',[$id]);
            $this->db->audit($actor,null,$action,$target['email']); return '?page=team';
        }
        if($action==='retry') {
            $this->db->run("UPDATE notifications SET status='pending',attempts=0,available_at=NOW(),last_error=NULL WHERE id=? AND status='failed'",[$id]);
            $this->db->audit($actor,null,'notification_retry',(string)$id); return '?page=queue';
        }
        if($action==='retry_update') {
            $this->db->run('UPDATE updates SET attempts=0,next_attempt_at=NOW(),last_error=NULL WHERE id=? AND processed_at IS NULL',[$id]);
            $this->db->audit($actor,null,'update_retry',(string)$id); return '?page=queue';
        }
        throw new \InvalidArgumentException('Unknown action');
    }
}
