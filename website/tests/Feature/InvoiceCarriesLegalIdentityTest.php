<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * فاکتورِ رسمی باید **همان** هویتِ حقوقی را بخوانَد که در پنل وارد شده.
 *
 * ═══ نقصی که این را لازم کرد ═══
 *
 * نامِ فروشنده روی فاکتور از `bank_holder` می‌آمد — یعنی نامِ صاحبِ حسابِ
 * بانکی، که لزوماً نامِ ثبتیِ شرکت نیست. نتیجه‌اش سندی بود که نامِ حقوقیِ
 * اشتباه داشت، و هیچ‌جا هم خطا نمی‌داد.
 *
 * ⚠️ و مثلِ همه‌جای دیگرِ این پروژه: هرچه پر نباشد **اصلاً چاپ نمی‌شود**.
 * «شماره ثبت: —» روی یک سندِ مالی از نبودنش بدتر است.
 */
class InvoiceCarriesLegalIdentityTest extends TestCase
{
    use RefreshDatabase;

    /** این پروژه فکتوری ندارد؛ همان الگوی بقیهٔ تست‌ها. */
    private function customer(): Customer
    {
        return Customer::create([
            'code'     => 'SN-'.random_int(100000, 999999),
            'email'    => 'v'.random_int(1, 99999).'@example.com',
            'password' => bcrypt('secret-pass-123'),
            'status'   => 'active',
        ]);
    }

    private function invoice(Customer $c, string $status = 'unpaid'): Invoice
    {
        return Invoice::create([
            'customer_id'   => $c->id,
            'number'        => 'INV-'.random_int(10000, 99999),
            'currency_code' => 'IRT',
            'subtotal'      => 1_500_000, 'tax' => 0, 'total' => 1_500_000,
            'paid'          => $status === 'paid' ? 1_500_000 : 0,
            'status'        => $status,
            'issued_at'     => now(), 'due_at' => now()->addDays(7),
        ]);
    }

    private function paidInvoice(): array
    {
        $customer = $this->customer();
        $invoice = $this->invoice($customer, 'paid');

        Payment::create([
            'invoice_id'    => $invoice->id,
            'customer_id'   => $customer->id,
            'currency_code' => 'IRT',
            'gateway'       => 'zarinpal',
            'status'        => 'paid',
            'amount'        => $invoice->total,
            'ref_id'        => 'REF-123456',
            'paid_at'       => now(),
        ]);

        return [$customer, $invoice->fresh()];
    }

