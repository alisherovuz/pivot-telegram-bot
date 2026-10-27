<?php
declare(strict_types=1);
namespace Pivot;
final class Applications {
    public const STATUSES=['new','under review','shortlisted','accepted','rejected'];
    public function __construct(private Db $db) {}
    public static function date(string $value): string {
        if($value==='') return '';
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        if(!$d || $d->format('Y-m-d')!==$value) throw new \InvalidArgumentException('Invalid date');
        return $value;
    }
    public static function range(array $input): array {
        $from=self::date((string)($input['from']??'')); $to=self::date((string)($input['to']??''));
        if($from && $to && $from>$to) throw new \InvalidArgumentException('Start date must precede end date');
        return [$from,$to];
    }
    public function search(array $filter,int $limit=1000,int $offset=0): array {
        [$where,$params]=$this->where($filter);
        return $this->db->all('SELECT a.*,u.username,ad.email AS reviewer,(SELECT AVG(score) FROM ratings r WHERE r.application_id=a.id) AS rating FROM applications a JOIN users u ON u.id=a.user_id LEFT JOIN admins ad ON ad.id=a.assigned_to WHERE '.$where.' ORDER BY a.id DESC LIMIT '.max(1,min($limit,10001)).' OFFSET '.max(0,$offset),$params);
    }
    public function count(array $filter): int { [$where,$params]=$this->where($filter); return (int)$this->db->value('SELECT COUNT(*) FROM applications a WHERE '.$where,$params); }
    private function where(array $f): array {
        [$from,$to]=self::range($f); $where=['1=1']; $p=[];
        if(!empty($f['q'])) { $where[]='(a.full_name LIKE ? OR a.startup LIKE ? OR a.reference LIKE ? OR a.phone LIKE ?)'; $v='%'.mb_substr((string)$f['q'],0,200).'%'; array_push($p,$v,$v,$v,$v); }
        if(!empty($f['status'])) { if(!in_array($f['status'],self::STATUSES,true)) throw new \InvalidArgumentException('Invalid status'); $where[]='a.status=?'; $p[]=$f['status']; }
        if(!empty($f['assigned'])) { $where[]='a.assigned_to=?'; $p[]=(int)$f['assigned']; }
        if(!empty($f['overdue'])) $where[]="a.review_due_at<NOW() AND a.status IN ('new','under review')";
        if($from) { $where[]='a.submitted_at>=?'; $p[]=$from.' 00:00:00'; }
        if($to) { $where[]='a.submitted_at<?'; $p[]=(new \DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d').' 00:00:00'; }
        return [implode(' AND ',$where),$p];
    }
}
