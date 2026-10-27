<?php
declare(strict_types=1);
namespace Pivot;
final class Statistics {
    public function __construct(private Db $db) {}
    public function report(array $filter=[]): array {
        [$from,$to]=Applications::range($filter);
        $period=function(string $column) use($from,$to): array {
            $s='1=1'; $p=[];
            if($from) { $s.=" AND $column>=?"; $p[]=$from.' 00:00:00'; }
            if($to) { $s.=" AND $column<?"; $p[]=(new \DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d').' 00:00:00'; }
            return [$s,$p];
        };
        [$u,$up]=$period('first_seen'); [$d,$dp]=$period('d.started_at'); [$a,$ap]=$period('submitted_at'); [$n,$np]=$period('created_at');
        $count=fn(string $sql,array $p=[])=>(int)$this->db->value($sql,$p);
        $users=$count("SELECT COUNT(*) FROM users WHERE $u",$up);
        $started=$count("SELECT COUNT(*) FROM drafts d WHERE $d",$dp);
        $completed=$count("SELECT COUNT(*) FROM drafts d JOIN applications a ON a.user_id=d.user_id WHERE $d",$dp);
        $metrics=[
            'Total bot users (all time)'=>$count('SELECT COUNT(*) FROM users'),
            'New users in period'=>$users,
            'Users in cohort with recorded successful membership check'=>$count("SELECT COUNT(*) FROM users WHERE $u AND membership_passed_at IS NOT NULL",$up),
            'Started applications (start-date cohort)'=>$started,
            'Completed in start-date cohort'=>$completed,
            'Unfinished in start-date cohort'=>$started-$completed,
            'Completion rate (%)'=>$started?round($completed/$started*100,1):0,
            'Applications submitted in period'=>$count("SELECT COUNT(*) FROM applications WHERE $a",$ap),
            'Pending reviews'=>$count("SELECT COUNT(*) FROM applications WHERE $a AND status IN ('new','under review')",$ap),
            'Overdue reviews'=>$count("SELECT COUNT(*) FROM applications WHERE $a AND status IN ('new','under review') AND review_due_at<NOW()",$ap),
        ];
        $steps=[];
        foreach(['Name','Startup','Contact','Attachment'] as $index=>$label) {
            $step=$index+1;
            $reached=$count("SELECT COUNT(*) FROM drafts d WHERE $d AND d.max_step>=?",[...$dp,$step]);
            $drop=$count("SELECT COUNT(*) FROM drafts d LEFT JOIN applications a ON a.user_id=d.user_id WHERE $d AND d.max_step=? AND a.id IS NULL",[...$dp,$step]);
            $steps[]=['step'=>$label,'reached'=>$reached,'unfinished_at_furthest_step'=>$drop];
        }
        $statuses=[]; foreach(Applications::STATUSES as $s) $statuses[]=['status'=>$s,'count'=>$count("SELECT COUNT(*) FROM applications WHERE $a AND status=?",[...$ap,$s])];
        return ['period'=>['from'=>$from,'to'=>$to],'metrics'=>$metrics,'steps'=>$steps,'statuses'=>$statuses,
            'daily_applications'=>$this->db->all("SELECT DATE(submitted_at) AS day,COUNT(*) AS count FROM applications WHERE $a GROUP BY DATE(submitted_at) ORDER BY day",$ap),
            'daily_users'=>$this->db->all("SELECT DATE(first_seen) AS day,COUNT(*) AS count FROM users WHERE $u GROUP BY DATE(first_seen) ORDER BY day",$up),
            'notifications'=>$this->db->all("SELECT status,kind,COUNT(*) AS count FROM notifications WHERE $n GROUP BY status,kind ORDER BY status,kind",$np)];
    }
}
