<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Post;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * هیچ لینکی روی صفحهٔ اصلیِ کارفرما نباید به روتِ ناموجود برود.
 *
 * ═══ 🔴 چرا این تست نوشته شد ═══
 *
 * لینکِ «همه»ی پنلِ «تازه‌ترین سرویس‌ها» به `/admin/services` می‌رفت و چنین
 * روتی **هرگز ثبت نشده بود**: فقط POSTهای `services/{service}/…` وجود داشتند،
 * پس لاراول `MethodNotAllowedHttpException` می‌داد و صفحهٔ ۴۰۴ِ سایت رندر
 * می‌شد. همان ماجرا روی `/admin/invoices` هم بود.
 *
 * هر دو ۲۰۰ برمی‌گرداندند (صفحهٔ ۴۰۴ خودش یک صفحهٔ سالم است)، هیچ خطایی در
 * لاگ نمی‌نوشتند، و هیچ تستی به آن‌ها دست نمی‌زد — تنها راهِ پیداشدنشان
 * **کلیک‌کردن** بود. لینکِ مرده روی صفحهٔ اصلیِ مدیر نباید فقط با کلیک پیدا
 * شود.
 *
 * ═══ چرا روتر و نه درخواستِ واقعی ═══
 *
 * ⚠️ این تست عمداً `GET` واقعی نمی‌زند و فقط می‌پرسد «آیا روتی برای این مسیر
 * ثبت شده؟». دلیلش این است که هدف باید **یک** چیز باشد: ثبت‌نبودنِ روت. اگر
 * درخواستِ واقعی می‌زدیم، هر ۵۰۰ِ بی‌ربطِ هر کنترلرِ دیگری این تست را قرمز
 * می‌کرد و پیامش دیگر «لینکِ مرده» نبود — و تستی که به دلایلِ نامربوط
 * می‌شکند، خیلی زود نادیده گرفته می‌شود.
 *
 * ⚠️ و `MethodNotAllowed` هم شکست شمرده می‌شود، نه فقط `NotFound`: دقیقاً
 * همان چیزی بود که `/admin/services` می‌داد. «روتی هست ولی با فعلِ دیگر» برای
 * یک `<a href>` یعنی همان ۴۰۴.
 */
class AdminDashboardLinksResolveTest extends TestCase
{
    use RefreshDatabase;

    /**
     * داشبورد را با داده‌ی واقعی پر می‌کند.
     *
     * 🔴 بی‌این، هر سه پنلِ «آخرین …» خالی رندر می‌شوند و لینک‌های پویا
     * (`/admin/customers/{id}`، `/admin/tickets/{id}`، `/admin/posts/{id}/edit`)
     * اصلاً روی صفحه نمی‌آیند — تست سبز می‌شد بی‌آنکه آن‌ها را دیده باشد.
     */
    private function seedDashboard(): Customer
    {
        $customer = Customer::create([
            'code'     => 'SN-'.random_int(100000, 999999),
            'email'    => 'linkcheck@example.com',
            'password' => bcrypt('secret-pass-123'),
            'status'   => 'active',
        ]);

        $invoice = Invoice::create([
            'customer_id' => $customer->id, 'number' => 'INV-'.random_int(10000, 99999),
            'currency_code' => 'IRT', 'subtotal' => 900000, 'tax' => 0,
            'total' => 900000, 'paid' => 900000, 'status' => 'paid',
            'issued_at' => now(), 'due_at' => now()->addDays(7),
        ]);

        Payment::create([
            'invoice_id' => $invoice->id, 'customer_id' => $customer->id,
            'currency_code' => 'IRT', 'gateway' => 'zarinpal', 'status' => 'paid',
            'amount' => 900000, 'paid_at' => now(),
        ]);

        Service::create([
            'customer_id' => $customer->id, 'name' => 'هاست لینوکس طلایی',
            'currency_code' => 'IRT', 'price' => 1200000, 'cycle' => 'monthly',
            'status' => 'active', 'next_due_at' => now()->addMonth(),
        ]);

        Ticket::create([
            'customer_id' => $customer->id, 'number' => 'TK-'.random_int(1000, 9999),
            'subject' => 'مشکل در اتصال', 'department' => 'support',
            'priority' => 'normal', 'status' => 'open',
            'last_reply_role' => 'customer', 'last_reply_at' => now(),
        ]);

        $post = Post::create([
            'type' => 'blog', 'slug' => 'link-check-'.random_int(1000, 9999), 'category' => 'news',
            'status' => 'published', 'published_at' => now(),
        ]);

        // کامنتِ تأییدنشده نشانِ کنارِ آیتمِ منو را روشن می‌کند — یعنی همان
        // شاخه‌ی نوارِ کناری که در حالتِ خالی رندر نمی‌شود هم سنجیده می‌شود.
        Comment::create([
            'post_slug' => $post->slug, 'name' => 'ناشناس',
            'email' => 'guest@example.com', 'body' => 'سلام', 'approved' => false,
        ]);

        return $customer;
    }

