<?php

/*
|--------------------------------------------------------------------------
| دروازهٔ AI — ثابت‌های مهندسی (M5)
|--------------------------------------------------------------------------
|
| ⚠️ این‌جا **سیاستِ پولی نیست**. حاشیهٔ سود (`ai_margin_pct`) و سربارِ ارز
| (`ai_providers.fx_fee_bp`) عددهایی‌اند که مالک تایپ می‌کند و در دیتابیس
| می‌نشینند؛ هیچ‌کدام پیش‌فرضِ کدی ندارند، چون پیش‌فرضِ حدسی یعنی فروش با
| حاشیه‌ای که کسی تأییدش نکرده. آنچه این‌جاست فقط پارامترهای ایمنیِ فنی است —
| و همه طوری انتخاب شده‌اند که اشتباه‌شان فقط **گران‌تر** بفروشد، نه ارزان‌تر.
|
| مرجع: docs/ai-gateway/m5-spec.md §2.8
*/

return [
    // سقفِ خروجی وقتی مدل سقفِ خودش را ندارد و مشتری max_tokens نفرستاده
    'default_max_output' => 4096,
    // حاشیهٔ امنِ رزرو روی برآوردِ بدترین حالت (۱۰٪)
    'hold_buffer_bp' => 1000,
    'min_balance_aware_output' => 256,

    'http_connect_s' => 10,
    'http_timeout_base_s' => 30,
    'http_timeout_per_20_out' => 1,
    'http_timeout_min_s' => 60,
    'http_timeout_max_s' => 240,
    'stream_idle_s' => 60,
    'stream_wall_s' => 600,

    /*
    | نرخِ ارز (D2). نرخِ اسکرپ‌شدهٔ کهنه‌تر از `fx_stale_after_h` سربارِ
    | `fx_stale_buffer_bp` می‌گیرد؛ کهنه‌تر از `fx_max_age_h` اصلاً قابلِ
    | فروش نیست. افتِ نرخ هر ۲۴ ساعت حداکثر `fx_max_daily_drop_bp`.
    */
    'fx_stale_after_h' => 6,
    'fx_max_age_h' => 24,
    'fx_stale_buffer_bp' => 200,
    'fx_max_daily_drop_bp' => 300,

    'rpm_per_key' => 60,
    'inflight_per_customer' => 4,
    'inflight_global' => 8,
    'auth_fail_per_ip_min' => 30,
    'replay_ttl_h' => 24,
    'decide_by_grace_s' => 300,
    'unknown_recovery_window_min' => 15,
    'unknown_recovery_days' => 7,
    'unknown_recovery_max_attempts' => 3,
    'hold_backstop_h' => 24,
];
