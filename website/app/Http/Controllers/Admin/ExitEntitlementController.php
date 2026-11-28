<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CloudInstance;
use App\Models\Service;
use App\Support\ExitCountries;
use App\Support\ExitEntitlement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExitEntitlementController extends Controller
{
    public function __invoke(Request $request, Service $service): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $data = $request->validate(['enabled' => ['required', 'in:0,1']]);
        $enable = $data['enabled'] === '1';

        DB::transaction(function () use ($request, $service, $enable) {
            $s = Service::whereKey($service->id)->lockForUpdate()->firstOrFail();
            $i = CloudInstance::where('service_id', $s->id)->lockForUpdate()->first();
            if (! ExitEntitlement::optional($s, $i)
                || CloudInstance::where('provider', 'proxmox')
                    ->where('provider_ref', $i->provider_ref)->count() !== 1) {
                throw ValidationException::withMessages(['enabled' => 'این سرویس برای افزونهٔ اکسیت واجد شرایط نیست.']);
            }

            $meta = $i->meta ?? [];
            $meta['exit_entitled'] = $enable;
            $meta['exit_entitled_at'] = now()->toIso8601String();
            $meta['exit_entitled_by'] = (int) $request->user()->id;
            // Enabling never changes routing. Revoking restores the local
            // egress via the agent; other service metadata remains untouched.
            if (! $enable) {
                $meta['exit_country'] = ExitCountries::NONE;
                $meta['exit_country_at'] = now()->toIso8601String();
                $meta['exit_country_by'] = 'admin';
            }
            $i->forceFill(['meta' => $meta])->save();
            ActivityLog::forService($s, 'settings',
                $enable ? 'افزونهٔ اختیاری تغییر کشور خروج توسط مدیر فعال شد؛ مسیر فعلی بدون تغییر.'
                    : 'افزونهٔ اختیاری تغییر کشور خروج توسط مدیر لغو شد؛ خروج عادی ایران در صف اعمال.',
                'staff', $request);
        });

        return back()->with('ok', $enable
            ? 'مجوز اکسیت فعال شد. مشتری می‌تواند از میان اکسیت‌های اختصاصیِ تأییدشده انتخاب کند؛ مسیر فعلی تغییر نکرد.'
            : 'مجوز اکسیت غیرفعال شد. بازگشت به خروج ایران در پیمایش بعدی عامل اعمال می‌شود.');
    }
}
