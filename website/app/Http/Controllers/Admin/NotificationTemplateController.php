<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificationTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

/**
 * مدیریتِ متنِ همهٔ پیام‌هایی که بین سرورنت و کاربر رد و بدل می‌شود.
 *
 * ⚠️ متنِ **پیامک** ویرایش‌پذیر نیست و این عمدی نیست بلکه محدودیتِ بیرونی است:
 * اپراتورهای ایرانی متنِ الگو را در پنلِ خودشان نگه می‌دارند و تأیید می‌کنند؛
 * ما فقط کدِ الگو و متغیرها را می‌فرستیم. صفحه این را صریح می‌گوید تا مدیر
 * دنبالِ فیلدی نگردد که وجود ندارد.
 */
class NotificationTemplateController extends Controller
{
    /**
     * صفحهٔ این بخش به تنظیمات منتقل شد (تبِ مربوطه). مسیر زنده مانده ولی
     * ویو ندارد — دو نسخه از یک صفحه دیر یا زود از هم فاصله می‌گیرند.
     */
    public function index(): RedirectResponse
    {
        return redirect()->to("/admin/settings?tab=messages");
    }

    public function edit(NotificationTemplate $template): View
    {
        return view('admin.template-edit', ['t' => $template]);
    }

    public function update(Request $request, NotificationTemplate $template): RedirectResponse
    {
        $data = $request->validate([
            'email_subject' => ['nullable', 'string', 'max:200'],
            'email_body'    => ['nullable', 'string', 'max:20000'],
            'bale_body'     => ['nullable', 'string', 'max:4000'],
            'is_active'     => ['nullable', 'boolean'],
        ], [], [
            'email_subject' => 'موضوع ایمیل', 'email_body' => 'متن ایمیل', 'bale_body' => 'متن کوتاه',
        ]);

        $template->update([
            'email_subject' => $data['email_subject'] ?? null,
            // متنِ ایمیل HTML است و از ویرایشگر می‌آید؛ با همان پاک‌سازیِ
            // بلاگ تمیز می‌شود تا تگِ خطرناک از پنل وارد ایمیل نشود.
            'email_body'    => filled($data['email_body'] ?? null)
                ? \App\Services\HtmlSanitizer::clean($data['email_body'])
                : null,
            'bale_body'     => $data['bale_body'] ?? null,
            'is_active'     => (bool) ($data['is_active'] ?? false),
            'updated_by'    => $request->user()?->id,
        ]);

        return back()->with('ok', 'متنِ «'.$template->title.'» ذخیره شد.');
    }

    /**
     * ارسالِ آزمایشی به خودِ مدیر.
     *
     * متغیرها با مقدارِ نمونه پر می‌شوند تا معلوم شود پیامِ نهایی چه شکلی است —
     * خواندنِ `{amount}` در ویرایشگر با دیدنِ «۲۵۰٬۰۰۰» در ایمیل فرق دارد.
     */
    public function test(Request $request, NotificationTemplate $template): RedirectResponse
    {
        $to = (string) ($request->user()?->email ?? '');

        if ($to === '') {
            return back()->withErrors('برای حساب شما ایمیلی ثبت نشده است.');
        }

        $vars = collect($template->variables ?? [])
            ->mapWithKeys(fn ($v) => [$v['name'] => '«نمونهٔ '.$v['name'].'»'])->all();

        $subject = NotificationTemplate::render($template->email_subject ?: $template->title, $vars);
        $body    = NotificationTemplate::render($template->email_body ?: $template->bale_body, $vars);

        // بی‌این، ایمیلِ خالی فرستاده می‌شد و مدیر فکر می‌کرد ارسال کار نمی‌کند
        if (trim(strip_tags($body)) === '') {
            return back()->withErrors('این الگو هنوز متنی ندارد — اول متن ایمیل یا متن کوتاه را بنویسید.');
        }

        try {
            Mail::mailer('smtp')->html($body, fn ($m) => $m->to($to)->subject('[آزمایشی] '.$subject));
        } catch (\Throwable $e) {
            return back()->withErrors('ارسال نشد: '.$e->getMessage());
        }

        return back()->with('ok', 'نسخهٔ آزمایشی به '.$to.' فرستاده شد.');
    }
}
