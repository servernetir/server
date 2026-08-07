{{--
  فیلدهای مشترکِ «افزودن» و «ویرایش» — یک جا، تا فرمِ ویرایش هرگز از فرمِ
  افزودن عقب نیفتد. ($a === null یعنی فرمِ افزودن)
--}}
<label>نوع
  <select name="kind">
    <option value="bank" @selected(($a->kind ?? 'bank') === 'bank')>حواله بانکی</option>
    <option value="crypto" @selected(($a->kind ?? '') === 'crypto')>رمزارز</option>
  </select>
</label>

<label>ارز / دارایی
  <input name="currency_code" dir="ltr" required maxlength="8" list="pa-cur"
         value="{{ $a->currency_code ?? '' }}" placeholder="EUR">
</label>
<datalist id="pa-cur">
  @foreach(\App\Models\PaymentAccount::SUGGESTED as $c)<option value="{{ $c }}">@endforeach
</datalist>

<label>عنوان نمایشی
  <input name="label" maxlength="80" value="{{ $a->label ?? '' }}" placeholder="حساب یورویی">
</label>

<label>صاحب حساب
  <input name="holder" dir="ltr" maxlength="120" value="{{ $a->holder ?? '' }}">
</label>

<label>نام بانک
  <input name="bank_name" dir="ltr" maxlength="120" value="{{ $a->bank_name ?? '' }}">
</label>

<label>IBAN
  <input name="iban" dir="ltr" maxlength="64" value="{{ $a->iban ?? '' }}">
</label>

<label>SWIFT / BIC
  <input name="swift" dir="ltr" maxlength="24" value="{{ $a->swift ?? '' }}">
</label>

<label>شماره حساب
  <input name="account_no" dir="ltr" maxlength="64" value="{{ $a->account_no ?? '' }}">
</label>

<label>کشور بانک
  <input name="country" dir="ltr" maxlength="60" value="{{ $a->country ?? '' }}">
</label>

<label>شبکه (فقط رمزارز)
  <input name="network" dir="ltr" maxlength="32" list="pa-net" value="{{ $a->network ?? '' }}" placeholder="TRC20">
</label>
<datalist id="pa-net">
  @foreach(\App\Models\PaymentAccount::NETWORKS as $n)<option value="{{ $n }}">@endforeach
</datalist>

<label class="full">آدرس کیف (فقط رمزارز)
  <input name="address" dir="ltr" maxlength="160" value="{{ $a->address ?? '' }}">
</label>

<label class="full">یادداشت برای مشتری
  <textarea name="note" maxlength="2000">{{ $a->note ?? '' }}</textarea>
</label>

<label>ترتیب نمایش
  <input name="sort" type="number" min="0" max="999" value="{{ $a->sort ?? 0 }}">
</label>

<label class="chk">
  <input type="checkbox" name="is_active" value="1" @checked($a->is_active ?? true)> فعال
</label>

<p class="pa-note">
  🔴 برای رمزارز، <b>شبکه اجباری است</b> — انتقال روی شبکهٔ اشتباه برگشت‌ناپذیر است و
  پول مشتری از بین می‌رود. حسابی که ناقص ذخیره شود (بانکی بدون IBAN و شماره حساب،
  یا رمزارز بدون آدرس و شبکه) در صفحهٔ فاکتور <b>نمایش داده نمی‌شود</b>؛ گزینه‌ای که
  پول را به ناکجا بفرستد از نبودِ گزینه بدتر است.
</p>
