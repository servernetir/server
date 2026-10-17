<?php

namespace App\Services\IdentityApi;

use App\Services\Identity\ZohalProvider;

class Contract
{
    public function input(array $service, array $input): array
    {
        if ($service['contract_blocker']) {
            throw new IdentityError('service_unavailable', 503);
        }
        $schema = $service['operations'][0]['input'];
        if (array_diff(array_keys($input), array_keys($schema['properties'] ?? []))) {
            throw new IdentityError('invalid_input', 422);
        }
        foreach ($input as $k => &$v) {
            if (! is_string($v)) {
                continue;
            }
            $v = trim(strtr($v, array_combine(preg_split('//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY), str_split('01234567890123456789'))));
            if ($k === 'mobile') {
                $v = preg_replace('/^(?:\+98|0098|98)(?=9[0-9]{9}$)/', '0', $v);
                if (preg_match('/^9[0-9]{9}$/D', $v)) {
                    $v = '0'.$v;
                }
                if (! preg_match('/^09[0-9]{9}$/D', $v)) {
                    throw new IdentityError('invalid_input', 422);
                }
            }
            if ($k === 'birth_date') {
                $n = str_replace(['-', '.', '/'], '', $v);
                if (! preg_match('/^(13|14)[0-9]{2}(0[1-9]|1[0-2])(0[1-9]|[12][0-9]|3[01])$/D', $n)) {
                    throw new IdentityError('invalid_input', 422);
                }
                $v = substr($n, 0, 4).'/'.substr($n, 4, 2).'/'.substr($n, 6, 2);
            }
            if (in_array($k, ['iban', 'IBAN'], true)) {
                $v = strtoupper(str_replace(' ', '', $v));
                if (! preg_match('/^IR[0-9]{24}$/D', $v)) {
                    throw new IdentityError('invalid_input', 422);
                }
            }
            if ($k === 'national_id' && ! preg_match('/^[0-9]{11}$/D', $v)) {
                throw new IdentityError('invalid_input', 422);
            }
            if ($k === 'national_code' && ($schema['properties'][$k]['maxLength'] ?? null) === 10
                && ! app(ZohalProvider::class)->validNationalId($v)) {
                throw new IdentityError('invalid_input', 422);
            }
            if ($k === 'card_number' && ! app(ZohalProvider::class)->validCard($v)) {
                throw new IdentityError('invalid_input', 422);
            }
            if (in_array($k, ['postal_code', 'sayad_id', 'bill_id', 'code', 'phone_number', 'region_code', 'bank_account', 'bank_code'], true)
                && ! preg_match('/^[0-9]{1,32}$/D', $v)) {
                throw new IdentityError('invalid_input', 422);
            }
            if ($k === 'website' && (! preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/Di', $v)
                || filter_var($v, FILTER_VALIDATE_IP))) {
                throw new IdentityError('invalid_input', 422);
            }
        }
        unset($v);
        $this->shape($input, $schema, true);
        ksort($input);

        return $input;
    }

    /** Validate explicit schema fields; response projection drops undocumented fields. */
    public function shape(mixed $value, array $s, bool $input = false): mixed
    {
        foreach (['anyOf', 'oneOf'] as $union) {
            if (isset($s[$union])) {
                foreach ($s[$union] as $candidate) {
                    try {
                        return $this->shape($value, $candidate, $input);
                    } catch (IdentityError) {
                    }
                }
                throw new IdentityError($input ? 'invalid_input' : 'invalid_provider_data', 422);
            }
        }
        $ok = match ($s['type'] ?? 'object') {
            'null' => $value === null,'boolean' => is_bool($value),'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),'string' => is_string($value),
            'array' => is_array($value) && array_is_list($value),'object' => is_array($value), default => false,
        };
        if (! $ok || isset($s['enum']) && ! in_array($value, $s['enum'], true)) {
            throw new IdentityError($input ? 'invalid_input' : 'invalid_provider_data', 422);
        }
        if (is_string($value)) {
            $len = mb_strlen($value);
            if ($len < ($s['minLength'] ?? 0) || $len > min($s['maxLength'] ?? 4096, 4096)) {
                throw new IdentityError('invalid_input', 422);
            }
            if (! $input && preg_match('~https?://|zohal|زحل~iu', $value)) {
                return null;
            }

            return $value;
        }
        if (($s['type'] ?? null) === 'array') {
            if (count($value) > 1000) {
                throw new IdentityError('invalid_provider_data', 422);
            }

            return array_map(fn ($v) => $this->shape($v, $s['items'] ?? [], $input), $value);
        }
        if (is_array($value)) {
            foreach ($s['required'] ?? [] as $k) {
                if (! array_key_exists($k, $value)) {
                    throw new IdentityError($input ? 'invalid_input' : 'invalid_provider_data', 422);
                }
            }
            $result = [];
            foreach ($s['properties'] ?? [] as $k => $sub) {
                if (array_key_exists($k, $value)) {
                    $result[$k] = $this->shape($value[$k], $sub, $input);
                }
            }

            return $result;
        }

        return $value;
    }

    public function response(array $service, array $json, int $http): ?array
    {
        if ($http !== 200 || ($json['result'] ?? null) !== 1 || ! is_array($json['response_body'] ?? null)
            || ($json['response_body']['error_code'] ?? null) !== null || ! array_key_exists('data', $json['response_body'])) {
            return null;
        }
        $schema = $service['operations'][0]['output']['properties']['response_body']['properties']['data'] ?? null;
        if (! $schema) {
            return null;
        }
        try {
            $data = $this->shape($json['response_body']['data'], $schema);
        } catch (IdentityError) {
            return null;
        }

        return ['data' => $data, 'outcome' => array_key_exists('matched',$data) ? ($data['matched'] ? 'matched' : 'unmatched') : 'completed'];
    }
}
