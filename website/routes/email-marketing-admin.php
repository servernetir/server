<?php

use App\Http\Controllers\Admin\EmailMarketingController;
use App\Http\Controllers\Admin\EmailEvidenceReviewController;
use App\Http\Controllers\Admin\EmailEvidenceDecisionController;
use App\Http\Controllers\Admin\EmailEvidenceDocumentController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\EmailMarketingCampaignController;

// Loaded only inside the existing auth:web + admin group; POST actions retain CSRF protection.
Route::get('/email-marketing', [EmailMarketingController::class, 'index'])->name('admin.email-marketing.index');
Route::get('/email-marketing/sample', [EmailMarketingController::class, 'sample'])->name('admin.email-marketing.sample');
Route::post('/email-marketing/stage', [EmailMarketingController::class, 'stage'])
    ->middleware('throttle:3,1')->name('admin.email-marketing.stage');
Route::post('/email-marketing/{preference}/suppress', [EmailMarketingController::class, 'suppress'])
    ->whereNumber('preference')->middleware('throttle:10,1')->name('admin.email-marketing.suppress');

Route::get('/email-marketing/evidence', [EmailEvidenceReviewController::class, 'index'])->name('admin.email-marketing.evidence.index');
Route::get('/email-marketing/evidence/sample', [EmailEvidenceReviewController::class, 'sample'])->name('admin.email-marketing.evidence.sample');
Route::post('/email-marketing/evidence/stage', [EmailEvidenceReviewController::class, 'stage'])
    ->middleware('throttle:3,1')->name('admin.email-marketing.evidence.stage');
Route::post('/email-marketing/evidence/documents', [EmailEvidenceDocumentController::class, 'store'])
    ->middleware('throttle:3,1')->name('admin.email-marketing.evidence.documents');

Route::get('/email-marketing/evidence/{review}/review', [EmailEvidenceDecisionController::class, 'review'])
    ->whereNumber('review')->name('admin.email-marketing.evidence.review');
Route::post('/email-marketing/evidence/{review}/decision', [EmailEvidenceDecisionController::class, 'decide'])
    ->whereNumber('review')->middleware('throttle:10,1')->name('admin.email-marketing.evidence.decide');

Route::post('/email-marketing/bulk-approve', [EmailMarketingCampaignController::class, 'approve'])
    ->middleware('throttle:3,1')->name('admin.email-marketing.bulk-approve');
Route::post('/email-marketing/recipients/import', [EmailMarketingCampaignController::class, 'import'])
    ->middleware('throttle:3,1')->name('admin.email-marketing.recipients.import');
Route::post('/email-marketing/campaigns', [EmailMarketingCampaignController::class, 'store'])
    ->middleware('throttle:5,1')->name('admin.email-marketing.campaigns.store');
Route::post('/email-marketing/campaigns/{campaign}/send', [EmailMarketingCampaignController::class, 'send'])
    ->whereNumber('campaign')->middleware('throttle:3,1')->name('admin.email-marketing.campaigns.send');
