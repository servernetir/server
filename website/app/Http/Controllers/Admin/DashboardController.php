<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Post;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Finance\BusinessLedger;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(BusinessLedger $ledger): View
    {
        // نگهبان hasTable همه‌جا: روی سروری که هنوز جدول‌های CMS را نساخته،
        // داشبورد نباید ۵۰۰ شود — عددِ صفر نشان می‌دهد، نه خطا.
        $hasCustomers = Schema::hasTable('customers');
        $hasInvoices  = Schema::hasTable('invoices');
        $hasTickets   = Schema::hasTable('tickets');

        return view('admin.dashboard', [
            'stats' => [
                'blog'      => Post::where('type', 'blog')->count(),
                'kb'        => Post::where('type', 'kb')->count(),
                'published' => Post::where('status', 'published')->count(),
                'draft'     => Post::where('status', 'draft')->count(),
                'comments'  => Comment::where('approved', false)->count(),
                'users'     => User::count(),
            ],
            // آمار کسب‌وکار — قلبِ پنلِ شبیه‌WHMCS
            'biz' => [
                'customers'       => $hasCustomers ? Customer::count() : 0,
                'customers_new'   => $hasCustomers ? Customer::where('created_at', '>=', now()->subDays(30))->count() : 0,
                'tickets_open'    => $hasTickets ? Ticket::where('status', 'open')->count() : 0,
                'invoices_unpaid' => $hasInvoices ? Invoice::whereIn('status', ['unpaid', 'partial', 'overdue'])->count() : 0,
                'unpaid_amount'   => $hasInvoices ? (int) Invoice::whereIn('status', ['unpaid', 'partial', 'overdue'])->sum('total')
                                                     - (int) Invoice::whereIn('status', ['unpaid', 'partial', 'overdue'])->sum('paid') : 0,
            ],
            'fin'    => $ledger->summary(),
            'recent' => Post::with('translations')->orderByDesc('id')->limit(5)->get(),
            'newCustomers' => $hasCustomers
                ? Customer::with('identityVerification')->orderByDesc('id')->limit(6)->get()
                : collect(),
        ]);
    }
}
