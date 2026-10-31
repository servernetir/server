<?php

namespace App\Services\Madar;

use App\Models\MadarMessage;
use App\Models\MadarParticipation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class MadarEvidence
{
    public static function rules(): array
    {
        return ['body' => 'required|string|min:5|max:5000',
            'attachment' => 'nullable|file|mimetypes:image/jpeg,image/png,image/webp|max:8192'];
    }

    /** Never fetch user-supplied links. Human review validates publication and ownership. */
    public function url(?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }
        $parts = parse_url(trim($url));
        $host = strtolower($parts['host'] ?? '');
        $ok = ($parts['scheme'] ?? '') === 'https' && ! isset($parts['user']) && ! isset($parts['pass'])
            && (! isset($parts['port']) || $parts['port'] === 443)
            && filter_var($url, FILTER_VALIDATE_URL) && ! filter_var($host, FILTER_VALIDATE_IP)
            && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}$/D', $host)
            && ! preg_match('/(?:^|\.)(?:localhost|local|internal|test|invalid|example)$/D', $host);
        app(MadarHub::class)->check((bool) $ok, 'url');

        return trim($url);
    }

    private function fingerprint(string $url): string
    {
        $p = parse_url($url);
        parse_str($p['query'] ?? '', $query);
        foreach (array_keys($query) as $key) {
            if (str_starts_with(strtolower($key), 'utm_') || in_array(strtolower($key), ['fbclid', 'gclid', 'igsh'], true)) {
                unset($query[$key]);
            }
        }
        ksort($query);

        return hash('sha256', strtolower($p['host']).rtrim($p['path'] ?? '/', '/').'?'.http_build_query($query));
    }

    public function write(MadarParticipation $p, string $role, int $actorId, array $data, ?UploadedFile $file = null, bool $submit = false): MadarMessage
    {
        $hub = app(MadarHub::class);
        $url = $this->url($data['evidence_url'] ?? null);
        $distribution = $this->url($data['distribution_url'] ?? null);
        $attachment = [];
        $path = null;
        if ($file) {
            $mime = $file->getMimeType();
            $hub->check(in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) && $file->getSize() <= 8 * 1024 * 1024, 'attachment');
            $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];
            $path = $file->storeAs('madar/'.$p->public_id, Str::uuid().'.'.$ext, 'local');
            $hub->check(is_string($path) && $path !== '', 'attachment');
            $attachment = ['attachment_path' => $path, 'attachment_mime' => $mime,
                'attachment_bytes' => $file->getSize(), 'attachment_sha256' => hash_file('sha256', $file->getRealPath())];
        }
        try {
            return DB::transaction(function () use ($p, $role, $actorId, $data, $submit, $url, $distribution, $attachment, $hub) {
                $fresh = MadarParticipation::whereKey($p->id)->lockForUpdate()->firstOrFail();
                $hub->check(in_array($fresh->status, MadarParticipation::STATUSES, true), 'state');
                if ($submit) {
                    $hub->check($role === 'customer' && in_array($fresh->status, ['working', 'changes_requested'], true), 'state');
                    $hub->check($fresh->work_due_at?->isFuture() === true, 'deadline');
                    $hub->check((bool) ($data['rights_confirmed'] ?? false), 'rights');
                    $meta = (int) $fresh->flow_version === 2 ? $fresh->reward_snapshot['mission']['mission'] : null;
                    $public = $meta['public_content'] ?? (bool) config('madar.campaigns.'.$fresh->campaign_key.'.public_content');
                    if ($meta && $meta['public_content']) {
                        $hub->check($url !== null, 'publication');
                        $hub->check(! $meta['distribution_required'] || ($distribution !== null && $distribution !== $url), 'distribution');
                        foreach (array_filter([$url, $distribution]) as $link) {
                            $hub->check(! preg_match('/(?:^|\.)(trustpilot\.com|google\.[a-z.]+|goo\.gl)$/D', strtolower(parse_url($link, PHP_URL_HOST))), 'publication');
                        }
                    }
                    $hub->check(! $public || (bool) ($data['disclosure_confirmed'] ?? false), 'disclosure');
                    $hub->check($url !== null || ! empty($attachment), 'evidence');
                    $hash = $url ? $this->fingerprint($url) : $attachment['attachment_sha256'];
                    // A unique DB claim closes the race between two customers submitting the same artifact.
                    foreach (array_unique(array_filter([$hash, $distribution ? $this->fingerprint($distribution) : null,
                        $attachment['attachment_sha256'] ?? null])) as $claim) {
                        DB::table('madar_artifacts')->insertOrIgnore(['hash' => $claim, 'participation_id' => $fresh->id,
                            'created_at' => now(), 'updated_at' => now()]);
                        $hub->check((int) DB::table('madar_artifacts')->where('hash', $claim)->value('participation_id') === $fresh->id, 'duplicate');
                    }
                    $fresh->forceFill(['status' => 'submitted', 'submitted_at' => now()])->save();
                }
                $extra = $distribution ? ['distribution_url' => $distribution] : [];
                $message = $fresh->messages()->create($attachment + $extra + ['sender_type' => $role, 'sender_id' => $actorId,
                    'body' => $data['body'], 'evidence_url' => $url, 'is_submission' => $submit,
                    'artifact_hash' => $hash ?? null, 'rights_confirmed' => (bool) ($data['rights_confirmed'] ?? false),
                    'disclosure_confirmed' => (bool) ($data['disclosure_confirmed'] ?? false)]);
                $fresh->forceFill([$role === 'customer' ? 'customer_read_at' : 'staff_read_at' => now(),
                    $role === 'customer' ? 'staff_read_at' : 'customer_read_at' => null])->touch();

                return $message;
            }, 3);
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }
    }
}
