<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * ورودِ دومرحله‌ای با اپلیکیشنِ احرازِ هویت — بخشِ مشتری.
 *
 * روی همان صفحهٔ `/account/security` می‌نشیند (بخشِ `#sec-2fa`) و چهار حالت
 * دارد: خاموش · در حالِ راه‌اندازی (رازِ تأییدنشده) · روشن · لحظهٔ نمایشِ
 * کدهای بازیابی.
 *
 * ═══ 🔴 چرا خاموش‌کردن هم کد می‌خواهد ═══
 *
 * کاربر با نشستِ باز پشتِ لپ‌تاپِ رهاشده‌اش بلند می‌شود. اگر «غیرفعال‌سازی»
 * یک دکمهٔ ساده باشد، هر کسی که به آن نشست برسد می‌تواند در دو کلیک تنها
 * لایه‌ای را که مانعش می‌شد بردارد — و صاحبِ حساب تا روزی که دیر شده هیچ
 * خبری ندارد. کد خواستن یعنی خاموش‌کردن هم همان‌قدر سخت است که روشن‌کردن.
 *
 * (کدِ بازیابی هم پذیرفته می‌شود: کسی که گوشی‌اش را گم کرده باید بتواند
 * دومرحله‌ای را بردارد و دوباره با گوشیِ تازه راه بیندازد.)
 */
class TwoFactorController extends Controller
{
    private function customer()
    {
        return Auth::guard('customer')->user();
    }

    /** ساختِ رازِ تأییدنشده — از این‌جا QR روی صفحهٔ امنیت ظاهر می‌شود */
    public function start(Request $request): RedirectResponse
    {
        $c = $this->customer();

        if ($c->hasTwoFactor()) {
            return $this->back(__('ui.tfa_already_on'), true);
        }

        $c->startTwoFactorSetup();

        return $this->back(__('ui.tfa_scan_now'));
    }

    /** تأیید با کدِ اپلیکیشن → فعال‌سازی + نمایشِ یک‌بارهٔ کدهای بازیابی */
    public function confirm(Request $request): RedirectResponse
    {
        $c = $this->customer();

        $data = $request->validate(
            ['code' => ['required', 'string', 'max:24']],
            [],
            ['code' => __('ui.tfa_code')],
        );

        if (! $c->twoFactorPending()) {
            return $this->back(__('ui.tfa_start_first'), true);
        }

        $codes = $c->confirmTwoFactor($data['code']);

        if ($codes === null) {
            return $this->back(__('ui.tfa_code_wrong'), true);
        }

        ActivityLog::record($c->id, 'security', 'ورود دومرحله‌ای (اپلیکیشن) فعال شد', $request, 'customer');

        return $this->back(__('ui.tfa_enabled_ok'))->with('tfa_recovery', $codes);
    }

    /** انصراف از راه‌اندازیِ نیمه‌کاره */
    public function cancel(Request $request): RedirectResponse
    {
        $c = $this->customer();

        if ($c->twoFactorPending()) {
            $c->disableTwoFactor();
        }

        return $this->back(__('ui.tfa_setup_cancelled'));
    }

    /** ساختِ دوبارهٔ کدهای بازیابی — با کدِ اپلیکیشن */
    public function recovery(Request $request): RedirectResponse
    {
        $c = $this->customer();

        $data = $request->validate(
            ['code' => ['required', 'string', 'max:24']],
            [],
            ['code' => __('ui.tfa_code')],
        );

        if (! $c->hasTwoFactor()) {
            return $this->back(__('ui.tfa_not_on'), true);
        }

        /*
        | ⚠️ عمداً `verifyTwoFactorCode` است نه فقط بررسیِ TOTP: کدِ بازیابی هم
        | باید بپذیرد. کسی که گوشی‌اش را گم کرده و با آخرین کدِ بازیابی‌اش
        | وارد شده، دقیقاً همان کسی است که **بیشترین** نیاز را به فهرستِ تازه
        | دارد؛ بستنِ این راه رویش یعنی فرستادنش به پشتیبانی.
        */
        if (! $c->verifyTwoFactorCode($data['code'], $reason)) {
            return $this->back($this->codeError($reason), true);
        }

        $codes = $c->regenerateRecoveryCodes();

        ActivityLog::record($c->id, 'security', 'کدهای بازیابی دومرحله‌ای بازسازی شد', $request, 'customer');

        return $this->back(__('ui.tfa_recovery_new'))->with('tfa_recovery', $codes);
    }

    /** خاموش‌کردن — با کدِ اپلیکیشن یا کدِ بازیابی */
    public function disable(Request $request): RedirectResponse
    {
        $c = $this->customer();

        $data = $request->validate(
            ['code' => ['required', 'string', 'max:24']],
            [],
            ['code' => __('ui.tfa_code')],
        );

        if (! $c->hasTwoFactor()) {
            return $this->back(__('ui.tfa_not_on'), true);
        }

        if (! $c->verifyTwoFactorCode($data['code'], $reason)) {
            return $this->back($this->codeError($reason), true);
        }

        $c->disableTwoFactor();

        ActivityLog::record($c->id, 'security', 'ورود دومرحله‌ای (اپلیکیشن) خاموش شد', $request, 'customer');

        return $this->back(__('ui.tfa_disabled_ok'));
    }

    /**
     * پیامِ متناسب با **دلیلِ** رد شدن.
     *
     * ⚠️ «تکراری» و «نادرست» یک پیام نمی‌گیرند: کاربری که کدِ چند ثانیه پیش را
     * دوباره زده، با پیامِ «کد نادرست است» می‌رود سراغِ ساعتِ گوشی و تنظیماتِ
     * اپلیکیشن — چون کدی که روی صفحه‌اش است همان است و به چشمش درست می‌آید.
     */
    private function codeError(?string $reason): string
    {
        return $reason === 'replay' ? __('ui.tfa_code_used') : __('ui.tfa_code_wrong');
    }

    /**
     * بازگشت به بخشِ دومرحله‌ای روی صفحهٔ امنیت.
     *
     * ⚠️ `withErrors` و نه `with('err')`: قالبِ این صفحه فقط `session('ok')` و
     * `$errors` را رندر می‌کند؛ یک فلشِ `err` بی‌صدا گم می‌شد.
     */
    private function back(string $message, bool $isError = false): RedirectResponse
    {
        $response = back()->withFragment('sec-2fa');

        return $isError
            ? $response->withErrors(['code' => $message])
            : $response->with('ok', $message);
    }
}