    /**
     * هر `href`ی که واقعاً به یک مسیرِ داخلی می‌رود.
     *
     * ⚠️ `#i-user` و امثالش رد می‌شوند: آن‌ها ارجاعِ `<use>` به اسپرایتِ SVG
     * درونِ همان صفحه‌اند، نه پیمایش. همین‌طور `mailto:`، `tel:` و آدرسِ کاملِ
     * سایت‌های بیرونی — مقصدشان دستِ ما نیست.
     *
     * @return list<string>
     */
    private function internalLinks(string $html): array
    {
        preg_match_all('/href="([^"]+)"/', $html, $m);

        $paths = [];

        foreach (array_unique($m[1]) as $href) {
            $href = html_entity_decode($href, ENT_QUOTES);

            if ($href === '' || str_starts_with($href, '#')
                || preg_match('#^(?:https?:|mailto:|tel:|javascript:|data:)#i', $href)) {
                continue;
            }

            // رشتهٔ پرس‌وجو و لنگر به انتخابِ روت ربطی ندارند
            $path = '/'.ltrim(strtok(strtok($href, '#'), '?'), '/');

            if ($path !== '/') {
                $paths[$path] = true;
            }
        }

        return array_keys($paths);
    }

    /**
     * آیا لاراول برای این مسیر روتی دارد؟
     *
     * ⚠️ `UrlGenerationException` هم گرفته می‌شود چون بعضی روت‌ها هنگامِ
     * تطبیق، بایندینگِ مدل را صدا می‌زنند؛ آن استثنا دربارهٔ **ثبتِ** روت چیزی
     * نمی‌گوید و نباید به‌جای «لینکِ مرده» گزارش شود.
     */
    private function routeExists(string $path): bool
    {
        try {
            Route::getRoutes()->match(Request::create($path, 'GET'));

            return true;
        } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
            return false;
        } catch (UrlGenerationException) {
            return true;
        }
    }

    /** @param  list<string>  $paths */
    private function assertAllResolve(array $paths, string $who): void
    {
        $this->assertNotEmpty($paths, 'هیچ لینکی از داشبورد استخراج نشد — تست دارد هیچ‌چیز را نمی‌سنجد.');

        $dead = array_values(array_filter($paths, fn ($p) => ! $this->routeExists($p)));

        $this->assertSame([], $dead,
            'روی داشبوردِ '.$who.' لینکی هست که هیچ روتِ GETی ندارد و ۴۰۴ می‌دهد: '.implode('، ', $dead));
    }

    /**
     * ⚠️ **هر سه نقش** جداگانه سنجیده می‌شوند.
     *
     * 🔴 داشبورد و نوارِ کناری برای هر نقش مجموعهٔ دیگری از لینک‌ها رندر
     * می‌کنند (`@unless($navSup)`، `@if($navAdmin)`). سنجیدنِ فقط دیدِ مدیر
     * یعنی لینک‌هایی که فقط پشتیبان یا نویسنده می‌بیند هرگز دیده نشوند.
     *
     * ⚠️ فهرست از `User::ROLES` می‌آید نه رشتهٔ دست‌نویس: نقشی که فردا اضافه
     * شود خودبه‌خود پوشش می‌گیرد — همان قاعده‌ای که خودِ `ROLES` برایش
     * ساخته شده.
     *
     * @return iterable<string,array{string,string}>
     */
    public static function roles(): iterable
    {
        foreach (User::ROLES as $role => $label) {
            yield $role => [$role, $label];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('roles')]
    public function test_every_link_on_the_dashboard_resolves(string $role, string $label): void
    {
        $this->seedDashboard();

        $html = $this->actingAs(User::factory()->create(['role' => $role]))
            ->get('/admin')->assertOk()->getContent();

        $this->assertAllResolve($this->internalLinks($html), $label);
    }

    /**
     * 🔴 نگهبانِ خودِ نگهبان.
     *
     * تستِ بالا فقط وقتی ارزش دارد که `routeExists()` واقعاً بتواند «نه»
     * بگوید. اگر روزی این متد بی‌سروصدا همیشه `true` برگرداند (استثنایی که
     * جای دیگری گرفته می‌شود، تغییرِ رفتارِ روتر)، آن دو تست سبز می‌مانند و
     * هیچ‌چیز را نمی‌سنجند — همان الگویی که در این پروژه ثبت شده: تستی که
     * فرضِ نانوشته‌اش را نمی‌سنجد، بدلِ محافظِ باگ می‌شود.
     */
    public function test_the_checker_itself_rejects_a_path_with_no_route(): void
    {
        $this->assertFalse($this->routeExists('/admin/this-page-was-never-registered'),
            'سنجه هر مسیری را «موجود» می‌خوانَد — پس تستِ لینک‌ها چیزی را نمی‌سنجد.');

        // 🔴 و مهم‌تر: مسیری که فقط با فعلِ دیگری ثبت شده هم باید «مرده» باشد.
        // دقیقاً حالتِ `/admin/services` پیش از این تغییر.
        Route::post('/only-post-verb', fn () => 'x');

        $this->assertFalse($this->routeExists('/only-post-verb'),
            'مسیری که فقط POST دارد برای یک <a href> همان ۴۰۴ است.');
    }
}
