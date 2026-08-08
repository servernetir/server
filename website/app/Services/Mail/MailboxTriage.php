<?php

namespace App\Services\Mail;

use App\Models\MailboxMessage;
use App\Services\AiContent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * عاملِ دسته‌بندی — روی `AiContent` سوار می‌شود، نه کنارش.
 *
 * مسیریابیِ ارائه‌دهنده، مدیریتِ کلید، تایم‌اوت و پارسِ JSON از قبل آن‌جا حل
 * شده. فقط `purpose` عوض می‌شود تا اگر خواستی برای این کار مدلِ ارزان‌تری
 * بگذاری، یک متغیرِ محیطی کافی باشد:
 *
 *     AI_PROVIDER_TRIAGE=deepseek
 *
 * 🔴 اگر مدل جواب ندهد، نامه‌ها **دست‌نخورده** می‌مانند: نه دسته می‌خورند نه
 * `reported_at`. یعنی اجرای بعدی دوباره سراغشان می‌آید. حالتِ خرابِ درست این
 * است که گزارش دیر برسد، نه اینکه نامه‌ها بی‌صدا «گزارش‌شده» علامت بخورند و
 * برای همیشه گم شوند.
 */
class MailboxTriage extends AiContent
{
    public function __construct()
    {
        $this->purpose = 'triage';
    }

    /**
     * دسته‌بندیِ یک دسته نامه. خروجی: تعدادِ نامه‌هایی که دسته خوردند.
     *
     * @param  Collection<int, MailboxMessage>  $messages
     */
    public function classify(Collection $messages): int
    {
        if ($messages->isEmpty()) {
            return 0;
        }

        $allowed = array_keys((array) config('mailboxes.categories', []));

        $sys = <<<TXT
You triage the inbox of a small Iranian hosting and web-development company
(ServerNet). For each email you are given, decide three things.

Return JSON only, in this exact shape:
{"items":[{"i":<the given index>,"category":"...","needs_reply":true|false,"importance":1-5,"summary":"..."}]}

category — exactly one of: %s
needs_reply — true ONLY if a human at ServerNet must write back. Newsletters,
  receipts, automated notifications and marketing all get false.
importance — 1 trivial, 3 normal, 5 a paying customer or real money is waiting.
summary — ONE short sentence in PERSIAN saying what the sender actually wants.
  No greeting, no restating the subject line, no advice. If it is obviously
  bulk or spam, write "تبلیغات" and nothing more.

Rules:
- Judge only from what you are given. Never invent an order number, an amount,
  a deadline or a name that is not in the text.
- If you cannot tell, use category "other", importance 2, needs_reply false.
- Return one item per input index. Never merge, never skip.
TXT;

        $sys = sprintf($sys, implode(', ', $allowed));

        $payload = $messages->values()->map(fn (MailboxMessage $m, int $i) => [
            'i'       => $i,
            'box'     => $m->account,
            'from'    => $m->from_name ? $m->from_name.' <'.$m->from_email.'>' : $m->from_email,
            'subject' => $m->subject,
            'text'    => mb_substr((string) $m->snippet, 0, 500),
        ])->all();

        $raw = $this->call($sys, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 2000, 150);

        if (! $raw) {
            Log::warning('mailbox.triage.no_response', ['count' => $messages->count()]);

            return 0;
        }

        $j = $this->json($raw);

        if (! is_array($j['items'] ?? null)) {
            Log::warning('mailbox.triage.unparsable', ['preview' => mb_substr($raw, 0, 300)]);

            return 0;
        }

        $done = 0;

        foreach ($j['items'] as $item) {
            $message = $messages->values()->get((int) ($item['i'] ?? -1));

            if (! $message) {
                continue;
            }

            $category = (string) ($item['category'] ?? 'other');

            $message->update([
                'category'    => in_array($category, $allowed, true) ? $category : 'other',
                'needs_reply' => (bool) ($item['needs_reply'] ?? false),
                'importance'  => max(1, min(5, (int) ($item['importance'] ?? 2))),
                'summary'     => mb_substr(trim((string) ($item['summary'] ?? '')), 0, 300) ?: null,
            ]);

            $done++;
        }

        Log::info('mailbox.triage.done', ['given' => $messages->count(), 'classified' => $done]);

        return $done;
    }
}
