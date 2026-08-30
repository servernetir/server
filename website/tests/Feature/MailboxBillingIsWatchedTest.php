<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * نشانیِ روی فاکتور باید صندوقی داشته باشد که کسی می‌خوانَدش.
 *
 * ═══ چرا این پیوند لازم است ═══
 *
 * `billing@servernet.cloud` روی هر فاکتورِ رسمی چاپ می‌شود. مشتری‌ای که دربارهٔ
 * پرداختِ خودش سؤال دارد به همان‌جا می‌نویسد. اگر آن صندوق در
 * `config/mailboxes.php` اعلام نشده باشد، نه در `/admin/mail` دیده می‌شود، نه
 * `mailbox:sync` می‌خوانَدش، نه در گزارشِ روزانهٔ بله می‌آید — و نامه بی‌صدا
 * گم می‌شود.
 *
 * 🔴 و این از **نداشتنِ** نشانی روی فاکتور بدتر است: مشتری فکر می‌کند پیامش
 * رسیده و منتظر می‌مانَد. همان الگوی همیشگیِ این پروژه — «شکست نمی‌خورد، فقط
 * اتفاق نمی‌افتد».
 *
 * ⚠️ این تست **رمز** را نمی‌سنجد و نباید بسنجد: رمزها فقط در `.env` سرورند و
 * عمداً در مخزن نیستند. چیزی که این‌جا قفل می‌شود «اعلامِ صندوق» است، نه
 * «فعال‌بودنش».
 */
class MailboxBillingIsWatchedTest extends TestCase
{
    /** همهٔ صندوق‌های اعلام‌شده، حتی آن‌هایی که هنوز رمز ندارند. */
    private function declaredUsers(): array
    {
        /*
        | ⚠️ `config('mailboxes.accounts')` از قبل با `filled($a['pass'])`
        | فیلتر شده، پس در محیطِ تست — که هیچ `MAILBOX_*_PASS` ندارد — همیشه
        | خالی است. اگر همان را می‌خواندم، این تست روی هر ماشینی سبز می‌شد
        | بی‌آنکه چیزی را ثابت کند.
        |
        | پس فایلِ config دوباره و **بی‌فیلتر** خوانده می‌شود.
        */
        $raw = require config_path('mailboxes.php');

        // فیلترِ داخلِ فایل را دور بزن: کاربران را از خودِ متنِ فایل بردار
        preg_match_all(
            "~'user'\s*=>\s*env\(\s*'[A-Z_]+'\s*,\s*'([^']+)'~",
            (string) file_get_contents(config_path('mailboxes.php')),
            $m
        );

        return array_map('mb_strtolower', $m[1] ?? []);
    }

    /** 🔴 نشانیِ فاکتور باید در فهرستِ صندوق‌ها باشد. */
    public function test_the_invoice_billing_address_has_a_declared_mailbox(): void
    {
        $onInvoice = mb_strtolower(trim((string) config('servernet.contact.billing_email')));

        $this->assertNotSame('', $onInvoice, 'نشانیِ حسابداری اصلاً تعریف نشده');

        $this->assertContains($onInvoice, $this->declaredUsers(),
            "نشانیِ «{$onInvoice}» روی فاکتور چاپ می‌شود ولی صندوقی برایش در "
            .'config/mailboxes.php اعلام نشده — نامهٔ مشتری بی‌صدا گم می‌شود.');
    }

    /**
     * ⚠️ و همین برای نشانی‌های دیگری که روی سایت تبلیغ می‌شوند.
     *
     * پشتیبانی و فروش هم جایی چاپ می‌شوند که مشتری می‌بیند.
     */
    public function test_the_public_support_address_is_watched_too(): void
    {
        $declared = $this->declaredUsers();

        foreach (['email', 'billing_email'] as $key) {
            $addr = mb_strtolower(trim((string) config("servernet.contact.{$key}")));

            if ($addr === '') {
                continue;
            }

            $this->assertContains($addr, $declared,
                "«{$addr}» روی سایت تبلیغ می‌شود ولی صندوقِ اعلام‌شده ندارد");
        }
    }

    /**
     * ⚠️ حسابِ بی‌رمز نباید وارد چرخه شود.
     *
     * فیلترِ داخلِ فایل هم کاربر را می‌سنجد هم رمز را. بی‌آن، حسابِ نیمه‌پر هر
     * ساعت یک خطای گیج‌کنندهٔ IMAP می‌ساخت — نقصی که یک‌بار روی جیمیل رخ داد.
     */
    public function test_an_account_without_a_password_never_enters_the_cycle(): void
    {
        // در محیطِ تست هیچ رمزی نیست، پس فهرستِ فعال باید خالی باشد
        $this->assertSame([], config('mailboxes.accounts'),
            'حسابی بی‌رمز وارد فهرستِ فعال شد');
    }

    /** 🔴 رمزها هرگز در مخزن نیستند. */
    public function test_no_mailbox_password_is_committed(): void
    {
        $src = (string) file_get_contents(config_path('mailboxes.php'));

        preg_match_all("~'pass'\s*=>\s*([^,\n]+)~", $src, $m);

        $this->assertNotEmpty($m[1], 'هیچ فیلدِ رمزی پیدا نشد — ساختار عوض شده');

        foreach ($m[1] as $expr) {
            $this->assertStringContainsString('env(', $expr,
                "رمز باید فقط از env بیاید، ولی این‌جا مقدارِ ثابت است: {$expr}");
        }
    }
}
