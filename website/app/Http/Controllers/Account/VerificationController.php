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

        /*
        | فردِ خارجی = فردی که استعلامِ هویتِ ایرانی (شاهکار/ثبتِ احوال) ندارد.
        | ایرانی در ثبت‌نام از آن مسیر رد شده و این‌جا مدرکی از او نمی‌خواهیم.
        |
        | مجموعهٔ خارجی همان الگویِ Wise/Binance است (خواستِ صریحِ کارفرما —
        | ۶ شهریور ۱۴۰۵): نامِ کامل + تاریخِ تولد (۱۸+) + آدرسِ کاملِ سکونت +
        | کشور (کدِ ISO — ستون char(2) است؛ متنِ آزاد روی MariaDB می‌شکست) +
        | مدرکِ هویتی به انتخاب (پاسپورت/کارتِ ملی/گواهینامه، کارت‌ها با پشت) +
        | سلفی با همان مدرک + مدرکِ آدرسِ ≤۳ ماه. تأییدِ دستیِ تیمِ ماست و
        | همان پرچمِ verified را می‌زند که IranSalesGate می‌خوانَد.
        */
        $foreign = ! $isCompany && $customer->identityVerification === null;

        $rules = ['type' => ['required', 'in:individual,company']];

        if ($foreign) {
            $rules += [
                'first_name'  => ['required', 'string', 'max:80'],
                'last_name'   => ['required', 'string', 'max:80'],
                // ۱۸+ : هر دو مرجعِ الگو (Wise/Binance) زیرِ ۱۸ را نمی‌پذیرند
                'birth_date'  => ['required', 'date_format:Y-m-d',
                    'before_or_equal:'.now()->subYears(18)->toDateString()],
                'country'     => ['required', 'string', \Illuminate\Validation\Rule::in(array_keys(\App\Support\Countries::ALL))],
                'address'     => ['required', 'string', 'max:500'],
                'city'        => ['required', 'string', 'max:64'],
                'postal_code' => ['nullable', 'string', 'max:20'],
                'id_type'     => ['required', 'in:passport,national_id,driving_license'],
            ];
        }
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

        /*
        | مدارکِ فردِ خارجی — بارِ اول اجباری، بعد از آن جایگزینیِ اختیاری.
        | جای «پاسپورتِ» ثابت، حالا نوعِ مدرک انتخابی است و kindِ ذخیره‌شده
        | همان نوع است (passport/national_id/driving_license) تا مدیر دقیقاً
        | بداند چه چیزی را باز می‌کند. پشتِ کارت فقط برای کارت/گواهینامه
        | معنا دارد؛ پاسپورت یک‌صفحه‌ای است.
        */
        $idKinds = ['passport', 'national_id', 'driving_license'];
        $haveId = count(array_intersect($idKinds, $have)) > 0;
        $idType = (string) $request->input('id_type', 'passport');
        $needBack = $foreign && $idType !== 'passport';

        $fileRule = ['file', 'mimetypes:application/pdf,image/png,image/jpeg', 'max:5120'];
        $rules['doc_passport'] = array_merge([$haveId || ! $foreign ? 'nullable' : 'required'], $fileRule);
        $rules['doc_id_back']  = array_merge([
            ($needBack && ! in_array('id_back', $have, true)) ? 'required' : 'nullable',
        ], $fileRule);
        $rules['doc_selfie']   = array_merge([
            in_array('selfie', $have, true) || ! $foreign ? 'nullable' : 'required',
        ], $fileRule);
        $rules['doc_address']  = array_merge([
            in_array('address_proof', $have, true) || ! $foreign ? 'nullable' : 'required',
        ], $fileRule);

        $data = $request->validate($rules, [], [
            'company_name' => 'نام شرکت', 'rep_first_name' => 'نامِ نماینده', 'rep_last_name' => 'نام‌خانوادگیِ نماینده',
            'doc_letter' => 'معرفی‌نامهٔ نماینده', 'doc_articles' => 'اساسنامه',
            'first_name' => __('ui.prof_first_name'), 'last_name' => __('ui.prof_last_name'),
            'country' => __('ui.prof_country'), 'birth_date' => __('ui.prof_birth_date'),
            'address' => __('ui.prof_address'), 'city' => __('ui.prof_city'),
            'postal_code' => __('ui.prof_postal'), 'id_type' => __('ui.prof_id_type'),
            'doc_passport' => __('ui.prof_doc_id_front'), 'doc_id_back' => __('ui.prof_doc_id_back'),
            'doc_selfie' => __('ui.prof_doc_selfie'), 'doc_address' => __('ui.prof_doc_address'),
        ]);

        $profile->type = $data['type'];

        if ($foreign) {
            $profile->fill(array_intersect_key($data, array_flip([
                'first_name', 'last_name', 'birth_date', 'country', 'address', 'city', 'postal_code',
            ])));
        }

        if ($isCompany) {
            $profile->fill(array_intersect_key($data, array_flip([
                'company_name', 'registration_number', 'economic_code',
                'rep_first_name', 'rep_last_name', 'rep_position',
            ])));
        }
        $profile->status = 'pending';
        $profile->reject_reason = null;
        $profile->save();

        foreach (['doc_letter' => 'rep_letter', 'doc_articles' => 'articles',
            'doc_passport' => $idType, 'doc_id_back' => 'id_back',
            'doc_selfie' => 'selfie', 'doc_address' => 'address_proof'] as $field => $kind) {
            if ($request->hasFile($field) && $request->file($field)->isValid()) {
                // مدرکِ هویتیِ تازه، هر نوعِ قبلی را جایگزین می‌کند (کاربر
                // ممکن است از کارتِ ملی به پاسپورت برگردد — دو ID نمی‌مانَد)
                $this->storeDoc($profile, $request->file($field), $kind,
                    in_array($kind, $idKinds, true) ? $idKinds : [$kind]);
            }
        }

        $this->notifySupport($customer, $profile);

        \App\Models\ActivityLog::record($customer->id, 'service',
            __($isCompany ? 'ui.act_kyc_req_co' : 'ui.act_kyc_req'), $request, 'customer');

        // به بخشِ حقوقیِ همان صفحهٔ پروفایل برمی‌گردد (صفحهٔ جدا حذف شده)
        return redirect()->to(lroute('account.profile').'#company')
            ->with('ok', __('ui.prof_vf_submitted'));
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

    private function storeDoc(CustomerProfile $profile, UploadedFile $file, string $kind, ?array $replaces = null): void
    {
        // نسخهٔ قبلیِ همین نوع (یا خانوادهٔ هم‌ردیف) را حذف کن — فقط آخرین معتبر است
        foreach (CustomerDocument::where('customer_profile_id', $profile->id)
            ->whereIn('kind', $replaces ?? [$kind])->get() as $old) {
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

    /*
    | اعلانِ «نیازمندِ تأیید» با دکمه‌های بررسی — خواستِ کارفرما (۶ شهریور):
    | «همان‌جا در بله مدارک را ببینم و تأیید/رد کنم؛ دیگر لازم نباشد جای
    | دیگری بروم.» دکمه‌ها callbackاند و روتر بله رسیدگی می‌کند؛ خودِ لینکِ
    | پنل عمداً حذف شد («لینک به کارِ من نمی‌آید»).
    */
    private function notifySupport(Customer $customer, CustomerProfile $profile): void
    {
        $who = $profile->company_name
            ?: trim(($profile->first_name ?? '').' '.($profile->last_name ?? ''))
            ?: $customer->email;
        $kind = $profile->type === 'company' ? 'حقوقی'
            : ($customer->identityVerification === null ? 'خارجی (مدرکِ سندی)' : 'حقیقی');
        $cb = \App\Services\Bale\Admin\AdminBaleRouter::CB_PREFIX;

        try {
            app(\App\Services\Notify\AdminNotifier::class)->event('کاربر نیازمندِ تأییدِ هویت', [
                'نوع'   => $kind,
                'نام (مطابق مدرک)' => $who,
                'کشور'  => \App\Support\Countries::name($profile->country),
                'زبانِ مشتری' => strtoupper((string) $customer->locale),
                'کد'    => $customer->code,
                'مدارک' => fa_num(\App\Models\CustomerDocument::where('customer_profile_id', $profile->id)->count()).' فایل',
            ], null, '🪪', [
                [['text' => '📄 مشاهدهٔ مدارک', 'data' => $cb.'kd:'.$profile->id]],
                [
                    ['text' => '✅ تأیید', 'data' => $cb.'ka:'.$profile->id],
                    ['text' => '⛔️ رد', 'data' => $cb.'kr:'.$profile->id],
                ],
                [['text' => '👤 پروفایلِ مشتری', 'data' => $cb.'c:'.$customer->id]],
            ]);
        } catch (\Throwable) {
        }
    }
}
