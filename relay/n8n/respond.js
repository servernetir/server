const d = $('Evaluate Response').first().json;

/*
| 🔴 دلیلِ شکست باید برگردد.
|
| نسخهٔ قبلی فقط `{status:'failed'}` می‌داد. `N8nRelaySender` آن را درست شکست
| می‌شمرد، ولی در `/system/sms-status` می‌نوشت «بی‌دلیل» — یعنی برای فهمیدنِ
| «چرا پیامک نرفت؟» باید تاریخچهٔ اجرای n8n باز می‌شد. دقیقاً همان نامرئی‌بودنی
| که در این پروژه گران تمام شده.
|
| ⚠️ فقط پیامِ خطای آی‌پی‌پنل، بریده‌شده — هیچ رازی برنمی‌گردد. این پاسخ به
|    سرورِ ما می‌رود و آن‌جا در کشِ `sms:last_error` می‌نشیند که از یک روتِ
|    **عمومی** خوانده می‌شود.
*/
const r = d.ippanel ?? {};
const why = r?.error?.message ?? r?.meta?.message ?? r?.message ?? null;

return [{ json: {
  status: d.delivered ? 'sent' : 'failed',
  template: d.template,
  request_id: d.request_id,
  // در موفقیت هم برمی‌گردد: با این شناسه می‌شود در پنلِ اپراتور ثابت کرد
  // پیامک واقعاً صادر شده — برای شکایتِ «نیامد» لازم است.
  ...(d.outbox_id ? { outbox_id: d.outbox_id } : {}),
  ...(d.delivered ? {} : { reason: String(why ?? 'ippanel_rejected').slice(0, 200) }),
} }];
