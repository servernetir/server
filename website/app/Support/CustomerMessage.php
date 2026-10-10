<?php

namespace App\Support;

/** Localize stored system messages at the customer boundary; never rewrite audit evidence. */
class CustomerMessage
{
    private static array $catalogues = [];
    private static array $uiCatalogues = [];
    private static ?array $patterns = null;

    private static function ui(string $locale): array
    {
        return self::$uiCatalogues[$locale] ??= require lang_path($locale.'/ui.php');
    }

    private static function catalogue(string $locale): array
    {
        return self::$catalogues[$locale] ??= array_merge(
            array_filter(self::ui($locale),
                fn ($key) => str_starts_with($key, 'act_') || str_starts_with($key, 'svc_name_'), ARRAY_FILTER_USE_KEY),
            require lang_path($locale.'/activity.php'),
            require lang_path($locale.'/history.php'),
        );
    }

    public static function activity(string $text, string $action, ?string $locale = null): string
    {
        $locale = self::locale($locale);
        $translated = self::translate($text, $locale);
        if ($translated !== null) {
            return $translated;
        }

        if ($locale === 'fa') {
            return $text;
        }

        // Unknown staff/provider prose stays in the audit log, with a truthful localized summary.
        $key = 'activity.summary_'.$action;
        return trans()->hasForLocale($key, $locale)
            ? trans($key, [], $locale)
            : trans('activity.summary_other', [], $locale);
    }

    public static function known(string $text, ?string $locale = null): ?string
    {
        return self::translate($text, self::locale($locale));
    }

