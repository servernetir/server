@php
  /** @var array|null $r  ردیفِ مشخصات: ['label'=>['fa','en','tr'],'fa','en','tr'] یا null */
  $lf = data_get($r, 'label.fa', ''); $le = data_get($r, 'label.en', ''); $lt = data_get($r, 'label.tr', '');
  $vf = data_get($r, 'fa', '');       $ve = data_get($r, 'en', '');       $vt = data_get($r, 'tr', '');
@endphp
<div class="psf-spec">
  <div class="psf-col">
    <small>برچسب</small>
    <input type="text" name="spec_label_fa[]" list="psf-labels" dir="rtl" placeholder="پردازنده" value="{{ $lf }}">
    <input type="text" name="spec_label_en[]" dir="ltr" placeholder="Processor" value="{{ $le }}">
    <input type="text" name="spec_label_tr[]" dir="ltr" placeholder="İşlemci" value="{{ $lt }}">
  </div>
  <div class="psf-col">
    <small>مقدار</small>
    <input type="text" name="spec_val_fa[]" dir="rtl" placeholder="تا ۲× Intel Xeon" value="{{ $vf }}">
    <input type="text" name="spec_val_en[]" dir="ltr" placeholder="Up to 2× Intel Xeon" value="{{ $ve }}">
    <input type="text" name="spec_val_tr[]" dir="ltr" placeholder="2× Intel Xeon'a kadar" value="{{ $vt }}">
  </div>
  <button type="button" class="psf-spec-del" title="حذفِ ردیف" aria-label="حذفِ ردیف">×</button>
</div>
