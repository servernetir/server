<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

class ProfileController extends Controller
{
    public function index(Request $request)
    {
        $currentId = $request->session()->getId();
        $userId    = Auth::id();

        $rows = DB::table('sessions')
            ->where(function ($q) use ($userId, $currentId) {
                $q->where('user_id', $userId)
                    ->orWhere('id', $currentId);
            })
            ->orderByDesc('last_activity')
            ->get();

        if (! $rows->contains('id', $currentId)) {
            $rows->prepend((object)[
                'id'            => $currentId,
                'ip_address'    => $request->ip(),
                'user_agent'    => $request->userAgent(),
                'last_activity' => now()->timestamp,
            ]);
        }

        $sessions = $rows->map(function ($s) use ($currentId) {
            $ua = strtolower($s->user_agent ?? '');

            $os = 'Unknown';
            if (str_contains($ua, 'windows')) $os = 'Windows';
            elseif (str_contains($ua, 'android')) $os = 'Android';
            elseif (str_contains($ua, 'iphone') || str_contains($ua, 'ios')) $os = 'iOS';
            elseif (str_contains($ua, 'mac os') || str_contains($ua, 'macintosh')) $os = 'macOS';
            elseif (str_contains($ua, 'linux')) $os = 'Linux';

            $browser = 'Unknown';
            if (str_contains($ua, 'edg/')) $browser = 'Edge';
            elseif (str_contains($ua, 'opr/') || str_contains($ua, 'opera')) $browser = 'Opera';
            elseif (str_contains($ua, 'chrome/')) $browser = 'Chrome';
            elseif (str_contains($ua, 'firefox/')) $browser = 'Firefox';
            elseif (str_contains($ua, 'safari/') && !str_contains($ua, 'chrome/')) $browser = 'Safari';

            return [
                'id'         => $s->id,
                'ip'         => $s->ip_address ?? '-',
                'device'     => $os . ', ' . $browser,
                'is_current' => $s->id === $currentId,
                'date'       => \Carbon\Carbon::createFromTimestamp($s->last_activity)->format('d.m.Y'),
                'ua'         => \Illuminate\Support\Str::limit($s->user_agent ?? '', 140),
            ];
        });

        $otherCount = DB::table('sessions')
            ->where('user_id', $userId)
            ->where('id', '!=', $currentId)
            ->count();

        $hasOtherSessions = $otherCount > 0;

        return view('profile', compact('sessions', 'hasOtherSessions'));
    }


    public function destroy(Request $request, string $id)
    {
        if ($id === $request->session()->getId()) {
            return back()->withErrors(['session' => 'You cannot delete the current session ❌']);
        }

        DB::table('sessions')
            ->where('user_id', Auth::id())
            ->where('id', $id)
            ->delete();

        return back()->with('success', 'The selection meeting was closed');
    }

    public function logoutOthers(Request $request)
    {
        $currentId = $request->session()->getId();
        $userId    = Auth::id();

        $otherCount = DB::table('sessions')
            ->where('user_id', $userId)
            ->where('id', '!=', $currentId)
            ->count();

        if ($otherCount === 0) {
            return back()->with('info', 'No other sessions to close');
        }

        DB::table('sessions')
            ->where('user_id', $userId)
            ->where('id', '!=', $currentId)
            ->delete();

        /** @var \App\Models\User $u */
        $u = Auth::user();
        $u->setRememberToken(Str::random(60));
        $u->save();


        return back()->with('success', 'Other sessions have been closed');
    }
}