<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Carbon\Carbon;

class ProfileController extends Controller
{
    public function index(Request $request)
    {
        $user = User::with(['inviter:id,name,email'])
            ->findOrFail(Auth::id());

        $verifyMap = [0 => 'Unverified', 1 => 'Basic', 2 => 'Full'];
        $statusMap = ['active' => 'Active', 'inactive' => 'Inactive', 'banned' => 'Banned'];

        $view = [
            'contract_no'     => str_pad((string)$user->id, 6, '0', STR_PAD_LEFT),
            'email'           => $user->email,
            'name'            => $user->name ?: '—',
            'phone'           => $user->phone ?: 'Not specified',
            'type'            => $user->user_type === 'company' ? 'Company' : 'Individual',
            'company_name'    => $user->user_type === 'company' ? ($user->company_name ?: '—') : null,
            'company_reg_no'  => $user->user_type === 'company' ? ($user->company_register_no ?: '—') : null,
            'company_nid'     => $user->user_type === 'company' ? ($user->company_national_id ?: '—') : null,
            'verification'    => $verifyMap[$user->verification_level] ?? 'Unknown',
            'referral_code'   => $user->referral_code ?: null,
            'invited_by'      => $user->inviter ? $user->inviter->email : null,
            'wallet_balance'  => number_format((float)$user->wallet_balance, 2) . ' €',
            'status'          => $statusMap[$user->status] ?? ucfirst($user->status),
            'last_login'      => $user->last_login_at ? Carbon::parse($user->last_login_at)->format('Y-m-d H:i') : '—',
            'member_since'    => $user->created_at ? Carbon::parse($user->created_at)->format('Y-m-d') : '—',
        ];

        return view('profile', compact('view'));
    }
}