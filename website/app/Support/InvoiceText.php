<?php

namespace App\Support;

use App\Models\InvoiceItem;
use Illuminate\Support\Carbon;

/** Customer/admin presentation only. Never rewrites issued invoice or settlement evidence. */
final class InvoiceText
{
    private static function trafficService(InvoiceItem $item): ?string
    {
        return preg_match('/^ترافیک اینترنت سرور #([1-9][0-9]*)$/uD', (string) $item->title, $m) === 1 ? $m[1] : null;
    }

    public static function title(InvoiceItem $item): string
    {
        $service = self::trafficService($item);

        return $service === null ? PanelText::text($item->title)
            : trans('invoices.traffic_title', ['service' => $service]);
    }

    public static function description(InvoiceItem $item): string
    {
        $raw = (string) $item->description;
        // This is the exact structured format issued by the reviewed settlement command.
        $pattern = '/^(?<gb>[0-9]+(?:\.[0-9]+)?) GB \| (?<from>\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z) — (?<to>\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z) \(UTC\)$/uD';
        if (self::trafficService($item) === null || preg_match($pattern, $raw, $m) !== 1) {
            return PanelText::text($raw);
        }
        try {
            $format = '!Y-m-d\\TH:i:s\\Z';
            $from = Carbon::createFromFormat($format, $m['from'], 'UTC');
            $to = Carbon::createFromFormat($format, $m['to'], 'UTC');
            if (! $from || ! $to || $from->format('Y-m-d\\TH:i:s\\Z') !== $m['from']
                || $to->format('Y-m-d\\TH:i:s\\Z') !== $m['to'] || $to->lte($from)) {
                return PanelText::text($raw);
            }
            $gb = str_contains($m['gb'], '.') ? rtrim(rtrim($m['gb'], '0'), '.') : $m['gb'];

            return trans('invoices.traffic_description', ['gb' => fa_num($gb),
                'from' => sdate($from->setTimezone('Asia/Tehran'), true),
                'to' => sdate($to->setTimezone('Asia/Tehran'), true)]);
        } catch (\Throwable) {
            return PanelText::text($raw);
        }
    }
}
