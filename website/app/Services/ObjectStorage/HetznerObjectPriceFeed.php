<?php

namespace App\Services\ObjectStorage;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Public website prices, not a Hetzner Console/account API.
 *
 * The official page's ho-price-container elements identify CLOUD_84/85/86.
 * Its public static/dist/vendors.js resolves those identifiers through this
 * price API and applies the visitor's VAT afterwards. In particular CLOUD_86
 * has an API field called "hourly", but the page defines its unit as EUR/TB.
 * Revalidate that unit contract on every fetch; never guess from field names.
 */
class HetznerObjectPriceFeed
{
    public const SOURCE_URL = 'https://www.hetzner.com/storage/object-storage/';

    public const API_BASE = 'https://website-price-api.hetzner.com/api/v1/products/';

    private const MAX_RESPONSE_AGE_SECONDS = 3600;

    /**
     * No cache fallback or persistence: callers must disable new sales on
     * failure and keep historical rates explicitly separate from fresh ones.
     * HTTP freshness proves retrieval freshness, not a tariff publication date.
     * All monetary values remain decimal strings to avoid binary-float loss.
     */
    public function fetch(): array
    {
        $page = $this->get(self::SOURCE_URL, 'text/html');
        $this->validateUnitContract($page->body());

        $sources = [];
        $prices = [];
        foreach (['CLOUD_84', 'CLOUD_85', 'CLOUD_86'] as $productId) {
            $sources[$productId] = self::API_BASE.$productId;
            $response = $this->get($sources[$productId], 'application/json');
            try {
                $data = json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                throw new RuntimeException('Hetzner Object Storage price source returned invalid JSON.');
            }
            $prices[$productId] = $this->productPrices($data, $productId);
        }

        return [
            'currency' => 'EUR',
            'tax_included' => false,
            'base_monthly_eur' => $this->decimal($prices['CLOUD_84']['monthly']['EUR'] ?? null),
            'base_hourly_eur' => $this->decimal($prices['CLOUD_84']['hourly']['EUR'] ?? null),
            'storage_tb_hour_eur' => $this->decimal($prices['CLOUD_85']['hourly']['EUR'] ?? null),
            'egress_tb_eur' => $this->decimal($prices['CLOUD_86']['hourly']['EUR'] ?? null),
            'source_url' => self::SOURCE_URL,
            'source_urls' => $sources,
            'fetched_at' => now()->utc()->toIso8601String(),
        ];
    }

    private function get(string $url, string $contentType): Response
    {
        try {
            $response = Http::withHeaders(['Accept' => $contentType])
                ->connectTimeout(8)->timeout(20)
                ->withOptions(['allow_redirects' => false])
                ->get($url);
        } catch (\Throwable) {
            // Never copy upstream bodies, headers or exception details to logs.
            throw new RuntimeException('Hetzner Object Storage price source is unavailable.');
        }

        if ($response->status() !== 200
            || ! str_starts_with(strtolower($response->header('Content-Type')), $contentType)
            || strlen($response->body()) > 2_000_000) {
            throw new RuntimeException('Hetzner Object Storage price source returned an unexpected response.');
        }

        $rawDate = $response->header('Date');
        try {
            $date = CarbonImmutable::createFromFormat('D, d M Y H:i:s \G\M\T', $rawDate, 'UTC');
        } catch (\Throwable) {
            $date = null;
        }
        $age = $response->header('Age');
        if (! $date || $date->format('D, d M Y H:i:s \G\M\T') !== $rawDate
            || ($age !== '' && ! preg_match('/\A[0-9]{1,8}\z/', $age))) {
            throw new RuntimeException('Hetzner Object Storage price source has no valid freshness evidence.');
        }

        $elapsed = now()->utc()->timestamp - $date->timestamp;
        if ($elapsed < -300 || $elapsed > self::MAX_RESPONSE_AGE_SECONDS
            || (int) $age > self::MAX_RESPONSE_AGE_SECONDS) {
            throw new RuntimeException('Hetzner Object Storage price source is stale.');
        }

        return $response;
    }

    private function productPrices(mixed $data, string $productId): array
    {
        $locations = is_array($data) ? ($data['locations'] ?? null) : null;
        if (! is_array($data) || ($data['productId'] ?? null) !== $productId
            || ! is_array($locations) || ! array_is_list($locations) || count($locations) !== 1
            || ($locations[0]['active'] ?? null) !== true
            || ($locations[0]['datacenter'] ?? null) !== 'ALL'
            || ! is_array($locations[0]['prices'] ?? null)) {
            throw new RuntimeException('Hetzner Object Storage price schema or availability changed.');
        }

        return $locations[0]['prices'];
    }

    private function decimal(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/\A(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,8})?\z/', $value)
            || trim(str_replace('.', '', $value), '0') === '') {
            throw new RuntimeException('Hetzner Object Storage price source has a missing or invalid EUR rate.');
        }

        return $value;
    }

    private function validateUnitContract(string $html): void
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            $loaded = $document->loadHTML($html, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (! $loaded) {
            throw new RuntimeException('Hetzner Object Storage price unit contract is unavailable.');
        }

        $xpath = new DOMXPath($document);
        foreach ($xpath->query('//ho-price-container[@product-key]') as $element) {
            $key = $element->getAttribute('product-key');
            if (! in_array($key, ['CLOUD_84', 'CLOUD_85', 'CLOUD_86'], true)) {
                continue;
            }
            if ($element->hasAttribute('operation')) {
                throw new RuntimeException('Hetzner Object Storage price transformation changed.');
            }
            $element->parentNode->replaceChild(
                $document->createTextNode('{{'.$key.'.'.$element->getAttribute('price-type').'}}'),
                $element,
            );
        }

        $answers = [];
        foreach ($xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " faq-answer ")]') as $answer) {
            $answers[] = trim(preg_replace('/\s+/u', ' ', $answer->textContent));
        }

        $has = static function (array $fragments) use ($answers): bool {
            foreach ($answers as $answer) {
                $matches = true;
                foreach ($fragments as $fragment) {
                    if (! str_contains($answer, $fragment)) {
                        $matches = false;
                        break;
                    }
                }
                if ($matches) {
                    return true;
                }
            }

            return false;
        };

        if (! $has([
            'base price of {{CLOUD_84.monthly}} per month',
            'includes 1 TB of storage (up to 744 TB-hours) and 1 TB of egress traffic',
        ]) || ! $has([
            'base price is billed hourly at {{CLOUD_84.hourly}}',
            'capped at {{CLOUD_84.monthly}} per month',
        ]) || ! $has([
            'All prices are exclusive of VAT',
            'Storage: {{CLOUD_85.hourly}} per TB-hour.',
            'Egress traffic: {{CLOUD_86.hourly}} per TB.',
        ]) || ! $has(['the included quota applies to the total storage and traffic across all Buckets in your account.'])) {
            throw new RuntimeException('Hetzner Object Storage price units, tax mode or allowance contract changed.');
        }
    }
}
