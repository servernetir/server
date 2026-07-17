<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

/**
 * صفحات راهکار سازمانی (/solutions/{slug}) — هر راهکار یک صفحه‌ی فروش
 * سئوشده و سه‌زبانه با محتوای غنی از config/solutions.php.
 */
class SolutionController extends Controller
{
    public function show(string $slug): View
    {
        $solutions = config('solutions');
        abort_unless(isset($solutions[$slug]), 404);

        return view('pages.solution', [
            'slug' => $slug,
            'sol'  => $solutions[$slug],
        ]);
    }
}
