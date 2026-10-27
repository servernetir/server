<?php

namespace App\Services\Cloud;

use App\Models\Setting;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Fixed-endpoint VPS.TC client. Mutations are never retried; callers journal and reconcile them. */
final class VpsTcApi
{
    private string $origin;
    private string $username;
    private string $password;
    private ?string $token = null;
    private ?string $refresh = null;

    public function __construct(?string $origin = null, ?string $username = null, ?string $password = null)
    {
        // Never combine saved credentials with another configured host/account.
        if ($origin !== null || $username !== null || $password !== null) {
            $tuple = [$origin ?? '', $username ?? '', $password ?? ''];
        } else {
            $savedOrigin = (string) Setting::get('cloud_vpstc_api_origin', '');
            $tuple = trim($savedOrigin) !== ''
                ? [$savedOrigin, (string) Setting::getSecret('cloud_vpstc_username'), (string) Setting::getSecret('cloud_vpstc_password')]
                : [(string) config('services.vpstc.base_url', ''), (string) config('services.vpstc.username', ''), (string) config('services.vpstc.password', '')];
        }
        $rawOrigin = trim($tuple[0]);
        $this->origin = self::validOrigin($rawOrigin) ? 'https://'.strtolower((string) parse_url($rawOrigin, PHP_URL_HOST)) : $rawOrigin;
        $this->username = trim($tuple[1]);
        $this->password = $tuple[2];
    }

