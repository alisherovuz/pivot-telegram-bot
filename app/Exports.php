<?php
declare(strict_types=1);
namespace Pivot;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Dompdf\Dompdf;
use Dompdf\Options;
final class Exports {
    public static function escape(mixed $s): string { return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
    private static function sheet(Spreadsheet $book,string $title,array $rows,bool $first=false): void {
        $sheet=$first?$book->getActiveSheet():$book->createSheet(); $sheet->setTitle($title);
        foreach($rows as $r=>$row) foreach(array_values($row) as $c=>$value) {
            // Explicit string cells preserve '+' / leading zero phones and neutralize formulas.
            $sheet->setCellValueExplicit([$c+1,$r+1],(string)($value??''),DataType::TYPE_STRING);
        }
        $sheet->freezePane('A2'); $sheet->getStyle('1:1')->getFont()->setBold(true);
        if($rows) $sheet->setAutoFilter($sheet->calculateWorksheetDimension());
        for($c=1;$c<=count($rows[0]??[]);$c++) $sheet->getColumnDimensionByColumn($c)->setWidth(24);
    }
    public static function xlsx(array $apps,?array $stats=null): string {
        $book=new Spreadsheet();
        $rows=[['Reference','Full name','Startup','Phone','Telegram ID','Status','Reviewer','Average rating','Submitted (Asia/Tashkent)','Review due (Asia/Tashkent)','Original filename']];
        foreach($apps as $a) $rows[]=[$a['reference'],$a['full_name'],$a['startup'],$a['phone'],$a['user_id'],$a['status'],$a['reviewer']??'',$a['rating']??'',$a['submitted_at'],$a['review_due_at'],$a['file_name']];
        self::sheet($book,'Applications',$rows,true);
        if($stats) {
            self::sheet($book,'Statistics',array_merge([['Metric','Value']],array_map(fn($k,$v)=>[$k,$v],array_keys($stats['metrics']),array_values($stats['metrics']))));
            foreach(['steps'=>'Funnel','statuses'=>'Statuses','daily_applications'=>'Daily applications','daily_users'=>'Daily users','notifications'=>'Notifications'] as $key=>$title) {
                $data=$stats[$key]; self::sheet($book,$title,$data?array_merge([array_keys($data[0])],array_map('array_values',$data)):[['No records']]);
            }
            self::sheet($book,'Definitions',[
                ['Period from',$stats['period']['from']?:'All time'],['Period to (inclusive)',$stats['period']['to']?:'All time'],
                ['Membership','Recorded successful checks; not proof of current subscription'],
                ['Funnel','Cohort by draft start date; latest completion and furthest step reached'],
                ['Applications','Filtered by submission date; statuses reflect current state'],
                ['Notifications','Filtered by creation date; current delivery state'],
            ]);
        }
        $book->setActiveSheetIndex(0); ob_start(); (new Xlsx($book))->save('php://output'); $data=ob_get_clean(); $book->disconnectWorksheets(); return $data;
    }
    public static function pdf(array $apps,?array $stats,string $cache): string {
        $e=self::escape(...); $html='<html><head><meta charset="UTF-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:10px;color:#172d2b}h1{font-size:22px}h2{font-size:15px}table{border-collapse:collapse;width:100%;margin-bottom:20px}td,th{border:1px solid #cad5d1;padding:6px;text-align:left;word-wrap:break-word}thead{display:table-header-group}.application{page-break-inside:avoid}</style></head><body><h1>Pivot Uzbekistan · Grand Demo Day</h1><p>Arizalar hisoboti · Asia/Tashkent</p>';
        foreach($apps as $a) {
            $html.='<div class="application"><h2>'.$e($a['reference']).' · '.$e($a['startup']).'</h2><table>';
            foreach(['full_name'=>'Ism Familiya','phone'=>'Telefon','user_id'=>'Telegram ID','status'=>'Holati','reviewer'=>'Reviewer','rating'=>'Rating','submitted_at'=>'Topshirilgan','review_due_at'=>'Ko‘rib chiqish muddati','file_name'=>'Asl fayl'] as $key=>$label) $html.='<tr><th>'.$e($label).'</th><td>'.$e($key==='status'?Bot::statusLabel($a[$key]):($a[$key]??'')).'</td></tr>';
            $html.='</table></div>';
        }
        if($stats) {
            $html.='<h2>Statistika</h2><p>'.$e($stats['period']['from']?:'All time').' — '.$e($stats['period']['to']?:'All time').'</p><p>Membership means recorded checks, not current subscription. Funnel cohorts use draft start date; application trends use submission date. Notifications use creation date. All states are current.</p><table>';
            foreach($stats['metrics'] as $k=>$v) $html.='<tr><td>'.$e($k).'</td><td>'.$e($v).'</td></tr>';
            $html.='</table>';
            foreach(['steps','statuses','daily_applications','daily_users','notifications'] as $key) {
                $html.='<h2>'.$e(ucwords(str_replace('_',' ',$key))).'</h2><table>';
                foreach($stats[$key] as $row) { $html.='<tr>'; foreach($row as $v) $html.='<td>'.$e($v).'</td>'; $html.='</tr>'; } $html.='</table>';
            }
        }
        $html.='<p>Ariza topshirish finalga qabul qilinish degani emas. Asl fayllar alohida yuklab olinadi.</p></body></html>';
        $options=new Options(['isRemoteEnabled'=>false,'isPhpEnabled'=>false,'isJavascriptEnabled'=>false,'defaultFont'=>'DejaVu Sans','fontCache'=>$cache,'tempDir'=>$cache,'chroot'=>dirname(__DIR__).'/vendor/dompdf/dompdf']);
        $pdf=new Dompdf($options); $pdf->loadHtml($html,'UTF-8'); $pdf->setPaper('A4'); $pdf->render(); return $pdf->output();
    }
}
