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
            'name'     => 'required|string|max:80',
            'email'    => 'required|email|max:120|unique:users,email',
            'role'     => 'required|in:admin,author',
            'password' => 'required|string|min:8|max:100',
        ]);

        User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'role'     => $data['role'],
            'password' => Hash::make($data['password']),
        ]);

        return back()->with('ok', 'کاربر ساخته شد.');
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
