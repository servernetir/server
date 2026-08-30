<?php

namespace App\Services\Provisioning;

use App\Models\Customer;
use App\Models\Server;
use App\Models\Service;

/**
 * تطبیقِ حساب‌های واقعیِ یک سرورِ WHM با سرویس‌های ثبت‌شده در سامانه.
 *
 * خواهرِ `CloudInventory` برای هاستِ اشتراکی، با همان سه دسته و **همان قاعدهٔ
 * ایمنی**: اگر پرسیدن از سرور شکست بخورد، هیچ سرویسی «شبح» شمرده نمی‌شود.
 *
 * چرا این اولین قدمِ «افزودنِ مشتریانِ قدیمی» است و نه خودِ واردکردن: پیش از
 * اینکه به یک واردکنندهٔ خودکار اعتماد کنیم، باید **ببینیم** چه چیزی روی سرور
 * هست و با چه چیزی می‌خورد. این گزارش هیچ چیزی نمی‌نویسد، پس بی‌خطر است و
 * همان‌جایی است که تصمیمِ قیمت و دوره — که WHM اصلاً نمی‌داندشان — گرفته می‌شود.
 */
class WhmInventory
{
    /**
     * @return array{
     *   ok: bool,
     *   message: string,
     *   orphans: array<int,array>,
     *   ghosts: array<int,array>,
     *   matched: array<int,array>
     * }
     */
    public function reconcile(Server $server): array
    {
        $empty = ['orphans' => [], 'ghosts' => [], 'matched' => []];

        if ($server->type !== 'whm') {
            return ['ok' => false, 'message' => 'این سرور از نوعِ WHM نیست.'] + $empty;
        }

        $res = (new WhmClient($server))->call('listaccts');

        if (! ($res['ok'] ?? false)) {
            // 🔴 خطا ≠ «هیچ حسابی نیست». اگر این را قاطی کنیم، توکنِ منقضی
            // گزارشی می‌سازد که می‌گوید همهٔ سایت‌های مشتریان ناپدید شده‌اند.
            return ['ok' => false, 'message' => (string) ($res['reason'] ?? 'پاسخی نیامد')] + $empty;
        }

        $accts = (array) ($res['data']['acct'] ?? ($res['raw']['data']['acct'] ?? []));

        // 🔴 فقط `server_id` کافی **نیست**. `server_id` نال‌پذیر است و فرمِ
        // ثبتِ دستیِ سرویس در پنلِ مدیریت آن را خالی می‌پذیرد — یعنی دقیقاً
        // همان حسابِ قدیمیِ دست‌نویسی که این ابزار هدفش است. با فیلترِ
        // server_id، آن سرویسِ **زنده و فاکتورشده** «یتیم» گزارش می‌شد، با
        // مشتریِ درست کنارش — قانع‌کننده‌ترین شکلِ ممکنِ «امن است، واردش کن».
        // واردکردنش یک سرویسِ تکراری روی همان نام‌کاربری می‌ساخت، و وقتی آن
        // تکراری پرداخت نمی‌شد، چرخهٔ بدهی `suspendacct` می‌زد و سایتِ
        // **مشتریِ پرداخت‌کننده** را می‌خواباند.
        $services = Service::query()
            ->where(fn ($q) => $q->where('server_id', $server->id)
                ->orWhereNull('server_id'))
            ->whereNotNull('username')
            ->where('username', '!=', '')
            ->with('customer:id,code,email')
            ->get(['id', 'customer_id', 'server_id', 'name', 'username', 'domain', 'status', 'plan']);

        $byUser = $services->keyBy(fn ($s) => strtolower((string) $s->username));

        $orphans = [];
        $matched = [];
        $seen = [];

        foreach ($accts as $a) {
            if (! is_array($a)) {
                continue;
            }

            $user = strtolower((string) ($a['user'] ?? ''));

            if ($user === '') {
                continue;
            }

            $seen[$user] = true;
            $svc = $byUser[$user] ?? null;

            $email = strtolower(trim((string) ($a['email'] ?? '')));

            $row = [
                'user'      => $user,
                'domain'    => (string) ($a['domain'] ?? ''),
                'email'     => $email,
                'plan'      => (string) ($a['plan'] ?? ''),
                'suspended' => (int) ($a['suspended'] ?? 0) === 1,
                'started'   => $a['startdate'] ?? null,
            ];

            if ($svc !== null) {
                $matched[] = $row + [
                    'service_id'    => $svc->id,
                    'service_name'  => $svc->name,
                    'customer_code' => $svc->customer?->code,
                    // اختلافِ تعلیق یعنی پنلِ ما و سرور دو چیزِ متفاوت می‌گویند
                    'status_drift'  => $row['suspended'] !== ($svc->status === 'suspended'),
                    'our_status'    => $svc->status,
                ];

                continue;
            }

            // حسابی که هیچ سرویسی ندارد — نامزدِ واردکردن.
            // مشتریِ متناظر را با ایمیل پیدا می‌کنیم؛ ایمیلِ خالی یا زبالهٔ WHM
            // یعنی **نمی‌شود** وارد کرد: `customers.email` یکتا و ناتهی است و
            // ساختنِ آدرسِ ساختگی، فضای نامِ ورود را برای همیشه آلوده می‌کند.
            $usable = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;

            $orphans[] = $row + [
                'customer_id'   => $usable ? Customer::where('email', $email)->value('id') : null,
                'email_usable'  => $usable,
            ];
        }

        // ⚠️ «شبح» فقط برای سرویسی معنا دارد که واقعاً به **همین** سرور
        // بسته شده. سرویسِ بی‌سرور را نمی‌شود «حسابش روی این سرور نیست»
        // نامید — هیچ‌وقت هم نبوده. و سرویسِ بی‌نام‌کاربری از قبل فیلتر شده:
        // سفارشِ پرداخت‌نشده و سفارشِ در صفِ تحویل هر دو نام‌کاربری ندارند و
        // همه‌شان یک‌جا شبح گزارش می‌شدند.
        $ghosts = $services
            ->filter(fn ($s) => (int) $s->server_id === (int) $server->id
                && ! isset($seen[strtolower((string) $s->username)])
                && ! in_array($s->status, Service::DEAD_STATUSES, true))
            ->map(fn ($s) => [
                'service_id'    => $s->id,
                'service_name'  => $s->name,
                'user'          => (string) $s->username,
                'domain'        => (string) $s->domain,
                'our_status'    => $s->status,
                'customer_code' => $s->customer?->code,
            ])->values()->all();

        return [
            'ok'      => true,
            'message' => '',
            'orphans' => $orphans,
            'ghosts'  => $ghosts,
            'matched' => $matched,
        ];
    }
}
