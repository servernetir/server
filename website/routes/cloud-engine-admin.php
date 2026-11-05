<?php

use App\Http\Controllers\Admin\CloudEngineController;
use Illuminate\Support\Facades\Route;

foreach (['' => '', 'en/' => 'en.', 'tr/' => 'tr.'] as $prefix => $name) {
    $locale = $prefix === '' ? 'fa' : rtrim($prefix, '/');
    Route::prefix($prefix.'admin/cloud-engine')->name($name.'admin.engine.')
        ->middleware(['locale:'.$locale, 'auth:web', 'admin'])->group(function () {
            $c = CloudEngineController::class;
            Route::get('/', [$c, 'index'])->name('index');
            Route::post('nodes/{node?}', [$c, 'node'])->whereNumber('node')->middleware('throttle:20,1')->name('node');
            Route::post('nodes/{node}/control', [$c, 'control'])->whereNumber('node')->middleware('throttle:20,1')->name('control');
            Route::post('subnets', [$c, 'subnet'])->middleware('throttle:20,1')->name('subnet');
            Route::post('subnets/{subnet}', [$c, 'subnetControl'])->whereNumber('subnet')->middleware('throttle:20,1')->name('subnet-control');
            Route::post('images', [$c, 'image'])->middleware('throttle:20,1')->name('image');
            Route::post('images/{image}/accept', [$c, 'acceptImage'])->whereNumber('image')->middleware('throttle:20,1')->name('accept-image');
            Route::post('mappings', [$c, 'mapping'])->middleware('throttle:20,1')->name('mapping');
            Route::post('plans', [$c, 'plan'])->middleware('throttle:20,1')->name('plan');
            Route::post('operations/{operation}/retry', [$c, 'retry'])->whereNumber('operation')->middleware('throttle:10,1')->name('retry');
        });
}
