<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        return view('admin.users', ['users' => User::orderBy('id')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'name' => 'required|string|max:80',
            'email' => 'required|email|max:120|unique:users,email',
            'role' => 'required|in:admin,author',
            'password' => 'required|string|min:8|max:100',
            /*
            | شمارهٔ تماس‌گیرنده — ⚠️ «فقط رقم» کافی نیست.
            | یک بار عددِ `1` ثبت شد، از همهٔ لایه‌ها رد شد و تماس را شکست.
            | حداقل ۱۰ رقم یعنی موبایل یا ثابتِ با پیش‌شماره.
            */
            'phone_extension' => ['nullable', 'string', 'max:16', 'regex:/^0?[0-9]{10}$/'],
        ]);

        User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
            'password' => Hash::make($data['password']),
            'phone_extension' => $data['phone_extension'] ?? null,
        ]);

        return back()->with('ok', 'کاربر ساخته شد.');
    }

    /**
     * ثبت یا پاک‌کردنِ داخلیِ تلفنِ یک کاربر.
     *
     * ⚠️ چرا روت جدا و نه فقط فیلد در فرمِ ساخت: کاربران **از قبل** ساخته
     * شده‌اند. اگر داخلی فقط موقع ساخت گرفته می‌شد، هیچ‌کدام از کارکنانِ فعلی
     * نمی‌توانستند تماس بگیرند و تنها راهش ساختنِ حسابِ تازه بود.
     *
     * ⚠️ رشتهٔ خالی مجاز است و یعنی «داخلی را بردار» — کارمندی که از تیم
     * پشتیبانی می‌رود نباید داخلی‌اش برای همیشه به اسمش بماند.
     */
    public function extension(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        /*
        | رشتهٔ خالی مجاز است (یعنی «بردار»)، ولی هر چیزِ دیگری باید شمارهٔ کامل باشد.
        |
        | 🔴 قانون‌ها **آرایه**‌اند نه رشتهٔ `|`-جدا: رجکسِ این‌جا خودش `|` دارد و
        | لاراول رشته را روی همان می‌شکند — نتیجه‌اش رجکسِ نصفه و
        | «No ending delimiter '/' found» در زمانِ اجرا، نه در زمانِ نوشتن.
        */
        $data = $request->validate([
            'phone_extension' => ['nullable', 'string', 'max:16', 'regex:/^(|0?[0-9]{10})$/'],
        ]);

        $ext = trim((string) ($data['phone_extension'] ?? ''));

        $user->update(['phone_extension' => $ext === '' ? null : $ext]);

        return back()->with('ok', $ext === ''
            ? 'داخلیِ «'.$user->name.'» برداشته شد.'
            : 'داخلیِ «'.$user->name.'» روی '.$ext.' تنظیم شد.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        if ($user->id === $request->user()->id) {
            return back()->withErrors(['user' => 'نمی‌توانید حساب خودتان را حذف کنید.']);
        }
        $user->delete();

        return back()->with('ok', 'کاربر حذف شد.');
    }
}
