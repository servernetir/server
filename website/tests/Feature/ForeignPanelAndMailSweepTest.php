<?php

namespace Tests\Feature;

use App\Mail\{AuditReportMail, BroadcastMail, MailboxReplyMail, OtpMail, ServiceReadyMail, TemplateMail};
use App\Models\{AuditReport, Customer, Invoice, InvoiceItem, Product, Service, Setting};
use App\Support\PanelText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Mail};
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ForeignPanelAndMailSweepTest extends TestCase
{
    use RefreshDatabase;

    public static function locales(): array { return [['en'], ['tr']]; }

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Mail::fake();
        Setting::put('pricing_rate_override', '100000');
    }

    private function customer(string $locale): Customer
    {
        return Customer::create(['email' => $locale.'@example.test', 'phone' => '+905321234567', 'password' => 'secret1234', 'status' => 'active', 'locale' => $locale]);
    }

    private function product(): Product
    {
        return Product::create(['name' => 'هاست آزمایشی', 'name_en' => 'Example hosting', 'name_tr' => 'Örnek hosting',
            'slug' => 'example-hosting', 'category' => 'shared', 'price' => 100000, 'setup_fee' => 0, 'tax_percent' => 10, 'cycle' => 'monthly', 'is_active' => true,
            'description' => 'توضیحات فارسی قدیمی', 'specs' => [['label' => 'حافظه', 'value' => '۲ گیگابایت']]]);
    }

    private function visibleText(string $html, bool $mainOnly = false): string
    {
        if ($mainOnly && preg_match('~<main\b[^>]*>(.*?)</main>~si', $html, $m)) { $html = $m[1]; }
        $html = preg_replace('~<!--.*?-->|<script\b[^>]*>.*?</script>|<style\b[^>]*>.*?</style>~si', '', $html);
        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function noPersian(string $text, string $context): void
    {
        $this->assertDoesNotMatchRegularExpression('/[\x{0600}-\x{06FF}]/u', $text, $context);
    }

    #[DataProvider('locales')]
    public function test_account_pages_and_historical_invoice_items_have_no_persian_system_text(string $locale): void
    {
        $customer = $this->customer($locale);
        config()->set('company.legal_name', 'اطمینان داده پردازان دانش');
        config()->set('company.address', ['street' => 'سفیرامید، پلاک 11', 'city' => 'ارومیه', 'province' => 'آذربایجان غربی', 'postcode' => '5716615000', 'country' => 'IR']);
        $product = $this->product();
        $service = Service::create(['customer_id' => $customer->id, 'name' => $product->name, 'description' => 'شرح قدیمی',
            'price' => 100000, 'currency_code' => 'IRT', 'cycle' => 'monthly', 'status' => 'active', 'provision_status' => 'done']);
        $invoice = Invoice::create(['customer_id' => $customer->id, 'service_id' => $service->id, 'kind' => 'service',
            'currency_code' => 'IRT', 'subtotal' => 100000, 'tax' => 0, 'total' => 100000, 'status' => 'unpaid', 'issued_at' => now()]);
        InvoiceItem::create(['invoice_id' => $invoice->id, 'title' => 'ثبتِ دامنهٔ example.test', 'description' => '۱ سال — نیم‌سرورهای سرورنت', 'quantity' => 1, 'unit_price' => 100000, 'line_total' => 100000]);
        $paths = ['', '/services', '/hosting', '/servers', '/domains', '/other', '/reseller', '/invoices', '/bank', '/tickets', '/profile', '/security', '/topup', '/order/'.$product->slug, '/invoices/'.$invoice->id];
        foreach ($paths as $path) {
            $response = $this->actingAs($customer, 'customer')->get('/'.$locale.'/account'.$path)->assertOk();
            $this->noPersian($this->visibleText($response->getContent(), true), $locale.' '.$path);
            preg_match('~<main\b[^>]*>(.*?)</main>~si', $response->getContent(), $main);
            preg_match_all('~(?:aria-label|title|placeholder|alt)="([^"]*)"~i', $main[1] ?? '', $attributes);
            foreach ($attributes[1] as $attribute) { $this->noPersian(html_entity_decode($attribute), 'accessible label '.$locale.' '.$path); }
        }
        $print = $this->get('/'.$locale.'/account/invoices/'.$invoice->id.'/print')->assertOk();
        $this->noPersian($this->visibleText($print->getContent()), $locale.' invoice print');
        $this->assertSame('ثبتِ دامنهٔ example.test', $invoice->items()->first()->title);
        $this->assertSame('هاست آزمایشی', $service->fresh()->name);
    }

    #[DataProvider('locales')]
    public function test_builder_summary_and_validation_feedback_are_localized(string $locale): void
    {
        $customer = $this->customer($locale);
        $this->actingAs($customer, 'customer');
        app()->setLocale($locale);
        $product = $this->product();
        $html = view('account.builder-checkout', \App\Http\Controllers\Account\AccountController::shell('') + [
            'product' => $product, 'ref' => 'SB-TEST01', 'domain' => 'example.test', 'taxPct' => 10,
            'check' => ['price_toman' => 100000, 'quote_id' => 1], 'quoteErr' => null,
            'errors' => new \Illuminate\Support\ViewErrorBag(),
        ])->render();
        $this->noPersian($this->visibleText($html, true), 'builder '.$locale);
        $this->assertStringContainsString('€', $html);
        $checkout = view('account.checkout', \App\Http\Controllers\Account\AccountController::shell('') + [
            'product' => $product, 'countries' => ['DE'], 'cycles' => ['monthly', 'yearly'],
            'isLicense' => false, 'handoffCycle' => null, 'errors' => new \Illuminate\Support\ViewErrorBag(),
        ])->render();
        $this->noPersian($this->visibleText($checkout, true), 'checkout location '.$locale);
        $this->post('/'.$locale.'/account/bank', [])->assertSessionHasErrors('card');
        foreach (session('errors')->all() as $error) { $this->noPersian($error, 'validation '.$locale); }
        $this->get('/'.$locale.'/account/bank')->assertOk();
    }

    #[DataProvider('locales')]
    public function test_legacy_identity_view_has_localized_statuses_and_document_fields(string $locale): void
    {
        $this->actingAs($this->customer($locale), 'customer');
        app()->setLocale($locale);
        foreach (['none', 'pending', 'rejected', 'verified'] as $status) {
            $profile = new \App\Models\CustomerProfile(['type' => 'company', 'status' => $status, 'reject_reason' => 'دلیل فارسی قدیمی']);
            $html = view('account.verify', \App\Http\Controllers\Account\AccountController::shell('') + [
                'profile' => $profile, 'docs' => collect(), 'errors' => new \Illuminate\Support\ViewErrorBag(),
            ])->render();
            $this->noPersian($this->visibleText($html, true), 'legacy verification '.$locale.' '.$status);
        }
    }

    #[DataProvider('locales')]
    public function test_all_automatic_mail_types_render_in_the_recipient_language_even_from_a_persian_job(string $locale): void
    {
        $customer = $this->customer($locale);
        $product = $this->product();
        app()->setLocale('fa');
        $report = new AuditReport(['host' => 'example.test', 'score' => 90, 'result' => ['checks' => []]]);
        $mails = [new OtpMail('123456', 5, $locale), new OtpMail('123456', 5, $locale, 'service_terminate'),
            new ServiceReadyMail($product->name, 'example.test', null, null, null, $locale, true),
            new TemplateMail('اطلاع‌رسانی قدیمی', '<p>متن فارسی قدیمی</p>', $locale),
            new BroadcastMail('اعلان مدیر', 'متن فارسی اعلان مدیر', $locale),
            new AuditReportMail($report, 'https://servernet.cloud/'.$locale.'/report/example', false, null, 'یادداشت فارسی', $locale)];
        foreach ($mails as $mail) {
            $html = $mail->render();
            $this->noPersian($this->visibleText($html), get_class($mail).' '.$locale);
            $this->noPersian((string) $mail->subject, 'subject '.$locale);
            $this->assertStringContainsString('lang="'.$locale.'"', $html);
            $this->assertSame('fa', app()->getLocale());
        }
        $reply = (new MailboxReplyMail('Reply\nدر پاسخ به (بخشی از نامهٔ شما):\n> Original', 'Re: Example', null, '<p>Reply</p>', '', 'Original'))->to($customer->email);
        $html = $reply->render();
        $this->noPersian($this->visibleText($html), 'reply chrome '.$locale);
        $this->assertStringContainsString('lang="'.$locale.'"', $html);
    }

    #[DataProvider('locales')]
    public function test_missing_product_translations_use_a_localized_identity_and_bank_fallbacks_are_latin(string $locale): void
    {
        app()->setLocale($locale);
        $product = Product::create(['name' => 'نام فارسی بدون ترجمه', 'slug' => 'untranslated', 'category' => 'shared', 'price' => 0, 'cycle' => 'monthly']);
        $this->noPersian($product->displayName(), 'product '.$locale);
        $this->assertStringContainsString('#'.$product->id, $product->displayName());
        foreach (['تجارت', 'رسالت', 'بانک ناشناخته'] as $name) { $this->noPersian(PanelText::bank($name), 'bank '.$locale); }
        $this->assertSame('نام فارسی بدون ترجمه', $product->fresh()->name);
    }

    public function test_panel_keys_and_placeholders_match_in_all_languages(): void
    {
        $fa = require lang_path('fa/panel.php');
        foreach (['en', 'tr'] as $locale) {
            $dict = require lang_path($locale.'/panel.php');
            $this->assertSame(array_keys($fa), array_keys($dict));
            foreach ($dict as $key => $value) {
                $this->noPersian($value, $locale.' '.$key);
                preg_match_all('/:([a-z_]+)/', $fa[$key], $a);
                preg_match_all('/:([a-z_]+)/', $value, $b);
                $this->assertSame($a[1], $b[1], $locale.' '.$key);
            }
        }
    }
}
