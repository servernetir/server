<?php

namespace App\Services\Madar;

use App\Models\MadarParticipation;
use App\Models\Service;

/** Local evidence checklist for Hermes; no publication, external model calls or financial authority. */
final class MadarReview
{
    public function checklist(MadarParticipation $p): array
    {
        $proof = $p->messages()->where('is_submission', true)->latest('id')->first();

        return [
            'service_history' => Service::where('customer_id', $p->customer_id)->whereNotNull('activated_at')->exists(),
            'reward_reserved' => $p->grant && ! $p->grant->revoked_at && $p->grant->expires_at->isFuture(),
            'terms_accepted' => $p->accepted_at !== null,
            'evidence_received' => $proof !== null && (filled($proof->evidence_url) || filled($proof->attachment_path)),
            'rights_declared' => $proof?->rights_confirmed === true,
            'disclosure_declared' => ! ($p->reward_snapshot['mission']['mission']['public_content'] ?? config('madar.campaigns.'.$p->campaign_key.'.public_content')) || $proof?->disclosure_confirmed === true,
            // Declarations and service history do not prove truth, ownership, quality or an incremental sale.
            'human_verification_required' => true,
        ];
    }
}
