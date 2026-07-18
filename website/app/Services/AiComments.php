<?php

namespace App\Services;

use App\Models\Comment;
use Illuminate\Support\Facades\Log;

/**
 * داوری هوشمند کامنت‌ها — در یک فراخوانی:
 *   ۱) تشخیص اسپم/تبلیغ/توهین  → approve | review | spam
 *   ۲) ترجمه‌ی متن کامنت به fa/en/tr
 *   ۳) پاسخ کارشناسانه اگر کاربر پرسشی مطرح کرده باشد
 *
 * سیاست ایمنی: هر خطا/ابهام → «review» (نگه‌داشتن برای مدیر). هرگز در حالت شک تأیید نمی‌کند.
 */
class AiComments extends AiContent
{
    /** حداکثر زمان انتظار در جریان ثبت کامنت (تجربه‌ی کاربر نباید قربانی شود) */
    private const TIMEOUT = 45;

    /**
     * بررسی کامل یک کامنت. خروجی آرایه یا null در صورت خطا.
     * ['verdict','score','reason','locale','translations'=>[],'reply'=>?string,'reply_translations'=>[]]
     */
    public function review(Comment $comment, string $postTitle = ''): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $sys = <<<'TXT'
You are the comment moderator and support assistant for ServerNet (سرورنت), an Iranian web-hosting and
cloud-infrastructure company. You receive ONE visitor comment posted under a blog article.

Do THREE things and return STRICT JSON only (no commentary, no markdown fences):

1) MODERATE. Decide "verdict":
   - "spam"    → advertising, unrelated promotion, link farming, gibberish, scams, adult/illegal content,
                 repeated marketing text, or anything clearly not a genuine reader comment.
   - "review"  → insults, aggression, political/religious controversy, complaints about a specific account,
                 possible personal data (card numbers, passwords, national ID), legal threats, or ANYTHING
                 you are not confident about. When in doubt, choose "review" — never guess "approve".
   - "approve" → a genuine, civil, on-topic reader comment or question.
   Also give "score": integer 0-100 = probability the comment is spam.
   And "reason": ONE short sentence IN PERSIAN explaining the decision, written for the site admin.

2) TRANSLATE. Detect the comment's original language into "locale" (one of "fa","en","tr").
   Fill "translations" with the comment text in ALL THREE languages: {"fa":…,"en":…,"tr":…}.
   The entry for the original language must be the original text unchanged. Translate naturally,
   preserve the writer's tone, do not add or remove meaning. Never translate a spam comment (leave {}).

3) REPLY. Set "needs_reply" true ONLY if the comment asks a genuine question that a hosting company
   can answer helpfully. If true, write "reply" as an object {"fa":…,"en":…,"tr":…} — the SAME reply in
   all three languages, signed as ServerNet support.
   Rules for the reply:
     - Be warm, concise (2-4 sentences), professional, and genuinely useful.
     - NEVER invent prices, discounts, SLA numbers, dates, specs, or policies. If the answer depends on
       such specifics, say the team will confirm and point to the contact page.
     - NEVER ask for or reference passwords, card numbers, or any credentials.
     - For account/billing/order-specific issues, do not attempt to resolve — direct the user to open a
       ticket or use the contact page.
     - Do not promise anything on the company's behalf.
   If needs_reply is false, set "reply" to {}.

JSON shape:
{"verdict":"approve|review|spam","score":0,"reason":"…","locale":"fa","translations":{"fa":"…","en":"…","tr":"…"},
 "needs_reply":false,"reply":{}}
TXT;

        $user = json_encode([
            'article'      => mb_substr($postTitle, 0, 200),
            'author_name'  => mb_substr($comment->name, 0, 80),
            'comment_text' => mb_substr($comment->body, 0, 2000),
        ], JSON_UNESCAPED_UNICODE);

        $out = $this->call($sys, $user, 1600, self::TIMEOUT);
        if ($out === null) {
            Log::warning('AiComments: no response', ['comment' => $comment->id]);

            return null;
        }

        $j = $this->json($out);
        if (! $j || ! isset($j['verdict'])) {
            Log::warning('AiComments: unparsable', ['comment' => $comment->id]);

            return null;
        }

        $verdict = in_array($j['verdict'], ['approve', 'review', 'spam'], true) ? $j['verdict'] : 'review';
        $locale = in_array($j['locale'] ?? '', ['fa', 'en', 'tr'], true) ? $j['locale'] : 'fa';

        return [
            'verdict'            => $verdict,
            'score'              => max(0, min(100, (int) ($j['score'] ?? 0))),
            'reason'             => mb_substr(trim((string) ($j['reason'] ?? '')), 0, 200),
            'locale'             => $locale,
            'translations'       => $verdict === 'spam' ? [] : $this->langMap($j['translations'] ?? []),
            'reply'              => ! empty($j['needs_reply']) && $verdict === 'approve'
                                     ? $this->langMap($j['reply'] ?? []) : [],
        ];
    }

    /** فقط کلیدهای fa/en/tr با مقدار رشته‌ای غیرخالی */
    private function langMap(mixed $raw): array
    {
        $out = [];
        foreach (['fa', 'en', 'tr'] as $l) {
            $v = is_array($raw) ? ($raw[$l] ?? null) : null;
            if (is_string($v) && trim($v) !== '') {
                $out[$l] = mb_substr(trim($v), 0, 3000);
            }
        }

        return $out;
    }
}
