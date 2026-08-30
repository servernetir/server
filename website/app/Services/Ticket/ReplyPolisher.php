<?php

namespace App\Services\Ticket;

use App\Services\AiContent;

/**
 * تصحیحِ نگارشِ پاسخی که **خودِ کارفرما** نوشته.
 *
 * ═══ چرا جدا از `TicketDraftWriter` ═══
 *
 * آن یکی از صفر می‌نویسد و اجازه دارد جمله بسازد. این یکی متنِ موجود را
 * صیقل می‌دهد و **حق ندارد چیزی به آن اضافه کند**. دو کارِ متفاوت با دو
 * قاعدهٔ متفاوت؛ یکی‌کردنشان یعنی روزی «صیقل» یک تعهدِ تازه اختراع کند.
 *
 * 🔴 خطرِ واقعیِ این ویژگی «بد نوشتن» نیست، **اضافه‌کردن** است.
 *
 * کارفرما می‌نویسد «تا فردا درست میشه». مدلِ رهاشده می‌تواند بنویسد «تا
 * ۲۴ ساعت آینده طبق SLA رفع خواهد شد» — و ناگهان شرکت به چیزی متعهد شده که
 * کسی نگفته بود. متنِ رسمی‌ترِ غلط از متنِ شکسته‌بستهٔ درست بدتر است، چون
 * معتبر به‌نظر می‌رسد.
 *
 * ⚠️ خروجی **جایگزین نمی‌شود**؛ به کارفرما نشان داده می‌شود و او تصمیم
 * می‌گیرد. هیچ مسیری در این کلاس چیزی را خودکار نمی‌فرستد.
 */
class ReplyPolisher extends AiContent
{
    /** بلندتر از این، درخواستِ صیقل نیست — نوشتنِ مقاله است. */
    public const MAX_CHARS = 4000;

    public function __construct()
    {
        // ⚠️ همان مسیرِ `support`: اگر کارفرما ارائه‌دهندهٔ پشتیبانی را عوض کند،
        //    این هم با آن می‌رود. مسیرِ جدا یعنی روزی یکی‌شان کهنه بماند.
        $this->purpose = 'support';
    }

    /**
     * متنِ صیقل‌خورده، یا `null` اگر مدل جواب نداد.
     *
     * ⚠️ `null` عمداً از رشتهٔ خالی جدا است: فراخوان باید بتواند بگوید
     * «تصحیح انجام نشد» و متنِ اصلیِ کارفرما را دست‌نخورده نگه دارد. اگر
     * خالی برگردانیم، رابط ممکن است همان خالی را جایگزینِ نوشتهٔ او کند.
     */
    public function polish(string $draft, string $locale = 'fa'): ?string
    {
        $draft = trim($draft);

        // متنِ خیلی کوتاه چیزی برای صیقل ندارد و فقط هزینهٔ تماس است
        if (mb_strlen($draft) < 12) {
            return null;
        }

        if (mb_strlen($draft) > self::MAX_CHARS) {
            $draft = mb_substr($draft, 0, self::MAX_CHARS);
        }

        $lang = match ($locale) {
            'en' => 'ENGLISH',
            'tr' => 'TURKISH',
            default => 'PERSIAN',
        };

        $sys = <<<TXT
You are an editor for ServerNet, an Iranian hosting and datacentre company.
The support agent has ALREADY written a reply. Your only job is to rewrite it
so it reads as professional, calm, corporate datacentre correspondence.

Write in {$lang}. Return ONLY the rewritten text — no preamble, no quotes, no
markdown, no explanation of what you changed.

Hard rules — breaking any of these costs the company real money:
- NEVER add a fact that is not already in the text: no price, no deadline, no
  SLA figure, no refund, no discount, no compensation, no ticket/invoice/IP
  number, no date, no plan name.
- NEVER change a number, a name, or a technical term that is already there.
- NEVER turn a vague statement into a specific promise. "soon" stays vague.
- If the original says the problem is the customer's fault, keep that meaning —
  do not soften it into an apology from us.
- Keep the same language the agent used.
- Keep it roughly the same length. This is an edit, not an expansion.
- No exclamation marks. No more than one apology. No corporate filler.

If the text is already clean, return it unchanged.
TXT;

        $out = $this->call($sys, $draft, 1200, 90);

        if ($out === null) {
            return null;
        }

        $out = trim($out);

        /*
        | ⚠️ مدل گاهی خروجی را در گیومه می‌پیچد یا با «متن اصلاح‌شده:» شروع
        |    می‌کند، با اینکه صریح منع شده. پاک‌سازی این‌جا انجام می‌شود نه در
        |    رابط — وگرنه هر مصرف‌کننده باید خودش تکرارش کند و یکی‌شان یادش
        |    می‌رود.
        */
        $out = preg_replace('~^(متن(ِ| )?(اصلاح|ویرایش)شده\s*:|Rewritten\s*:|Edited\s*:)\s*~iu', '', $out);
        $out = trim($out, " \t\n\r\0\x0B\"«»");

        return $out === '' ? null : $out;
    }
}
