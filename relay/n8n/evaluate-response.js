const prev = $('Verify & Map Template').first().json;
const res = $json ?? {};

/*
| 🔴 شکلِ **واقعی**ِ پاسخِ موفقِ آی‌پی‌پنل — از یک اجرای واقعی برداشته شده:
|
|   { "data": { "message_outbox_ids": [1462501007] },
|     "meta": { "status": true, "message": "انجام شد", "message_code": "200-1" } }
|
| نسخهٔ قبلی دنبالِ `res.code` و `res.status` و `res.data.message_id` می‌گشت —
| **هیچ‌کدام وجود ندارند**. پس پیامکی که واقعاً رفته بود «شکست» گزارش می‌شد.
|
| این آینهٔ دقیقِ دامِ «۲۰۰ ولی نرفت» است و به‌همان اندازه گران: مشتری پیامک را
| می‌گیرد، سایت می‌گوید نرفت، او دوباره می‌زند — دو بار هزینه، و یک هشدارِ
| دروغین در پایش که بعد از چند بار نادیده گرفته می‌شود.
|
| ⚠️ به کدِ HTTP تکیه نمی‌کنیم: این گره `onError: continueRegularOutput` دارد،
|    پس شیءِ خطا هم از همین‌جا رد می‌شود. همیشه بدنه را می‌خوانیم.
|
| ⚠️ فایل‌کلوز: هر پاسخِ ناشناخته = شکست. ادعای دروغینِ موفقیت بدتر از هشدارِ
|    اضافی است، چون کسی دنبالِ پیامکی که «رفته» نمی‌گردد.
*/
const outbox = res?.data?.message_outbox_ids;

const ok = res?.meta?.status === true
  || (Array.isArray(outbox) && outbox.length > 0)
  || String(res?.meta?.message_code ?? '').startsWith('200');

return [{ json: {
  ...prev,
  delivered: ok,
  // شناسهٔ صندوقِ خروجی — برای ردیابیِ یک پیامک در پنلِ اپراتور
  outbox_id: Array.isArray(outbox) ? (outbox[0] ?? null) : null,
  ippanel: res,
} }];
