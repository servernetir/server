<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * ورودِ مدیر به پنلِ کاربریِ مشتری («جای او نشستن»).
 *
 * برای پشتیبانی لازم است: مدیر باید دقیقاً همان چیزی را ببیند که مشتری می‌بیند.
 *
 * ═══ قواعدِ امنیتی که این کلاس رعایت می‌کند ═══
 *
 * ۱) فقط نقشِ «مدیر» (نه نویسنده) می‌تواند. با isAdmin() سنجیده می‌شود.
 * ۲) شناسهٔ مدیر در نشست می‌ماند تا بازگشت ممکن باشد؛ نشستِ مدیر **بسته
 *    نمی‌شود** — او هم‌زمان در گاردِ web وارد می‌ماند.
 * ۳) هر ورود و خروج در لاگِ فعالیت با actor=staff ثبت می‌شود. جای‌نشستن
 *    باید همیشه ردِ ممیزی داشته باشد.
 * ۴) نوارِ هشدارِ همیشگی در پنل نشان داده می‌شود تا مدیر فراموش نکند جای
 *    مشتری نشسته و به‌اشتباه عملی انجام ندهد.
 * ۵) رمزِ مشتری هرگز لازم نیست و هیچ‌جا لمس نمی‌شود.
 */
class ImpersonateController extends Controller
{
    /** کلیدِ نشست که می‌گوید این نشستِ مشتری در واقع مالِ کدام مدیر است */
    public const SESSION_KEY = 'impersonator_id';

    public function start(Request $request, Customer $customer): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        if ($customer->status === 'closed') {
            return back()->withErrors('حسابِ این مشتری بسته است و ورود به پنلش ممکن نیست.');
        }

        $admin = $request->user();

        // مدیر در گاردِ web وارد می‌ماند؛ فقط گاردِ customer را هم پر می‌کنیم
        Auth::guard('customer')->login($customer);
        $request->session()->put(self::SESSION_KEY, $admin->id);

        ActivityLog::record($customer->id, 'impersonate',
            'مدیر «'.$admin->name.'» وارد پنلِ این مشتری شد', $request, 'staff');

        return redirect(console_lroute('account.home'))
            ->with('ok', 'وارد پنلِ «'.$customer->displayName().'» شدید. برای بازگشت، از نوارِ بالای صفحه استفاده کنید.');
    }

    /** بازگشت به پنلِ مدیریت و بستنِ نشستِ مشتری */
    public function stop(Request $request): RedirectResponse
    {
        $adminId = $request->session()->pull(self::SESSION_KEY);

        $customer = Auth::guard('customer')->user();

        if ($customer !== null) {
            ActivityLog::record($customer->id, 'impersonate',
                'مدیر از پنلِ این مشتری خارج شد', $request, 'staff');
        }

        Auth::guard('customer')->logout();

        // اگر نشستِ مدیر به هر دلیل از بین رفته بود، به ورودِ مدیریت برگردان
        return redirect($adminId && Auth::guard('web')->check()
            ? '/admin/customers'.($customer ? '/'.$customer->id : '')
            : '/admin/login');
    }
}
