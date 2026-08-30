<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * فرصت‌های شغلی — نمایش موقعیت‌ها و دریافت درخواست همکاری.
 * درخواست از طریق وبهوک n8n به تیم می‌رسد (ایمیل سرور روی log است).
 */
class CareersController extends Controller
{
    public function show(): View
    {
        return view('pages.careers', [
            'perks'     => config('careers.perks'),
            'positions' => config('careers.positions'),
        ]);
    }

    public function apply(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'     => 'required|string|max:100',
            'email'    => 'required|email|max:120',
            'phone'    => 'nullable|string|max:30',
            'position' => 'required|string|max:80',
            'resume'   => 'nullable|url|max:300',
            'message'  => 'nullable|string|max:2000',
            'website'  => 'nullable|max:0', // honeypot
        ], ['website.max' => 'spam']);

        // محدودیت نرخ ساده
        $rk = 'career.apply.'.md5((string) $request->ip());
        if ((int) Cache::get($rk, 0) >= 4) {
            return back()->with('career_status', 'busy')->withFragment('apply');
        }
        Cache::put($rk, (int) Cache::get($rk, 0) + 1, 1800);

        $this->notify($data);

        return back()->with('career_status', 'ok')->withFragment('apply');
    }

    private function notify(array $d): void
    {
        if (! $webhook = config('services.n8n.chat_webhook')) {
            return;
        }
        $body = "درخواست همکاری جدید:\n"
            ."نام: {$d['name']}\nایمیل: {$d['email']}\nتلفن: ".($d['phone'] ?? '—')."\n"
            ."موقعیت: {$d['position']}\nرزومه: ".($d['resume'] ?? '—')."\n"
            ."پیام: ".($d['message'] ?? '—');
        $ch = curl_init($webhook);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode(['message' => $body, 'session' => 'career-'.substr(md5($d['email']), 0, 8)], JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}
