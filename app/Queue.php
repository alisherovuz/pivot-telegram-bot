<?php
declare(strict_types=1);
namespace Pivot;
final class Queue {
    public function __construct(private Db $db,private TelegramTransport $telegram) {}
    public function add(string $key,int $user,array $payload,string $kind='bot',?int $app=null,string $method='sendMessage'): void {
        $this->db->run('INSERT IGNORE INTO notifications(dedupe_key,user_id,application_id,method,payload,kind,available_at,created_at) VALUES(?,?,?,?,?,?,NOW(),NOW())',[$key,$user,$app,$method,json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$kind]);
    }
    public function deliver(int $limit=20): int {
        // Caller holds the cron advisory lock. Recover interrupted deliveries after 5 minutes.
        $this->db->run("UPDATE notifications SET status='pending',locked_at=NULL WHERE status='sending' AND locked_at < DATE_SUB(NOW(),INTERVAL 5 MINUTE)");
        $rows=$this->db->all("SELECT * FROM notifications WHERE status='pending' AND available_at<=NOW() ORDER BY id LIMIT ".max(1,min($limit,100)));
        foreach($rows as $r) {
            $this->db->run("UPDATE notifications SET status='sending',locked_at=NOW(),attempts=attempts+1 WHERE id=?",[$r['id']]);
            try {
                $result=$this->telegram->call($r['method'],json_decode($r['payload'],true,512,JSON_THROW_ON_ERROR));
                $this->db->run("UPDATE notifications SET status='sent',sent_at=NOW(),locked_at=NULL,last_error=NULL,telegram_message_id=? WHERE id=?",[$result['message_id']??null,$r['id']]);
            } catch (TelegramException $e) {
                $attempt=(int)$r['attempts']+1; $permanent=in_array($e->apiCode,[400,401,403,404],true);
                $status=$permanent||$attempt>=5?'failed':'pending'; $delay=max($e->retryAfter,min(3600,30*(2**$attempt)));
                $this->db->run('UPDATE notifications SET status=?,locked_at=NULL,last_error=?,available_at=DATE_ADD(NOW(),INTERVAL ? SECOND) WHERE id=?',[$status,$e->getMessage(),$delay,$r['id']]);
            }
        }
        return count($rows);
    }
}
