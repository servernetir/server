<?php

namespace App\Services\Payment;

/**
 * فهرست درگاه‌های در دسترس.
 *
 * جدا از PaymentService است تا افزودن درگاه بعدی (رمزارز) فقط یک ثبت باشد،
 * نه دست بردن در منطق پول.
 */
class GatewayRegistry
{
    /** @var array<string,PaymentGateway> */
    private array $gateways = [];

    public function register(PaymentGateway $gateway): void
    {
        $this->gateways[$gateway->key()] = $gateway;
    }

    public function get(?string $key): ?PaymentGateway
    {
        return $key === null ? null : ($this->gateways[$key] ?? null);
    }

    /**
     * درگاه‌هایی که واقعاً قابل استفاده‌اند برای این ارز.
     * درگاه پیکربندی‌نشده اصلاً به کاربر نشان داده نمی‌شود — دکمه‌ای که
     * همیشه خطا می‌دهد بدتر از نبودنش است.
     *
     * @return array<string,PaymentGateway>
     */
    public function availableFor(string $currency): array
    {
        return array_filter(
            $this->gateways,
            fn (PaymentGateway $g) => $g->enabled() && $g->currency() === $currency,
        );
    }

    /** @return array<string,PaymentGateway> */
    public function all(): array
    {
        return $this->gateways;
    }
}
