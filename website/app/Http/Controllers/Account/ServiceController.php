<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * سرویس‌های مشتری — سمت خودِ مشتری (پنل کاربری).
 * فقط سرویس‌های خودش را می‌بیند.
 */
class ServiceController extends Controller
{
    public function index(): View
    {
        $customer = Auth::guard('customer')->user();

        $services = Schema::hasTable('services')
            ? $customer->services()->with(['invoices' => fn ($q) => $q->latest('id')])->latest('id')->get()
            : collect();

        return view('account.services', AccountController::shell('services') + [
            'services' => $services,
        ]);
    }
}
