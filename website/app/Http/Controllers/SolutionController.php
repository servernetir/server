<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * صفحات راهکار سازمانی (/solutions/{slug}) — هر راهکار یک صفحه‌ی فروش
 * سئوشده و سه‌زبانه با محتوای غنی از config/solutions.php.
 */
class SolutionController extends Controller
{
    /** راهکارهایی که با صفحه‌ی محصول یکی شده‌اند → ریدایرکت دائمی */
    private const MERGED = ['email' => 'email']; // solution slug => hosting slug

    public function show(string $slug): View|RedirectResponse
    {
        if (isset(self::MERGED[$slug])) {
            return redirect(lroute('hosting', self::MERGED[$slug]), 301);
        }

        $solutions = config('solutions');
        abort_unless(isset($solutions[$slug]), 404);

        return view('pages.solution', [
            'slug' => $slug,
            'sol'  => $solutions[$slug],
        ]);
    }
}
