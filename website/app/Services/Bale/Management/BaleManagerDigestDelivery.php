<?php

namespace App\Services\Bale\Management;

use App\Services\Bale\Admin\AdminBaleGate;
use App\Services\Bale\BaleSender;
use App\Services\Notify\BaleManagerPolicy;

/** One manager report per Tehran day. Ambiguous sends are never automatically repeated. */
class BaleManagerDigestDelivery
{
    public function __construct(private BaleManagerDailyDigest $digest, private AdminBaleGate $gate,
        private BaleSender $sender, private ?string $directory = null) {}

    public function send(): array
    {
        if (! BaleManagerPolicy::enabled()) { return ['status' => 'disabled']; }
        $binding = $this->gate->binding();
        if (! $this->gate->enabled() || ! $binding || ! $this->gate->boundUser()) { return ['status' => 'owner_unavailable']; }
        $chat = (string) ($binding['chat_id'] ?? '');
        if (! preg_match('/^-?[0-9]+$/D', $chat)) { return ['status' => 'owner_unavailable']; }
        if (! $this->sender->enabled()) { return ['status' => 'sender_unavailable']; }
        $directory = $this->directory ?? storage_path('app/private/bale-manager-digest');
        if (is_link($directory) || (! is_dir($directory) && ! mkdir($directory, 0700, true))) { throw new \RuntimeException('digest_directory_unavailable'); }
        chmod($directory, 0700);
        $day = now('Asia/Tehran')->format('Y-m-d');
        $path = $directory.'/'.$day.'.json';
        $lockPath = $directory.'/'.$day.'.lock';
        if (is_link($path) || is_link($lockPath)) { throw new \RuntimeException('digest_path_invalid'); }
        $lock = fopen($lockPath, 'c+b');
        if (! $lock) { throw new \RuntimeException('digest_lock_unavailable'); }
        chmod($lockPath, 0600);
        try {
            if (! flock($lock, LOCK_EX | LOCK_NB)) { return ['status' => 'busy']; }
            if (is_file($path)) {
                $previous = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
                return ['status' => 'already_claimed', 'prior_status' => $previous['status'] ?? 'unknown', 'day' => $day];
            }
            // Queries upstream balance APIs only in the scheduled delivery path.
            // Preview and callbacks consume the cached observations exclusively.
            try { app(ProviderBalanceSnapshot::class)->snapshot(); } catch (\Throwable) {}
            try { app(ManagerTrafficSnapshot::class)->refresh(); } catch (\Throwable) {}
            $text = $this->digest->text();
            $receipt = ['version' => 1, 'day' => $day, 'status' => 'dispatching', 'claimed_at' => now()->toIso8601String(),
                'report_sha256' => hash('sha256', $text), 'owner_fingerprint' => substr(hash('sha256', $chat), 0, 12)];
            $this->write($path, $receipt);
            try {
                // Refresh the binding immediately before dispatch. Never send to a stale owner.
                $fresh = $this->gate->binding();
                if (! BaleManagerPolicy::enabled() || ! $this->gate->enabled() || ! $this->gate->boundUser()
                    || (string) ($fresh['user_id'] ?? '') !== (string) ($binding['user_id'] ?? '')
                    || (string) ($fresh['chat_id'] ?? '') !== $chat) {
                    $receipt['status'] = 'owner_changed';
                } else {
                    $id = $this->sender->sendButtons($chat, $text, $this->digest->buttons());
                    $receipt['status'] = $id !== null ? 'sent' : 'uncertain';
                    if ($id !== null) { $receipt['message_id'] = $id; }
                }
            } catch (\Throwable) { $receipt['status'] = 'uncertain'; }
            $receipt['finished_at'] = now()->toIso8601String();
            $this->write($path, $receipt);
            if ($receipt['status'] === 'sent') {
                try { app(ManagementCostChanges::class)->snapshot(true); } catch (\Throwable) {}
            }
            return ['status' => $receipt['status'], 'day' => $day];
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function write(string $path, array $receipt): void
    {
        $stage = tempnam(dirname($path), '.receipt-');
        if (! $stage) { throw new \RuntimeException('digest_receipt_unavailable'); }
        try {
            chmod($stage, 0600);
            $bytes = json_encode($receipt, JSON_THROW_ON_ERROR);
            if (file_put_contents($stage, $bytes) !== strlen($bytes) || ! rename($stage, $path)) { throw new \RuntimeException('digest_receipt_unavailable'); }
        } finally { if (is_file($stage)) { unlink($stage); } }
    }
}