    /**
     * تعدادِ عنصرهای `.pay-seal` در **DOM**.
     *
     * ⚠️ جست‌وجوی رشته‌ای این‌جا دروغ می‌گوید: نامِ کلاس در `<style>` همان صفحه
     * هم هست، پس `assertStringNotContainsString('pay-seal', …)` روی هر فاکتوری
     * — حتی بی‌مهر — شکست می‌خورد. اولین نسخهٔ همین تست دقیقاً همین‌جا افتاد.
     */
    private function sealCount(string $html): int
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);

        return (new \DOMXPath($dom))->query('//div[contains(@class,"pay-seal")]')->length;
    }

    private function print(Customer $customer, Invoice $invoice): string
    {
        return $this->actingAs($customer, 'customer')
            ->get('/account/invoices/'.$invoice->id.'/print?noprint=1')
            ->assertOk()
            ->getContent();
    }

    /** 🔴 نامِ ثبتی و شناسه‌ها از پنل روی فاکتور می‌آیند. */
    public function test_the_paid_invoice_carries_the_legal_identity_from_the_panel(): void
    {
        Setting::put('company_legal_name', 'شرکت آزمون (سهامی خاص)');
        Setting::put('company_reg_no', '552134');
        Setting::put('company_national_id', '10320123456');
        Setting::put('company_address', 'خیابان ولیعصر، پلاک ۱');
        Setting::put('company_city', 'تهران');

        [$customer, $invoice] = $this->paidInvoice();
        $html = $this->print($customer, $invoice);

        $this->assertStringContainsString('شرکت آزمون (سهامی خاص)', $html);
        $this->assertStringContainsString(fa_num('552134'), $html, 'شماره ثبت روی فاکتور نیامد');
        $this->assertStringContainsString(fa_num('10320123456'), $html, 'شناسهٔ ملی روی فاکتور نیامد');
        $this->assertStringContainsString('خیابان ولیعصر', $html, 'نشانی روی فاکتور نیامد');
    }

    /**
     * ⚠️ نامِ ثبتی بر `bank_holder` می‌چربد.
     *
     * `bank_holder` نامِ صاحبِ حساب است، نه نامِ حقوقیِ شرکت.
     */
    public function test_the_legal_name_beats_the_bank_account_holder(): void
    {
        Setting::put('bank_holder', 'احسان ابراهیمی');
        Setting::put('company_legal_name', 'شرکت آزمون (سهامی خاص)');

        [$customer, $invoice] = $this->paidInvoice();
        $html = $this->print($customer, $invoice);

        $this->assertStringContainsString('شرکت آزمون (سهامی خاص)', $html);
        $this->assertStringNotContainsString('احسان ابراهیمی', $html,
            'نامِ صاحبِ حساب به‌جای نامِ ثبتی روی فاکتورِ رسمی نشست');
    }

    /** ⚠️ و بی‌هویتِ حقوقی، `bank_holder` راهِ دوم می‌مانَد — فاکتور بی‌نام نمی‌شود. */
    public function test_the_bank_holder_is_still_the_fallback(): void
    {
        Setting::put('bank_holder', 'احسان ابراهیمی');

        [$customer, $invoice] = $this->paidInvoice();

        $this->assertStringContainsString('احسان ابراهیمی', $this->print($customer, $invoice));
    }

    /** 🔴 شناسهٔ پرنشده هیچ برچسبی هم چاپ نمی‌کند. */
    public function test_an_empty_identity_prints_nothing_at_all(): void
    {
        Setting::put('company_legal_name', 'شرکت آزمون (سهامی خاص)');

        [$customer, $invoice] = $this->paidInvoice();
        $html = $this->print($customer, $invoice);

        foreach (['ui.trust_reg_no', 'ui.trust_national', 'ui.trust_economic'] as $key) {
            $this->assertStringNotContainsString(__($key).':', $html,
                "برچسبِ «".__($key)."» بی‌مقدار روی فاکتور چاپ شد");
        }
    }

    /**
     * 🔴 مهر **داخلِ** جعبهٔ سبزِ رسید است، نه زیرِ آن.
     *
     * مهر یعنی «این پرداخت را تأیید می‌کنیم»، پس باید کنارِ همان چیزی بنشیند
     * که تأییدش می‌کند. جدا افتادنش پایینِ صفحه در چاپ گاهی به صفحهٔ بعد
     * می‌رفت و از رسید جدا می‌شد.
     */
    public function test_the_seal_sits_inside_the_green_paid_box(): void
    {
        Setting::put('stamp_path', 'company/stamp.png');
        Setting::put('stamp_mime', 'image/png');
        \Illuminate\Support\Facades\Storage::disk('local')->put('company/stamp.png', 'fake-png-bytes');

        [$customer, $invoice] = $this->paidInvoice();
        $html = $this->print($customer, $invoice);

        $this->assertSame(1, $this->sealCount($html), 'مهر روی فاکتورِ پرداخت‌شده نیامد');

        /*
        | ⚠️ ساختار را با DOM می‌سنجم، نه با فاصلهٔ رشته‌ای.
        |
        | «مهر جایی نزدیکِ جعبه است» همان چیزی است که قبلاً هم درست بود — مهر
        | دقیقاً زیرِ جعبه می‌نشست. تنها ادعای معنادار این است که مهر
        | **فرزندِ** همان جعبه باشد، و فقط DOM می‌تواند آن را ثابت کند.
        */
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        $x = new \DOMXPath($dom);

        $box = $x->query('//div[contains(@class,"pay-receipt")]')->item(0);
        $this->assertNotNull($box, 'جعبهٔ سبزِ رسید پیدا نشد');

        $sealInside = $x->query('.//div[contains(@class,"pay-seal")]', $box);
        $this->assertSame(1, $sealInside->length, 'مهر فرزندِ جعبهٔ سبز نیست');

        $img = $x->query('.//img', $sealInside->item(0));
        $this->assertSame(1, $img->length, 'تصویرِ مهر داخلِ جعبه نیست');
        $this->assertStringStartsWith('data:image/png;base64,', $img->item(0)->getAttribute('src'),
            'مهر باید data-uri باشد تا در PDF هم بیاید');
    }

    /**
     * 🔴 فاکتور باید در **یک برگهٔ A4 عمودی** جا شود.
     *
     * کارفرما گزارش داد که حتی با یک ردیف محصول دو برگه می‌شود. ریشه‌اش
     * `size:A4 landscape` بود: افقی فقط ۲۱۰ میلی‌متر ارتفاع دارد (با حاشیه
     * ~۱۸۶) در حالی که عمودی ۲۹۷ است (~۲۷۷) — نزدیک ۵۰٪ فضای عمودیِ بیشتر.
     *
     * ⚠️ ارتفاعِ واقعی را نمی‌شود در PHPUnit سنجید؛ در مرورگر با فونتِ واقعیِ
     * IRANSans اندازه گرفته شد: با ۴ ردیف ۱۷۹٫۹ میلی‌متر، یعنی ۹۷ میلی‌متر
     * فضای اضافه و جا برای ~۱۲ ردیف. آنچه این‌جا قفل می‌شود، همان تصمیم‌هایی
     * است که آن عدد را ممکن کردند.
     */
    public function test_the_print_sheet_is_built_for_one_portrait_page(): void
    {
        [$customer, $invoice] = $this->paidInvoice();
        $html = $this->print($customer, $invoice);

        $this->assertMatchesRegularExpression('~@page\s*\{[^}]*size:\s*A4\s+portrait~i', $html,
            'اندازهٔ صفحه دیگر A4 عمودی نیست — همین باعثِ دو برگه شدن بود');

        /*
        | ⚠️ فقط داخلِ خودِ قانونِ `@page` سنجیده می‌شود.
        |
        | `assertStringNotContainsString('landscape', …)` ساده به‌نظر می‌رسید و
        | شکست خورد — چون واژه در **کامنتِ توضیحیِ** همان فایل هست («قبلاً
        | landscape بود»). این سومین بار امروز است که آشکارسازِ متنی به کامنت
        | گیر می‌کند؛ ادعا باید روی چیزی باشد که مرورگر می‌خوانَد، نه روی متنِ فایل.
        */
        preg_match('~@page\s*\{([^}]*)\}~i', $html, $page);
        $this->assertStringNotContainsString('landscape', $page[1] ?? '',
            'حالتِ افقی به قانونِ @page برگشت');

        // فشرده‌سازی فقط برای چاپ؛ نسخهٔ روی صفحه نباید تغییر کند
        $this->assertMatchesRegularExpression('~@media\s+print\s*\{~', $html,
            'بلوکِ فشرده‌سازیِ چاپ نیست');

        /*
        | 🔴 بلوکِ پایانی نباید وسطش بشکند.
        |
        | بدترین حالت این نیست که سند دو برگه شود؛ این است که «جمعِ کل» یا مهر
        | تنها روی برگهٔ دوم بیفتد و برگهٔ اول ناقص به‌نظر برسد.
        */
        foreach (['.totals', '.pay', '.foot'] as $sel) {
            $this->assertStringContainsString($sel, $html);
        }
        $this->assertStringContainsString('page-break-inside:avoid', $html,
            'محافظِ نشکستنِ بلوکِ پایانی برداشته شده');
    }

    /** ⚠️ کد اقتصادی به‌خواستِ کارفرما روی فاکتور نمی‌آید — ولی در صفحهٔ تماس می‌مانَد. */
    public function test_the_economic_code_stays_off_the_invoice_but_not_off_the_site(): void
    {
        Setting::put('company_legal_name', 'شرکت آزمون (سهامی خاص)');
        Setting::put('company_economic_code', '411122223333');

        [$customer, $invoice] = $this->paidInvoice();

        $this->assertStringNotContainsString(fa_num('411122223333'), $this->print($customer, $invoice),
            'کد اقتصادی روی فاکتور چاپ شد');

        // ولی `company_identity()` همچنان برش می‌گرداند — منبعِ مشترکِ صفحهٔ تماس است
        $this->assertContains('411122223333', collect(company_identity())->pluck('value')->all(),
            'کد اقتصادی از منبعِ مشترک حذف شده — صفحهٔ تماس هم آن را از دست می‌دهد');
    }

    /** 🔴 نشانیِ روی فاکتور، حسابداری است نه پشتیبانیِ فنی. */
    public function test_the_invoice_shows_the_billing_address_not_support(): void
    {
        config([
            'servernet.contact.email'         => 'support@servernet.cloud',
            'servernet.contact.billing_email' => 'acc@servernet.cloud',
        ]);

        [$customer, $invoice] = $this->paidInvoice();
        $html = $this->print($customer, $invoice);

        $this->assertStringContainsString('acc@servernet.cloud', $html);
        $this->assertStringNotContainsString('support@servernet.cloud', $html,
            'نشانیِ پشتیبانیِ فنی روی سندِ مالی نشست');
    }

    /** ⚠️ روی پیش‌فاکتورِ پرداخت‌نشده هیچ مهری نیست — سندِ رسمی نیست. */
    public function test_no_seal_on_an_unpaid_proforma(): void
    {
        Setting::put('stamp_path', 'company/stamp.png');
        Setting::put('stamp_mime', 'image/png');
        \Illuminate\Support\Facades\Storage::disk('local')->put('company/stamp.png', 'fake-png-bytes');

        $customer = $this->customer();
        $invoice = $this->invoice($customer);

        $this->assertSame(0, $this->sealCount($this->print($customer, $invoice)),
            'مهر روی پیش‌فاکتورِ پرداخت‌نشده چاپ شد');
    }
}
