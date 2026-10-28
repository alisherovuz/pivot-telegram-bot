<?php
declare(strict_types=1);
namespace Pivot;

/** Recorded private-chat interactions, including visitors without an application. */
final class BotUsers {
    public const STAGES=['all'=>'All users','not_submitted'=>'No application submitted','not_started'=>'Not started','draft'=>'Unfinished','submitted'=>'Submitted'];
    public function __construct(private Db $db) {}
    private function where(array $filter): array {
        $stage=(string)($filter['stage']??'all');
        if(!isset(self::STAGES[$stage])) throw new \InvalidArgumentException('Invalid user filter');
        [$from,$to]=Applications::range($filter); $where=['1=1']; $params=[];
        if($stage==='not_submitted') $where[]='a.id IS NULL';
        if($stage==='not_started') $where[]='a.id IS NULL AND d.user_id IS NULL';
        if($stage==='draft') $where[]='a.id IS NULL AND d.user_id IS NOT NULL';
        if($stage==='submitted') $where[]='a.id IS NOT NULL';
        if(!empty($filter['q'])) {
            $q=mb_substr(trim((string)$filter['q']),0,200);
            $where[]='(CAST(u.id AS CHAR) LIKE ? OR u.username LIKE ? OR a.full_name LIKE ? OR d.full_name LIKE ?)';
            array_push($params,'%'.$q.'%','%'.ltrim($q,'@').'%','%'.$q.'%','%'.$q.'%');
        }
        if($from) { $where[]='u.first_seen>=?'; $params[]=$from.' 00:00:00'; }
        if($to) { $where[]='u.first_seen<?'; $params[]=(new \DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d').' 00:00:00'; }
        return [implode(' AND ',$where),$params];
    }
    private const JOINS=' FROM users u LEFT JOIN drafts d ON d.user_id=u.id LEFT JOIN applications a ON a.user_id=u.id ';
    public function count(array $filter=[]): int {
        [$where,$params]=$this->where($filter);
        return (int)$this->db->value('SELECT COUNT(*)'.self::JOINS.'WHERE '.$where,$params);
    }
    public function search(array $filter=[],int $limit=30,int $offset=0): array {
        [$where,$params]=$this->where($filter);
        return $this->db->all('SELECT u.*,COALESCE(a.full_name,d.full_name) AS full_name,COALESCE(a.phone,d.phone) AS phone,d.step,d.cancelled,a.id AS application_id,a.reference,a.status'.self::JOINS.'WHERE '.$where.' ORDER BY u.last_seen DESC,u.id DESC LIMIT '.max(1,min(10000,$limit)).' OFFSET '.max(0,$offset),$params);
    }
    public function get(int $id): ?array {
        return $this->db->one('SELECT u.*,COALESCE(a.full_name,d.full_name) AS full_name,COALESCE(a.phone,d.phone) AS phone,d.step,d.cancelled,a.id AS application_id,a.reference,a.status'.self::JOINS.'WHERE u.id=?',[$id]);
    }
    public static function progress(array $user): string {
        if($user['application_id']!==null) return 'Submitted';
        if($user['step']===null) return 'Not started';
        $step=['name'=>'Name','startup'=>'Startup','phone'=>'Contact','file'=>'File'][$user['step']]??'Registration';
        return (!empty($user['cancelled'])?'Paused':'Unfinished').' · '.$step;
    }
    public function summary(): array {
        $row=$this->db->one('SELECT COUNT(*) AS total,COALESCE(SUM(u.first_seen>=CURDATE() AND u.first_seen<DATE_ADD(CURDATE(),INTERVAL 1 DAY)),0) AS new_today,COALESCE(SUM(u.last_seen>=DATE_SUB(NOW(),INTERVAL 7 DAY)),0) AS active_7_days,COALESCE(SUM(a.id IS NULL AND d.user_id IS NULL),0) AS not_started,COALESCE(SUM(a.id IS NULL AND d.user_id IS NOT NULL),0) AS unfinished,COALESCE(SUM(a.id IS NOT NULL),0) AS submitted'.self::JOINS);
        return array_map('intval',$row);
    }
}
