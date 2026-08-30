<?php

namespace App\Services\Provisioning;

/**
 * نتیجهٔ یک عملِ فراهم‌سازی — موفق/ناموفق، همراهِ اطلاعاتِ حساب.
 *
 * الگو از PaymentGateway\StartResult گرفته شده: readonly + سازندهٔ نام‌دار.
 */
final class ProvisionResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $username = null,
        public readonly ?string $password = null,
        public readonly ?string $panelUrl = null,
        public readonly ?string $error = null,
        public readonly bool $manual = false,       // یعنی نیاز به اقدامِ دستیِ ادمین دارد
        public readonly array $meta = [],
    ) {}

    public static function success(?string $username, ?string $password, ?string $panelUrl, array $meta = []): self
    {
        return new self(ok: true, username: $username, password: $password, panelUrl: $panelUrl, meta: $meta);
    }

    /** برای درایورِ دستی: کاری روی API نشد، ادمین باید تحویل دهد */
    public static function manual(string $note = '', array $meta = []): self
    {
        return new self(ok: false, manual: true, error: $note ?: null, meta: $meta);
    }

    public static function fail(string $error, array $meta = []): self
    {
        return new self(ok: false, error: mb_substr($error, 0, 290), meta: $meta);
    }
}
