<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\AiUsage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AiController extends Controller
{
    public function index()
    {
        $customer = Auth::guard('customer')->user();
        $history = Schema::hasTable('ai_usage')
            ? AiUsage::where('customer_id', $customer->id)->orderByDesc('id')->paginate(20)
            : new \Illuminate\Pagination\LengthAwarePaginator([], 0, 20);
        $spent = Schema::hasTable('ai_usage') ? (int) AiUsage::where('customer_id', $customer->id)
            ->whereIn('status', AiUsage::CHARGED)->where('created_at', '>=', now()->startOfMonth())
            ->sum(DB::raw('charged_irt - refunded_irt')) : 0;
        return view('account.ai', AccountController::shell('ai') + [
            'history' => $history, 'spent' => $spent,
            'projects' => $customer->aiProjects()->withCount('activeTokens')->get(),
        ]);
    }
}
