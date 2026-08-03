@extends('emails.layout')

@section('title', $subject)
@section('preview', \Illuminate\Support\Str::limit(strip_tags($html), 90))

@section('content')
  {{-- HTML از ویرایشگرِ پنل می‌آید و پیش از ذخیره با HtmlSanitizer::clean پاک
       شده است (NotificationTemplateController::update). این‌جا دوباره فرار
       نمی‌دهیم وگرنه مدیر تگ‌های خودش را به‌صورتِ متن می‌بیند. --}}
  {!! $html !!}
@endsection
