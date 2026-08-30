<?php

namespace App\Http\Controllers;

use App\Services\DocsRepository;
use Illuminate\View\View;

class DocsController extends Controller
{
    public function __construct(private DocsRepository $docs) {}

    /** صفحه‌ی اصلی مستندات — همه‌ی بخش‌ها به‌صورت کارت */
    public function index(): View
    {
        return view('pages.docs', ['tree' => $this->docs->tree()]);
    }

    /** یک مقاله‌ی مستندات */
    public function show(string $slug): View
    {
        $doc = $this->docs->find($slug);
        abort_if($doc === null, 404);

        return view('pages.docs-article', [
            'doc'        => $doc,
            'tree'       => $this->docs->tree(),
            'neighbours' => $this->docs->neighbours($doc['slug'], $doc['category']),
        ]);
    }
}
