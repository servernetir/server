<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * پیشوندِ زبان با حروفِ بزرگ باید ۳۰۱ شود، نه ۴۰۴.
 *
 * در ردیابِ ۴۰۴ دیدیم لینکِ بیوی اینستاگرام «/TR» است و ترافیکِ واقعیِ تبلیغات
 * دور می‌ریخت.
 */
class LocaleCaseRedirectTest extends TestCase
{
    public function test_uppercase_locale_prefix_redirects(): void
    {
        $this->get('/TR')->assertRedirect('/tr');
        $this->get('/EN')->assertRedirect('/en');
        $this->get('/Tr')->assertRedirect('/tr');
    }

    public function test_uppercase_locale_keeps_the_rest_of_the_path(): void
    {
        $this->get('/TR/blog')->assertRedirect('/tr/blog');
    }

    /** فارسی پیشوند ندارد، پس به ریشه می‌رود */
    public function test_uppercase_fa_goes_to_root(): void
    {
        $this->get('/FA')->assertRedirect('/');
    }

    /** مسیرهای دوحرفیِ ناشناس باید همان ۴۰۴ بمانند */
    public function test_unknown_two_letter_path_is_still_404(): void
    {
        $this->get('/ZZ')->assertNotFound();
    }

    /** نسخهٔ کوچک باید عادی کار کند، نه حلقهٔ هدایت */
    public function test_lowercase_locales_are_untouched(): void
    {
        $this->get('/tr')->assertOk();
        $this->get('/en')->assertOk();
    }
}
