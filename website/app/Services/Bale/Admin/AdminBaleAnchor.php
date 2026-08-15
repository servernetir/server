<?php

namespace App\Services\Bale\Admin;

use App\Models\Ticket;

/**
 * «کدام تیکت؟» — بی‌آنکه کارفرما شماره‌ای تایپ کند.
 *
 * ═══ چرا ریپلای، هستهٔ ارگونومیِ این قابلیت است ═══
 *
 * هر اعلانِ تیکت که همین حالا به بله می‌رود، آدرسِ `/admin/tickets/{id}` را
 * در متنش دارد (`Account\TicketController` آن را به `Notifier::fire` می‌دهد و
 * `AdminNotifier::event` در انتهای پیام چاپش می‌کند). پس اگر کارفرما روی همان
 * اعلان **ریپلای** بزند، تیکت از متنِ نقل‌شده پیدا می‌شود:
 *
 *   • هیچ شناسه‌ای تایپ نمی‌شود
 *   • هیچ حالتی («الان روی کدام تیکتیم») ذخیره نمی‌شود — یعنی هیچ‌چیز کهنه
 *     نمی‌شود و دو دستگاه هم‌زمان مشکلی نمی‌سازند
 *   • روی اعلان‌هایی که **پیش از** ساختِ این قابلیت فرستاده شده‌اند هم کار
 *     می‌کند، چون لینک از قبل آن‌جا بوده
 *
 * ⚠️ ولی مسیرِ تایپی (`/r <شماره> …`) هم همیشه هست: اگر بله روزی
 * `reply_to_message` را در آپدیت نگذارد، قابلیت **کم‌می‌شود**، نمی‌شکند.
 */
class AdminBaleAnchor
{
    /** آدرسِ تیکت در متنِ اعلان — همان چیزی که `AdminNotifier` چاپ می‌کند */
    private const URL_RE = '#/admin/tickets/(\d+)#';

    /** قالبِ شماره‌ای که مشتری نقل می‌کند */
    private const NUM_RE = '#TK-\d{6}-\d{4}#i';

    /** تیکتِ نقل‌شده در پیامی که به آن ریپلای شده */
    public function ticketFrom(array $message): ?Ticket
    {
        $quoted = $message['reply_to_message'] ?? null;

        if (! is_array($quoted)) {
            return null;
        }

        $text = (string) ($quoted['text'] ?? $quoted['caption'] ?? '');

        if ($text === '') {
            return null;
        }

        if (preg_match(self::URL_RE, $text, $m) === 1) {
            return $this->find((int) $m[1]);
        }

        $folded = $this->asciiDigits($text);

        if (preg_match(self::NUM_RE, $folded, $m) === 1) {
            return $this->byNumber(mb_strtoupper($m[0]));
        }

        return null;
    }

    /**
     * تیکت از روی چیزی که آدم تایپ کرده: `TK-260815-0007` یا `#42` یا `42`.
     *
     * 🔴 **تاشدنِ رقمِ فارسی اجباری است.** شماره‌ای که کارفرما کپی می‌کند از
     * پنل یا از اعلان می‌آید و آن‌جا از `fa_num()` رد شده — یعنی «۰۰۰۷» است نه
     * «0007». بی‌این تبدیل، طبیعی‌ترین کارِ ممکن (کپی‌کردنِ شماره) هرگز جواب
     * نمی‌دهد.
     *
     * ⚠️ و عمداً از `BaleWebhookController::normalize()` استفاده نمی‌شود: آن
     * `preg_replace('/[^0-9]/')` می‌زند که رقمِ فارسی را **حذف** می‌کند، نه
     * تبدیل — یعنی «TK-۲۶۰۸۱۵-۰۰۰۷» به «TK--» تبدیل می‌شد.
     */
    public function resolve(string $ref): ?Ticket
    {
        $ref = trim($this->asciiDigits($ref));
        $ref = ltrim($ref, '#');
        $ref = trim($ref);

        if ($ref === '') {
            return null;
        }

        if (preg_match(self::URL_RE, $ref, $m) === 1) {
            return $this->find((int) $m[1]);
        }

        $upper = mb_strtoupper($ref);

        if (($t = $this->byNumber($upper)) !== null) {
            return $t;
        }

        return ctype_digit($ref) ? $this->find((int) $ref) : null;
    }

    /** رقمِ فارسی (U+06F0…) و عربی (U+0660…) → رقمِ لاتین */
    public function asciiDigits(string $s): string
    {
        return strtr($s, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    private function byNumber(string $number): ?Ticket
    {
        try {
            return Ticket::where('number', $number)->first();
        } catch (\Throwable) {
            return null;
        }
    }

    private function find(int $id): ?Ticket
    {
        try {
            return Ticket::find($id);
        } catch (\Throwable) {
            return null;
        }
    }
}
