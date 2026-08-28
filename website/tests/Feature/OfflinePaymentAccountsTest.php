<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PaymentAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * پرداختِ آفلاینِ مشتریِ خارجی — حوالهٔ ارزی و رمزارز.
 *
 * ═══ باگی که این فایل برای تکرارنشدنش نوشته شد ═══
 *
 * مشتریِ انگلیسی/ترک می‌توانست سفارش بدهد و فاکتور بگیرد، ولی صفحهٔ فاکتور
 * فقط دو کارتِ **غیرفعالِ** «به‌زودی» نشانش می‌داد. یعنی بدهی‌ای داشت که هیچ
 * راهی برای پرداختش وجود نداشت: ما پول نمی‌گرفتیم و او فکر می‌کرد سایت خراب
 * است. صفحه ۲۰۰ می‌داد و هیچ خطایی هم تولید نمی‌شد.
 */
class OfflinePaymentAccountsTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        return Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'x'.random_int(1, 99999).'@example.com',
            'password' => bcrypt('secret-pass-123'),
            'status' => 'active',
        ]);
    }

    private function invoice(Customer $c, string $currency = 'EUR'): Invoice
    {
        return Invoice::create([
            'customer_id' => $c->id,
            'number' => 'INV-'.random_int(10000, 99999),
            'currency_code' => $currency,
            'subtotal' => 10000,
            'tax' => 0,
            'total' => 10000,
            'paid' => 0,
            'status' => 'unpaid',
            'issued_at' => now(),
            'due_at' => now()->addDays(7),
        ]);
    }

    private function bank(array $over = []): PaymentAccount
    {
        return PaymentAccount::create($over + [
            'kind' => 'bank', 'currency_code' => 'EUR', 'label' => 'Euro account',
            'holder' => 'ServerNet', 'bank_name' => 'Test Bank',
            'iban' => 'DE89370400440532013000', 'swift' => 'COBADEFF',
            'is_active' => true,
        ]);
    }

    private function usdt(array $over = []): PaymentAccount
    {
        return PaymentAccount::create($over + [
            'kind' => 'crypto', 'currency_code' => 'USDT', 'network' => 'TRC20',
            'address' => 'TXn4rDummyAddressForTesting0000000', 'is_active' => true,
        ]);
    }

    /** 🔴 ادعای اصلی: فاکتور یورویی باید مقصدِ پرداختِ واقعی داشته باشد */
    public function test_a_euro_invoice_shows_the_euro_account_and_the_crypto_wallet(): void
    {
        $c = $this->customer();
        $inv = $this->invoice($c, 'EUR');
        $this->bank();
        $this->usdt();

        $html = $this->actingAs($c, 'customer')->get('/en/account/invoices/'.$inv->id)
            ->assertOk()->getContent();

        $this->assertStringContainsString('DE89370400440532013000', $html, 'IBAN باید دیده شود');
        $this->assertStringContainsString('TXn4rDummyAddressForTesting0000000', $html, 'آدرس کیف باید دیده شود');
        $this->assertStringContainsString('TRC20', $html, 'شبکه باید کنارِ آدرس باشد');
        $this->assertStringNotContainsString(__('ui.inv_soon'), $html,
            'وقتی حسابِ واقعی هست، «به‌زودی» نباید بماند');
    }

    /**
     * ⚠️ رمزارز نباید با ارزِ فاکتور فیلتر شود.
     *
     * اگر فیلترِ ارز روی کیفِ تتر هم بخورد، فاکتورِ یورویی هیچ گزینهٔ رمزارزی
     * نمی‌بیند — یعنی قابلیتی که صریح خواسته شده بی‌صدا ناپدید می‌شود.
     */
    public function test_crypto_is_offered_regardless_of_the_invoice_currency(): void
    {
        $this->usdt();

        foreach (['EUR', 'GBP', 'TRY', 'IRT'] as $cur) {
            $found = PaymentAccount::forInvoiceCurrency($cur);
            $this->assertCount(1, $found, "برای فاکتورِ {$cur} کیفِ رمزارز باید پیشنهاد شود");
        }

        // ولی حسابِ بانکی فقط برای ارزِ خودش — مگر فاکتورِ تومانی (پایین)
        $this->bank();
        $this->assertCount(2, PaymentAccount::forInvoiceCurrency('EUR'));
        $this->assertCount(1, PaymentAccount::forInvoiceCurrency('GBP'),
            'حسابِ یورویی نباید در فاکتورِ پوندی پیشنهاد شود');
    }

    /**
     * 🔴 فاکتورِ **تومانی** هر مقصدِ ارزی را می‌پذیرد — رخدادِ واقعی (۶ شهریور):
     *
     * مدیر حسابِ حوالهٔ یورویی ساخت و مشتریِ خارجی همچنان «Coming soon» می‌دید،
     * چون فاکتورهای مشتریِ خارجی درونی IRT هستند (یورو فقط نمایش است) و تطبیقِ
     * ارز، حسابِ EUR را به هیچ فاکتوری نمی‌رساند. جریانِ حواله آفلاین است و
     * مبلغ را مدیر دستی تأیید می‌کند، پس تطبیقِ ماشینیِ ارز محافظِ هیچ‌چیز نبود.
     */
    /**
     * 🔴 همهٔ حساب‌های بانکی **یک** کارت‌اند و متنِ آزادِ فارسیِ مدیر روی
     * صفحهٔ انگلیسی چاپ نمی‌شود (خواستِ کارفرما، ۶ شهریور: «همه را در یک
     * کارتِ INTERNATIONAL BANK TRANSFER بیاور؛ فارسی ننویسد»).
     */
    public function test_all_wire_accounts_share_one_card_and_persian_labels_never_leak(): void
    {
        $c = $this->customer();
        $inv = $this->invoice($c, 'IRT');
        $this->bank(['label' => 'حساب اصلی شرکت', 'note' => 'فقط حواله']);
        $this->bank(['currency_code' => 'GBP', 'iban' => 'GB29NWBK60161331926819', 'label' => 'حساب پوندی']);

        $html = $this->actingAs($c, 'customer')->get('/en/account/invoices/'.$inv->id)
            ->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-m="wire"'),
            'دو حسابِ بانکی باید یک کارتِ واحد بسازند، نه دو کارت.');
        $this->assertStringContainsString('DE89370400440532013000', $html);
        $this->assertStringContainsString('GB29NWBK60161331926819', $html, 'هر دو حساب در پنل باشند.');
        $this->assertStringContainsString('<select name="payment_account_id"', $html,
            'با بیش از یک حساب، انتخابِ مقصد لازم است.');

        $this->assertStringNotContainsString('حساب اصلی شرکت', $html,
            'برچسبِ فارسیِ مدیر نباید روی صفحهٔ انگلیسی بیاید.');
        $this->assertStringNotContainsString('فقط حواله', $html,
            'یادداشتِ فارسی نباید روی صفحهٔ انگلیسی بیاید.');
    }

    public function test_an_irt_invoice_offers_the_foreign_wire_account(): void
    {
        $c = $this->customer();
        $inv = $this->invoice($c, 'IRT');
        $this->bank();

        $this->assertCount(1, PaymentAccount::forInvoiceCurrency('IRT'),
            'حسابِ ارزی باید روی فاکتورِ تومانی پیشنهاد شود — وگرنه مشتریِ خارجی برای همیشه «به‌زودی» می‌بیند.');

        $html = $this->actingAs($c, 'customer')->get('/en/account/invoices/'.$inv->id)
            ->assertOk()->getContent();

        $this->assertStringContainsString('DE89370400440532013000', $html, 'IBAN باید دیده شود');
        $this->assertStringNotContainsString(__('ui.inv_soon_activate'), $html,
            'با حسابِ واقعی، «Coming soon» نباید بماند');
    }

    /**
     * 🔴 حسابِ ناقص هرگز نمایش داده نمی‌شود.
     *
     * آدرسِ رمزارز بدونِ شبکه یعنی مشتری خودش حدس می‌زند؛ انتقال روی شبکهٔ
     * اشتباه برگشت‌ناپذیر است. «نبودنِ گزینه» از «گزینهٔ خراب» بهتر است.
     */
    public function test_an_incomplete_account_is_never_shown(): void
    {
        $noNetwork = PaymentAccount::create([
            'kind' => 'crypto', 'currency_code' => 'USDT',
            'address' => 'TXsomething', 'network' => null, 'is_active' => true,
        ]);
        $noIban = PaymentAccount::create([
            'kind' => 'bank', 'currency_code' => 'EUR',
            'iban' => null, 'account_no' => null, 'is_active' => true,
        ]);
        $archived = $this->bank(['is_active' => false, 'iban' => 'DE00000000000000000000']);

        $this->assertFalse($noNetwork->isUsable(), 'رمزارزِ بی‌شبکه نباید قابلِ استفاده باشد');
        $this->assertFalse($noIban->isUsable(), 'حسابِ بانکیِ بی‌شماره نباید قابلِ استفاده باشد');
        $this->assertFalse($archived->isUsable(), 'حسابِ بایگانی نباید نشان داده شود');

        $this->assertCount(0, PaymentAccount::forInvoiceCurrency('EUR'));
        $this->assertCount(0, PaymentAccount::forInvoiceCurrency('USDT'));
    }

    /** رسید باید بگوید به کدام مقصد و چقدر — وگرنه مدیر نمی‌تواند تطبیق دهد */
    public function test_the_receipt_records_which_account_and_how_much_was_sent(): void
    {
        $c = $this->customer();
        $inv = $this->invoice($c, 'EUR');
        $acc = $this->bank();

        $this->actingAs($c, 'customer')
            ->post('/en/account/invoices/'.$inv->id.'/bank-transfer', [
                'payment_account_id' => $acc->id,
                'reference' => 'WIRE-12345',
                'sent_amount' => '125.50',
                'paid_from' => 'Ehsan E.',
            ])->assertRedirect();

        $r = \App\Models\BankTransferReceipt::where('invoice_id', $inv->id)->firstOrFail();

        $this->assertSame($acc->id, $r->payment_account_id);
        $this->assertSame(12550, $r->sent_amount, 'مبلغِ فرستاده باید در واحدِ فرعی ذخیره شود');
        $this->assertSame('EUR', $r->sent_currency);
        $this->assertSame('pending', $r->status, 'رسید باید در انتظارِ تأیید بماند — پول هنوز نرسیده');
    }

    /**
     * 🔴 شناسهٔ حسابِ نامعتبر باید رد شود، نه اینکه رسیدِ بی‌مقصد بسازد.
     *
     * حسابِ بایگانی‌شده یا ارزِ دیگر یعنی رسیدی که مدیر هرگز نمی‌تواند در
     * صورت‌حساب پیدایش کند.
     */
    public function test_a_receipt_cannot_point_at_an_account_that_is_not_offered(): void
    {
        $c = $this->customer();
        $inv = $this->invoice($c, 'EUR');
        $gbp = $this->bank(['currency_code' => 'GBP', 'iban' => 'GB33BUKB20201555555555']);

        $this->actingAs($c, 'customer')
            ->post('/en/account/invoices/'.$inv->id.'/bank-transfer', [
                'payment_account_id' => $gbp->id,
                'reference' => 'WIRE-999',
            ])->assertSessionHasErrors();

        $this->assertSame(0, \App\Models\BankTransferReceipt::where('invoice_id', $inv->id)->count());
    }

    /** بدونِ هیچ حسابی، مشتریِ خارجی «به‌زودی» می‌بیند — نه کارتِ خرابِ بی‌مقصد */
    public function test_with_no_accounts_configured_the_foreign_customer_sees_coming_soon(): void
    {
        $c = $this->customer();
        $inv = $this->invoice($c, 'EUR');

        $this->actingAs($c, 'customer')->get('/en/account/invoices/'.$inv->id)
            ->assertOk()->assertSee(__('ui.inv_soon'), false);
    }

    /** هر سه فایل زبان باید کلیدهای یکسان داشته باشند */
    public function test_all_three_language_files_stay_in_sync(): void
    {
        $keys = [];

        foreach (['fa', 'en', 'tr'] as $loc) {
            $keys[$loc] = array_keys((array) require lang_path($loc.'/ui.php'));
            sort($keys[$loc]);
        }

        $this->assertSame($keys['fa'], $keys['en']);
        $this->assertSame($keys['fa'], $keys['tr']);
    }
}
