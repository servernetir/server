<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Calendar dates only: no timezone conversion or accounting-period changes. */
final class FinanceDates
{
    public static function iso(mixed $value, string $field = 'date'): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $bad = fn () => ValidationException::withMessages([$field => __('finance.invalid_jalali_date')]);
        if (! is_string($value) || mb_strlen($value) > 32) {
            throw $bad();
        }
        $ascii = strtr(trim($value), array_combine(preg_split('//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY), str_split('01234567890123456789')));
        if (! preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})$/D', $ascii, $m)) {
            throw $bad();
        }
        [$y, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        if ($y >= 1300 && $y <= 1500) {
            $j = Jalali::parse($ascii);
            if ($j === null) {
                throw $bad();
            }
            [$y, $month, $day] = Jalali::toGregorian(...$j);
        } elseif ($y < 1700 || $y > 2500) {
            throw $bad();
        }
        if (! checkdate($month, $day, $y)) {
            throw $bad();
        }

        return sprintf('%04d-%02d-%02d', $y, $month, $day);
    }

    public static function normalize(Request $request, array $fields): void
    {
        $values = [];
        foreach ($fields as $field) {
            if ($request->exists($field)) {
                $values[$field] = self::iso($request->input($field), $field);
            }
        }
        $request->merge($values);
    }

    public static function display(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }
        try {
            $iso = self::iso($value);
            [$y, $m, $d] = array_map('intval', explode('-', $iso));
            $j = Jalali::fromGregorian($y, $m, $d);

            return fa_num(str_replace('-', '/', Jalali::format(...$j)));
        } catch (ValidationException) {
            return $value;
        }
    }
}
