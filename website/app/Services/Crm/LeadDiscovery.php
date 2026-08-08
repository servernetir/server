<?php

namespace App\Services\Crm;

use App\Models\CrmLead;
use App\Models\CrmSuppression;
use App\Services\SafeUrl;
use Illuminate\Support\Facades\Log;

/**
 * پیدا کردنِ سرنخ — Google Places (Text Search، نسخهٔ جدید).
 *
 * چرا Places و نه اسکرپِ نتایجِ جستجو: نتیجهٔ جستجو نه امتیاز دارد نه تعدادِ
 * نظر، و همان دو عدد کلِ فیلترِ ما هستند. بدونشان یا به کلینیکی می‌نویسیم که
 * تازه باز شده و پول ندارد، یا به زنجیره‌ای که آژانسِ تبلیغاتی دارد. اسکرپ هم
 * هر بار که گوگل HTML را عوض کند می‌شکند و شرایطِ استفاده را هم نقض می‌کند.
 *
 * هزینه در عمل ناچیز است: هر اجرا یک درخواست (۲۰ نتیجه)، روزی یک اجرا.
 *
 * 🔴 بدونِ `GOOGLE_PLACES_KEY` این سرویس **کاری نمی‌کند** و صادقانه می‌گوید
 * چرا. مسیرِ بی‌کلید، واردکردنِ دستیِ فهرست از پنل است — نه یک اسکرپرِ شکننده
 * که یک ماهِ دیگر بی‌صدا صفر برمی‌گرداند.
 */
class LeadDiscovery
{
    private const ENDPOINT = 'https://places.googleapis.com/v1/places:searchText';

    /** فقط همین فیلدها را می‌خواهیم — فیلدِ اضافه یعنی SKUِ گران‌تر */
    private const FIELDS = 'places.id,places.displayName,places.websiteUri,places.rating,'
        .'places.userRatingCount,places.formattedAddress,places.internationalPhoneNumber';

    /**
     * @return array{added:int, seen:int, skipped:array<string,int>, error?:string}
     */
    public function run(?int $limit = null): array
    {
        $key = (string) config('crm.discovery.places_key');

        if ($key === '') {
            Log::warning('crm.discovery.no_key');

            return ['added' => 0, 'seen' => 0, 'skipped' => [], 'error' => 'no_places_key'];
        }

        $limit = $limit ?: (int) config('crm.discovery.per_run', 15);
        $added = 0;
        $seen = 0;
        $skipped = [];

        foreach ((array) config('crm.discovery.queries', []) as $q) {
            if ($added >= $limit) {
                break;
            }

            foreach ($this->search($key, $q) as $place) {
                if ($added >= $limit) {
                    break;
                }

                $seen++;
                $reason = $this->reject($place);

                if ($reason !== null) {
                    $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;

                    continue;
                }

                if ($this->store($place, $q)) {
                    $added++;
                } else {
                    $skipped['duplicate'] = ($skipped['duplicate'] ?? 0) + 1;
                }
            }
        }

        Log::info('crm.discovery.done', ['added' => $added, 'seen' => $seen, 'skipped' => $skipped]);

        return ['added' => $added, 'seen' => $seen, 'skipped' => $skipped];
    }

    /**
     * یک جستجوی متنی. خروجی: آرایهٔ خامِ Places.
     *
     * @return array<int, array<string, mixed>>
     */
    private function search(string $key, array $q): array
    {
        $body = [
            'textQuery'    => trim(($q['q'] ?? '').' in '.($q['city'] ?? '')),
            'pageSize'     => 20,
            'languageCode' => 'en',
        ];

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Goog-Api-Key: '.$key,
                'X-Goog-FieldMask: '.self::FIELDS,
            ],
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($code !== 200) {
            // 🔴 بی‌صدا رد نکن: کلیدِ منقضی و سهمیهٔ تمام‌شده هر دو «صفر سرنخ»
            // می‌دهند و بدونِ این لاگ، هفته‌ها فکر می‌کنی بازار خالی است.
            Log::error('crm.discovery.http', [
                'code' => $code, 'err' => $err, 'body' => mb_substr((string) $raw, 0, 400),
            ]);

            return [];
        }

        $j = json_decode((string) $raw, true);

        return is_array($j['places'] ?? null) ? $j['places'] : [];
    }

    /**
     * چرا این کسب‌وکار به درد نمی‌خورد؟ `null` یعنی می‌خورد.
     *
     * «نقطهٔ شیرین» عمدی است: امتیازِ خیلی پایین یعنی مشکلش سایت نیست،
     * امتیازِ خیلی بالا با هزار نظر یعنی از قبل آژانس و بودجه دارد.
     */
    private function reject(array $p): ?string
    {
        $site = (string) ($p['websiteUri'] ?? '');

        if ($site === '') {
            return 'no_website';
        }

        if (! SafeUrl::allowed($site)) {
            return 'unsafe_url';
        }

        // سایتی که روی فیسبوک/اینستاگرام/لینک‌تری است، سایت نیست.
        $host = strtolower((string) parse_url($site, PHP_URL_HOST));
        foreach (['facebook.', 'instagram.', 'linktr.ee', 'wa.me', 'business.site', 'wixsite.'] as $bad) {
            if (str_contains($host, $bad)) {
                return 'social_only';
            }
        }

        $rating = (float) ($p['rating'] ?? 0);
        $reviews = (int) ($p['userRatingCount'] ?? 0);

        if ($rating < (float) config('crm.discovery.min_rating')) {
            return 'rating_low';
        }
        if ($rating > (float) config('crm.discovery.max_rating')) {
            return 'rating_high';
        }
        if ($reviews < (int) config('crm.discovery.min_reviews')) {
            return 'reviews_low';
        }
        if ($reviews > (int) config('crm.discovery.max_reviews')) {
            return 'reviews_high';
        }

        if (CrmSuppression::where('domain', $host)->exists()) {
            return 'suppressed';
        }

        return null;
    }

    /** `false` یعنی از قبل بوده — همان کلینیک نباید دو بار ایمیل بگیرد. */
    private function store(array $p, array $q): bool
    {
        $site = (string) $p['websiteUri'];
        $hash = CrmLead::hashFor($site);

        if (CrmLead::where('domain_hash', $hash)->exists()) {
            return false;
        }

        CrmLead::create([
            'domain_hash' => $hash,
            'company'     => mb_substr((string) ($p['displayName']['text'] ?? $q['q']), 0, 160),
            'country'     => $q['country'] ?? null,
            'city'        => $q['city'] ?? null,
            'vertical'    => $q['vertical'] ?? null,
            'website'     => mb_substr($site, 0, 190),
            'phone'       => mb_substr((string) ($p['internationalPhoneNumber'] ?? ''), 0, 40) ?: null,
            'source'      => 'places',
            'stage'       => 'new',
            'notes'       => trim(sprintf(
                "%s · %s نظر · %s",
                (string) ($p['rating'] ?? '—'),
                (string) ($p['userRatingCount'] ?? '—'),
                (string) ($p['formattedAddress'] ?? ''),
            )),
        ]);

        return true;
    }
}
