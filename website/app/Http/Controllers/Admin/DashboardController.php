<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        return view('admin.dashboard', [
            'stats' => [
                'blog'      => Post::where('type', 'blog')->count(),
                'kb'        => Post::where('type', 'kb')->count(),
                'published' => Post::where('status', 'published')->count(),
                'draft'     => Post::where('status', 'draft')->count(),
                'comments'  => Comment::where('approved', false)->count(),
                'users'     => User::count(),
            ],
            'recent' => Post::with('translations')->orderByDesc('id')->limit(6)->get(),
        ]);
    }
}
