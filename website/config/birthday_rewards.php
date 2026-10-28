<?php

return [
    // Preparation only. Enabling prices and approving an SMS pattern are separate decisions.
    'enabled' => false,
    'sms_enabled' => false,
    'pattern_approved' => false,
    'pattern_reference' => null,
    'timezone' => 'Asia/Tehran',
    'valid_hours' => 24,
    'batch_size' => 100,
    'sms_from_hour' => 9,
    'sms_until_hour' => 21,
    'message' => "🎂 {{name}} عزیز، تولدت مبارک!\nامروز روز توئه؛ هدیه‌اش با ما 🎁\nیه سورپرایز مخصوص تولدت توی پنلت گذاشتیم؛ قیمت‌هایی که فقط ۲۴ ساعت برای تو فعالن!\nبیا هدیه‌ات رو ببین 💙\nhttps://console.servernet.cloud",
];
