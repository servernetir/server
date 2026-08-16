<?php

namespace App\Http\Controllers;

use App\Models\AuditReport;
use App\Models\OutreachContact;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * صفحهٔ عمومیِ گزارشِ بررسیِ سایت — نشانی‌ای که برای صاحبِ سایت می‌فرستیم.
 */
class ReportController extends Controller
{
    public function show(string $token): View
    {
        // روی نصبی که هنوز مهاجرت نخورده، ۵۰۰ ندهد
        abort_unless(Schema::hasTable('audit_reports'), 404);

        $report = AuditReport::where('token', $token)->firstOrFail();

        return view('pages.report', ['report' => $report]);
    }

    /**
     * لغوِ اشتراکِ کمپین — **بی‌نیاز به ورود و بی‌نیاز به تأیید**.
     *
     * 🔴 لینکِ لغو باید با یک کلیک کار کند. اگر صفحهٔ تأیید یا فرمِ ورود بگذاریم،
     * بخشی از گیرنده‌ها رد می‌شوند و به‌جایش دکمهٔ «اسپم» را می‌زنند — که به
     * اعتبارِ ارسالِ کلِ دامنهٔ ما می‌خورد، نه فقط به این کمپین.
     *
     * ⚠️ عمداً GET است با اینکه حالت را عوض می‌کند: کلاینت‌های ایمیل فقط لینک
     * می‌دهند. توکن تصادفی و یکتاست و تنها کاری که می‌کند «دیگر برایم نفرست»
     * است — بدترین سوءاستفاده‌اش این است که کسی خودش را از فهرست بیرون بگذارد.
     */
    public function unsubscribe(string $token): View|RedirectResponse
    {
        abort_unless(Schema::hasTable('outreach_contacts'), 404);

        $contact = OutreachContact::where('unsubscribe_token', $token)->firstOrFail();

        OutreachContact::suppress($contact->email);

        return view('pages.unsubscribed', ['email' => $contact->email]);
    }
}
