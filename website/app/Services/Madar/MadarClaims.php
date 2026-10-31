<?php

namespace App\Services\Madar;

use App\Models\Customer;
use App\Models\MadarMission;
use App\Models\MadarParticipation;
use App\Models\MarketingCampaign;
use App\Services\Marketing\CampaignManager;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

final class MadarClaims
{
    public function __construct(private MadarHub $hub, private MadarEvidence $evidence, private MadarPublishing $publishing) {}

    public function submit(Customer $customer, array $data, ?UploadedFile $file = null): MadarParticipation
    {
        abort_unless($this->hub->catalogReady(), 503);
        $payloadHash = hash('sha256', json_encode([$customer->id, (int) $data['mission_id'], $data['title'], $data['body'],
            $data['evidence_url'] ?? null, $data['distribution_url'] ?? null, $file ? hash_file('sha256', $file->getRealPath()) : null,
            (bool) ($data['rights_confirmed'] ?? false), (bool) ($data['disclosure_confirmed'] ?? false), $data['terms_version']], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($customer, $data, $file, $payloadHash) {
            $freshCustomer = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            if ($existing = MadarParticipation::where('public_id', $data['request_key'])->first()) {
                $this->hub->check((int) $existing->customer_id === $freshCustomer->id && $existing->submission_hash
                    && hash_equals($existing->submission_hash, $payloadHash), 'retry_mismatch');

                return $existing;
            }
            $mission = MadarMission::findOrFail($data['mission_id']);
            $c = MarketingCampaign::whereKey($mission->reward_campaign_id)->lockForUpdate()->firstOrFail();
            $control = DB::table('madar_campaigns')->where('campaign_key', $mission->campaign_key)->first();
            $this->hub->check($freshCustomer->isActive() && $control?->open && $mission->published_at->lte(now())
                && $mission->submissions_until->isFuture() && $this->publishing->validProgram($c, $mission->reward_amount_irt), 'closed');
            $this->hub->check($data['terms_version'] === $mission->snapshot['terms_version'], 'terms_changed');
            $week = now('Asia/Tehran')->startOfWeek(CarbonInterface::MONDAY)->utc();
            $prior = MadarParticipation::where('customer_id', $freshCustomer->id)->where('flow_version', 2)
                ->whereNotIn('status', ['rejected', 'cancelled']);
            $this->hub->check((clone $prior)->where('terms_accepted_at', '>=', $week)->count() < $mission->snapshot['weekly_cap'], 'weekly_limit');
            $this->hub->check(! (clone $prior)->where('campaign_key', $mission->campaign_key)
                ->where('terms_accepted_at', '>=', now()->subDays($mission->snapshot['repeat_days']))->exists(), 'repeat_limit');
            $public = $mission->snapshot['mission']['public_content'];
            if ($public) {
                $this->hub->check(filled($data['evidence_url'] ?? null) && (bool) ($data['disclosure_confirmed'] ?? false), 'publication');
                foreach (['evidence_url', 'distribution_url'] as $key) {
                    if (filled($data[$key] ?? null)) {
                        $url = $this->evidence->url($data[$key]);
                        $host = strtolower(parse_url($url, PHP_URL_HOST));
                        $this->hub->check(! preg_match('/(?:^|\.)(trustpilot\.com|google\.[a-z.]+|goo\.gl)$/D', $host), 'publication');
                    }
                }
                $this->hub->check(! $mission->snapshot['mission']['distribution_required'] || filled($data['distribution_url'] ?? null), 'distribution');
            }
            $this->hub->check($this->publishing->capacity($c, $mission->reward_amount_irt) > 0, 'capacity');
            $grant = app(CampaignManager::class)->issue($c, $freshCustomer, 'madar:'.$data['request_key']);
            $this->hub->check($grant->liability === $mission->reward_amount_irt && $grant->snapshot['rule'] == $mission->snapshot['rule'], 'fixed_program');
            $p = MadarParticipation::create(['public_id' => $data['request_key'], 'customer_id' => $freshCustomer->id,
                'campaign_key' => $mission->campaign_key, 'mission_id' => $mission->id, 'flow_version' => 2, 'submission_hash' => $payloadHash,
                'title' => $data['title'], 'locale' => app()->getLocale(), 'status' => 'working', 'terms_version' => $data['terms_version'],
                'terms_accepted_at' => now(), 'accepted_at' => now(), 'grant_id' => $grant->id, 'work_due_at' => now()->addDays(14),
                'reward_snapshot' => ['campaign_id' => $c->id, 'amount_irt' => $grant->liability, 'rule' => $grant->snapshot['rule'],
                    'expires_at' => $grant->expires_at->toIso8601String(), 'mission' => $mission->snapshot, 'mission_version' => $mission->version],
                'customer_read_at' => now()]);
            $this->evidence->write($p, 'customer', $freshCustomer->id, $data, $file, true);

            return $p->fresh();
        }, 5);
    }
}
