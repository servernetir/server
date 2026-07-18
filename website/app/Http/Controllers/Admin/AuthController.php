<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class AuthController extends Controller
{
    /** راه‌اندازی اولین مدیر — فقط وقتی هیچ کاربری وجود ندارد (خودغیرفعال‌شونده) */
    public function showSetup(): View|RedirectResponse
    {
        if (User::count() > 0) {
            return redirect('/admin/login');
        }

        return view('admin.setup');
    }

    public function setup(Request $request): RedirectResponse
    {
        if (User::count() > 0) {
            return redirect('/admin/login');
        }
        $data = $request->validate([
            'name'     => 'required|string|max:80',
            'email'    => 'required|email|max:120',
            'password' => 'required|string|min:8|max:100|confirmed',
        ]);
        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'role'     => 'admin',
            'password' => Hash::make($data['password']),
        ]);
        Auth::login($user);

        return redirect('/admin');
    }

    public function showLogin(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect('/admin');
        }

        return view('admin.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        if (Auth::attempt($data, $request->boolean('remember'))) {
            $request->session()->regenerate();

            return redirect()->intended('/admin');
        }

        return back()->withErrors(['email' => 'ایمیل یا رمز عبور نادرست است.'])->onlyInput('email');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/admin/login');
    }
}
