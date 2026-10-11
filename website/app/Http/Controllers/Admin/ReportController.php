<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Finance\BusinessLedger;
use App\Services\Reports\BusinessReport;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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
    public function index(Request $request, BusinessReport $report, BusinessLedger $ledger): Response
    {
        $days = (int) $request->integer('days', 30);

        if (! in_array($days, BusinessReport::WINDOWS, true)) {
            $days = 30;
        }

        $data = [
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
        ];

        // The administration surface is Persian-only. Render it in Persian,
        // then restore the request locale so this controller cannot poison the
        // next request/test in a long-running worker.
        $previousLocale = app()->getLocale();
        try {
            app()->setLocale('fa');
            $html = view('admin.reports', $data)->render();
        } finally {
            app()->setLocale($previousLocale);
        }

        return response($html);
    }
}
