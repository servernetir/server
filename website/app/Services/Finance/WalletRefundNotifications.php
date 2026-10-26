<?php

namespace App\Services\Finance;

use App\Models\WalletRefundRequest;
use App\Services\Notify\AdminNotifier;
use App\Support\ErrorTracker;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Mail;

final class WalletRefundNotifications
{
    public function requested(WalletRefundRequest $r): void
    {
        try {
            app(AdminNotifier::class)->event('درخواست عودت موجودی کیف پول',
                ['مشتری' => $r->customer->code, 'مبلغ تومان' => $r->amount_irt, 'درخواست' => $r->public_id],
                url('/admin/wallet-refunds/'.$r->public_id), '💸');
        } catch (\Throwable $e) {
            ErrorTracker::note('notify', $e, ['area' => 'wallet-refund-admin', 'request' => $r->public_id]);
        }
    }

    public function status(WalletRefundRequest $r): void
    {
        try {
            $customer = $r->customer;
            $locale = in_array($customer->locale, ['en', 'tr'], true) ? $customer->locale : 'fa';
            App::withLocale($locale, function () use ($r, $customer, $locale) {
                $text = __('refund.status_notice', ['id' => $r->public_id, 'status' => __('refund.status_'.$r->status)])
                    ."\n".url(($locale === 'fa' ? '' : $locale.'/').'account/wallet-refunds');
                Mail::raw($text, fn ($m) => $m->to($customer->email)->subject(__('refund.title')));
            });
        } catch (\Throwable $e) {
            ErrorTracker::note('notify', $e, ['area' => 'wallet-refund-customer', 'request' => $r->public_id]);
        }
    }
}
