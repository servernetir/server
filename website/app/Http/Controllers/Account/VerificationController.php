<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerDocument;
use App\Models\CustomerProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * احراز هویتِ مشتری — به‌ویژه حقوقی (شرکت).
 *
 * مشتریِ حقوقی اطلاعاتِ شرکت را وارد و **معرفی‌نامهٔ نماینده** و **اساسنامه** را
 * آپلود می‌کند؛ پروفایل «در انتظار بررسی» می‌شود و به پشتیبانی (بله + ایمیل)
 * اعلان می‌رود. تأییدِ نهایی دستیِ تیمِ پشتیبانی است. مدارک بیرونِ webroot
 * ذخیره می‌شوند.
 */
class VerificationController extends Controller
{
    /**
     * صفحهٔ جدا حذف شد و با «پروفایل و احراز هویت» ادغام شد.
     * روت می‌ماند تا لینک‌ها/بوکمارک‌های قدیمی نشکنند.
     */
    public function show(): RedirectResponse
    {
        return redirect()->route(
            (\App\Providers\AppServiceProvider::LOCALES[app()->getLocale()] ?? '').'account.profile'
        );
    }

    public function submit(Request $request): RedirectResponse
    {
        $customer = Auth::guard('customer')->user();
        $profile = $this->profileFor($customer);

        $isCompany = $request->input('type') === 'company' || $profile->type === 'company';

        $rules = ['type' => ['required', 'in:individual,company']];
        if ($isCompany) {
            $rules += [
                'company_name'        => ['required', 'string', 'max:190'],
                'registration_number' => ['nullable', 'string', 'max:60'],
                'economic_code'       => ['nullable', 'string', 'max:60'],
                'rep_first_name'      => ['required', 'string', 'max:80'],
                'rep_last_name'       => ['required', 'string', 'max:80'],
                'rep_position'        => ['nullable', 'string', 'max:80'],
            ];
        }
        // اولین بار: هر دو سند لازم است؛ اگر قبلاً آپلود شده، اختیاری
        $have = CustomerDocument::where('customer_profile_id', $profile->id)->pluck('kind')->all();
        $docRule = fn ($kind) => [
            in_array($kind, $have, true) ? 'nullable' : ($isCompany ? 'required' : 'nullable'),
            'file', 'mimetypes:application/pdf,image/png,image/jpeg', 'max:5120',
        ];
        $rules['doc_letter']   = $docRule('rep_letter');
        $rules['doc_articles'] = $docRule('articles');

        $data = $request->validate($rules, [], [
            'company_name' => 'نام شرکت', 'rep_first_name' => 'نامِ نماینده', 'rep_last_name' => 'نام‌خانوادگیِ نماینده',
            'doc_letter' => 'معرفی‌نامهٔ نماینده', 'doc_articles' => 'اساسنامه',
        ]);

        $profile->type = $data['type'];
        if ($isCompany) {
            $profile->fill(array_intersect_key($data, array_flip([
                'company_name', 'registration_number', 'economic_code',
                'rep_first_name', 'rep_last_name', 'rep_position',
            ])));
        }
        $profile->status = 'pending';
        $profile->reject_reason = null;
        $profile->save();

        foreach (['doc_letter' => 'rep_letter', 'doc_articles' => 'articles'] as $field => $kind) {
            if ($request->hasFile($field) && $request->file($field)->isValid()) {
                $this->storeDoc($profile, $request->file($field), $kind);
            }
        }

        $this->notifySupport($customer, $profile);

        \App\Models\ActivityLog::record($customer->id, 'service',
            'درخواستِ تأییدِ هویت'.($isCompany ? 'ِ حقوقی' : '').' ثبت شد', $request, 'customer');

        // به بخشِ حقوقیِ همان صفحهٔ پروفایل برمی‌گردد (صفحهٔ جدا حذف شده)
        return redirect()->to(lroute('account.profile').'#company')
            ->with('ok', 'اطلاعات و مدارک ثبت و برای بررسیِ پشتیبانی ارسال شد. پس از تأیید، پروفایلتان تأیید می‌شود.');
    }

    /** پروفایلِ پیش‌فرضِ مشتری را برمی‌گرداند یا می‌سازد (AccountController هم استفاده می‌کند) */
    public function profileFor(Customer $customer): CustomerProfile
    {
        return $customer->profiles()->orderByDesc('is_default')->orderBy('id')->first()
            ?: CustomerProfile::create([
                'customer_id' => $customer->id,
                'type'        => 'individual',
                'is_default'  => true,
                'status'      => 'draft',
                'email'       => $customer->email,
                'mobile'      => $customer->phone,
            ]);
    }

    private function storeDoc(CustomerProfile $profile, UploadedFile $file, string $kind): void
    {
        // نسخهٔ قبلیِ همین نوع را حذف کن (فقط آخرین معتبر است)
        foreach (CustomerDocument::where('customer_profile_id', $profile->id)->where('kind', $kind)->get() as $old) {
            Storage::disk('local')->delete($old->disk_path);
            $old->delete();
        }

        $path = $file->storeAs('kyc/'.$profile->customer_id, $kind.'-'.uniqid().'.'.$file->extension(), 'local');

        CustomerDocument::create([
            'customer_profile_id' => $profile->id,
            'kind'                => $kind,
            'status'              => 'pending',
            'scan_status'         => 'skipped',
            'disk_path'           => $path,
            'original_name'       => mb_substr($file->getClientOriginalName(), 0, 190),
            'mime'                => $file->getClientMimeType(),
            'size_bytes'          => $file->getSize(),
            'sha256'              => hash_file('sha256', $file->getRealPath()),
            'uploaded_at'         => now(),
        ]);
    }

    private function notifySupport(Customer $customer, CustomerProfile $profile): void
    {
        $who = $profile->company_name ?: $customer->email;
        $text = '🔔 کاربرِ '.($profile->type === 'company' ? 'حقوقی' : 'حقیقی')
            .' نیازمندِ تأیید: «'.$who.'» ('.$customer->code.'). در پنل مدیریت → احراز هویت بررسی کنید.';

        // بله به شمارهٔ پشتیبانی — از APIِ ربات، نه سفیر (پشتیبانی مشتری نیست)
        try {
            $phone = (string) config('servernet.contact.notify_phone', '');
            app(\App\Services\Bale\BaleNotifier::class)->toAdmin($phone, $text);
        } catch (\Throwable) {
        }

        // ایمیل به پشتیبانی
        try {
            $email = (string) config('servernet.contact.email', 'support@servernet.cloud');
            Mail::mailer('smtp')->raw($text, fn ($m) => $m->to($email)->subject('تأیید کاربر جدید — '.$customer->code));
        } catch (\Throwable) {
        }
    }
}
