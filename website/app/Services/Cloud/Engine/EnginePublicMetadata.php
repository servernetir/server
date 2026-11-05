<?php

namespace App\Services\Cloud\Engine;

use App\Models\EngineVm;
use App\Models\Service;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Customer metadata is isolated from infrastructure metadata and never sent upstream. */
class EnginePublicMetadata
{
    public function editable(Service $service, EngineVm $vm): bool
    {
        return in_array($service->status, ['active', 'suspended', 'expired'], true) && $service->cancelled_at === null
            && ! $service->isInternalInfrastructure() && in_array($vm->status, ['running', 'off'], true)
            && $service->customer()->where('status', 'active')->exists();
    }

    public function validate(array $input): array
    {
        $data = Validator::make($input, [
            'labels' => ['sometimes', 'array', 'max:50'], 'labels.*' => ['nullable', 'string', 'max:256', 'regex:/\A[^\x00-\x1F\x7F]*\z/uD'],
            'protection' => ['sometimes', 'array:delete,rebuild'],
            'protection.delete' => ['sometimes', 'boolean'], 'protection.rebuild' => ['sometimes', 'boolean'],
        ])->validate();
        if ($data === [] || array_diff(array_keys($input), ['labels', 'protection']) !== []) {
            throw ValidationException::withMessages(['metadata' => 'invalid_metadata']);
        }
        foreach (array_keys($data['labels'] ?? []) as $key) {
            if (! is_string($key) || ! preg_match('/\A[A-Za-z][A-Za-z0-9._\/-]{0,62}\z/D', $key)) {
                throw ValidationException::withMessages(['labels' => 'invalid_label_key']);
            }
        }
        if (isset($data['labels'])) {
            $data['labels'] = array_map(fn ($value) => (string) $value, $data['labels']);
            ksort($data['labels']);
        }
        if (isset($data['protection'])) {
            $data['protection'] = array_map(fn ($value) => (bool) $value, $data['protection']);
            ksort($data['protection']);
        }
        return $data;
    }

    public function present(EngineVm $vm): array
    {
        return ['labels' => (object) ($vm->metadata['customer_labels'] ?? []),
            'protection' => ['delete' => ($vm->metadata['customer_protection']['delete'] ?? false) === true,
                'rebuild' => ($vm->metadata['customer_protection']['rebuild'] ?? false) === true]];
    }

    public function protected(EngineVm $vm, string $action): bool
    {
        return in_array($action, ['delete', 'rebuild'], true)
            && ($vm->metadata['customer_protection'][$action] ?? false) === true;
    }
}
