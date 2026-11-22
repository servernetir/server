<?php

namespace App\Services\Marketing;

use DomainException;

/** Literal substitutions only: recipient data is never parsed as another template. */
final class EmailCampaignTemplate
{
    private const TOKEN_PATTERN = '/(?<!\{)\{\{\s*([^{}]*?)\s*\}\}(?!\})/u';

    private const NAME_TOKENS = ['نام و نام خانوادگی', 'full_name'];

    private const UNSUBSCRIBE_TOKENS = ['لینک لغو عضویت', 'unsubscribe_url'];

    public function validate(string $template): void
    {
        if (! mb_check_encoding($template, 'UTF-8')) {
            throw new DomainException('Email template must be valid UTF-8.');
        }

        $remaining = preg_replace_callback(self::TOKEN_PATTERN, function (array $match): string {
            $this->assertToken($this->tokenName($match[1]));

            return '';
        }, $template);

        if ($remaining === null || str_contains($remaining, '{{') || str_contains($remaining, '}}')) {
            throw new DomainException('Email template placeholder is malformed.');
        }
    }

    public function containsUnsubscribeToken(string $template): bool
    {
        $this->validate($template);
        preg_match_all(self::TOKEN_PATTERN, $template, $matches);

        foreach ($matches[1] as $token) {
            if (in_array($this->tokenName($token), self::UNSUBSCRIBE_TOKENS, true)) {
                return true;
            }
        }

        return false;
    }

    public function requiresName(string $template): bool
    {
        $this->validate($template);
        preg_match_all(self::TOKEN_PATTERN, $template, $matches);

        foreach ($matches[1] as $token) {
            if (in_array($this->tokenName($token), self::NAME_TOKENS, true)) {
                return true;
            }
        }

        return false;
    }

    public function render(string $template, ?string $fullName, string $unsubscribeUrl): string
    {
        $this->validate($template);

        return preg_replace_callback(self::TOKEN_PATTERN, function (array $match) use ($fullName, $unsubscribeUrl): string {
            $token = $this->tokenName($match[1]);
            if (in_array($token, self::NAME_TOKENS, true)) {
                return $this->normalizeName($fullName);
            }

            if (! filter_var($unsubscribeUrl, FILTER_VALIDATE_URL)
                || ! in_array(strtolower((string) parse_url($unsubscribeUrl, PHP_URL_SCHEME)), ['http', 'https'], true)
                || preg_match('/[\x00-\x20\x7f]/', $unsubscribeUrl)) {
                throw new DomainException('Recipient unsubscribe URL is invalid.');
            }

            return $unsubscribeUrl;
        }, $template);
    }

    private function tokenName(string $token): string
    {
        return preg_replace('/\A\s+|\s+\z/u', '', $token);
    }

    private function assertToken(string $token): void
    {
        if (! in_array($token, [...self::NAME_TOKENS, ...self::UNSUBSCRIBE_TOKENS], true)) {
            throw new DomainException('Email template placeholder is unsupported.');
        }
    }

    public function normalizeName(?string $fullName): string
    {
        if ($fullName === null || ! mb_check_encoding($fullName, 'UTF-8')) {
            throw new DomainException('Recipient full name is required by this template.');
        }

        $name = trim(preg_replace('/\s+/u', ' ', preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $fullName)));
        if ($name === '' || mb_strlen($name) > 190 || str_contains($name, '{{') || str_contains($name, '}}')) {
            throw new DomainException('Recipient full name is invalid.');
        }

        return $name;
    }
}
