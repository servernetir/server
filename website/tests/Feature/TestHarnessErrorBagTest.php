<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ViewErrorBag;
use PHPUnit\Framework\ExpectationFailedException;
use Tests\TestCase;

/**
 * گاردِ خودِ داربستِ تست — نه یک قابلیتِ محصول.
 *
 * 🔴 چیزی که این‌جا قفل می‌شود: **پیامِ شکستِ ادعا باید دربارهٔ خودِ ادعا باشد.**
 *
 * چون `config/session.serialization = 'json'` است، `Store::save()` هنگامِ
 * ذخیره `ViewErrorBag` را درونِ حافظه با آرایه جایگزین می‌کند. لاراول هنگامِ
 * ساختنِ پیامِ شکست روی همان مقدار `->all()` می‌زند، پس هر ادعای شکسته روی یک
 * پاسخِ `redirect()->withErrors(...)` به‌جای پیامِ واقعی یک
 * `Call to a member function all() on array` می‌داد.
 *
 * بهایش یک تستِ قرمزِ گمراه‌کننده نیست، بلکه **زمانِ عیب‌یابی** است: یک بار
 * روی `DomainPurchaseTest` ساعت‌ها صرفِ سه مظنونِ بی‌گناه شد (فایلِ ترجمه،
 * `withErrors()`، و `assertSessionHasErrors()`) در حالی که علتِ واقعی فقط یک
 * آدرسِ کهنه در خودِ تست بود.
 *
 * `Tests\TestCase::createTestResponse()` بَگ را پس از هر درخواست بازمی‌سازد.
 * اگر روزی آن override برداشته شود، این فایل قرمز می‌شود.
 */
class TestHarnessErrorBagTest extends TestCase
{
    /**
     * ⚠️ گروهِ `web` **لازم** است و تزئینی نیست.
     *
     * روتِ برهنه هیچ میدل‌وری ندارد، پس `StartSession` نمی‌دود، نشست هرگز
     * `save()` نمی‌شود و `prepareErrorBagForSerialization()` اصلاً صدا زده
     * نمی‌شود — یعنی بَگ خودبه‌خود سالم می‌مانَد و این تست **بی‌آنکه چیزی را
     * بسنجد** سبز می‌شود. (نسخهٔ اولِ همین فایل دقیقاً همین اشکال را داشت و
     * با خاموش‌کردنِ عمدیِ override لو رفت.)
     */
    private function routeThatRedirectsWithErrors(): void
    {
        Route::middleware('web')->get('/_harness/redirect-with-errors',
            fn () => redirect('/where-it-really-went')
                ->withErrors('اطلاعاتِ مالک کامل نیست.'));
    }

    /** پس از درخواست، `errors` باید بَگ باشد نه آرایهٔ سریال‌شده. */
    public function test_the_session_error_bag_survives_json_session_serialization(): void
    {
        // پیش‌فرضی که کلِ این گارد رویش سوار است — اگر عوض شد، باید بدانیم.
        $this->assertSame('json', config('session.serialization'));

        $this->routeThatRedirectsWithErrors();

        $this->get('/_harness/redirect-with-errors');

        $errors = app('session.store')->get('errors');

        $this->assertInstanceOf(ViewErrorBag::class, $errors);
        $this->assertSame('اطلاعاتِ مالک کامل نیست.', $errors->first());
    }

    /**
     * 🔴 ادعای شکسته باید **شکست** بدهد، نه `Error`.
     *
     * ⚠️ عمداً هیچ `catch (\Error)` ای این‌جا نیست: اگر آن باگ برگردد، استثنا
     * از تست بیرون می‌زند و همین تست با همان پیامِ تاریخی قرمز می‌شود.
     */
    public function test_a_failing_redirect_assertion_reports_itself_not_a_type_error(): void
    {
        $this->routeThatRedirectsWithErrors();

        $response = $this->get('/_harness/redirect-with-errors');

        try {
            $response->assertRedirect('/somewhere-else');
        } catch (ExpectationFailedException $e) {
            /*
            | 🔴 قلبِ گارد: متنِ خطاهای نشست به پیام ضمیمه شده، یعنی همان مسیرِ
            | `injectResponseContext() → ->all()` که قبلاً می‌ترکید این‌بار تا
            | آخر رفته است. اگر بَگ دوباره آرایه شود، این‌جا اصلاً به `catch`
            | نمی‌رسیم — یک `Error` از تست بیرون می‌زند.
            */
            $this->assertStringContainsString('اطلاعاتِ مالک کامل نیست.', $e->getMessage());

            /*
            | و شکست واقعاً دربارهٔ **آدرس** است.
            | ⚠️ مقدارها در پیام نیستند؛ `assertEquals` آنها را در
            | `ComparisonFailure` می‌گذارد نه در `getMessage()`.
            */
            $this->assertStringContainsString(
                '/where-it-really-went',
                (string) $e->getComparisonFailure()?->getActual()
            );

            return;
        }

        $this->fail('ادعای ریدایرکت به آدرسِ غلط باید می‌شکست.');
    }
}
