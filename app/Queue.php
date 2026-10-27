<?php
declare(strict_types=1);
namespace Pivot;
final class Queue {
    public function __construct(private Db $db,private TelegramTransport $telegram) {}
    public function add(string $key,int $user,array $payload,string $kind='bot',?int $app=null,string $method='sendMessage'): void {
        $this->db->run('INSERT IGNORE INTO notifications(dedupe_key,user_id,application_id,method,payload,kind,available_at,created_at) VALUES(?,?,?,?,?,?,NOW(),NOW())',[$key,$user,$app,$method,json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$kind]);
    }
    public function deliver(int $limit=20, ?int $userId=null): int {
        $limit=max(1,min($limit,100));
        $where="((status='pending' AND available_at<=NOW()) OR (status='sending' AND locked_at<DATE_SUB(NOW(),INTERVAL 5 MINUTE)))";
        $params=[];
        if($userId!==null) { $where.=' AND user_id=?'; $params[]=$userId; }
        $rows=$this->db->all("SELECT id,user_id FROM notifications WHERE $where ORDER BY id LIMIT 100",$params);
        $attempted=0;
        foreach($rows as $candidate) {
            if($attempted >= $limit) break;
            // Cron and concurrent webhooks share this per-recipient lock.
            $database=(string)$this->db->value('SELECT DATABASE()');
            $lock='pivot-send-'.substr(hash('sha256',$database.':'.$candidate['user_id']),0,48);
            if((int)$this->db->value('SELECT GET_LOCK(?,0)',[$lock])!==1) continue;
            try {
                $this->db->run("UPDATE notifications SET status='pending',locked_at=NULL WHERE user_id=? AND status='sending' AND locked_at<DATE_SUB(NOW(),INTERVAL 5 MINUTE)",[$candidate['user_id']]);
                $r=$this->db->one("SELECT * FROM notifications WHERE user_id=? AND status IN ('pending','sending') ORDER BY id LIMIT 1",[$candidate['user_id']]);
                if(!$r || (int)$r['id']!==(int)$candidate['id'] || $r['status']!=='pending' || $r['available_at']>date('Y-m-d H:i:s')) continue;
                if($this->db->run("UPDATE notifications SET status='sending',locked_at=NOW(),attempts=attempts+1 WHERE id=? AND status='pending'",[$r['id']])->rowCount()!==1) continue;
                $attempted++;
                try {
                    $result=$this->telegram->call($r['method'],json_decode($r['payload'],true,512,JSON_THROW_ON_ERROR));
                    $this->db->run("UPDATE notifications SET status='sent',sent_at=NOW(),locked_at=NULL,last_error=NULL,telegram_message_id=? WHERE id=?",[$result['message_id']??null,$r['id']]);
                } catch (TelegramException $e) {
                    $attempt=(int)$r['attempts']+1; $permanent=in_array($e->apiCode,[400,401,403,404],true);
                    $status=$permanent||$attempt>=5?'failed':'pending'; $delay=max($e->retryAfter,min(3600,30*(2**$attempt)));
                    $this->db->run('UPDATE notifications SET status=?,locked_at=NULL,last_error=?,available_at=DATE_ADD(NOW(),INTERVAL ? SECOND) WHERE id=?',[$status,$e->getMessage(),$delay,$r['id']]);
                }
            } finally { $this->db->run('SELECT RELEASE_LOCK(?)',[$lock]); }
        }
        return $attempted;
    }
}
