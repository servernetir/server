<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Finance\BusinessLedger;
use App\Services\Reports\BusinessReport;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * `/admin/reports` — یک صفحه که به سؤالِ «کسب‌وکارم در چه حالی است» جواب دهد.
 *
 * ⚠️ نامش `Admin\ReportController` است و با `App\Http\Controllers\ReportController`
 * (گزارشِ عمومیِ بررسیِ سایت) هیچ ربطی ندارد. هم‌نام‌اند و کارشان کاملاً جداست.
 *
 * ⚠️ اعدادِ گذشته از `BusinessLedger` می‌آیند، نه از پرس‌وجوی تازه. دو منبع
 * برای «سودِ من چقدر بود» یعنی روزی دو صفحهٔ پنل دو عدد بگویند و کارفرما
 * نداند کدام درست است.
 */
class ReportController extends Controller
{
    public function index(Request $request, BusinessReport $report, BusinessLedger $ledger): View
    {
        $days = (int) $request->integer('days', 30);

        if (! in_array($days, BusinessReport::WINDOWS, true)) {
            $days = 30;
        }

        return view('admin.reports', [
            'days'        => $days,
            'windows'     => BusinessReport::WINDOWS,
            'forecast'    => $report->forecast($days),
            'customers'   => $report->customers(12),
            'infra'       => $report->infrastructure(),
            'blindSpots'  => $report->blindSpots(),

            // نگاهِ گذشته — از همان دفتری که /admin/finance می‌خوانَد
            'ledgerReady' => $ledger->ready(),
            'month'       => $ledger->summary(now()->startOfMonth(), now()),
            'trend'       => $ledger->monthlyTrend(6),
        ]);
    }
}
