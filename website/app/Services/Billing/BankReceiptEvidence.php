<?php

namespace App\Services\Billing;

use App\Models\BankTransferReceipt;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** Private receipt evidence; no URL exposes its storage path. */
final class BankReceiptEvidence
{
    public function store(UploadedFile $file, int $customerId): array
    {
        if (! $file->isValid() || ! in_array($file->getMimeType(), ['image/jpeg', 'image/png', 'application/pdf'], true)
            || $file->getSize() > 5 * 1024 * 1024 || $customerId < 1) {
            throw new \InvalidArgumentException('Receipt evidence must be JPEG, PNG or PDF up to 5 MB');
        }
        $path = $file->store('bank-receipts/'.$customerId, 'local');
        if (! $path) { throw new \RuntimeException('Receipt evidence could not be stored'); }
        return ['evidence_path' => $path, 'evidence_name' => mb_substr(basename($file->getClientOriginalName()), 0, 180),
            'evidence_mime' => $file->getMimeType(), 'evidence_sha256' => hash_file('sha256', Storage::disk('local')->path($path)),
            'evidence_size' => $file->getSize()];
    }

    public function path(BankTransferReceipt $receipt): ?string
    {
        $relative = (string) $receipt->evidence_path;
        if (! preg_match('~^bank-receipts/'.(int) $receipt->customer_id.'/[a-zA-Z0-9._-]+$~D', $relative)
            || ! in_array($receipt->evidence_mime, ['image/jpeg', 'image/png', 'application/pdf'], true)) { return null; }
        $disk = Storage::disk('local');
        $root = realpath($disk->path('bank-receipts/'.(int) $receipt->customer_id));
        $path = realpath($disk->path($relative));
        if (! $root || ! $path || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR) || ! is_file($path)) { return null; }
        if (! hash_equals((string) $receipt->evidence_sha256, hash_file('sha256', $path))) { return null; }
        return $path;
    }
}