    public static function validOrigin(string $origin): bool
    {
        $origin = trim($origin);
        if (! preg_match('~\Ahttps://([^/?#]+?)/?\z~iD', $origin, $match)) {
            return false;
        }
        $host = strtolower($match[1]);
        if (strlen($host) > 253 || ! preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*(?:vps\.tc|whitelabelservices\.us)\z/D', $host)) {
            return false;
        }
        $parts = parse_url($origin);

        return is_array($parts)
            && strtolower($parts['scheme'] ?? '') === 'https'
            && strtolower($parts['host'] ?? '') === $host
            && ! isset($parts['user']) && ! isset($parts['pass'])
            && ! isset($parts['port'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment'])
            && in_array($parts['path'] ?? '', ['', '/'], true);
    }

    public function isConfigured(): bool
    {
        return self::validOrigin($this->origin) && $this->username !== '' && $this->password !== '';
    }

    /** Bind durable receipts to their original account without revealing credentials. */
    public function accountFingerprint(): string
    {
        return hash_hmac('sha256', implode("\0", [$this->origin, $this->username, $this->password]), (string) config('app.key'));
    }

    public function balance(): array
    {
        return $this->read('/api/balance', 'details');
    }

    public function categories(): array
    {
        return $this->read('/api/category', 'categories');
    }

    public function invoices(): array
    {
        return $this->read('/api/invoice', 'invoices');
    }

    public function services(): array
    {
        // The published endpoint lists all account services and declares no
        // pagination parameters. Callers must reject any partial-list markers;
        // a missing row never proves an ambiguous purchase did not happen.
        return $this->read('/api/service', 'services');
    }

    public function paymentMethods(): array
    {
        return $this->read('/api/payment', 'payments');
    }

    public function paymentFees(): array
    {
        return $this->read('/api/payment/fees', 'payments');
    }

    public function invoice(string $invoiceId): array
    {
        return $this->withIds([$invoiceId], fn () => $this->read('/api/invoice/'.$invoiceId, 'invoice'));
    }

    public function serviceMethods(string $serviceId): array
    {
        return $this->withIds([$serviceId], fn () => $this->read('/api/service/'.$serviceId.'/methods', 'methods'));
    }

    /** Calculate one monthly configuration; this route never submits an order or payment. */
    public function quote(string $productId, string $paymentMethod, array $custom = [], ?string $hostname = null): array
    {
        return $this->withIds([$productId, $paymentMethod], function () use ($productId, $paymentMethod, $custom, $hostname) {
            $hostname ??= 'preflight-'.$productId.'.example.invalid';
            if ($hostname === '' || strlen($hostname) > 253
                || filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                return $this->failure('invalid_quote', 'A valid hostname is required for the calculation.');
            }
            $payload = ['pay_method' => (int) $paymentMethod, 'output' => 'full', 'items' => [[
                'type' => 'product', 'product_id' => (int) $productId, 'domain' => $hostname,
                'cycle' => 'm', 'custom' => $custom,
            ]]];
            try {
                if (strlen(json_encode($payload, JSON_THROW_ON_ERROR, 32)) > 200000) {
                    return $this->failure('invalid_quote', 'The calculation configuration is too large.');
                }
            } catch (Throwable) {
                return $this->failure('invalid_quote', 'The calculation configuration is invalid.');
            }

            $result = $this->read('/api/quote', 'summary', $payload);
            if (! $result['ok']) { return $result; }
            $data = $result['data'];
            if ($data['summary'] === [] || array_is_list($data['summary'])
                || ! is_array($data['items'] ?? null) || ! array_is_list($data['items'])
                || count($data['items']) !== 1 || ! is_array($data['items'][0]) || $data['items'][0] === []) {
                return $this->failure('invalid_response', 'VPS.TC returned an unsupported calculation response.');
            }
            $item = $data['items'][0];
            $identities = [];
            if (array_key_exists('product_id', $item)) { $identities[] = $item['product_id']; }
            if (array_key_exists('product', $item)) {
                if (! is_array($item['product']) || ! array_key_exists('id', $item['product'])) {
                    return $this->failure('invalid_response', 'VPS.TC returned an unsupported calculation item.');
                }
                $identities[] = $item['product']['id'];
            }
            if ($identities === []) {
                return $this->failure('invalid_response', 'VPS.TC did not identify the calculated product.');
            }
            foreach ($identities as $identity) {
                if ((! is_int($identity) && ! is_string($identity)) || (string) $identity !== $productId) {
                    return $this->failure('invalid_response', 'VPS.TC calculated a different product.');
                }
            }
            foreach ([$data, $data['summary'], $item] as $row) {
                if ($this->hasApiError($row)
                    || (array_key_exists('errors', $row) && ! in_array($row['errors'], [null, false, '', [], 0], true))
                    || (array_key_exists('valid', $row) && $row['valid'] !== true)) {
                    return $this->failure('invalid_response', 'VPS.TC did not validate the requested calculation.');
                }
            }

            return $result;
        });
    }

    /** Only monthly orders are accepted; prepaid monthly offers cannot be sold hourly. */
    public function order(string $productId, array $payload): array
    {
        return $this->withIds([$productId], function () use ($productId, $payload) {
            $allowed = ['product_id', 'domain', 'hostname', 'cycle', 'pay_method', 'custom', 'promocode', 'aff_id'];
            if (array_diff(array_keys($payload), $allowed) !== []
                || ($payload['cycle'] ?? null) !== 'm'
                || (! is_string($payload['pay_method'] ?? null) && ! is_int($payload['pay_method'] ?? null))
                || ! preg_match('/\A[1-9][0-9]{0,17}\z/D', (string) ($payload['pay_method'] ?? ''))
                || (isset($payload['product_id']) && ! is_string($payload['product_id']) && ! is_int($payload['product_id']))
                || (isset($payload['product_id']) && (string) $payload['product_id'] !== $productId)
                || (isset($payload['custom']) && ! is_array($payload['custom']))
                || (isset($payload['promocode']) && (! is_string($payload['promocode']) || strlen($payload['promocode']) > 512))
                || (isset($payload['aff_id']) && ((! is_string($payload['aff_id']) && ! is_int($payload['aff_id']))
                    || ! preg_match('/\A[1-9][0-9]{0,17}\z/D', (string) $payload['aff_id'])))) {
                return $this->failure('invalid_order', 'A validated monthly order and payment method are required.');
            }
            foreach (['domain', 'hostname'] as $field) {
                if (isset($payload[$field]) && (! is_string($payload[$field]) || strlen($payload[$field]) > 253
                    || filter_var($payload[$field], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false)) {
                    return $this->failure('invalid_order', 'A valid hostname is required.');
                }
            }
            try {
                if (strlen(json_encode($payload, JSON_THROW_ON_ERROR)) > 200000) {
                    return $this->failure('invalid_order', 'The order configuration is too large.');
                }
            } catch (Throwable) {
                return $this->failure('invalid_order', 'The order configuration is invalid.');
            }
            $payload['product_id'] = (int) $productId;

            return $this->mutate('/api/order/'.$productId, $payload, ['items']);
        });
    }

    public function renewService(string $serviceId): array
    {
        return $this->withIds([$serviceId], fn () => $this->mutate('/api/service/'.$serviceId.'/renew', ['id' => (int) $serviceId], ['invoice_id']));
    }

    /** A successful receipt is not proof of payment; read the invoice afterwards. */
    public function applyInvoiceCredit(string $invoiceId, ?string $amount = null): array
    {
        return $this->withIds([$invoiceId], function () use ($invoiceId, $amount) {
            if ($amount !== null && (! preg_match('/\A(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,8})?\z/D', $amount)
                || ! preg_match('/[1-9]/', $amount))) {
                return $this->failure('invalid_amount', 'A positive plain decimal credit amount is required.');
            }

            return $this->mutate('/api/invoice/'.$invoiceId.'/credit', $amount === null ? [] : ['amount' => $amount]);
        });
    }

    /** Cancel the billed parent service; deleting its VM alone does not stop billing. */
    public function cancelService(string $serviceId, bool $immediate = true, string $reason = 'Customer requested service cancellation'): array
    {
        return $this->withIds([$serviceId], function () use ($serviceId, $immediate, $reason) {
            if ($reason === '' || strlen($reason) > 1000 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $reason)) {
                return $this->failure('invalid_reason', 'A short cancellation reason is required.');
            }

            return $this->mutate('/api/service/'.$serviceId.'/cancel', ['id' => (int) $serviceId, 'immediate' => $immediate ? 'true' : 'false', 'reason' => $reason]);
        });
    }

    public function products(string $categoryId): array
    {
        return $this->withIds([$categoryId], fn () => $this->read('/api/category/'.$categoryId.'/product', 'products'));
    }

    public function productOrderForm(string $productId): array
    {
        return $this->withIds([$productId], fn () => $this->read('/api/order/'.$productId, 'product'));
    }

    public function service(string $serviceId): array
    {
        return $this->withIds([$serviceId], fn () => $this->read('/api/service/'.$serviceId, 'service'));
    }

    /** Billing identity may remain readable while the hypervisor manager is offline.
     * This method is not evidence of VM availability or successful provisioning.
     */
    public function serviceBilling(string $serviceId): array
    {
        return $this->withIds([$serviceId], fn () => $this->read('/api/service/'.$serviceId, 'service', null, true));
    }

    public function templates(string $serviceId): array
    {
        return $this->withIds([$serviceId], fn () => $this->read('/api/service/'.$serviceId.'/templates', 'templates'));
    }

    public function vms(string $serviceId): array
    {
        return $this->withIds([$serviceId], fn () => $this->read('/api/service/'.$serviceId.'/vms', 'vms'));
    }

    public function vm(string $serviceId, string $vmId): array
    {
        return $this->withVm($serviceId, $vmId, fn () => $this->read('/api/service/'.$serviceId.'/vms/'.$vmId, 'vm'));
    }

    public function vmUsage(string $serviceId, string $vmId): array
    {
        return $this->withVm($serviceId, $vmId, fn () => $this->read('/api/service/'.$serviceId.'/vms/'.$vmId.'/usage', 'usage'));
    }

    public function power(string $serviceId, string $vmId, string $action): array
    {
        return $this->withVm($serviceId, $vmId, function () use ($serviceId, $vmId, $action) {
            if (! in_array($action, ['start', 'stop', 'shutdown', 'reboot', 'reset'], true)) {
                return $this->failure('invalid_action', 'The power operation is not supported.');
            }

            return $this->mutate('/api/service/'.$serviceId.'/vms/'.$vmId.'/'.$action, ['vmid' => $vmId]);
        });
    }

    public function rebuild(string $serviceId, string $vmId, string $template): array
    {
        return $this->withVm($serviceId, $vmId, function () use ($serviceId, $vmId, $template) {
            if (! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,127}\z/D', $template)) {
                return $this->failure('invalid_template', 'A valid operating system template is required.');
            }

            return $this->mutate('/api/service/'.$serviceId.'/vms/'.$vmId.'/rebuild', ['template' => $template]);
        });
    }

    public function console(string $serviceId, string $vmId): array
    {
        return $this->withVm($serviceId, $vmId, fn () => $this->failure('unsupported_console', 'This console requires a supplier browser session and is unavailable through the server API.'));
    }

    private function withVm(string $serviceId, string $vmId, callable $operation): array
    {
        return $this->withIds([$serviceId], function () use ($vmId, $operation) {
            // vCenter uses MoRefs such as vm-1056; Proxmox uses positive integers.
            if (! preg_match('/\A(?:vm-)?[1-9][0-9]{0,17}\z/D', $vmId)) {
                return $this->failure('invalid_id', 'A valid provider VM identifier is required.');
            }

            return $operation();
        });
    }

    /**
     * Never replay a mutation, including after 401, 429 or a lost response.
     * Callers must persist their immutable request before calling and reconcile
     * receipts with invoice/service/VM state before treating it as completed.
     */
    private function mutate(string $path, array $payload, array $expectedKeys = []): array
    {
        if (! $this->isConfigured()) {
            return $this->failure('not_configured', 'VPS.TC credentials and an approved HTTPS API origin are required.');
        }
        if ($this->token === null && ! $this->authenticate(false)) {
            return $this->failure('authentication_failed', 'VPS.TC authentication failed. No service operation was submitted.');
        }
        try {
            $response = $this->request('POST', $path, $payload, true);
            if (! $response->successful()) {
                if (in_array($response->status(), [400, 403, 404, 422], true)) {
                    return $this->failure('mutation_rejected', 'VPS.TC rejected the operation. Verify the requested resource and configuration.');
                }

                return $this->failure('outcome_unknown', 'The VPS.TC operation outcome is unknown. Reconcile the provider state before any retry.');
            }
            $data = $this->decode($response);
            if ($data === null) {
                return $this->failure('outcome_unknown', 'The VPS.TC operation returned an unreadable receipt. Reconcile the provider state before any retry.');
            }
            if ($this->hasApiError($data)) {
                foreach ($expectedKeys as $key) {
                    if (array_key_exists($key, $data)) {
                        return $this->failure('outcome_unknown', 'The VPS.TC operation returned a conflicting receipt. Reconcile the provider state before any retry.');
                    }
                }
                return $this->failure('mutation_rejected', 'VPS.TC rejected the operation. Verify the requested resource and configuration.');
            }
            $hasReceipt = ($data['status'] ?? null) === true || ($data['success'] ?? null) === true;
            foreach ($expectedKeys as $key) {
                if (! array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '' || $data[$key] === []
                    || ($key === 'items' && ! is_array($data[$key]))
                    || ($key === 'invoice_id' && ((! is_string($data[$key]) && ! is_int($data[$key]))
                        || ! preg_match('/\A[1-9][0-9]{0,17}\z/D', (string) $data[$key])))) {
                    return $this->failure('outcome_unknown', 'The VPS.TC operation receipt is incomplete. Reconcile the provider state before any retry.');
                }
                $hasReceipt = true;
            }
            if (! $hasReceipt) {
                return $this->failure('outcome_unknown', 'The VPS.TC operation returned no confirmed receipt. Reconcile the provider state before any retry.');
            }

            return ['ok' => true, 'data' => $data, 'message' => '', 'code' => 'ok'];
        } catch (Throwable) {
            return $this->failure('outcome_unknown', 'The VPS.TC operation could not be confirmed. Reconcile the provider state before any retry.');
        }
    }

    private function withIds(array $ids, callable $operation): array
    {
        foreach ($ids as $id) {
            if (! preg_match('/\A[1-9][0-9]{0,17}\z/D', $id)) {
                return $this->failure('invalid_id', 'A valid provider resource identifier is required.');
            }
        }

        return $operation();
    }

    private function read(string $path, string $expectedKey, ?array $calculation = null, bool $billingIdentityOnly = false): array
    {
        if (! $this->isConfigured()) {
            return $this->failure('not_configured', 'VPS.TC credentials and an approved HTTPS API origin are required.');
        }
        if ($this->token === null && ! $this->authenticate(false)) {
            return $this->failure('authentication_failed', 'VPS.TC authentication failed. Check the reseller credentials and API origin.');
        }
        try {
            // The only POST read is the fixed /quote calculation above.
            $method = $calculation === null ? 'GET' : 'POST';
            $response = $this->request($method, $path, $calculation, true);
            // One auth recovery per read/calculation; never a business mutation.
            if ($response->status() === 401) {
                if (! $this->authenticate(true)) {
                    return $this->failure('authentication_failed', 'VPS.TC authentication could not be renewed.');
                }
                $response = $this->request($method, $path, $calculation, true);
            }
            if ($response->status() >= 300 && $response->status() < 400) {
                return $this->failure('redirect_rejected', 'VPS.TC returned a redirect. Verify the API origin.');
            }
            if ($response->status() === 401 || $response->status() === 403) {
                return $this->failure('access_denied', 'The VPS.TC reseller account cannot access this resource.');
            }
            if ($response->status() === 429) {
                return $this->failure('rate_limited', 'VPS.TC is rate limiting requests. Try again later.');
            }
            if (! $response->successful()) {
                return $this->failure('provider_error', 'VPS.TC could not complete the read-only request.');
            }
            $data = $this->decode($response);
            $billingAdvisory = $billingIdentityOnly && $expectedKey === 'service'
                && preg_match('~\A/api/service/[1-9][0-9]{0,17}\z~D', $path)
                && ($data['error'] ?? null) === ['management_offline']
                && ($data['success'] ?? true) !== false && ($data['status'] ?? true) !== false;
            if ($data === null || ! array_key_exists($expectedKey, $data) || ! is_array($data[$expectedKey])
                || ($this->hasApiError($data) && ! $billingAdvisory)) {
                return $this->failure('invalid_response', 'VPS.TC returned an unsupported response.');
            }

            return ['ok' => true, 'data' => $data, 'message' => '', 'code' => 'ok'];
        } catch (Throwable) {
            return $this->failure('connection_failed', 'The VPS.TC API could not be reached securely.');
        }
    }

    private function authenticate(bool $renew): bool
    {
        try {
            $useRefresh = $renew && $this->refresh !== null;
            $response = $this->request(
                'POST',
                $useRefresh ? '/api/token' : '/api/login',
                $useRefresh ? ['refresh_token' => $this->refresh] : ['username' => $this->username, 'password' => $this->password],
                false
            );
            if (! $response->successful()) {
                $this->token = null;

                return false;
            }
            $data = $this->decode($response);
            if ($data === null || $this->hasApiError($data) || ! isset($data['token']) || ! is_string($data['token']) || trim($data['token']) === '' || preg_match('/[\r\n]/', $data['token'])) {
                $this->token = null;

                return false;
            }
            $this->token = $data['token'];
            $this->refresh = isset($data['refresh']) && is_string($data['refresh']) && $data['refresh'] !== '' ? $data['refresh'] : null;

            return true;
        } catch (Throwable) {
            $this->token = null;

            return false;
        }
    }

    private function request(string $method, string $path, ?array $payload, bool $authenticated): Response
    {
        $request = Http::acceptJson()->asJson()->connectTimeout(5)->timeout(20)
            ->withOptions(['verify' => true, 'allow_redirects' => false]);
        if ($authenticated) {
            $request = $request->withToken($this->token);
        }

        return $method === 'POST'
            ? $request->post($this->origin.$path, $payload ?? [])
            : $request->get($this->origin.$path);
    }

    private function decode(Response $response): ?array
    {
        $body = trim($response->body());
        if ($body === '' || strlen($body) > 2000000 || $body[0] !== '{') {
            return null;
        }
        try {
            $data = json_decode($body, true, 128, JSON_THROW_ON_ERROR);

            return is_array($data) ? $data : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function hasApiError(array $data): bool
    {
        if ((array_key_exists('success', $data) && $data['success'] === false)
            || (array_key_exists('status', $data) && $data['status'] === false)) {
            return true;
        }

        return array_key_exists('error', $data)
            && ! in_array($data['error'], [null, false, '', [], 0], true);
    }

    private function failure(string $code, string $message): array
    {
        return ['ok' => false, 'data' => [], 'message' => $message, 'code' => $code];
    }
}
