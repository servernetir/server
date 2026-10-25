@props(['name', 'value' => null, 'required' => false, 'min' => null, 'max' => null])
@php
  $raw=is_string($value)?$value:'';
  try { $iso=\App\Support\FinanceDates::iso($raw,$name)??''; } catch (\Illuminate\Validation\ValidationException) { $iso=''; }
  $label=\App\Support\FinanceDates::display($raw);
  $jalali=$iso?\App\Support\Jalali::format(...\App\Support\Jalali::fromGregorian(...array_map('intval',explode('-',$iso)))):'';
@endphp
<input type="text" name="{{ $name }}" value="{{ $label }}" data-jdate data-iso="{{ $iso }}" data-label="{{ $label }}" data-jalali="{{ $jalali }}"
  placeholder="۱۴۰۵/۰۸/۰۳" dir="ltr" autocomplete="off" maxlength="10"
  @if($required) required @endif @if($min) data-min="{{ $min }}" @endif @if($max) data-max="{{ $max }}" @endif
  {{ $attributes->class(['ad-input']) }}>
