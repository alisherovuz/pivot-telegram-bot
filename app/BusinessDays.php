<?php
declare(strict_types=1);
namespace Pivot;
final class BusinessDays {
    public static function due(\DateTimeImmutable $from,array $holidays): string {
        $date=$from->setTimezone(new \DateTimeZone('Asia/Tashkent')); $days=0;
        while ($days<3) { $date=$date->modify('+1 day'); if ((int)$date->format('N')<6 && !in_array($date->format('Y-m-d'),$holidays,true)) $days++; }
        return $date->format('Y-m-d H:i:s');
    }
}
