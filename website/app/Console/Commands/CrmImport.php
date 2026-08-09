<?php

namespace App\Console\Commands;

use App\Models\CrmLead;
use App\Models\CrmSuppression;
use Illuminate\Console\Command;

/**
 * واردکردنِ دسته‌ایِ سرنخ از CSV.
 *
 * مسیرِ بی‌کلید: تا وقتی `GOOGLE_PLACES_KEY` نگذاشته‌ای، قیف از این‌جا یا از
 * فرمِ پنل پر می‌شود. ستون‌ها با نامشان خوانده می‌شوند نه با ترتیبشان، پس
 * فایلی که از گوگل‌شیت یا اکسل بیرون می‌آید معمولاً همان‌طور کار می‌کند.
 *
 *     company,website,email,city,country,vertical
 *
 * فقط `company` و `website` اجباری‌اند؛ نشانیِ ایمیل را خودِ `crm:enrich` از
 * سایتشان برمی‌دارد.
 *
 * 🔴 هر ردیف از سه سد رد می‌شود: نشانیِ نامعتبر، تکراری، و فهرستِ سیاه. هیچ‌کدام
 * بی‌صدا نیستند — در پایان دقیقاً می‌گوید چند تا و چرا رد شدند. واردکردنِ
 * خاموشِ ۵۰ ردیف که ۴۰تایش رد شده، بدترین حالتِ ممکن است.
 */
class CrmImport extends Command
{
    protected $signature = 'crm:import
        {file : مسیرِ فایل CSV}
        {--dry : فقط گزارش بده، چیزی ثبت نکن}';

    protected $description = 'واردکردن دسته‌ای سرنخ از فایل CSV';

    public function handle(): int
    {
        $path = (string) $this->argument('file');

        if (! is_readable($path)) {
            $this->error("فایل خوانده نشد: {$path}");

            return self::FAILURE;
        }

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);

        if (! $header) {
            $this->error('فایل خالی است.');
            fclose($handle);

            return self::FAILURE;
        }

        // BOMِ اکسل: بدونِ حذفش، ستونِ اول هیچ‌وقت «company» شناخته نمی‌شود
        $header[0] = preg_replace('~^\xEF\xBB\xBF~', '', (string) $header[0]);
        $cols = array_map(fn ($h) => mb_strtolower(trim((string) $h)), $header);

        foreach (['company', 'website'] as $need) {
            if (! in_array($need, $cols, true)) {
                $this->error("ستونِ «{$need}» در فایل نیست. سرستون‌ها: ".implode(', ', $cols));
                fclose($handle);

                return self::FAILURE;
            }
        }

        $dry = (bool) $this->option('dry');
        $added = 0;
        $skipped = [];
        $row = 1;

        while (($data = fgetcsv($handle)) !== false) {
            $row++;

            if ($data === [null] || $data === []) {
                continue;
            }

            $r = [];

            foreach ($cols as $i => $name) {
                $r[$name] = isset($data[$i]) ? trim((string) $data[$i]) : '';
            }

            // نرمال‌سازی **قبل از** بررسی — وگرنه ردیفی که کاربر بدونِ
            // https:// نوشته، هم اعتبارسنجی‌اش می‌لنگد هم بدونِ پروتکل ذخیره
            // می‌شود و بعداً SiteAudit نمی‌تواند بازش کند.
            if (filled($r['website']) && ! preg_match('~^https?://~i', $r['website'])) {
                $r['website'] = 'https://'.$r['website'];
            }

            $reason = $this->reject($r);

            if ($reason !== null) {
                $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;
                $this->line("· ردیف {$row} رد شد ({$reason}): ".($r['company'] ?: '—'));

                continue;
            }

            if (! $dry) {
                CrmLead::create([
                    'domain_hash' => CrmLead::hashFor($r['website']),
                    'company'     => mb_substr($r['company'], 0, 160),
                    'website'     => mb_substr($r['website'], 0, 190),
                    'email'       => filled($r['email'] ?? null) ? mb_substr($r['email'], 0, 190) : null,
                    'city'        => mb_substr($r['city'] ?? '', 0, 80) ?: null,
                    'country'     => mb_substr($r['country'] ?? '', 0, 2) ?: null,
                    'vertical'    => mb_substr($r['vertical'] ?? '', 0, 40) ?: null,
                    'source'      => 'import',
                    'stage'       => 'new',
                ]);
            }

            $added++;
            $this->line('✓ '.$r['company']);
        }

        fclose($handle);

        $this->newLine();
        $this->info(($dry ? '[آزمایشی] ' : '')."افزوده‌شده: {$added}");

        foreach ($skipped as $reason => $count) {
            $this->line("  رد شد ({$reason}): {$count}");
        }

        if ($added > 0 && ! $dry) {
            $this->line('حالا: php artisan crm:enrich --limit='.min(10, $added));
        }

        return self::SUCCESS;
    }

    /**
     * ⚠️ اینجا عمداً `SafeUrl::allowed()` صدا زده **نمی‌شود**.
     *
     * آن تابع DNS را واقعاً می‌پرسد، و نگهبانِ درستی است — ولی برای لحظه‌ای که
     * قرار است رشته‌ای وارد شود، نه. یک قطعیِ لحظه‌ایِ DNS یا دامنه‌ای که هنوز
     * منتشر نشده، نباید کلِ ردیف را دور بیندازد؛ آن هم وقتی هیچ درخواستی به آن
     * نشانی زده نمی‌شود.
     *
     * نگهبانِ واقعیِ SSRF سرِ جای خودش است: `SiteAudit` و `ContactFinder` پیش
     * از **باز کردن** هر نشانی `SafeUrl::allowed()` را صدا می‌زنند. اینجا فقط
     * چیزهایی رد می‌شوند که هیچ‌وقت نشانیِ یک کسب‌وکار نیستند.
     */
    private function reject(array $r): ?string
    {
        if (blank($r['company'] ?? null) || blank($r['website'] ?? null)) {
            return 'ناقص';
        }

        $site = $r['website'];
        $host = mb_strtolower((string) parse_url($site, PHP_URL_HOST));

        if (! filter_var($site, FILTER_VALIDATE_URL) || $host === '' || ! str_contains($host, '.')) {
            return 'نشانیِ نامعتبر';
        }

        // نشانیِ عددی یا محلی = سایتِ کسب‌وکار نیست
        if (filter_var($host, FILTER_VALIDATE_IP) || in_array($host, ['localhost', 'localhost.localdomain'], true)) {
            return 'نشانیِ نامعتبر';
        }

        if (CrmLead::where('domain_hash', CrmLead::hashFor($site))->exists()) {
            return 'تکراری';
        }

        if (filled($r['email'] ?? null) && CrmSuppression::blocks($r['email'])) {
            return 'فهرستِ سیاه';
        }

        return null;
    }
}
