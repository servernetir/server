<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\WalletRefundRequest;
use App\Services\Finance\WalletRefundNotifications;
use App\Support\ErrorTracker;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class WalletRefundNotificationsTest extends TestCase
{
    public static function locales(): array
    {
        return [
            'Persian' => ['fa', 'عودت موجودی کیف پول', 'تأییدشده؛ منتظر واریز', '/account/wallet-refunds', 'وضعیت درخواست عودت '],
            'English' => ['en', 'Wallet balance refund', 'Approved; awaiting transfer', '/en/account/wallet-refunds', 'Refund request '],
            'Turkish' => ['tr', 'Cüzdan bakiyesi iadesi', 'Onaylandı; havale bekliyor', '/tr/account/wallet-refunds', '00000000-0000-4000-8000-000000000001 numaralı iade talebinin durumu: '],
            'Unsupported locale falls back' => ['de', 'عودت موجودی کیف پول', 'تأییدشده؛ منتظر واریز', '/account/wallet-refunds', 'وضعیت درخواست عودت '],
        ];
    }

    private function requestFor(string $locale): WalletRefundRequest
    {
        $request = new WalletRefundRequest(['public_id' => '00000000-0000-4000-8000-000000000001', 'status' => 'approved', 'amount_irt' => 40000]);
        return $request->setRelation('customer', new Customer(['email' => 'refund@example.test', 'locale' => $locale]));
    }

    #[DataProvider('locales')]
    public function test_status_mail_reaches_transport_in_customer_language_without_changing_application_locale(string $locale, string $subject, string $status, string $urlPath, string $noticePrefix): void
    {
        app()->setLocale($locale === 'en' ? 'tr' : 'en');
        $originalLocale = app()->getLocale();
        $sent = null;
        Mail::shouldReceive('raw')->once()->andReturnUsing(function (string $text, callable $callback) use (&$sent): void {
            $message = new Message(new Email);
            $callback($message);
            $sent = ['text' => $text, 'mail' => $message->getSymfonyMessage()];
        });
        $request = $this->requestFor($locale);
        app(WalletRefundNotifications::class)->status($request);
        $this->assertNotNull($sent);
        $this->assertSame($subject, $sent['mail']->getSubject());
        $this->assertSame('refund@example.test', $sent['mail']->getTo()[0]->getAddress());
        $this->assertStringStartsWith($noticePrefix, $sent['text']);
        $this->assertStringContainsString($status, $sent['text']);
        $this->assertStringContainsString($request->public_id, $sent['text']);
        $this->assertStringEndsWith($urlPath, $sent['text']);
        $this->assertStringNotContainsString('refund.status_', $sent['text']);
        $this->assertSame($originalLocale, app()->getLocale());
        $this->assertSame([], ErrorTracker::recent());
    }

    public function test_transport_failure_is_recorded_without_changing_request_or_locale(): void
    {
        app()->setLocale('tr');
        Mail::shouldReceive('raw')->once()->andThrow(new \RuntimeException('Synthetic mail transport failure'));
        $request = $this->requestFor('en');
        $before = $request->getAttributes();
        app(WalletRefundNotifications::class)->status($request);
        $this->assertSame($before, $request->getAttributes());
        $this->assertSame('tr', app()->getLocale());
        $errors = ErrorTracker::recent();
        $this->assertCount(1, $errors);
        $this->assertSame('notify', $errors[0]['area']);
        $this->assertSame('wallet-refund-customer', $errors[0]['ctx']['area']);
        $this->assertSame($request->public_id, $errors[0]['ctx']['request']);
    }
}
