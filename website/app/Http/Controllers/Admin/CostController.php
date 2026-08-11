<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServiceCost;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * هزینه‌های ثابت سرویس‌ها — که صاحب کسب‌وکار خودش می‌نویسد.
 *
 * این همان جواب سؤالِ «این ‎−۱۵۰۰ تومانِ پیامک از کجا می‌آید؟» است: از حالا
 * از این‌جا. مدیر تعرفهٔ واقعی هر سرویس را وارد می‌کند و دفتر مالی با همان
 * عدد هزینه ثبت می‌کند، نه با فرضِ ما.
 */
class CostController extends Controller
{
    /**
     * صفحهٔ این بخش به تنظیمات منتقل شد (تبِ مربوطه). مسیر زنده مانده ولی
     * ویو ندارد — دو نسخه از یک صفحه دیر یا زود از هم فاصله می‌گیرند.
     */
    public function index(): RedirectResponse
    {
        return redirect()->to("/admin/settings?tab=costs");
    }

    /** ویرایش گروهیِ مبالغ — همهٔ ردیف‌ها با یک ثبت */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'amount'   => ['array'],
            'amount.*' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'note'     => ['array'],
            'note.*'   => ['nullable', 'string', 'max:200'],
        ]);

        foreach (($data['amount'] ?? []) as $id => $amount) {
            $cost = ServiceCost::find($id);
            if ($cost === null) {
                continue;
            }
            $cost->amount = (int) $amount;
            $cost->note   = $data['note'][$id] ?? $cost->note;
            $cost->save();
        }

        return back()->with('ok', 'هزینه‌های سرویس‌ها به‌روزرسانی شد. دفتر مالی از این پس با این اعداد حساب می‌کند.');
    }

    /** افزودن یک هزینهٔ ثابتِ دلخواه (لایسنس، اجاره، هر چیز تکرارشونده) */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'label'  => ['required', 'string', 'max:120'],
            'amount' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'note'   => ['nullable', 'string', 'max:200'],
        ]);

        // کلید ماشینی از برچسب — یکتا، فقط برای ارجاع کد در صورت نیاز
        $key = 'custom_'.Str::slug($data['label'], '_');
        if (mb_strlen($key) > 55 || ServiceCost::where('key', $key)->exists()) {
            $key = 'custom_'.substr(md5($data['label'].microtime()), 0, 10);
        }

        ServiceCost::create([
            'key'           => $key,
            'label'         => $data['label'],
            'currency_code' => 'IRT',
            'amount'        => $data['amount'],
            'note'          => $data['note'] ?? null,
            'is_system'     => false,
        ]);

        return back()->with('ok', 'هزینهٔ جدید افزوده شد.');
    }

    public function destroy(ServiceCost $cost): RedirectResponse
    {
        // کلیدهای سیستمی (پیامک، شاهکار…) را کد صدا می‌زند؛ حذفشان یعنی
        // برگشت بی‌صدا به config. فقط هزینه‌های دلخواهِ خودِ مدیر حذف می‌شوند.
        if ($cost->is_system) {
            return back()->withErrors('این هزینهٔ سیستمی است و حذف نمی‌شود؛ می‌توانید مبلغش را صفر کنید.');
        }

        $cost->delete();

        return back()->with('ok', 'هزینه حذف شد.');
    }
}
