<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CommentController extends Controller
{
    public function index(Request $request): View
    {
        $filter = $request->query('f', 'pending');   // pending | approved | all
        $q = Comment::query()->orderByDesc('id');

        if ($filter === 'pending') {
            $q->where('approved', false);
        } elseif ($filter === 'approved') {
            $q->where('approved', true);
        }

        return view('admin.comments', [
            'comments' => $q->paginate(25)->withQueryString(),
            'filter'   => $filter,
            'counts'   => [
                'pending'  => Comment::where('approved', false)->count(),
                'approved' => Comment::where('approved', true)->count(),
            ],
        ]);
    }

    public function approve(Comment $comment): RedirectResponse
    {
        $comment->update(['approved' => true]);

        return back()->with('ok', 'کامنت تأیید و منتشر شد.');
    }

    public function destroy(Comment $comment): RedirectResponse
    {
        $comment->delete();

        return back()->with('ok', 'کامنت حذف شد.');
    }

    /** حذف پاسخ خودکار هوش مصنوعی (اگر مدیر نپسندید) */
    public function dropReply(Comment $comment): RedirectResponse
    {
        $comment->update(['reply' => null, 'reply_translations' => null, 'replied_at' => null]);

        return back()->with('ok', 'پاسخ هوش مصنوعی حذف شد.');
    }
}
