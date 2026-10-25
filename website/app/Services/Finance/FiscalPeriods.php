<?php

namespace App\Services\Finance;

use App\Support\Jalali;
use Illuminate\Support\Carbon;

final class FiscalPeriods
{
    public static function window(int $year, int $quarter = 0): array
    {
        if ($year < 1300 || $year > 1600 || $quarter < 0 || $quarter > 4) {
            throw new \InvalidArgumentException('Invalid fiscal period');
        }
        $month = $quarter === 0 ? 1 : ($quarter - 1) * 3 + 1;
        [$gy,$gm,$gd] = Jalali::toGregorian($year, $month, 1);
        $from = Carbon::create($gy, $gm, $gd, 0, 0, 0, 'Asia/Tehran');
        $nextMonth = $quarter === 0 ? 1 : $month + 3;
        $nextYear = $quarter === 0 ? $year + 1 : $year;
        if ($nextMonth > 12) {
            $nextYear++;
            $nextMonth = 1;
        }
        [$gy,$gm,$gd] = Jalali::toGregorian($nextYear, $nextMonth, 1);

        return [$from, Carbon::create($gy, $gm, $gd, 0, 0, 0, 'Asia/Tehran')->subDay()->endOfDay()];
    }
}