    public static function fragment(string $text, ?string $locale = null, int $depth = 0): string
    {
        $locale = self::locale($locale);
        if ($depth > 12) {
            return $locale === 'fa' ? $text : trans('activity.detail', [], $locale);
        }

        foreach (['fa', 'en', 'tr'] as $source) {
            $dictionary = self::ui($source);
            if (trans()->hasForLocale('google.channel', $source) && trans()->hasForLocale('google.channel', $locale)) {
                $google = trans('google.channel', [], $source);
                if ($text === $google) {
                    return trans('google.channel', [], $locale);
                }
                if ($text === $google.($dictionary['act_login_app'] ?? '')) {
                    return trans('google.channel', [], $locale).trans('ui.act_login_app', [], $locale);
                }
            }
            foreach (['act_ch_email', 'act_ch_mobile'] as $channel) {
                if ($text === ($dictionary[$channel] ?? '').($dictionary['act_login_app'] ?? '')) {
                    return trans('ui.'.$channel, [], $locale).trans('ui.act_login_app', [], $locale);
                }
            }
            foreach ($dictionary as $key => $value) {
                if (is_string($value) && $value === $text && ! str_contains($value, ':')) {
                    $target = trans('ui.'.$key, [], $locale);
                    if (self::safe($target, $locale)) {
                        return $target;
                    }
                }
            }
        }

        $translated = self::translate($text, $locale, $depth + 1);
        if ($translated !== null) {
            return $translated;
        }

        if ($locale === 'fa') {
            return $text;
        }

        $digits = array_combine(preg_split('//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY),
            str_split('01234567890123456789'));
        $text = strtr($text, $digits + ['٬' => ',', '،' => ',', 'تومان' => 'IRT', 'ریال' => 'IRR',
            'گیگابایت' => 'GB', 'مگابایت' => 'MB', 'ترابایت' => 'TB', 'گیگاهرتز' => 'GHz', 'هسته' => 'vCPU', '٪' => '%']);
        foreach ((array) config('billing.cycles', []) as $cycle) {
            foreach ((array) ($cycle['label'] ?? []) as $label) {
                $text = str_replace($label, $cycle['label'][$locale] ?? $cycle['label']['en'], $text);
            }
        }

        // Country/city labels are generated data, rather than free-form Persian messages.
        $countries = [];
        foreach (\App\Models\CloudLocation::COUNTRIES as $labels) {
            foreach (['fa', 'en', 'tr'] as $source) {
                $countries[$labels[$source]] = $labels[$locale] ?? $labels['en'];
            }
        }
        $text = strtr($text, $countries);
        foreach (['berlin', 'washington', 'falkenstein', 'nuremberg', 'moscow', 'stockholm', 'vienna', 'london', 'distributed'] as $place) {
            foreach (['fa', 'en', 'tr'] as $source) {
                $text = str_replace(trans('history.history_'.$place, [], $source), trans('history.history_'.$place, [], $locale), $text);
            }
        }
        foreach (\App\Models\CloudLocation::CITIES_FA as $slug => $label) {
            $text = str_replace($label, ucwords(str_replace('-', ' ', $slug)), $text);
        }

        return self::safe($text, $locale) ? $text : trans('activity.detail', [], $locale);
    }

    private static function translate(string $text, string $locale, int $depth = 0): ?string
    {
        if ($depth > 12) {
            return null;
        }
        foreach (self::patterns() as [$key, $pattern, $names]) {
            if (preg_match($pattern, $text, $matches) !== 1) {
                continue;
            }
            $vars = [];
            foreach ($names as $name => $_) {
                $vars[$name] = $name === 'staff' || ($key === 'staff_cancel' && $name === 'name') ? self::staff($matches[$name], $locale)
                    : ($key === 'act_kyc_ok' && $locale !== 'fa' ? \Illuminate\Support\Str::ascii($matches[$name], 'fa')
                        : self::fragment($matches[$name], $locale, $depth + 1));
            }
            $group = str_starts_with($key, 'history_') ? 'history'
                : (str_starts_with($key, 'act_') || str_starts_with($key, 'svc_name_') ? 'ui' : 'activity');
            if (! trans()->hasForLocale($group.'.'.$key, $locale)) {
                continue;
            }
            $result = trans($group.'.'.$key, $vars, $locale);
            if (self::safe($result, $locale)) {
                return $result;
            }
        }
        return null;
    }

    private static function patterns(): array
    {
        if (self::$patterns !== null) {
            return self::$patterns;
        }
        $candidates = [];
        foreach (['fa', 'en', 'tr'] as $source) {
            foreach (self::catalogue($source) as $key => $template) {
                if (! is_string($template) || str_starts_with($key, 'summary_') || $key === 'detail') {
                    continue;
                }
                $candidates[] = [$key, $template, strlen(preg_replace('/(?<![a-z0-9_]):([a-z_]+)/i', '', $template))];
            }
        }
        // Prefer specific events over broad wrappers such as "Cloud server #:id — :text".
        usort($candidates, fn ($a, $b) => $b[2] <=> $a[2]);
        $patterns = [];
        foreach ($candidates as [$key, $template]) {
            $names = [];
            $pattern = '';
            foreach (preg_split('/((?<![a-z0-9_]):[a-z_]+)/i', $template, -1, PREG_SPLIT_DELIM_CAPTURE) as $part) {
                if (preg_match('/^:([a-z_]+)$/i', $part, $m)) {
                    $name = $m[1];
                    $pattern .= isset($names[$name]) ? '\\k<'.$name.'>' : '(?<'.$name.'>.*?)';
                    $names[$name] = true;
                } else {
                    $pattern .= preg_quote($part, '~');
                }
            }
            $patterns[] = [$key, '~^'.$pattern.'$~suD', $names];
        }
        return self::$patterns = $patterns;
    }

    private static function safe(string $text, string $locale): bool
    {
        return $locale === 'fa' || preg_match('/[\x{0600}-\x{06FF}]/u', $text) !== 1;
    }

    private static function staff(string $name, string $locale): string
    {
        if ($locale === 'fa' || self::safe($name, $locale)) {
            return $name;
        }
        if (! app()->bound('customer.language.staff')) {
            app()->scoped('customer.language.staff', fn () => \Illuminate\Support\Facades\Schema::hasTable('users')
                ? \App\Models\User::select('name', 'name_latin')->get() : collect());
        }
        $staff = app('customer.language.staff')->firstWhere('name', $name);
        $latin = $staff?->displayNameFor($locale);
        return $latin && self::safe($latin, $locale) ? $latin : trans('ui.tk_staff', [], $locale);
    }

    private static function locale(?string $locale): string
    {
        $locale ??= app()->getLocale();
        return in_array($locale, ['fa', 'en', 'tr'], true) ? $locale : 'en';
    }
}
