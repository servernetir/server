<?php

use Illuminate\Support\Facades\Route;

// Keep old bookmarks useful after removing this page from the account area.
// Public unsubscribe capabilities remain in email-marketing-public.php.
Route::get('/email-preferences', fn () => redirect()->to(lroute('account.profile'))
    ->header('Cache-Control', 'private, no-store'))->name('email-preferences');
Route::post('/email-preferences', fn () => abort(410))
    ->middleware('throttle:10,1')->name('email-preferences.update');
